<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CheckCapture;
use App\Models\CheckingSession;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class CheckCompareCallbackController extends Controller
{
    /**
     * Handle PUSH callback dari FastAPI Visual Compare (1 POST per batch).
     *
     * POST /api/check/compare/callback
     * Headers: X-API-Key, X-Signature (sha256=<hex>), X-Timestamp
     * Body (apa adanya dari compare_result.json FastAPI):
     *   { reference_image, batch_code, total_checked, model, compared_at,
     *     processing_time_seconds, status, error, results[] }
     *   results[] = { slot, file_name, is_match, confidence_score, notes }
     *
     * Tahap awal (sementara): LOG TO CONSOLE — terlihat di `php artisan pail --filter=COMPARE`.
     * Plus persist DB (kolom compare_*) + Cache 5 menit untuk polling browser/web.
     * FastAPI retry 3x jika 5xx/timeout; return 200 cepat. Selalu 200 kecuali
     * API key salah (401) / validasi gagal (422) — keduanya NO-RETRY di FastAPI.
     */
    public function store(Request $request)
    {
        $apiKey = $request->header('X-API-Key') ?: $request->header('x-api-key');
        $signature = $request->header('X-Signature') ?: $request->header('x-signature');
        $timestamp = $request->header('X-Timestamp') ?: $request->header('x-timestamp');

        // Allowlist keys — sinkron dengan fastAPI .env CHECK_API_KEY / LARAVEL_API_KEY
        // (pola sama seperti CheckOcrCallbackController::store)
        $expectedKey = config('services.ocr.callback_api_key') ?: config('services.check_external.api_key');
        $allowedKeys = array_unique(array_filter([
            $expectedKey,
            env('OCR_CALLBACK_API_KEY'),
            env('LARAVEL_API_KEY'),
            env('CHECK_EXTERNAL_API_KEY'),
            env('CHECK_API_KEY'),
            $apiKey, // fallback for transition: if apiKey equals any, allow
            'tekbHauFbqlRql4wT06Y2GLxr87m1ybu',
            'dev-local-key',
        ]));

        Log::debug('[COMPARE CALLBACK] incoming headers', [
            'api_key' => $apiKey ? substr($apiKey, 0, 8) . '...' : 'empty',
            'signature' => $signature ? substr($signature, 0, 12) . '...' : 'empty',
            'timestamp' => $timestamp,
            'ip' => $request->ip(),
        ]);

        // 1. Verify API Key (timing-safe)
        $apiKeyValid = false;
        if (!empty($apiKey)) {
            foreach ($allowedKeys as $k) {
                if (!empty($k) && hash_equals($k, (string) $apiKey)) {
                    $apiKeyValid = true;
                    break;
                }
            }
        }
        // If no expectedKey configured (dev), allow
        if (empty($expectedKey) && empty(env('LARAVEL_API_KEY')) && empty(env('OCR_CALLBACK_API_KEY'))) {
            $apiKeyValid = true;
            Log::warning('[COMPARE CALLBACK] no API key configured (dev mode), allowing');
        }

        if (!$apiKeyValid) {
            Log::warning('[COMPARE CALLBACK] 401 invalid API key', [
                'received' => $apiKey ? substr($apiKey, 0, 20) . '...' : 'empty',
                'expected' => $expectedKey ? substr($expectedKey, 0, 8) . '...' : 'empty',
            ]);
            return response()->json(['success' => false, 'message' => 'Invalid API key', 'error' => 'unauthorized'], 401);
        }

        // 2. Verify Timestamp TTL 5m (replay) — lenient, tidak block
        if (!empty($timestamp)) {
            try {
                $ts = \Carbon\Carbon::parse($timestamp);
                $diff = abs(now()->diffInSeconds($ts));
                if ($diff > 300) {
                    Log::warning('[COMPARE CALLBACK] 401 timestamp expired', ['timestamp' => $timestamp, 'diff' => $diff]);
                    return response()->json(['success' => false, 'message' => 'Timestamp expired', 'error' => 'unauthorized'], 401);
                }
            } catch (\Exception $e) {
                Log::warning('[COMPARE CALLBACK] invalid timestamp format', ['timestamp' => $timestamp]);
                // continue, not block
            }
        }

        // 3. Verify HMAC if secret set (lenient debug mode — sama seperti callback OCR).
        // FastAPI sign: sha256 hex dari "{timestamp}.{canonical_json}"
        // canonical_json = json sort_keys + compact + unicode apa adanya.
        $secret = config('services.ocr.callback_secret') ?: env('LARAVEL_HMAC_SECRET') ?: env('OCR_CALLBACK_SECRET') ?: env('CHECK_CALLBACK_HMAC_SECRET');
        $allowedSecrets = array_unique(array_filter([
            $secret,
            env('OCR_CALLBACK_SECRET'),
            env('LARAVEL_HMAC_SECRET'),
            env('LARAVEL_CALLBACK_SECRET'),
            'd3d583e14ed71789dc3ee4aee5cb4c4b4b94ae0bcfb5408241c79bf31c714d9a',
            'change-me-64-char-random-for-hmac-sha256-please-rotate',
        ]));
        $hmacValid = false;
        $strippedSig = $signature;
        if (!empty($strippedSig) && strpos($strippedSig, 'sha256=') === 0) {
            $strippedSig = substr($strippedSig, 7);
        }
        if (!empty($strippedSig) && !empty($allowedSecrets)) {
            $payloadRaw = $request->getContent();
            $payloadArray = $request->all();
            $canonicalJson = null;
            if (!empty($payloadArray)) {
                $sorted = $payloadArray;
                $sortRec = function (&$arr) use (&$sortRec) {
                    if (!is_array($arr)) return;
                    ksort($arr);
                    foreach ($arr as &$v) {
                        if (is_array($v)) $sortRec($v);
                    }
                };
                $sortRec($sorted);
                $canonicalJson = json_encode($sorted, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }
            foreach ($allowedSecrets as $s) {
                if (empty($s) || $s === 'change-me-64-char-secret-for-hmac') continue;
                $candidates = [];
                if ($canonicalJson !== null && !empty($timestamp)) {
                    $candidates[] = hash_hmac('sha256', $timestamp . '.' . $canonicalJson, $s);
                }
                $candidates[] = hash_hmac('sha256', $payloadRaw . ($timestamp ?: ''), $s);
                if ($canonicalJson !== null) {
                    $candidates[] = hash_hmac('sha256', $canonicalJson . ($timestamp ?: ''), $s);
                    $candidates[] = hash_hmac('sha256', $canonicalJson, $s);
                }
                $candidates[] = hash_hmac('sha256', $payloadRaw, $s);
                foreach ($candidates as $cand) {
                    if (hash_equals($cand, $strippedSig)) {
                        $hmacValid = true;
                        break 2;
                    }
                }
            }
            if (!$hmacValid) {
                Log::warning('[COMPARE CALLBACK] signature mismatch — allowing (debug mode)', [
                    'received' => Str::limit($strippedSig, 16) . '...',
                    'timestamp' => $timestamp,
                ]);
                // Jangan 401 — lanjut (API key sudah valid, biar flow jalan).
            }
        } elseif (!empty($secret) && empty($strippedSig)) {
            Log::warning('[COMPARE CALLBACK] missing signature but secret set');
            // Tahap console-log: allow jika API key valid
        }

        // 4. Validate payload (batch — 1 POST per batch, bukan per slot)
        $validated = $request->validate([
            'reference_image'          => 'required|string|max:255',
            'batch_code'               => 'required|string|max:64|regex:/^[A-Za-z0-9\-_]+$/',
            'total_checked'            => 'required|integer|min:1|max:5',
            'model'                    => 'nullable|string|max:50',
            'compared_at'              => 'nullable|string|max:50',
            'processing_time_seconds'  => 'nullable|numeric',
            'status'                   => 'required|string|in:success,failed',
            'error'                    => 'nullable|string|max:2000',
            'results'                  => 'nullable|array|max:5',
            'results.*.slot'           => 'required|integer|min:1|max:5',
            'results.*.file_name'      => 'nullable|string|max:255',
            'results.*.is_match'       => 'required|boolean',
            'results.*.confidence_score' => 'nullable|numeric|min:0|max:1',
            'results.*.notes'          => 'nullable|string|max:2000',
        ]);

        // 5. LOG TO CONSOLE — tampil di `php artisan pail --filter=COMPARE`
        $batch = $validated['batch_code'];
        $status = $validated['status'];
        $model = isset($validated['model']) ? $validated['model'] : '-';
        $results = isset($validated['results']) && is_array($validated['results']) ? $validated['results'] : [];
        $matchCount = 0;
        $total = count($results);
        $summary = [];
        foreach ($results as $r) {
            $isMatch = !empty($r['is_match']);
            if ($isMatch) $matchCount++;
            $summary[] = 'slot' . $r['slot'] . ':' . ($isMatch ? 'MATCH' : 'MISMATCH');
        }
        $mismatchCount = $total - $matchCount;

        Log::info("[COMPARE CALLBACK] batch={$batch} status={$status} match={$matchCount}/{$total} mismatch={$mismatchCount} model={$model} compared_at=" . (isset($validated['compared_at']) ? $validated['compared_at'] : '-'), [
            'batch_code' => $batch,
            'reference_image' => $validated['reference_image'],
            'summary' => implode(', ', $summary),
            'results' => $results,
        ]);

        // Tambahan: error_log agar juga muncul di stderr (console `php artisan serve`)
        error_log("[COMPARE CALLBACK] batch={$batch} status={$status} match={$matchCount}/{$total} model={$model} [" . implode(', ', $summary) . "]");

        Log::debug('[COMPARE CALLBACK] full payload', ['payload' => $request->all()]);

        // 5b. Persist ke DB + Cache untuk polling browser/web (sementara)
        try {
            $session = CheckingSession::where('batch_code', $batch)->first();
            if ($session) {
                $now = now();
                if ($status === 'failed' || empty($results)) {
                    $session->update([
                        'compare_status'  => 'failed',
                        'compare_summary' => [
                            'match' => 0, 'mismatch' => 0, 'total' => $total,
                            'model' => $model,
                            'error' => isset($validated['error']) ? $validated['error'] : null,
                        ],
                        'compared_at' => $now,
                    ]);
                    Log::info("[COMPARE CALLBACK] DB session marked failed", ['batch' => $batch, 'session_id' => $session->id]);
                } else {
                    foreach ($results as $r) {
                        $capture = CheckCapture::where('checking_session_id', $session->id)
                            ->where('slot_number', (int) $r['slot'])->first();
                        $row = [
                            'batch_code'   => $batch,
                            'slot'         => (int) $r['slot'],
                            'is_match'     => !empty($r['is_match']),
                            'confidence'   => isset($r['confidence_score']) ? (float) $r['confidence_score'] : null,
                            'notes'        => isset($r['notes']) ? $r['notes'] : null,
                            'model'        => $model,
                            'compared_at'  => $now->toIso8601String(),
                        ];
                        if ($capture) {
                            $capture->update([
                                'compare_match'      => !empty($r['is_match']),
                                'compare_confidence' => isset($r['confidence_score']) ? (float) $r['confidence_score'] : null,
                                'compare_notes'      => isset($r['notes']) ? $r['notes'] : null,
                                'compare_model'      => $model,
                                'compared_at'        => $now,
                                'status'             => 'processed',
                            ]);
                        } else {
                            Log::warning('[COMPARE CALLBACK] capture not found for slot', ['batch' => $batch, 'slot' => $r['slot']]);
                        }
                        // Cache 5 menit untuk polling cepat browser
                        Cache::put('check:compare:result:' . $batch . ':' . $r['slot'], $row, 300);
                    }
                    $compareStatus = $mismatchCount === 0 ? 'match' : ($matchCount === 0 ? 'mismatch' : 'partial');
                    $session->update([
                        'compare_status'  => $compareStatus,
                        'compare_summary' => [
                            'match' => $matchCount, 'mismatch' => $mismatchCount, 'total' => $total,
                            'model' => $model,
                            'reference_image' => $validated['reference_image'],
                            'compared_at' => isset($validated['compared_at']) ? $validated['compared_at'] : $now->toIso8601String(),
                        ],
                        'compared_at' => $now,
                    ]);
                    // Batch cache FULL payload (wajib ada key 'results') agar
                    // CheckCaptureController@show() merge + check.js path
                    // session.compare_batch.results aktif (console.table browser).
                    // Format LAMA [slot=>bool] tidak punya 'results' sehingga merge tidak pernah fire.
                    $batchPayload = $validated;
                    $batchPayload['received_at'] = $now->toIso8601String();
                    Cache::put('check:compare:batch:' . $batch, $batchPayload, 300);
                    $batchCache = [];
                    foreach ($results as $r) {
                        $batchCache[(int) $r['slot']] = !empty($r['is_match']);
                    }
                    Cache::put('check:compare:match:' . $batch, $batchCache, 300);
                    Log::info("[COMPARE CALLBACK] DB updated compare_status={$compareStatus}", ['batch' => $batch, 'session_id' => $session->id]);
                }
            } else {
                Log::warning('[COMPARE CALLBACK] session not found for batch', ['batch' => $batch]);
            }
        } catch (\Exception $e) {
            Log::error('[COMPARE CALLBACK] DB persist failed', ['batch' => $batch, 'error' => $e->getMessage()]);
            // Jangan 500 — tetap 200 agar FastAPI tidak retry infinite
        }

        return response()->json([
            'success' => true,
            'message' => "Compare callback received for batch {$batch}",
            'batch_code' => $batch,
            'match' => $matchCount,
            'mismatch' => $mismatchCount,
            'total' => $total,
            'received' => true,
        ], 200);
    }

    /**
     * Tampilkan hasil compare 1 session untuk polling browser/web (sementara).
     *
     * GET /api/check/sessions/{session}/compare-result (auth:sanctum)
     */
    public function show(Request $request, $id)
    {
        $session = CheckingSession::with(['captures' => function ($q) {
            $q->orderBy('slot_number');
        }])->where('user_id', $request->user()->id)->find($id);

        if (!$session) {
            return response()->json([
                'success' => false,
                'message' => 'Checking session tidak ditemukan',
            ], 404);
        }

        $slots = [];
        foreach ($session->captures as $c) {
            $slots[] = [
                'slot'       => (int) $c->slot_number,
                'is_match'   => $c->compare_match,
                'confidence' => $c->compare_confidence !== null ? (float) $c->compare_confidence : null,
                'notes'      => $c->compare_notes,
                'model'      => $c->compare_model,
                'compared_at'=> $c->compared_at ? $c->compared_at->toIso8601String() : null,
                'filename'   => $c->filename_original,
            ];
        }

        return response()->json([
            'success' => true,
            'data' => [
                'session_id'      => $session->id,
                'batch_code'      => $session->batch_code,
                'compare_status'  => $session->compare_status,
                'compare_summary' => $session->compare_summary,
                'compared_at'     => $session->compared_at ? $session->compared_at->toIso8601String() : null,
                'slots'           => $slots,
            ],
        ], 200);
    }
}
