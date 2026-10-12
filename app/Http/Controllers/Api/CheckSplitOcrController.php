<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CheckOcrResult;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Split OCR per Label — Checking 2-capture (Label 1 & Label 2 per sisi).
 *
 * PLAN_CHECKING_LABEL_2_CAPTURE_OCR_CALLBACK §5.4:
 * reuse alur Reference single (POST /api/test/status 1x per label) + hybrid
 * PUSH callback + PULL polling. 1 sesi dual = maks 4 baris (front1/2, back1/2).
 * Retake 1 sisi = UPDATE baris yang sama (tidak hapus sisi lain).
 */
class CheckSplitOcrController extends Controller
{
    /**
     * Forward 1 crop split ke FastAPI single OCR.
     *
     * POST /api/check/split-preview (multipart)
     * Fields: image, check_session_code, side (front|back), label_slot (1|2),
     *   template_id (S|M|L|XL|TL), template_name, template_width, template_height,
     *   filename, resolution_w, resolution_h, filesize
     */
    public function preview(Request $request)
    {
        $validated = $request->validate([
            'image'               => 'required|image|mimes:png,jpg,jpeg|max:5120',
            'check_session_code'  => 'required|string|max:64|regex:/^CHK-[A-Za-z0-9\-_]+$/',
            'side'                => 'required|string|in:front,back',
            'label_slot'          => 'required|integer|min:1|max:2',
            'template_id'         => 'required|string|in:S,M,L,XL,TL',
            'template_name'       => 'required|string|max:50',
            'template_width'      => 'required|integer|min:1',
            'template_height'     => 'required|integer|min:1',
            'filename'            => ['required', 'string', 'max:255', 'regex:/^[A-Za-z0-9\-_\.]+\.png$/i'],
            'resolution_w'        => 'required|integer|min:1',
            'resolution_h'        => 'required|integer|min:1',
            'filesize'            => 'required|integer|min:1',
        ]);

        $side = $validated['side'];
        $slot = (int) $validated['label_slot'];
        // Slot global 1..4 untuk korelasi FastAPI (front1=1, front2=2, back1=3, back2=4).
        $globalSlot = $side === 'back' ? $slot + 2 : $slot;
        $sessionCode = $validated['check_session_code'];

        $externalUrl = config('services.test_signal.base_url');
        $timeout = max(15, (int) config('services.test_signal.preview_timeout', 15));

        Log::info('[CHECK SPLIT] forward split OCR', [
            'session'  => $sessionCode,
            'side'     => $side,
            'slot'     => $slot,
            'filename' => $validated['filename'],
            'user_id'  => $request->user()->id,
        ]);

        $client = new Client([
            'timeout'         => $timeout,
            'connect_timeout' => 5,
            'http_errors'     => false,
        ]);

        try {
            $file = $request->file('image');
            // Optimasi R2/L1 (2026-10-06): downscale sebelum forward (PNG-preserving).
            $forwardBytes = file_get_contents($file->getRealPath());
            try {
                $prepped = \App\Services\OcrImagePrep::prepare($forwardBytes, $validated['filename']);
                $forwardBytes = $prepped['bytes'];
            } catch (\Exception $e) {
                \Illuminate\Support\Facades\Log::debug('[CHECK SPLIT] image prep skip', ['error' => $e->getMessage()]);
            }
            $response = $client->post($externalUrl, [
                'headers' => ['Accept' => 'application/json'],
                'multipart' => [
                    ['name' => 'image', 'contents' => $forwardBytes, 'filename' => $validated['filename']],
                    ['name' => 'source', 'contents' => 'checking-label'],
                    ['name' => 'timestamp', 'contents' => now()->toIso8601String()],
                    ['name' => 'template_id', 'contents' => $validated['template_id']],
                    ['name' => 'template_name', 'contents' => $validated['template_name']],
                    ['name' => 'template_width', 'contents' => (string) $validated['template_width']],
                    ['name' => 'template_height', 'contents' => (string) $validated['template_height']],
                    ['name' => 'filename', 'contents' => $validated['filename']],
                    ['name' => 'resolution_w', 'contents' => (string) $validated['resolution_w']],
                    ['name' => 'resolution_h', 'contents' => (string) $validated['resolution_h']],
                    ['name' => 'filesize', 'contents' => (string) $validated['filesize']],
                    // Korelasi anti-tertukar (FastAPI simpan ke metadata + ikut di callback).
                    ['name' => 'check_session_id', 'contents' => $sessionCode],
                    ['name' => 'label_slot', 'contents' => (string) $globalSlot],
                ],
            ]);

            $statusCode = $response->getStatusCode();
            $body = json_decode($response->getBody()->getContents(), true);

            // 429 burst FastAPI (5/menit): teruskan agar frontend countdown, jangan simpan baris sukses.
            if ($statusCode === 429) {
                $retryAfter = (int) ($body['retry_after_seconds'] ?? 60);
                Log::warning('[CHECK SPLIT] FastAPI rate limited', [
                    'session' => $sessionCode, 'side' => $side, 'slot' => $slot, 'retry_after' => $retryAfter,
                ]);
                return response()->json([
                    'success'             => false,
                    'status'              => 'rate_limited',
                    'message'             => "Terlalu cepat — FastAPI batasi 5 gambar/menit. Tunggu {$retryAfter} detik lalu coba lagi.",
                    'retry_after_seconds' => $retryAfter,
                    'data'                => ['filename' => $validated['filename'], 'side' => $side, 'label_slot' => $slot],
                ], 429);
            }

            // R6 (2026-10-06): antrean OCR penuh → 503 + countdown seperti 429.
            if ($statusCode === 503) {
                $retryAfter = (int) ($body['retry_after_seconds'] ?? $response->getHeaderLine('Retry-After') ?: 3);
                Log::warning('[CHECK SPLIT] FastAPI OCR queue full', [
                    'session' => $sessionCode, 'side' => $side, 'slot' => $slot, 'retry_after' => $retryAfter,
                ]);
                return response()->json([
                    'success'             => false,
                    'status'              => 'queue_full',
                    'message'             => "Antrean OCR penuh — tunggu {$retryAfter} detik lalu coba lagi.",
                    'retry_after_seconds' => $retryAfter,
                    'data'                => ['filename' => $validated['filename'], 'side' => $side, 'label_slot' => $slot],
                ], 503);
            }

            if ($statusCode < 200 || $statusCode >= 300 || empty($body['success'])) {
                Log::warning('[CHECK SPLIT] FastAPI forward non-2xx', [
                    'status_code' => $statusCode, 'response' => $body, 'filename' => $validated['filename'],
                ]);
                return response()->json([
                    'success' => false,
                    'message' => 'External service mengembalikan error',
                    'external_status' => $statusCode,
                    'external_response' => $body,
                ], 502);
            }

            // Simpan korelasi (upsert — retake sisi ini menimpa baris yang sama).
            $row = CheckOcrResult::updateOrCreate(
                [
                    'user_id'            => $request->user()->id,
                    'check_session_code' => $sessionCode,
                    'side'               => $side,
                    'label_slot'         => $slot,
                ],
                [
                    'client_filename' => $validated['filename'],
                    'saved_filename'  => $body['filename'] ?? null,
                    'request_id'      => $body['request_id'] ?? null,
                    'cleaned_text'    => null,
                    'lines'           => null,
                    'status'          => 'pending',
                    'is_empty'        => false,
                    'engine'          => null,
                    'processed_at'    => null,
                ]
            );

            Log::info('[CHECK SPLIT] queued', [
                'id' => $row->id, 'saved' => $body['filename'] ?? null,
                'poll_url' => '/api/check/split-ocr-status?filename=' . ($body['filename'] ?? ''),
            ]);

            return response()->json([
                'success' => true,
                'status'  => 'processing',
                'message' => 'Split dikirim ke OCR — polling menunggu callback',
                'data'    => [
                    'id'                 => $row->id,
                    'check_session_code' => $sessionCode,
                    'side'               => $side,
                    'label_slot'         => $slot,
                    'global_slot'        => $globalSlot,
                    'client_filename'    => $validated['filename'],
                    'saved_filename'     => $body['filename'] ?? null,
                    'request_id'         => $body['request_id'] ?? null,
                    'poll_url'           => '/api/check/split-ocr-status?filename=' . ($body['filename'] ?? $validated['filename']),
                ],
            ], 200);
        } catch (ConnectException $e) {
            Log::error('[CHECK SPLIT] connect failed', ['error' => $e->getMessage(), 'filename' => $validated['filename'] ?? null]);
            return response()->json(['success' => false, 'message' => 'Gagal terhubung ke external service', 'error' => $e->getMessage()], 502);
        } catch (RequestException $e) {
            Log::error('[CHECK SPLIT] http error', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => 'External service mengembalikan error', 'error' => $e->getMessage()], 502);
        }
    }

    /**
     * Polling 1 URL spesifik — DB dulu (cepat), fallback proxy FastAPI.
     *
     * GET /api/check/split-ocr-status?filename={saved_filename}
     */
    public function status(Request $request)
    {
        // R4 (2026-10-06): long-poll opsional, teruskan ke FastAPI ?wait=.
        $validated = $request->validate(['filename' => 'required|string|max:255', 'wait' => 'sometimes|numeric|min:0|max:10']);
        $filename = basename($validated['filename']);
        $wait = isset($validated['wait']) ? min(10, max(0, (float) $validated['wait'])) : (float) config('services.test_signal.ocr_longpoll_wait', 8);

        if (Str::contains($filename, '..')) {
            return response()->json(['success' => false, 'message' => 'Invalid filename'], 422);
        }

        // 1. DB dulu — callback mungkin sudah simpan cleaned_text (tanpa hit FastAPI).
        $row = CheckOcrResult::where('user_id', $request->user()->id)
            ->where(function ($q) use ($filename) {
                $q->where('saved_filename', $filename)->orWhere('client_filename', $filename);
            })
            ->latest('id')
            ->first();

        if ($row && $this->rowHasText($row)) {
            return response()->json([
                'success' => true,
                'source'  => 'database',
                'data'    => $this->rowToData($row),
            ], 200);
        }

        // 2. Proxy FastAPI GET /api/test/ocr/{file} — 1 URL canonical.
        // R4 (2026-10-06): tambah ?wait= long-poll; timeout = wait+5s.
        $base = config('services.test_signal.base_url');
        $ocrBase = preg_replace('#/status$#', '/ocr/', rtrim($base, '/'));
        $url = $ocrBase . $filename;
        if ($wait > 0) {
            $url .= '?wait=' . $wait;
        }

        $client = new Client(['timeout' => max(5, (int) ceil($wait) + 5), 'connect_timeout' => 2, 'http_errors' => false]);
        try {
            $resp = $client->get($url, ['headers' => ['Accept' => 'application/json']]);
            $code = $resp->getStatusCode();
            $body = json_decode($resp->getBody()->getContents(), true);

            if ($code === 429 || $code === 503) {
                // FIX 429 (2026-10-08): teruskan Retry-After FastAPI agar JS bisa
                // backoff tepat (hormati header), bukan retry 3 detik buta.
                $retryAfter = (int) ($body['retry_after_seconds'] ?? $resp->getHeaderLine('Retry-After') ?: 10);
                $retryAfter = max(2, min(60, $retryAfter));
                return response()->json([
                    'success' => false, 'status' => 'rate_limited', 'message' => 'Too Many Requests — backoff',
                    'retry_after_seconds' => $retryAfter,
                    'data' => ['filename' => $filename],
                ], 429)->header('Retry-After', (string) $retryAfter);
            }

            $cleaned = $body['cleaned_text'] ?? null;
            $lines = $body['lines'] ?? [];
            $status = $body['status'] ?? null;
            if ($code === 200 && ($status === 'success' || $status === 'failed') && $this->isTextPresent($cleaned, $lines)) {
                // Simpan agar next poll DB-hit (hemat request FastAPI).
                if ($row) {
                    $row->update([
                        'cleaned_text' => $cleaned,
                        'lines'        => $lines,
                        'status'       => $status,
                        'is_empty'     => (bool) ($body['is_empty'] ?? false),
                        'engine'       => $body['engine'] ?? $row->engine,
                        'processed_at' => isset($body['processed_at']) ? \Carbon\Carbon::parse($body['processed_at']) : now(),
                    ]);
                    $row->refresh();
                    return response()->json(['success' => true, 'source' => 'external', 'data' => $this->rowToData($row)], 200);
                }
                return response()->json(['success' => true, 'source' => 'external', 'data' => $body], 200);
            }

            if ($code === 200 && ($status === 'pending' || ($body['success'] ?? true) === false)) {
                return response()->json([
                    'success' => true, 'status' => 'processing', 'message' => 'OCR masih processing',
                    'data' => ['filename' => $filename, 'status' => 'processing'],
                ], 202);
            }

            return response()->json([
                'success' => false, 'status' => 'not_found', 'message' => 'OCR belum tersedia. Coba lagi atau tunggu callback.',
                'data' => ['filename' => $filename],
            ], 404);
        } catch (ConnectException $e) {
            // FastAPI mati: jika ada baris pending, tetap 202 agar UI tidak error keras.
            return response()->json([
                'success' => true, 'status' => 'processing', 'message' => 'External belum terjangkau — coba lagi',
                'data' => ['filename' => $filename, 'status' => 'processing'],
            ], 202);
        } catch (\Exception $e) {
            Log::error('[CHECK SPLIT] status proxy error', ['error' => $e->getMessage(), 'filename' => $filename]);
            return response()->json(['success' => false, 'message' => 'Gagal cek OCR', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Retake cleanup best-effort — hapus baris sisi itu + file FastAPI (abaikan 404/422).
     *
     * POST /api/check/split-retake-cleanup { check_session_code, side, label_slot }
     * Selalu 200 agar frontend tidak stuck (meski FastAPI mati).
     */
    public function retakeCleanup(Request $request)
    {
        $validated = $request->validate([
            'check_session_code' => 'required|string|max:64|regex:/^CHK-[A-Za-z0-9\-_]+$/',
            'side'               => 'required|string|in:front,back',
            'label_slot'         => 'required|integer|min:1|max:2',
        ]);

        $row = CheckOcrResult::where('user_id', $request->user()->id)
            ->where('check_session_code', $validated['check_session_code'])
            ->where('side', $validated['side'])
            ->where('label_slot', (int) $validated['label_slot'])
            ->first();

        $saved = $row ? $row->saved_filename : null;
        if ($row) {
            $row->delete();
            Log::info('[CHECK SPLIT] retake cleanup DB row deleted', [
                'session' => $validated['check_session_code'], 'side' => $validated['side'],
                'slot' => $validated['label_slot'], 'saved' => $saved,
            ]);
        }

        // Best-effort hapus file FastAPI (butuh X-API-Key — kirim, abaikan hasil).
        if ($saved) {
            try {
                $base = config('services.test_signal.base_url');
                $ocrBase = preg_replace('#/status$#', '/ocr/', rtrim($base, '/'));
                $apiKey = env('OCR_CALLBACK_API_KEY') ?: env('LARAVEL_API_KEY') ?: '';
                $client = new Client(['timeout' => 3, 'connect_timeout' => 2, 'http_errors' => false]);
                $client->delete($ocrBase . basename($saved), [
                    'headers' => ['Accept' => 'application/json', 'X-API-Key' => $apiKey],
                ]);
            } catch (\Exception $e) {
                Log::warning('[CHECK SPLIT] FastAPI delete best-effort failed', ['error' => $e->getMessage()]);
            }
        }

        return response()->json(['success' => true, 'message' => 'Cleanup ok (best-effort)'], 200);
    }

    private function rowHasText(CheckOcrResult $row): bool
    {
        if (!in_array($row->status, ['success', 'failed'], true)) {
            return false;
        }
        return $this->isTextPresent($row->cleaned_text, $row->lines ?? []);
    }

    private function isTextPresent($cleaned, $lines): bool
    {
        if (is_string($cleaned) && strtolower(trim($cleaned)) === 'none') {
            return true; // kontrak RapidOCR: 'none' = success valid
        }
        $t = trim((string) ($cleaned ?? ''));
        if ($t === '') {
            return false;
        }
        if (is_array($lines) && !empty($lines)) {
            foreach ($lines as $l) {
                if (trim((string) $l) !== '') {
                    return true;
                }
            }
            return false;
        }
        return true;
    }

    private function rowToData(CheckOcrResult $row): array
    {
        return [
            'check_session_code' => $row->check_session_code,
            'side'               => $row->side,
            'label_slot'         => (int) $row->label_slot,
            'client_filename'    => $row->client_filename,
            'saved_filename'     => $row->saved_filename,
            'filename'           => $row->saved_filename,
            'request_id'         => $row->request_id,
            'cleaned_text'       => $row->cleaned_text,
            'lines'              => $row->lines ?? [],
            'status'             => $row->status,
            'is_empty'           => (bool) $row->is_empty,
            'engine'             => $row->engine,
            'processed_at'       => $row->processed_at ? $row->processed_at->toIso8601String() : null,
        ];
    }
}
