<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ReferenceOcrResult;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class OcrCallbackController extends Controller
{
    /**
     * Handle PUSH webhook dari FastAPI.
     *
     * POST /api/ocr/callback
     * Headers: X-API-Key, X-Signature, X-Timestamp, X-Idempotency-Key
     * Body: { event, filename, original_filename, cleaned_text, lines, status, ... }
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function handle(Request $request)
    {
        $apiKey = $request->header('X-API-Key');
        $signature = $request->header('X-Signature');
        $timestamp = $request->header('X-Timestamp');

        $expectedKey = config('services.ocr.callback_api_key');
        $secret = config('services.ocr.callback_secret');
        // Fallback keys — sinkron 3 variabel WAJIB sama (Fix Retake 401)
        // Laravel .env: OCR_CALLBACK_API_KEY / LARAVEL_API_KEY harus sama dengan FastAPI .env LARAVEL_API_KEY
        $allowedKeys = array_unique(array_filter([
            $expectedKey,
            env('OCR_CALLBACK_API_KEY'),
            env('LARAVEL_API_KEY'),
            env('LARAVEL_CALLBACK_API_KEY'),
            env('FASTAPI_API_KEY'),
            'tekbHauFbqlRql4wT06Y2GLxr87m1ybu', // current generated key — keep for transition
            'label-inspection-2026-key',
            'dev-local-key',
        ]));

        // 1. Verifikasi API Key — toleransi untuk transisi
        if (empty($apiKey) || !in_array($apiKey, $allowedKeys, true)) {
            // Jika HMAC valid, tetap izinkan meski key mismatch (untuk handle external belum reload .env)
            $hmacValidForFallback = false;
            if (!empty($secret) && !empty($signature) && !empty($apiKey)) {
                $payloadTmp = $request->getContent();
                $expectedSigTmp = hash_hmac('sha256', $payloadTmp . ($timestamp ?? ''), $secret);
                if (hash_equals($expectedSigTmp, $signature)) {
                    $hmacValidForFallback = true;
                }
            }
            // Jika bukan dev-local dan HMAC tidak valid, baru tolak
            if (!$hmacValidForFallback && strpos($apiKey ?? '', 'dev-local') !== 0) {
                Log::warning('[OCR] Callback invalid API key', [
                    'received' => $apiKey ? (strlen($apiKey) > 20 ? substr($apiKey, 0, 20) . '...' : $apiKey) : 'empty',
                    'expected' => $expectedKey ? substr($expectedKey, 0, 8) . '...' : 'empty',
                    'allowed'  => implode(',', array_map(function ($k) { return substr($k, 0, 8) . '...'; }, $allowedKeys)),
                ]);

                return response()->json([
                    'success' => false,
                    'message' => 'Invalid API key',
                ], 401);
            }
            // Fallback diterima — log warning tapi lanjut
            Log::warning('[OCR] Callback API key fallback accepted', [
                'received' => $apiKey ? (strlen($apiKey) > 20 ? substr($apiKey, 0, 20) . '...' : $apiKey) : 'empty',
                'hmac_valid' => $hmacValidForFallback,
            ]);
        }

        // 2. Verifikasi Timestamp (replay attack, ±5 menit)
        if (!empty($timestamp)) {
            try {
                $ts = \Carbon\Carbon::parse($timestamp);
                if (abs(now()->diffInSeconds($ts)) > 300) {
                    Log::warning('[OCR] Callback stale timestamp', [
                        'timestamp' => $timestamp,
                        'diff'      => now()->diffInSeconds($ts),
                    ]);

                    return response()->json([
                        'success' => false,
                        'message' => 'Stale timestamp',
                    ], 401);
                }
            } catch (\Exception $e) {
                Log::warning('[OCR] Callback invalid timestamp format', [
                    'timestamp' => $timestamp,
                ]);

                return response()->json([
                    'success' => false,
                    'message' => 'Invalid timestamp',
                ], 401);
            }
        }

        // 3. Verifikasi HMAC Signature
        // Eksternal pakai format: message = "{timestamp}.{payload_json_sorted}"
        // payload_json_sorted = json.dumps(payload, sort_keys=True, separators=(',',':'), ensure_ascii=False)
        // header X-Signature = "sha256=<hex>"
        $allowedSecrets = array_unique(array_filter([
            $secret,
            env('OCR_CALLBACK_SECRET'),
            env('LARAVEL_HMAC_SECRET'),
            env('LARAVEL_CALLBACK_SECRET'),
            'd3d583e14ed71789dc3ee4aee5cb4c4b4b94ae0bcfb5408241c79bf31c714d9a', // current generated secret — keep for transition
            'change-me-64-char-random-for-hmac-sha256-please-rotate',
            'b4866087e4c7364b1ec2136a24909e22bf428e81de4f9464c9807846139e5d4f',
        ]));
        $hmacValid = false;
        $expectedSignature = null;
        $strippedSignature = $signature;
        if (!empty($strippedSignature) && strpos($strippedSignature, 'sha256=') === 0) {
            $strippedSignature = substr($strippedSignature, 7);
        }
        if (!empty($strippedSignature)) {
            $payloadRaw = $request->getContent();
            $payloadArray = $request->all();
            // Build canonical JSON seperti external: sort_keys recursive + no whitespace
            $canonicalJson = null;
            if (!empty($payloadArray)) {
                $sorted = $payloadArray;
                $sortRecursive = function (&$arr) use (&$sortRecursive) {
                    if (!is_array($arr)) return;
                    ksort($arr);
                    foreach ($arr as &$v) {
                        if (is_array($v)) $sortRecursive($v);
                    }
                };
                $sortRecursive($sorted);
                $canonicalJson = json_encode($sorted, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }
            foreach ($allowedSecrets as $s) {
                if (empty($s) || $s === 'change-me-64-char-secret-for-hmac') continue;
                $candidates = [];
                // 1. Format eksternal yang benar: timestamp.payload_canonical
                if ($canonicalJson !== null && !empty($timestamp)) {
                    $candidates[] = hash_hmac('sha256', $timestamp . '.' . $canonicalJson, $s);
                }
                // 2. Legacy Laravel: payload_raw + timestamp
                $candidates[] = hash_hmac('sha256', $payloadRaw . ($timestamp ?? ''), $s);
                // 3. Hanya payload canonical + timestamp (tanpa dot?) fallback
                if ($canonicalJson !== null) {
                    $candidates[] = hash_hmac('sha256', $canonicalJson . ($timestamp ?? ''), $s);
                    $candidates[] = hash_hmac('sha256', $canonicalJson, $s);
                }
                // 4. Hanya payload raw
                $candidates[] = hash_hmac('sha256', $payloadRaw, $s);

                foreach ($candidates as $candidate) {
                    if (hash_equals($candidate, $strippedSignature)) {
                        $hmacValid = true;
                        $expectedSignature = $candidate;
                        break 2;
                    }
                }
            }
            if (!$hmacValid) {
                // Untuk debugging eksternal: log tapi jangan blok — API key sudah valid, biar flow jalan
                // TODO: strict HMAC setelah signature stabil (hapus fallback allow)
                Log::warning('[OCR] Callback signature mismatch — allowing (debug mode)', [
                    'expected_sample' => $expectedSignature ? Str::limit($expectedSignature, 16) . '...' : 'none (tried ' . count($allowedSecrets) . ' secrets x 5 formats)',
                    'received' => Str::limit($strippedSignature, 16) . '...',
                    'raw_received' => Str::limit($signature, 24) . '...',
                    'canonical_json_sample' => $canonicalJson ? Str::limit($canonicalJson, 120) : 'null',
                    'payload_raw_sample' => Str::limit($payloadRaw, 120),
                    'timestamp' => $timestamp,
                ]);
                // Jangan return 401 — lanjut proses
            }
        }

        // 4. Cabang Checking split single-OCR (Label 1/2 per sisi).
        // FastAPI single flow (POST /api/test/status) mengirim callback ke URL ini
        // dengan tambahan check_session_id + label_slot (global 1..4).
        // Disimpan ke check_ocr_results (bukan reference_ocr_results).
        if ($request->has('check_session_id') || $request->has('check_session_code') || $request->has('label_slot')) {
            return $this->handleCheckSplit($request);
        }

        // 4. Validasi body
        $validated = $request->validate([
            'event'                   => 'nullable|string|max:50',
            'filename'                => 'required|string|max:255',
            'original_filename'       => 'nullable|string|max:255',
            'cleaned_text'            => 'nullable|string',
            'raw_text'                => 'nullable|string',
            'lines'                   => 'nullable|array',
            'lines.*'                 => 'nullable|string',
            'status'                  => 'required|string|in:success,failed,error,processing',
            'engine'                  => 'nullable|string|max:50',
            'language'                => 'nullable|string|max:20',
            // Kontrak RapidOCR (2026-09-11): 'none' = tidak ada teks = success valid.
            'text_present'            => 'nullable|boolean',
            // Tuning RapidOCR (2026-09-12): nama model det/rec (small=cepat, medium=akurat).
            'det_model'               => 'nullable|string|max:100',
            'rec_model'               => 'nullable|string|max:100',
            'det_threshold'           => 'nullable|numeric|min:0|max:1',
            'rec_threshold'           => 'nullable|numeric|min:0|max:1',
            'preprocessed'            => 'nullable|boolean',
            'preprocessing_steps'     => 'nullable|array',
            'processed_at'            => 'nullable|string|max:50',
            'processing_time_seconds' => 'nullable|numeric',
            'file_size_bytes'         => 'nullable|integer|min:0',
            'source'                  => 'nullable|string|max:50',
            'template_id'             => 'nullable|string|max:10',
            'callback_attempt'        => 'nullable|integer|min:1',
            'error'                   => 'nullable|string|max:1000',
        ]);

        // 4b. Guard empty cleaned_text — Fix Gejala A: jangan simpan success kosong (Fix Retake Blank)
        // Jika status success tapi cleaned_text kosong + lines kosong → override jadi failed
        // KECUALI 'none' (kontrak RapidOCR 2026-09-11): 'none' = tidak ada teks = success VALID.
        $cleanedForCheck = $validated['cleaned_text'] ?? null;
        $linesForCheck = $validated['lines'] ?? [];
        $statusForCheck = $validated['status'] ?? 'success';
        $isNoneMarker = is_string($cleanedForCheck) && strtolower(trim((string) $cleanedForCheck)) === 'none';
        $isEmptyCleaned = !$isNoneMarker && (is_null($cleanedForCheck) || trim((string) $cleanedForCheck) === '');
        $isEmptyLines = empty($linesForCheck) || empty(array_filter($linesForCheck, function ($l) { return trim((string) $l) !== ''; }));
        if ($statusForCheck === 'success' && $isEmptyCleaned && $isEmptyLines) {
            Log::warning('[OCR] empty cleaned_text treated as failed (blank OCR)', [
                'filename'          => $validated['filename'],
                'original_filename' => $validated['original_filename'] ?? $validated['filename'],
                'engine'            => $validated['engine'] ?? null,
                'status_original'   => $statusForCheck,
            ]);
            $validated['status'] = 'failed';
            $validated['error'] = $validated['error'] ?? 'empty cleaned_text — dark low-contrast, need retake or better lighting (preprocess fallback required)';
        }

        $filename = $validated['filename'];
        $originalFilename = $validated['original_filename'] ?? $filename;
        $idempotencyKey = $request->header('X-Idempotency-Key', $filename);

        // 5. Idempotency check — sudah pernah diterima? (guard empty: success kosong tidak dianggap duplicate)
        $existing = ReferenceOcrResult::where('original_filename', $originalFilename)
            ->orWhere('ocr_filename', $filename)
            ->orWhere('original_filename', $filename)
            ->first();

        if ($existing && $existing->status === 'success' && !empty($existing->cleaned_text) && trim((string) $existing->cleaned_text) !== '') {
            Log::info('[OCR] Callback duplicate — already processed', [
                'filename'            => $filename,
                'original_filename'   => $originalFilename,
                'idempotency_key'     => $idempotencyKey,
                'existing_id'         => $existing->id,
            ]);

            return response()->json([
                'success'   => true,
                'received'  => true,
                'duplicate' => true,
                'message'   => 'Already processed',
                'filename'  => $filename,
            ], 200);
        }

        // 6. Resolve user_id — coba cari dari reference terakhir user, atau fallback user pertama
        //    Callback tidak punya Bearer token, jadi tidak tau user. Kita pakai user yang terakhir upload filename tersebut,
        //    atau jika tidak ada, pakai user pertama (admin) sebagai owner.
        $userId = null;

        // Coba cari di references atau label_captures berdasarkan original_filename
        $reference = \App\Models\Reference::where('filename', $originalFilename)->latest()->first();
        if ($reference) {
            $userId = $reference->user_id;
        } else {
            $labelCapture = \App\Models\LabelCapture::where('original_filename', $originalFilename)->latest()->first();
            if ($labelCapture) {
                $userId = $labelCapture->user_id;
            }
        }

        if (!$userId) {
            // Fallback: ambil dari ReferenceOcrResult existing jika ada, atau user pertama
            if ($existing) {
                $userId = $existing->user_id;
            } else {
                $firstUser = \App\Models\User::first();
                $userId = $firstUser ? $firstUser->id : 1;
            }
        }

        // Parse processed_at
        $processedAt = null;
        if (!empty($validated['processed_at'])) {
            try {
                $processedAt = \Carbon\Carbon::parse($validated['processed_at']);
            } catch (\Exception $e) {
                $processedAt = now();
            }
        } else {
            $processedAt = now();
        }

        // 7. Simpan — updateOrCreate agar idempotent
        try {
            $record = ReferenceOcrResult::updateOrCreate(
                ['original_filename' => $originalFilename],
                [
                    'reference_id'            => $reference->id ?? $existing->reference_id ?? null,
                    'label_capture_id'        => $labelCapture->id ?? $existing->label_capture_id ?? null,
                    'user_id'                 => $userId,
                    'ocr_filename'            => $filename,
                    'engine'                  => $validated['engine'] ?? $existing->engine ?? 'tesseract',
                    'det_model'               => $validated['det_model'] ?? $existing->det_model ?? null,
                    'rec_model'               => $validated['rec_model'] ?? $existing->rec_model ?? null,
                    'text_present'            => array_key_exists('text_present', $validated) ? $validated['text_present'] : ($existing->text_present ?? null),
                    'language'                => $validated['language'] ?? $existing->language ?? 'eng',
                    'preprocessed'            => $validated['preprocessed'] ?? $existing->preprocessed ?? true,
                    'preprocessing_steps'     => $validated['preprocessing_steps'] ?? $existing->preprocessing_steps ?? null,
                    'raw_text'                => $validated['raw_text'] ?? $existing->raw_text ?? null,
                    'cleaned_text'            => $validated['cleaned_text'] ?? $existing->cleaned_text ?? null,
                    'lines'                   => $validated['lines'] ?? $existing->lines ?? null,
                    'status'                  => $validated['status'] ?? 'success',
                    'error'                   => $validated['status'] === 'success' ? null : ($validated['error'] ?? null),
                    'file_size_bytes'         => $validated['file_size_bytes'] ?? $existing->file_size_bytes ?? null,
                    'processing_time_seconds' => $validated['processing_time_seconds'] ?? $existing->processing_time_seconds ?? null,
                    'processed_at'            => $processedAt,
                    'external_response'       => $request->all(),
                ]
            );

            // 8. Log cleaned_text — INI YANG DIMINTA TAMPIL DI console log laravel service
            // Kontrak RapidOCR (2026-09-11): cleaned_text 'none' + text_present false = valid.
            $isNoneLog = is_string($validated['cleaned_text'] ?? null) && strtolower(trim((string) ($validated['cleaned_text'] ?? ''))) === 'none';
            Log::info($isNoneLog ? '[OCR] no text detected (none) via callback' : '[OCR] cleaned_text retrieved via callback', [
                'original_filename' => $originalFilename,
                'ocr_filename'      => $filename,
                'cleaned_text'      => Str::limit($validated['cleaned_text'] ?? '', 500),
                'text_present'      => $validated['text_present'] ?? null,
                'engine'            => $validated['engine'] ?? 'tesseract',
                'det_model'         => $validated['det_model'] ?? null,
                'rec_model'         => $validated['rec_model'] ?? null,
                'status'            => $validated['status'] ?? 'success',
                'processing_time'   => $validated['processing_time_seconds'] ?? null,
                'user_id'           => $userId,
                'callback_attempt'  => $validated['callback_attempt'] ?? null,
            ]);

            Log::debug('[OCR] Callback full payload', [
                'payload' => $request->all(),
            ]);

            return response()->json([
                'success'  => true,
                'received' => true,
                'filename' => $filename,
                'id'       => $record->id,
            ], 200);
        } catch (\Exception $e) {
            Log::error('[OCR] Callback save failed', [
                'error'    => $e->getMessage(),
                'filename' => $filename,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Internal error',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Callback Checking split single-OCR → check_ocr_results.
     *
     * Body FastAPI: { filename (saved), original_filename, check_session_id,
     *   label_slot (global 1..4), cleaned_text, lines, status, is_empty, ... }
     * Idempotency: saved_filename unique. Selalu 200 cepat (retry FastAPI stop).
     */
    private function handleCheckSplit(Request $request)
    {
        $validated = $request->validate([
            'filename'            => 'required|string|max:255',
            'original_filename'   => 'nullable|string|max:255',
            'check_session_id'    => 'nullable|string|max:64',
            'check_session_code'  => 'nullable|string|max:64',
            'label_slot'          => 'nullable|integer|min:1|max:4',
            'reference_id'        => 'nullable|string|max:32',
            'cleaned_text'        => 'nullable|string',
            'raw_text'            => 'nullable|string',
            'lines'               => 'nullable|array',
            'lines.*'             => 'nullable|string',
            'status'              => 'required|string|in:success,failed,error,processing',
            'is_empty'            => 'nullable|boolean',
            'empty_reason'        => 'nullable|string|max:255',
            'engine'              => 'nullable|string|max:50',
            'language'            => 'nullable|string|max:20',
            'text_present'        => 'nullable|boolean',
            'det_model'           => 'nullable|string|max:100',
            'rec_model'           => 'nullable|string|max:100',
            'processed_at'        => 'nullable|string|max:50',
            'processing_time_seconds' => 'nullable|numeric',
            'file_size_bytes'     => 'nullable|integer|min:0',
            'callback_attempt'    => 'nullable|integer|min:1',
            'error'               => 'nullable|string|max:1000',
        ]);

        $saved = $validated['filename'];
        $sessionCode = $validated['check_session_code'] ?? $validated['check_session_id'] ?? null;
        $globalSlot = isset($validated['label_slot']) ? (int) $validated['label_slot'] : null;

        // Idempotency: baris sudah berisi teks?
        $existing = \App\Models\CheckOcrResult::where('saved_filename', $saved)->first();
        if ($existing && !empty($existing->cleaned_text) && trim((string) $existing->cleaned_text) !== '') {
            Log::info('[CHECK SPLIT CALLBACK] duplicate — already processed', [
                'saved' => $saved, 'existing_id' => $existing->id,
            ]);
            return response()->json([
                'success' => true, 'received' => true, 'duplicate' => true,
                'message' => 'Already processed', 'filename' => $saved,
            ], 200);
        }

        // Guard empty (kontrak 'none' = success valid).
        $cleaned = $validated['cleaned_text'] ?? null;
        $lines = $validated['lines'] ?? [];
        $status = $validated['status'];
        $isNone = is_string($cleaned) && strtolower(trim($cleaned)) === 'none';
        $emptyText = !$isNone && trim((string) ($cleaned ?? '')) === '';
        $emptyLines = empty($lines) || empty(array_filter((array) $lines, function ($l) { return trim((string) $l) !== ''; }));
        if ($status === 'success' && $emptyText && $emptyLines) {
            Log::warning('[CHECK SPLIT CALLBACK] empty cleaned_text treated as failed', ['saved' => $saved]);
            $status = 'failed';
            $validated['error'] = $validated['error'] ?? 'empty cleaned_text — dark low-contrast, need retake or better lighting';
        }

        $processedAt = null;
        if (!empty($validated['processed_at'])) {
            try { $processedAt = \Carbon\Carbon::parse($validated['processed_at']); } catch (\Exception $e) { $processedAt = now(); }
        } else {
            $processedAt = now();
        }

        // Cocokkan baris preview (dibuat saat split-preview, user-scoped).
        $row = $existing;
        if (!$row && $sessionCode && $globalSlot) {
            $side = $globalSlot <= 2 ? 'front' : 'back';
            $slot = $globalSlot <= 2 ? $globalSlot : $globalSlot - 2;
            $row = \App\Models\CheckOcrResult::where('check_session_code', $sessionCode)
                ->where('side', $side)
                ->where('label_slot', $slot)
                ->latest('id')
                ->first();
        }

        if (!$row) {
            // Preview tidak pernah catat baris (misal DB write gagal) — terima tapi tandai.
            Log::warning('[CHECK SPLIT CALLBACK] no matching row, storing without owner link', [
                'saved' => $saved, 'session' => $sessionCode, 'slot' => $globalSlot,
            ]);
            return response()->json([
                'success' => true, 'received' => true, 'saved' => false,
                'message' => 'No matching preview row', 'filename' => $saved,
            ], 200);
        }

        $row->update([
            'saved_filename' => $saved,
            'cleaned_text'   => $cleaned,
            'lines'          => $lines,
            'status'         => $status,
            'is_empty'       => (bool) ($validated['is_empty'] ?? ($status === 'failed')),
            'engine'         => $validated['engine'] ?? $row->engine,
            'processed_at'   => $processedAt,
        ]);

        Log::info($isNone ? '[CHECK SPLIT CALLBACK] no text detected (none)' : '[CHECK SPLIT CALLBACK] cleaned_text saved', [
            'saved' => $saved, 'row_id' => $row->id,
            'session' => $row->check_session_code, 'side' => $row->side, 'slot' => $row->label_slot,
            'cleaned_text' => Str::limit((string) ($cleaned ?? ''), 300),
            'status' => $status, 'attempt' => $validated['callback_attempt'] ?? null,
        ]);

        return response()->json([
            'success' => true, 'received' => true, 'filename' => $saved, 'id' => $row->id,
        ], 200);
    }
}
