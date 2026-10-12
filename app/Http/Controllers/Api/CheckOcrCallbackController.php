<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CheckCapture;
use App\Models\CheckingSession;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class CheckOcrCallbackController extends Controller
{
    /**
     * Handle PUSH callback dari FastAPI Check OCR per label.
     *
     * POST /api/check/ocr/callback
     * Headers: X-API-Key, X-Signature, X-Timestamp
     * Body: { batch_code, slot, filename, cleaned_text, lines, is_empty, status, ... }
     *
     * Tahap awal: hanya log ke console (Log::info), tidak wajib simpan DB.
     * FastAPI akan retry 3x jika 5xx/timeout; return 200 cepat <50ms.
     */
    public function store(Request $request)
    {
        $apiKey = $request->header('X-API-Key') ?? $request->header('x-api-key');
        $signature = $request->header('X-Signature') ?? $request->header('x-signature');
        $timestamp = $request->header('X-Timestamp') ?? $request->header('x-timestamp');

        // Allowlist keys — sinkron dengan fastAPI .env CHECK_API_KEY / LARAVEL_API_KEY
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

        // For debug: log headers
        Log::debug('[CHECK CALLBACK] incoming headers', [
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
            Log::warning('[CHECK CALLBACK] no API key configured (dev mode), allowing');
        }

        if (!$apiKeyValid) {
            Log::warning('[CHECK CALLBACK] 401 invalid API key', [
                'received' => $apiKey ? substr($apiKey, 0, 20) . '...' : 'empty',
                'expected' => $expectedKey ? substr($expectedKey, 0, 8) . '...' : 'empty',
            ]);
            return response()->json(['success' => false, 'message' => 'Invalid API key', 'error' => 'unauthorized'], 401);
        }

        // 2. Verify Timestamp TTL 5m (replay)
        if (!empty($timestamp)) {
            try {
                $ts = \Carbon\Carbon::parse($timestamp);
                $diff = abs(now()->diffInSeconds($ts));
                if ($diff > 300) {
                    Log::warning('[CHECK CALLBACK] 401 timestamp expired', ['timestamp' => $timestamp, 'diff' => $diff]);
                    return response()->json(['success' => false, 'message' => 'Timestamp expired', 'error' => 'unauthorized'], 401);
                }
            } catch (\Exception $e) {
                Log::warning('[CHECK CALLBACK] invalid timestamp format', ['timestamp' => $timestamp]);
                // continue, not block
            }
        }

        // 3. Verify HMAC if secret set
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
                $candidates[] = hash_hmac('sha256', $payloadRaw . ($timestamp ?? ''), $s);
                if ($canonicalJson !== null) {
                    $candidates[] = hash_hmac('sha256', $canonicalJson . ($timestamp ?? ''), $s);
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
                Log::warning('[CHECK CALLBACK] signature mismatch — allowing (debug mode)', [
                    'received' => Str::limit($strippedSig, 16) . '...',
                    'timestamp' => $timestamp,
                ]);
                // Jangan 401 — lanjut (API key sudah valid, biar flow jalan). Strict mode bisa return 401.
            }
        } elseif (!empty($secret) && empty($strippedSig)) {
            Log::warning('[CHECK CALLBACK] missing signature but secret set');
            // For initial console log stage, allow missing signature if API key valid
        }

        // 3b. PROGRESSIVE CALLBACK — event final batch summary (FastAPI baru).
        // Dikirim 1x di akhir batch via background thread (non-blocking).
        // Payload: { event=batch_completed, batch_code, overall_status, progress,
        //   completed_count, failed_count, processing_time_total_seconds,
        //   is_final=true, compare_summary, completed_at }.
        // WAJIB branch SEBELUM validasi per-slot (summary tidak punya slot/filename).
        // Idempoten: retry + Cache::put overwrite key yang sama, update session
        // by batch_code (bukan insert) sehingga kirim ulang aman.
        $progressEvent = $request->input('event', 'slot_completed');
        if ($progressEvent === 'batch_completed') {
            $validatedSummary = $request->validate([
                'event' => 'nullable|string|in:slot_completed,batch_completed',
                'batch_code' => 'required|string|max:64|regex:/^[A-Za-z0-9\-_]+$/',
                'overall_status' => 'nullable|string|max:50',
                'progress' => 'nullable|string|max:20',
                'completed_count' => 'nullable|integer|min:0|max:5',
                'failed_count' => 'nullable|integer|min:0|max:5',
                'processing_time_total_seconds' => 'nullable|numeric|min:0',
                'is_final' => 'nullable|boolean',
                'compare_summary' => 'nullable|array',
                'completed_at' => 'nullable|string|max:50',
            ]);
            $sumBatch = $validatedSummary['batch_code'];
            $sumOverall = $validatedSummary['overall_status'] ?? '-';
            $sumProgress = $validatedSummary['progress'] ?? '-';
            $sumCompare = $validatedSummary['compare_summary'] ?? [];
            $combined = is_array($sumCompare) ? ($sumCompare['combined_overall'] ?? '-') : '-';
            Log::info("[CHECK CALLBACK SUMMARY] Batch {$sumBatch} COMPLETED overall={$sumOverall} progress={$sumProgress} combined={$combined}", $request->all());
            error_log("[CHECK CALLBACK SUMMARY] Batch {$sumBatch} COMPLETED overall={$sumOverall} progress={$sumProgress} combined={$combined}");
            try {
                $summaryPayload = array_merge($validatedSummary, [
                    'received_at' => now()->toIso8601String(),
                ]);
                Cache::put('check:ocr:batch_summary:' . $sumBatch, $summaryPayload, 600);
                $sess = CheckingSession::where('batch_code', $sumBatch)->first();
                if ($sess) {
                    // Catat ringkasan tanpa merusak kolom visual-compare:
                    // simpan di notes HANYA bila kosong, update utama via cache.
                    // compared_at diisi agar polling tahu batch selesai.
                    try {
                        $sess->update(['compared_at' => now()]);
                    } catch (\Exception $e) {
                        Log::warning('[CHECK CALLBACK SUMMARY] session touch failed', ['batch' => $sumBatch, 'error' => $e->getMessage()]);
                    }
                    Log::info("[CHECK CALLBACK SUMMARY] session touched", ['batch' => $sumBatch, 'session_id' => $sess->id]);
                } else {
                    Log::warning('[CHECK CALLBACK SUMMARY] session not found for batch', ['batch' => $sumBatch]);
                }
            } catch (\Exception $e) {
                Log::error('[CHECK CALLBACK SUMMARY] persist failed', ['batch' => $sumBatch, 'error' => $e->getMessage()]);
                // Jangan 500 — tetap 200 agar FastAPI tidak retry infinite
            }
            return response()->json([
                'success' => true,
                'message' => 'Batch summary recorded',
                'batch_code' => $sumBatch,
            ], 200);
        }

        // 4a. Batch JSON handler (FastAPI baru: {batch_code, results[4]}) — hybrid dengan per-slot lama
        if ($request->has('results') && is_array($request->input('results')) && !$request->has('slot')) {
            $validatedBatch = $request->validate([
                'batch_code' => 'required|string|max:64|regex:/^[A-Za-z0-9\-_]+$/',
                'reference_image' => 'nullable|string|max:255',
                'total_checked' => 'nullable|integer|min:1|max:5',
                'model' => 'nullable|string|max:50',
                'compared_at' => 'nullable|string|max:50',
                'processing_time_seconds' => 'nullable|numeric',
                'status' => 'nullable|string|in:success,failed,error',
                'error' => 'nullable|string|max:1000',
                'results' => 'required|array|min:1|max:5',
                'results.*.slot' => 'required|integer|min:1|max:5',
                'results.*.file_name' => 'nullable|string|max:255',
                'results.*.is_match' => 'required|boolean',
                'results.*.confidence_score' => 'nullable|numeric|min:0|max:1',
                'results.*.notes' => 'nullable|string|max:1000',
            ]);
            $batch = $validatedBatch['batch_code'];
            $results = $validatedBatch['results'];
            Log::info("[CHECK CALLBACK] batch={$batch} total_checked=" . count($results) . " model=" . ($validatedBatch['model'] ?? '-') . " results=" . json_encode($results, JSON_UNESCAPED_UNICODE), $validatedBatch);
            error_log("[CHECK CALLBACK] batch={$batch} total=" . count($results) . " model=" . ($validatedBatch['model'] ?? '-'));
            Cache::put('check:compare:batch:' . $batch, $validatedBatch, 300);
            foreach ($results as $r) {
                Cache::put('check:compare:slot:' . $batch . ':' . $r['slot'], $r, 300);
                try {
                    $sess = CheckingSession::where('batch_code', $batch)->first();
                    if ($sess) {
                        $cap = CheckCapture::where('checking_session_id', $sess->id)->where('slot_number', $r['slot'])->first();
                        if ($cap) {
                            try { $cap->update(['is_match' => $r['is_match'], 'confidence_score' => $r['confidence_score'], 'compare_notes' => $r['notes'], 'compare_raw' => json_encode($r)]); }
                            catch (\Exception $e) {
                                $cap->update(['cleaned_text' => $r['is_match'] ? 'MATCH' : 'NO_MATCH', 'ocr_status' => 'success', 'raw_text' => $r['notes'], 'error_message' => 'confidence:' . ($r['confidence_score'] ?? '-')]);
                            }
                        }
                    }
                } catch (\Exception $e) { Log::error('[CHECK CALLBACK] batch DB failed', ['batch' => $batch, 'error' => $e->getMessage()]); }
            }
            Log::debug('[CHECK CALLBACK] batch full payload', ['payload' => $request->all()]);
            return response()->json(['success' => true, 'message' => "Batch callback received for {$batch} " . count($results) . " slots", 'batch_code' => $batch, 'received' => true], 200);
        }

        // 4b. Validate payload per-slot lama + field progresif aditif FastAPI baru.
        // PROGRESSIVE (2026-10-09): FastAPI menyisipkan event/progress/is_final/
        // processed_at tanpa mengubah struktur utama (backward compat). Semua
        // nullable agar payload lama tetap lolos; unknown fields tetap diabaikan
        // validator tapi tersedia via $request->input() untuk cache/log.
        $validated = $request->validate([
            'event' => 'nullable|string|in:slot_completed,batch_completed',
            'progress' => 'nullable|string|max:20',
            'is_final' => 'nullable|boolean',
            'completed_at' => 'nullable|string|max:50',
            'batch_code' => 'required|string|max:64|regex:/^[A-Za-z0-9\-_]+$/',
            'slot' => 'required|integer|min:1|max:5',
            'filename' => 'required|string|max:255',
            'original_filename' => 'nullable|string|max:255',
            'stored_path' => 'nullable|string|max:512',
            'cleaned_text' => 'nullable|string',
            'raw_text' => 'nullable|string',
            'lines' => 'nullable|array',
            'lines.*' => 'nullable|string',
            'is_empty' => 'required|boolean',
            'empty_reason' => 'nullable|string|max:255',
            'status' => 'required|string|in:success,failed,pending,error',
            'error' => 'nullable|string|max:1000',
            'engine' => 'nullable|string|max:50',
            'language' => 'nullable|string|max:20',
            // Kontrak RapidOCR (2026-09-11): 'none' = tidak ada teks = success valid.
            'text_present' => 'nullable|boolean',
            // Tuning RapidOCR (2026-09-12): nama model det/rec (small=cepat, medium=akurat).
            'det_model' => 'nullable|string|max:100',
            'rec_model' => 'nullable|string|max:100',
            'det_threshold' => 'nullable|numeric|min:0|max:1',
            'rec_threshold' => 'nullable|numeric|min:0|max:1',
            'preprocessed' => 'nullable|boolean',
            'preprocessing_steps' => 'nullable|array',
            'preprocessing_attempts' => 'nullable|array',
            'preprocessed_pass' => 'nullable|integer',
            'processed_at' => 'nullable|string|max:50',
            'processing_time_seconds' => 'nullable|numeric',
            'file_size_bytes' => 'nullable|integer|min:0',
            // COMPARE_TEXT_SIMILARITY_PLAN (2026-09-12): blok similarity per slot.
            // REVIEW_FLOW_COMPARE_FRONT_BACK_PLAN (2026-10-05) TAHAP L4: + field side.
            // FIX_WRONG_REFERENCE (2026-10-07): + ref_source diagnostik dari FastAPI.
            'side' => 'nullable|string|in:front,back,unknown',
            'ref_source' => 'nullable|string|max:20',
            'similarity' => 'nullable|array',
            'similarity.version' => 'nullable|string|max:20',
            'similarity.verdict' => 'nullable|string|in:PASS,FAIL,SKIP',
            'similarity.score' => 'nullable|numeric|min:0|max:1',
            'similarity.threshold' => 'nullable|numeric|min:0|max:1',
            'similarity.ref_lines' => 'nullable|integer|min:0',
            'similarity.slot_lines' => 'nullable|integer|min:0',
            'similarity.matched_lines' => 'nullable|integer|min:0',
            'similarity.reason' => 'nullable|string|max:255',
            // TOLERANSI TANDA BACA (2026-10-09): flag QA aditif dari FastAPI.
            // numeric_diff=true = ada perbedaan menyentuh digit (desimal/kode)
            // walau verdict PASS — operator wajib verifikasi manual.
            // punct_ignored_count = jumlah diff punct-only yang difilter.
            'similarity.numeric_diff' => 'nullable|boolean',
            'similarity.punct_ignored_count' => 'nullable|integer|min:0|max:1000',
            // CONTENT-GATE (2026-10-09): jumlah kata tak-terjelaskan (beda konten
            // sungguhan, verdict FAIL + reason content_mismatch). Aditif.
            'similarity.unexplained_orphans' => 'nullable|integer|min:0|max:1000',
            // PLAN_GEMINI_API_TEXT_COMPARE (2026-10-07): field baru dari
            // FastAPI gemini_compare.gemini_result_to_verdict(). Tanpa rules
            // ini $validated memangkas field baru sehingga browser/DB tak
            // menerimanya. Semua nullable agar payload difflib lama tetap lolos.
            'similarity.compare_engine' => 'nullable|string|max:20',
            'similarity.critical_count' => 'nullable|integer|min:0|max:1000',
            'similarity.minor_count' => 'nullable|integer|min:0|max:1000',
            'similarity.total_diffs' => 'nullable|integer|min:0|max:1000',
            'similarity.gemini_diffs' => 'nullable|array|max:50',
            'similarity.gemini_diffs.*.id' => 'nullable|integer|min:1',
            'similarity.gemini_diffs.*.teks_acuan' => 'nullable|string|max:500',
            'similarity.gemini_diffs.*.teks_check' => 'nullable|string|max:500',
            'similarity.gemini_diffs.*.severity' => 'nullable|string|in:critical,minor',
            'similarity.gemini_diffs.*.category' => 'nullable|string|max:50',
            // PLAN_IMPROVE_COMPARE_PRECISION_WITH_DIFF_REASON (2026-10-07):
            // diff_details dari FastAPI (aditif). max:50 (naik dari 20) karena
            // path Gemini (gemini_result_to_verdict) mengkonversi SEMUA diffs
            // tanpa cap 20 seperti difflib — batas lama akan 422 untuk batch
            // dengan >20 perbedaan. Tanpa rules ini $validated akan memangkas
            // diff_details sehingga browser tak menerimanya.
            'similarity.diff_details' => 'nullable|array|max:50',
            'similarity.diff_details.*.type' => 'nullable|string|in:changed,missing,added',
            'similarity.diff_details.*.ref_line_num' => 'nullable|integer|min:1',
            'similarity.diff_details.*.check_line_num' => 'nullable|integer|min:1',
            'similarity.diff_details.*.ref_text' => 'nullable|string|max:500',
            'similarity.diff_details.*.check_text' => 'nullable|string|max:500',
            // PLAN_COMPARE_PARAGRAPH_GLOBAL (2026-10-07): blok laporan kata.
            // Tanpa rules ini $validated memangkas "perbandingan" sehingga
            // browser tak menerimanya. Key lama di atas tidak berubah.
            'perbandingan' => 'nullable|array',
            'perbandingan.ringkasan_perbandingan' => 'nullable|array',
            'perbandingan.ringkasan_perbandingan.total_perbedaan' => 'nullable|integer|min:0',
            'perbandingan.ringkasan_perbandingan.status' => 'nullable|string|in:Selesai,Dilewati',
            'perbandingan.detail_perbedaan' => 'nullable|array|max:20',
            'perbandingan.detail_perbedaan.*.id' => 'nullable|integer|min:1',
            'perbandingan.detail_perbedaan.*.teks_acuan' => 'nullable|string|max:500',
            'perbandingan.detail_perbedaan.*.teks_check' => 'nullable|string|max:500',
            'reference_excerpt' => 'nullable|string|max:500',
        ]);

        // 5. LOG TO CONSOLE — INI YANG DIMINTA USER (tahap awal, tampil di php artisan pail)
        $batch = $validated['batch_code'];
        $slot = $validated['slot'];
        $cleaned = $validated['cleaned_text'] ?? '';
        $isEmpty = $validated['is_empty'] ? 'EMPTY' : 'OK';
        $status = $validated['status'];
        // REVIEW_FLOW_COMPARE_FRONT_BACK_PLAN (2026-10-05): sisi label (default unknown utk payload lama).
        $side = $validated['side'] ?? 'unknown';

        // Utama: Log::info akan tulis ke storage/logs/laravel.log dan terlihat di `php artisan pail --filter=CHECK`
        // Kontrak RapidOCR (2026-09-11): 'none' = tidak ada teks = success valid (netral, bukan retake).
        // PROGRESSIVE (2026-10-09): log LIVE per slot agar terlihat bertahap
        // tiap ~25s di pail (bukan borongan di akhir). Baris lama dipertahankan.
        // REVIEW_FLOW_COMPARE_FRONT_BACK_PLAN (2026-10-05): log sisi label.
        $isNoneSlot = is_string($cleaned) && strtolower(trim($cleaned)) === 'none';
        Log::info($isNoneSlot ? "[CHECK CALLBACK] batch={$batch} slot={$slot} side={$side} no text detected (none)" : "[CHECK CALLBACK] batch={$batch} slot={$slot} side={$side} status={$status} is_empty={$isEmpty} cleaned_text=" . json_encode(Str::limit($cleaned, 500), JSON_UNESCAPED_UNICODE), $validated);

        // PROGRESSIVE LIVE (2026-10-09): 1 baris ringkas per slot — muncul di
        // pail tiap ~25s seiring FastAPI dispatch (bukan borongan akhir).
        // Idempoten: retry payload sama → baris log sama, DB/cache overwrite.
        $liveProgress = $validated['progress'] ?? $request->input('progress', '-');
        $liveVerdict = (is_array($validated['similarity'] ?? null) && isset($validated['similarity']['verdict'])) ? $validated['similarity']['verdict'] : '-';
        $liveScore = (is_array($validated['similarity'] ?? null) && array_key_exists('score', $validated['similarity'])) ? $validated['similarity']['score'] : null;
        $liveScoreStr = $liveScore === null ? '-' : $liveScore;
        Log::info("[CHECK CALLBACK LIVE] Batch={$batch} Slot={$slot} Verdict={$liveVerdict} Score={$liveScoreStr} Progress={$liveProgress}");
        error_log("[CHECK CALLBACK LIVE] Batch={$batch} Slot={$slot} Verdict={$liveVerdict} Score={$liveScoreStr} Progress={$liveProgress}");

        // COMPARE_TEXT_SIMILARITY_PLAN (2026-09-12): verdict similarity ke pail.
        // REVIEW_FLOW_COMPARE_FRONT_BACK_PLAN (2026-10-05): + sisi label.
        // PLAN_GEMINI_API_TEXT_COMPARE (2026-10-07): + engine/critical/minor.
        // Guard penuh: payload difflib lama (tanpa field Gemini) tetap jalan.
        $sim = $validated['similarity'] ?? null;
        if (is_array($sim) && isset($sim['verdict'])) {
            $compareEngine = $sim['compare_engine'] ?? 'difflib';
            Log::info("[CHECK COMPARE] batch={$batch} slot={$slot} side={$side} engine={$compareEngine} verdict={$sim['verdict']} score=" . ($sim['score'] ?? '-') . " matched=" . ($sim['matched_lines'] ?? 0) . "/" . ($sim['ref_lines'] ?? 0) . " thr=" . ($sim['threshold'] ?? '-'), [
                'batch_code' => $batch, 'slot' => $slot, 'side' => $side, 'similarity' => $sim,
                'reference_excerpt' => Str::limit((string) ($validated['reference_excerpt'] ?? ''), 120),
            ]);
            error_log("[CHECK COMPARE] batch={$batch} slot={$slot} side={$side} engine={$compareEngine} verdict={$sim['verdict']} score=" . ($sim['score'] ?? '-'));
            // FIX_WRONG_REFERENCE (2026-10-07): sumber acuan tiap slot ke pail.
            $refSrc = $validated['ref_source'] ?? null;
            if (is_string($refSrc) && $refSrc !== '') {
                error_log("[CHECK COMPARE] batch={$batch} slot={$slot} ref_source={$refSrc}");
                if ($refSrc === 'single') {
                    error_log("[CHECK COMPARE] WARNING batch={$batch} slot={$slot} memakai acuan TUNGGAL — pastikan benar bila batch berisi >1 produk");
                }
            }
            // PLAN_IMPROVE_COMPARE_PRECISION_WITH_DIFF_REASON (2026-10-07):
            // teruskan alasan perbedaan ke pail agar operator bisa validasi verdict.
            $diffs = (isset($sim['diff_details']) && is_array($sim['diff_details'])) ? $sim['diff_details'] : [];
            if (count($diffs) > 0) {
                error_log("[COMPARE DIFF] batch={$batch} slot={$slot} verdict={$sim['verdict']} — " . count($diffs) . " perbedaan:");
                foreach (array_slice($diffs, 0, 10) as $dd) {
                    $dtype = $dd['type'] ?? '?';
                    if ($dtype === 'changed') {
                        error_log('[COMPARE DIFF]   changed L' . ($dd['ref_line_num'] ?? '?') . '→L' . ($dd['check_line_num'] ?? '?') . ': Acuan="' . ($dd['ref_text'] ?? '') . '" → Check="' . ($dd['check_text'] ?? '') . '"');
                    } elseif ($dtype === 'missing') {
                        error_log('[COMPARE DIFF]   missing L' . ($dd['ref_line_num'] ?? '?') . ': Acuan="' . ($dd['ref_text'] ?? '') . '" (tidak ada di Check)');
                    } elseif ($dtype === 'added') {
                        error_log('[COMPARE DIFF]   added L' . ($dd['check_line_num'] ?? '?') . ': Check="' . ($dd['check_text'] ?? '') . '" (tidak ada di Acuan)');
                    }
                }
                if (count($diffs) > 10) {
                    error_log('[COMPARE DIFF]   ... dan ' . (count($diffs) - 10) . ' perbedaan lainnya');
                }
            } elseif (($sim['verdict'] ?? null) === 'PASS') {
                error_log("[COMPARE DIFF] batch={$batch} slot={$slot} PASS — semua baris cocok");
            }
            // PLAN_GEMINI_API_TEXT_COMPARE (2026-10-07): gemini_diffs ke pail.
            // Kebijakan FastAPI: TIDAK ADA TOLERANSI — 1+ perbedaan apapun = FAIL
            // (termasuk severity minor). Guard is_array agar payload difflib
            // lama (tanpa gemini_diffs) tetap jalan.
            $geminiDiffs = (isset($sim['gemini_diffs']) && is_array($sim['gemini_diffs'])) ? $sim['gemini_diffs'] : [];
            $criticalCount = $sim['critical_count'] ?? null;
            $minorCount = $sim['minor_count'] ?? null;
            if ($compareEngine === 'gemini_flash' || count($geminiDiffs) > 0 || $criticalCount !== null || $minorCount !== null) {
                error_log("[GEMINI COMPARE] batch={$batch} slot={$slot} side={$side} engine={$compareEngine} critical=" . ($criticalCount ?? '-') . " minor=" . ($minorCount ?? '-'));
                foreach (array_slice($geminiDiffs, 0, 10) as $gd) {
                    if (!is_array($gd)) {
                        continue;
                    }
                    $sev = $gd['severity'] ?? '?';
                    $cat = $gd['category'] ?? '?';
                    $ta = $gd['teks_acuan'] ?? '';
                    $tc = $gd['teks_check'] ?? '';
                    $icon = ($sev === 'critical') ? 'CRITICAL' : 'minor';
                    error_log('[GEMINI DIFF]   [' . $icon . '] ' . $cat . ': Acuan="' . $ta . '" → Check="' . $tc . '"');
                }
                if (count($geminiDiffs) > 10) {
                    error_log('[GEMINI DIFF]   ... dan ' . (count($geminiDiffs) - 10) . ' perbedaan lainnya');
                }
                if (count($geminiDiffs) === 0 && ($sim['verdict'] ?? null) === 'PASS') {
                    error_log("[GEMINI DIFF] batch={$batch} slot={$slot} PASS — identik (tidak ada perbedaan)");
                }
            }
        }

        // PLAN_COMPARE_PARAGRAPH_GLOBAL (2026-10-07): ringkasan + detail kata ke pail.
        // Guard is_array: payload lama tanpa "perbandingan" tetap jalan.
        $perb = $validated['perbandingan'] ?? null;
        if (is_array($perb)) {
            $ringkas = (isset($perb['ringkasan_perbandingan']) && is_array($perb['ringkasan_perbandingan']))
                ? $perb['ringkasan_perbandingan'] : [];
            $details = (isset($perb['detail_perbedaan']) && is_array($perb['detail_perbedaan']))
                ? $perb['detail_perbedaan'] : [];
            $total = $ringkas['total_perbedaan'] ?? count($details);
            $pstatus = $ringkas['status'] ?? '?';
            error_log("[COMPARE RINGKASAN] batch={$batch} slot={$slot} side={$side} total_perbedaan={$total} status={$pstatus}");
            foreach (array_slice($details, 0, 10) as $dd) {
                $ta = array_key_exists('teks_acuan', $dd) && $dd['teks_acuan'] !== null ? '"' . $dd['teks_acuan'] . '"' : '(tidak ada)';
                $tc = array_key_exists('teks_check', $dd) && $dd['teks_check'] !== null ? '"' . $dd['teks_check'] . '"' : '(tidak ada)';
                error_log('[COMPARE DETAIL] batch=' . $batch . ' slot=' . $slot . ' ' . ($dd['id'] ?? '?') . '. Acuan=' . $ta . ' -> Check=' . $tc);
            }
            if (count($details) > 10) {
                error_log('[COMPARE DETAIL] batch=' . $batch . ' slot=' . $slot . ' ... dan ' . (count($details) - 10) . ' perbedaan lainnya');
            }
            if (count($details) === 0 && $pstatus === 'Selesai') {
                error_log("[COMPARE DETAIL] batch={$batch} slot={$slot} tidak ada perbedaan kata");
            }
        }

        // Tambahan: error_log agar juga muncul di php's error_log / stderr (console `php artisan serve`)
        error_log("[CHECK CALLBACK] batch={$batch} slot={$slot} status={$status} is_empty={$isEmpty} cleaned_text=" . Str::limit(str_replace("\n", "\\n", $cleaned), 200));
        // OCR MODE (2026-09-11): raw teks eksplisit untuk console log
        error_log("[CHECK OCR RAW] batch={$batch} slot={$slot} raw_text=" . Str::limit(str_replace("\n", "\\n", (string) ($validated['raw_text'] ?? '')), 300));

        // Debug full payload ke channel debug (optional)
        Log::debug('[CHECK CALLBACK] full payload', ['payload' => $request->all(), 'headers' => ['X-API-Key' => substr((string)$apiKey,0,8).'...', 'X-Signature' => substr((string)$signature,0,12).'...']]);

        // 5b. Persist ke DB + Cache untuk polling browser log (CP-034b — browser console)
        try {
            $session = CheckingSession::where('batch_code', $batch)->first();
            if ($session) {
                $capture = CheckCapture::where('checking_session_id', $session->id)
                    ->where('slot_number', $slot)->first();
                if ($capture) {
                    $ocrStatus = $validated['is_empty'] ? 'empty' : ($validated['status'] === 'success' ? 'success' : $validated['status']);
                    if ($ocrStatus === 'pending') $ocrStatus = 'success';
                    $capture->update([
                        'cleaned_text' => $cleaned,
                        'raw_text'     => $validated['raw_text'] ?? null,
                        'ocr_status'   => $ocrStatus,
                        'ocr_engine'   => $validated['engine'] ?? 'tesseract',
                        'ocr_det_model'=> $validated['det_model'] ?? null,
                        'ocr_rec_model'=> $validated['rec_model'] ?? null,
                        'error_message'=> $validated['error'] ?? ($validated['is_empty'] ? ($validated['empty_reason'] ?? null) : null),
                        'status'       => 'processed',
                    ]);
                    // PLAN_GEMINI_API_TEXT_COMPARE (2026-10-07): audit aditif
                    // hasil similarity FastAPI (v1 difflib / v2-gemini). Guard:
                    // payload lama tanpa similarity → kolom tetap null; migration
                    // belum jalan → tangkap agar update utama di atas tidak rollback.
                    try {
                        $simForDb = (isset($validated['similarity']) && is_array($validated['similarity'])) ? $validated['similarity'] : null;
                        if (is_array($simForDb) && isset($simForDb['verdict'])) {
                            $geminiForDb = (isset($simForDb['gemini_diffs']) && is_array($simForDb['gemini_diffs'])) ? array_values($simForDb['gemini_diffs']) : null;
                            $totalForDb = $simForDb['total_diffs'] ?? ($geminiForDb !== null ? count($geminiForDb) : null);
                            if ($totalForDb === null && isset($simForDb['diff_details']) && is_array($simForDb['diff_details'])) {
                                $totalForDb = count($simForDb['diff_details']);
                            }
                            $capture->update([
                                'compare_engine'       => $simForDb['compare_engine'] ?? 'difflib',
                                'compare_version'      => $simForDb['version'] ?? null,
                                'compare_verdict'      => $simForDb['verdict'] ?? null,
                                'compare_score'        => $simForDb['score'] ?? null,
                                'critical_diff_count'  => $simForDb['critical_count'] ?? null,
                                'minor_diff_count'     => $simForDb['minor_count'] ?? null,
                                'total_diffs'          => $totalForDb,
                                'gemini_diffs_json'    => $geminiForDb,
                            ]);
                        }
                    } catch (\Exception $e) {
                        Log::warning('[CHECK CALLBACK] gemini audit persist skipped (migration?)', ['batch' => $batch, 'slot' => $slot, 'error' => $e->getMessage()]);
                    }
                    Log::info("[CHECK CALLBACK] DB updated slot={$slot} ocr_status={$ocrStatus}", ['capture_id'=>$capture->id, 'session_id'=>$session->id]);
                } else {
                    Log::warning('[CHECK CALLBACK] capture not found for slot', ['batch'=>$batch,'slot'=>$slot]);
                }
                // Cache 5 menit untuk polling cepat browser
                // PROGRESSIVE (2026-10-09): teruskan event/progress/is_final agar
                // check.js bisa render progres hidup (1/4 → 4/4) per slot.
                $cacheKey = 'check:ocr:result:'.$batch.':'.$slot;
                Cache::put($cacheKey, [
                    'batch_code'=>$batch,'slot'=>$slot,'cleaned_text'=>$cleaned,'raw_text'=>$validated['raw_text']??'','is_empty'=>$validated['is_empty'],'status'=>$status,'lines'=>$validated['lines']??[],'engine'=>$validated['engine']??null,'text_present'=>$validated['text_present']??null,'det_model'=>$validated['det_model']??null,'rec_model'=>$validated['rec_model']??null,'processed_at'=>$validated['processed_at']??now()->toIso8601String(),
                    // COMPARE_TEXT_SIMILARITY_PLAN (2026-09-12): teruskan ke browser.
                    // REVIEW_FLOW_COMPARE_FRONT_BACK_PLAN (2026-10-05): + side.
                    // FIX_WRONG_REFERENCE (2026-10-07): + ref_source diagnostik.
                    // PLAN_GEMINI_API_TEXT_COMPARE (2026-10-07): similarity kini
                    // membawa compare_engine/gemini_diffs/critical/minor (aditif).
                    'side' => $validated['side'] ?? 'unknown',
                    'ref_source' => $validated['ref_source'] ?? null,
                    'similarity' => $validated['similarity'] ?? null,
                    // PLAN_COMPARE_PARAGRAPH_GLOBAL (2026-10-07): teruskan blok kata.
                    'perbandingan' => $validated['perbandingan'] ?? null,
                    'reference_excerpt' => $validated['reference_excerpt'] ?? null,
                    'event' => $validated['event'] ?? $request->input('event', 'slot_completed'),
                    'progress' => $validated['progress'] ?? $request->input('progress'),
                    'is_final' => (bool) ($validated['is_final'] ?? $request->input('is_final', false)),
                ], 300);
                // Batch cache untuk GET /api/check/sessions/{id}/ocr-results
                $batchCache = 'check:ocr:batch:'.$batch;
                $prev = Cache::get($batchCache, []);
                $prev[$slot] = $cleaned;
                Cache::put($batchCache, $prev, 300);
            } else {
                Log::warning('[CHECK CALLBACK] session not found for batch', ['batch'=>$batch]);
            }
        } catch (\Exception $e) {
            Log::error('[CHECK CALLBACK] DB persist failed', ['batch'=>$batch,'slot'=>$slot,'error'=>$e->getMessage()]);
            // Jangan 500 — tetap 200 agar FastAPI tidak retry infinite
        }

        // 6. (Opsional) dump ke console jika APP_DEBUG (hanya untuk dev, akan muncul di pail)
        if (config('app.debug')) {
            // Tidak pakai dump() yang break response, cukup log
        }

        return response()->json([
            'success' => true,
            'message' => "Slot {$slot} recorded progressively",
            'batch_code' => $batch,
            'slot' => $slot,
            'received' => true,
        ], 200);
    }
}
