<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ReferenceOcrResult;
use App\Services\OcrClient;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class TestSignalController extends Controller
{
    /**
     * Kirim data test ke external service.
     *
     * Mendukung 2 mode:
     * 1. JSON: { "status": "true" } atau { "status": "false" } (dari Dashboard)
     * 2. Multipart: image + metadata (dari Reference Capture Result)
     *
     * POST /api/test
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function send(Request $request)
    {
        $externalUrl = config('services.test_signal.base_url');
        $timeout     = config('services.test_signal.timeout', 10);

        $isImageUpload = $request->hasFile('image');

        if ($isImageUpload) {
            return $this->handleImageUpload($request, $externalUrl, $timeout);
        }

        return $this->handleStatusSignal($request, $externalUrl, $timeout);
    }

    /**
     * Mode 1: Handle status signal (true/false) dari Dashboard.
     */
    private function handleStatusSignal(Request $request, string $externalUrl, int $timeout)
    {
        $validated = $request->validate([
            'status' => 'required|string|in:true,false',
        ]);

        $payload = [
            'status'    => $validated['status'],
            'source'    => 'label-inspection',
            'timestamp' => now()->toIso8601String(),
        ];

        Log::info('[TestSignal] Mengirim status ke external service', [
            'target'  => $externalUrl,
            'payload' => $payload,
        ]);

        return $this->forwardToExternal($externalUrl, $timeout, $payload);
    }

    /**
     * Kirim payload JSON ke external service (mode status signal).
     */
    private function forwardToExternal(string $externalUrl, int $timeout, array $payload)
    {
        $client = new Client([
            'timeout'         => $timeout,
            'connect_timeout' => 5,
            'http_errors'     => false,
        ]);

        try {
            $response = $client->post($externalUrl, [
                'headers' => [
                    'Content-Type' => 'application/json',
                    'Accept'       => 'application/json',
                ],
                'json' => $payload,
            ]);

            $statusCode = $response->getStatusCode();
            $body = json_decode($response->getBody()->getContents(), true);

            Log::info('[TestSignal] External service merespon', [
                'status_code' => $statusCode,
                'response'    => $body,
            ]);

            return response()->json([
                'success'           => true,
                'message'           => 'Data berhasil dikirim ke external service',
                'external_status'   => $statusCode,
                'external_response' => $body,
                'data'              => $payload,
            ], 200);

        } catch (ConnectException $e) {
            Log::error('[TestSignal] Gagal koneksi ke external service', [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Gagal terhubung ke external service',
                'error'   => $e->getMessage(),
            ], 502);

        } catch (RequestException $e) {
            Log::error('[TestSignal] HTTP error dari external service', [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'External service mengembalikan error',
                'error'   => $e->getMessage(),
            ], 502);
        }
    }

    /**
     * Mode 2: Handle image upload dari Reference Capture Result.
     */
    private function handleImageUpload(Request $request, string $externalUrl, int $timeout)
    {
        $validated = $request->validate([
            'image'           => 'required|image|max:5120',
            'template_id'     => 'required|string|in:S,M,L,XL,TL',
            'template_name'   => 'required|string|max:50',
            'template_width'  => 'required|integer|min:1',
            'template_height' => 'required|integer|min:1',
            'filename'        => 'required|string|max:255',
            'resolution_w'    => 'required|integer|min:1',
            'resolution_h'    => 'required|integer|min:1',
            'filesize'        => 'required|integer|min:1',
        ]);

        $payload = [
            'source'          => 'label-inspection',
            'timestamp'       => now()->toIso8601String(),
            'template_id'     => $validated['template_id'],
            'template_name'   => $validated['template_name'],
            'template_width'  => $validated['template_width'],
            'template_height' => $validated['template_height'],
            'filename'        => $validated['filename'],
            'resolution_w'    => $validated['resolution_w'],
            'resolution_h'    => $validated['resolution_h'],
            'filesize'        => $validated['filesize'],
        ];

        Log::info('[TestSignal] Mengirim image ke external service', [
            'target'  => $externalUrl,
            'payload' => $payload,
        ]);

        $client = new Client([
            'timeout'         => $timeout,
            'connect_timeout' => 5,
            'http_errors'     => false,
        ]);

        try {
            $file = $request->file('image');
            // Optimasi R2/L1 (2026-10-06): downscale sisi terpanjang sebelum
            // forward (PNG-preserving). Best effort — gagal = pakai asli.
            $forwardBytes = file_get_contents($file->getRealPath());
            try {
                $prepped = \App\Services\OcrImagePrep::prepare($forwardBytes, $validated['filename']);
                $forwardBytes = $prepped['bytes'];
            } catch (\Exception $e) {
                Log::debug('[TestSignal] image prep skip', ['error' => $e->getMessage()]);
            }
            $response = $client->post($externalUrl, [
                'headers' => [
                    'Accept' => 'application/json',
                ],
                'multipart' => [
                    [
                        'name'     => 'image',
                        'contents' => $forwardBytes,
                        'filename' => $validated['filename'],
                    ],
                    [
                        'name'     => 'source',
                        'contents' => 'label-inspection',
                    ],
                    [
                        'name'     => 'timestamp',
                        'contents' => now()->toIso8601String(),
                    ],
                    [
                        'name'     => 'template_id',
                        'contents' => $validated['template_id'],
                    ],
                    [
                        'name'     => 'template_name',
                        'contents' => $validated['template_name'],
                    ],
                    [
                        'name'     => 'template_width',
                        'contents' => (string) $validated['template_width'],
                    ],
                    [
                        'name'     => 'template_height',
                        'contents' => (string) $validated['template_height'],
                    ],
                    [
                        'name'     => 'filename',
                        'contents' => $validated['filename'],
                    ],
                    [
                        'name'     => 'resolution_w',
                        'contents' => (string) $validated['resolution_w'],
                    ],
                    [
                        'name'     => 'resolution_h',
                        'contents' => (string) $validated['resolution_h'],
                    ],
                    [
                        'name'     => 'filesize',
                        'contents' => (string) $validated['filesize'],
                    ],
                ],
            ]);

            $statusCode = $response->getStatusCode();
            $body = json_decode($response->getBody()->getContents(), true);

            Log::info('[TestSignal] External service merespon image', [
                'status_code' => $statusCode,
                'response'    => $body,
            ]);

            // FastAPI 429 (fix 2026-09-15): sama seperti preview — jangan samarkan
            // sebagai queued, karena tidak ada job yang dibuat di external.
            if ($statusCode === 429) {
                $retryAfter = (int) ($body['retry_after_seconds'] ?? 60);
                return response()->json([
                    'success'             => false,
                    'status'              => 'rate_limited',
                    'message'             => "Terlalu cepat — FastAPI batasi 5 gambar/menit. Tunggu {$retryAfter} detik lalu coba lagi.",
                    'retry_after_seconds' => $retryAfter,
                    'data'                => ['filename' => $validated['filename'], 'status' => 'rate_limited'],
                ], 429);
            }

            // R6 (2026-10-06): antrean OCR FastAPI penuh → 503 + Retry-After.
            // Perlakukan seperti 429 agar frontend backoff (job tidak dibuat).
            if ($statusCode === 503) {
                $retryAfter = (int) ($body['retry_after_seconds'] ?? $response->getHeaderLine('Retry-After') ?: 3);
                return response()->json([
                    'success'             => false,
                    'status'              => 'queue_full',
                    'message'             => "Antrean OCR penuh — tunggu {$retryAfter} detik lalu coba lagi.",
                    'retry_after_seconds' => $retryAfter,
                    'data'                => ['filename' => $validated['filename'], 'status' => 'queue_full'],
                ], 503);
            }

            // Hybrid: jika external langsung return cleaned_text (sync), simpan & log
            if (isset($body['cleaned_text']) || isset($body['data']['cleaned_text']) || isset($body['ocr']['cleaned_text'])) {
                $ocrData = $body['cleaned_text'] ?? $body['data']['cleaned_text'] ?? $body['ocr']['cleaned_text'] ?? null;
                $rawOcr = $body;

                if (is_array($ocrData)) {
                    $rawOcr = $ocrData;
                } elseif (isset($body['data']) && is_array($body['data'])) {
                    $rawOcr = $body['data'];
                }

                try {
                    $cleanedText = $rawOcr['cleaned_text'] ?? $ocrData ?? null;
                    if ($cleanedText !== null) {
                        Log::info('[OCR] cleaned_text retrieved via sync response', [
                            'original_filename' => $validated['filename'],
                            'ocr_filename'      => $rawOcr['filename'] ?? null,
                            'cleaned_text'      => Str::limit($cleanedText, 500),
                            'engine'            => $rawOcr['engine'] ?? null,
                            'status'            => $rawOcr['status'] ?? 'success',
                        ]);

                        ReferenceOcrResult::updateOrCreate(
                            ['original_filename' => $validated['filename']],
                            [
                                'user_id'                 => $request->user()->id,
                                'ocr_filename'            => $rawOcr['filename'] ?? null,
                                'engine'                  => $rawOcr['engine'] ?? 'tesseract',
                                'language'                => $rawOcr['language'] ?? 'eng',
                                'preprocessed'            => $rawOcr['preprocessed'] ?? true,
                                'preprocessing_steps'     => $rawOcr['preprocessing_steps'] ?? null,
                                'raw_text'                => $rawOcr['raw_text'] ?? null,
                                'cleaned_text'            => $cleanedText,
                                'lines'                   => $rawOcr['lines'] ?? null,
                                'status'                  => $rawOcr['status'] ?? 'success',
                                'file_size_bytes'         => $rawOcr['file_size_bytes'] ?? $validated['filesize'],
                                'processing_time_seconds' => $rawOcr['processing_time_seconds'] ?? null,
                                'processed_at'            => isset($rawOcr['processed_at']) ? \Carbon\Carbon::parse($rawOcr['processed_at']) : now(),
                                'external_response'       => $body,
                            ]
                        );
                    }
                } catch (\Exception $e) {
                    Log::warning('[OCR] Gagal simpan sync OCR', ['error' => $e->getMessage()]);
                }

                return response()->json([
                    'success'           => true,
                    'message'           => 'Image berhasil dikirim dan OCR selesai (sync)',
                    'external_status'   => $statusCode,
                    'external_response' => $body,
                    'data'              => array_merge($payload, [
                        'cleaned_text' => $cleanedText ?? null,
                        'ocr'          => $rawOcr,
                    ]),
                ], 200);
            }

            // Async path — simpan placeholder processing, tunggu webhook
            try {
                ReferenceOcrResult::updateOrCreate(
                    ['original_filename' => $validated['filename']],
                    [
                        'user_id'           => $request->user()->id,
                        'status'            => 'processing',
                        'external_response' => $body,
                    ]
                );
            } catch (\Exception $e) {
                Log::warning('[OCR] Gagal simpan placeholder processing', ['error' => $e->getMessage()]);
            }

            return response()->json([
                'success'           => true,
                'message'           => 'Image berhasil dikirim ke external service — OCR queued, menunggu callback',
                'external_status'   => $statusCode,
                'external_response' => $body,
                'data'              => array_merge($payload, ['ocr_status' => 'processing']),
            ], 200);

        } catch (ConnectException $e) {
            Log::error('[TestSignal] Gagal koneksi ke external service', [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Gagal terhubung ke external service',
                'error'   => $e->getMessage(),
            ], 502);

        } catch (RequestException $e) {
            Log::error('[TestSignal] HTTP error dari external service', [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'External service mengembalikan error',
                'error'   => $e->getMessage(),
            ], 502);
        }
    }

    /**
     * Auto OCR Preview — untuk flow baru tanpa tombol Test.
     *
     * Dipanggil OTOMATIS setelah Capture Label. Forward image ke external
     * TANPA simpan ke DB (sesuai requirement: jangan simpan OCR text & file dulu).
     * Hasil OCR ditunggu via sync response atau via polling GET /api/ocr/{filename}.
     *
     * POST /api/ocr/preview
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function preview(Request $request)
    {
        $validated = $request->validate([
            'image'           => 'required|image|max:5120',
            'template_id'     => 'required|string|in:S,M,L,XL,TL',
            'template_name'   => 'required|string|max:50',
            'template_width'  => 'required|integer|min:1',
            'template_height' => 'required|integer|min:1',
            'filename'        => 'required|string|max:255',
            'resolution_w'    => 'required|integer|min:1',
            'resolution_h'    => 'required|integer|min:1',
            'filesize'        => 'required|integer|min:1',
        ]);

        $externalUrl = config('services.test_signal.base_url');
        $timeout     = config('services.test_signal.preview_timeout', config('services.test_signal.timeout', 10));

        $payload = [
            'source'          => 'label-inspection',
            'timestamp'       => now()->toIso8601String(),
            'template_id'     => $validated['template_id'],
            'template_name'   => $validated['template_name'],
            'template_width'  => $validated['template_width'],
            'template_height' => $validated['template_height'],
            'filename'        => $validated['filename'],
            'resolution_w'    => $validated['resolution_w'],
            'resolution_h'    => $validated['resolution_h'],
            'filesize'        => $validated['filesize'],
        ];

        Log::info('[OCR Preview] Mengirim image untuk auto OCR (tanpa simpan DB)', [
            'target'   => $externalUrl,
            'filename' => $validated['filename'],
            'user_id'  => $request->user()->id,
            'template' => $validated['template_id'],
        ]);

        $client = new Client([
            'timeout'         => $timeout,
            'connect_timeout' => 5,
            'http_errors'     => false,
        ]);

        try {
            $file = $request->file('image');
            // Optimasi R2/L1 (2026-10-06): downscale sisi terpanjang sebelum
            // forward (PNG-preserving). Best effort — gagal = pakai asli.
            $forwardBytes = file_get_contents($file->getRealPath());
            try {
                $prepped = \App\Services\OcrImagePrep::prepare($forwardBytes, $validated['filename']);
                $forwardBytes = $prepped['bytes'];
            } catch (\Exception $e) {
                Log::debug('[TestSignal] image prep skip', ['error' => $e->getMessage()]);
            }
            $response = $client->post($externalUrl, [
                'headers' => [
                    'Accept' => 'application/json',
                ],
                'multipart' => [
                    [
                        'name'     => 'image',
                        'contents' => $forwardBytes,
                        'filename' => $validated['filename'],
                    ],
                    [
                        'name'     => 'source',
                        'contents' => 'label-inspection',
                    ],
                    [
                        'name'     => 'timestamp',
                        'contents' => now()->toIso8601String(),
                    ],
                    [
                        'name'     => 'template_id',
                        'contents' => $validated['template_id'],
                    ],
                    [
                        'name'     => 'template_name',
                        'contents' => $validated['template_name'],
                    ],
                    [
                        'name'     => 'template_width',
                        'contents' => (string) $validated['template_width'],
                    ],
                    [
                        'name'     => 'template_height',
                        'contents' => (string) $validated['template_height'],
                    ],
                    [
                        'name'     => 'filename',
                        'contents' => $validated['filename'],
                    ],
                    [
                        'name'     => 'resolution_w',
                        'contents' => (string) $validated['resolution_w'],
                    ],
                    [
                        'name'     => 'resolution_h',
                        'contents' => (string) $validated['resolution_h'],
                    ],
                    [
                        'name'     => 'filesize',
                        'contents' => (string) $validated['filesize'],
                    ],
                ],
            ]);

            $statusCode = $response->getStatusCode();
            $body = json_decode($response->getBody()->getContents(), true);

            Log::info('[OCR Preview] External merespon', [
                'status_code' => $statusCode,
                'response'    => $body,
                'filename'    => $validated['filename'],
            ]);

            // FastAPI 429 (5 gambar/menit burst, fix 2026-09-15): JANGAN samarkan sebagai
            // processing — filename tidak pernah diantrekan sehingga polling hanya
            // menghasilkan 404 loop → "OCR timeout". Teruskan 429 + retry_after
            // agar frontend langsung tampilkan hitung mundur (tanpa polling sia-sia).
            if ($statusCode === 429) {
                $retryAfter = (int) ($body['retry_after_seconds'] ?? 60);
                Log::warning('[OCR Preview] FastAPI rate limited', [
                    'filename'    => $validated['filename'],
                    'retry_after' => $retryAfter,
                ]);
                return response()->json([
                    'success'             => false,
                    'status'              => 'rate_limited',
                    'message'             => "Terlalu cepat — FastAPI batasi 5 gambar/menit. Tunggu {$retryAfter} detik lalu Retake.",
                    'retry_after_seconds' => $retryAfter,
                    'data'                => ['filename' => $validated['filename'], 'status' => 'rate_limited'],
                ], 429);
            }

            // R6 (2026-10-06): antrean OCR FastAPI penuh → 503 + Retry-After.
            if ($statusCode === 503) {
                $retryAfter = (int) ($body['retry_after_seconds'] ?? $response->getHeaderLine('Retry-After') ?: 3);
                Log::warning('[OCR Preview] FastAPI OCR queue full', [
                    'filename'    => $validated['filename'],
                    'retry_after' => $retryAfter,
                ]);
                return response()->json([
                    'success'             => false,
                    'status'              => 'queue_full',
                    'message'             => "Antrean OCR penuh — tunggu {$retryAfter} detik lalu Retake.",
                    'retry_after_seconds' => $retryAfter,
                    'data'                => ['filename' => $validated['filename'], 'status' => 'queue_full'],
                ], 503);
            }

            // Sync: jika external langsung return cleaned_text, kembalikan langsung (TANPA INSERT DB)
            $hasCleaned = isset($body['cleaned_text']) || isset($body['data']['cleaned_text']) || isset($body['ocr']['cleaned_text']);
            if ($hasCleaned) {
                $rawOcr = $body;
                if (isset($body['data']) && is_array($body['data']) && isset($body['data']['cleaned_text'])) {
                    $rawOcr = $body['data'];
                } elseif (isset($body['ocr']) && is_array($body['ocr']) && isset($body['ocr']['cleaned_text'])) {
                    $rawOcr = $body['ocr'];
                } elseif (isset($body['cleaned_text']) && is_string($body['cleaned_text'])) {
                    $rawOcr = $body;
                }

                $cleanedText = $rawOcr['cleaned_text'] ?? $body['cleaned_text'] ?? $body['data']['cleaned_text'] ?? $body['ocr']['cleaned_text'] ?? null;

                if ($cleanedText !== null) {
                    // Guard empty (Fix A): sync return kosong → jangan success, anggap processing/empty agar Retake jelas
                    // KECUALI 'none' (kontrak RapidOCR 2026-09-11): 'none' = success VALID.
                    $trimmedSync = trim((string) $cleanedText);
                    $isNoneSync = strtolower($trimmedSync) === 'none';
                    $linesSync = $rawOcr['lines'] ?? [];
                    $isEmptySync = !$isNoneSync && $trimmedSync === '' && (empty($linesSync) || empty(array_filter((array) $linesSync, function ($l) { return trim((string) $l) !== ''; })));
                    if ($isEmptySync) {
                        Log::warning('[OCR] preview sync returned empty cleaned_text — treating as processing (need retake)', [
                            'filename' => $validated['filename'],
                            'engine'   => $rawOcr['engine'] ?? null,
                        ]);
                        return response()->json([
                            'success'         => true,
                            'status'          => 'processing',
                            'message'         => 'OCR returned empty — likely dark image, try retake with better lighting',
                            'data'            => array_merge($payload, ['ocr_status' => 'empty', 'engine' => $rawOcr['engine'] ?? 'tesseract']),
                            'external_status' => $statusCode,
                            'external_response' => $body,
                        ], 200);
                    }

                    Log::info('[OCR] cleaned_text retrieved via sync preview (no DB save)', [
                        'original_filename' => $validated['filename'],
                        'ocr_filename'      => $rawOcr['filename'] ?? null,
                        'cleaned_text'      => Str::limit($cleanedText, 500),
                        'engine'            => $rawOcr['engine'] ?? null,
                        'status'            => $rawOcr['status'] ?? 'success',
                    ]);

                    return response()->json([
                        'success' => true,
                        'status'  => 'success',
                        'message' => 'OCR selesai (sync)',
                        'data'    => [
                            'filename'                => $validated['filename'],
                            'ocr_filename'            => $rawOcr['filename'] ?? null,
                            'cleaned_text'            => $cleanedText,
                            'raw_text'                => $rawOcr['raw_text'] ?? null,
                            'lines'                   => $rawOcr['lines'] ?? null,
                            'engine'                  => $rawOcr['engine'] ?? 'tesseract',
                            // Tuning RapidOCR (2026-09-12): nama model det/rec + text_present.
                            'det_model'               => $rawOcr['det_model'] ?? null,
                            'rec_model'               => $rawOcr['rec_model'] ?? null,
                            'text_present'            => $rawOcr['text_present'] ?? null,
                            'language'                => $rawOcr['language'] ?? 'eng',
                            'status'                  => $rawOcr['status'] ?? 'success',
                            'processing_time_seconds' => $rawOcr['processing_time_seconds'] ?? null,
                            'processed_at'            => $rawOcr['processed_at'] ?? now()->toIso8601String(),
                            'file_size_bytes'         => $rawOcr['file_size_bytes'] ?? $validated['filesize'],
                        ],
                        'external_status'   => $statusCode,
                        'external_response' => $body,
                    ], 200);
                }
            }

            // Async: kembalikan processing — frontend akan polling GET /api/ocr/{filename}
            // JANGAN simpan ke reference_ocr_results di sini (sesuai requirement)
            return response()->json([
                'success'           => true,
                'status'            => 'processing',
                'message'           => 'Image berhasil dikirim ke external service — OCR queued, menunggu callback',
                'external_status'   => $statusCode,
                'external_response' => $body,
                'data'              => array_merge($payload, ['ocr_status' => 'processing']),
            ], 200);

        } catch (ConnectException $e) {
            Log::error('[OCR Preview] Gagal koneksi ke external service', [
                'error'    => $e->getMessage(),
                'filename' => $validated['filename'] ?? null,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Gagal terhubung ke external service',
                'error'   => $e->getMessage(),
            ], 502);

        } catch (RequestException $e) {
            Log::error('[OCR Preview] HTTP error dari external service', [
                'error'    => $e->getMessage(),
                'filename' => $validated['filename'] ?? null,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'External service mengembalikan error',
                'error'   => $e->getMessage(),
            ], 502);
        }
    }

    /**
     * Fallback PULL: cek status OCR berdasarkan filename.
     *
     * GET /api/test/ocr-status?filename=label-ref-m-...png
     * GET /api/ocr/{filename}
     *
     * FIX CP-016: scoped ke user_id, cache 5s, hanya INSERT jika success, 1 canonical URL, hilangkan 429/404 noise.
     */
    public function ocrStatus(Request $request)
    {
        $validated = $request->validate([
            'filename' => 'required|string|max:255',
            // R4 (2026-10-06): long-poll opsional, teruskan ke FastAPI ?wait=.
            'wait'     => 'sometimes|numeric|min:0|max:10',
        ]);
        $wait = isset($validated['wait']) ? (float) $validated['wait'] : null;

        $filename = basename($validated['filename']);

        // Security: block path traversal
        if (Str::contains($filename, '..') || Str::contains($filename, '/') || Str::contains($filename, '\\')) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid filename',
            ], 422);
        }

        // 1. DB cache dulu — hanya jika success + cleaned_text + scoped user_id (hindari stale & leak antar user)
        $existing = ReferenceOcrResult::where('original_filename', $filename)
            ->where('user_id', $request->user()->id)
            ->where('status', 'success')
            ->whereNotNull('cleaned_text')
            ->latest()
            ->first();

        if ($existing) {
            // Guard empty DB (Fix A): jika existing success tapi cleaned_text kosong, jangan serve, anggap not_found
            // KECUALI 'none' (kontrak RapidOCR 2026-09-11): serve sebagai success valid.
            $existingTrimmed = trim((string) ($existing->cleaned_text ?? ''));
            $isNoneDb = strtolower($existingTrimmed) === 'none';
            $existingLines = $existing->lines ?? [];
            $existingEmpty = !$isNoneDb && $existingTrimmed === '' && (empty($existingLines) || empty(array_filter((array) $existingLines, function ($l) { return trim((string) $l) !== ''; })));
            if ($existingEmpty) {
                Log::warning('[OCR] DB cached success but empty — treating as not_found', ['filename' => $filename, 'user_id' => $request->user()->id]);
                // Jangan return, lanjut ke fetch external
            } else {
                Log::info('[OCR] cleaned_text served from DB (cached)', [
                    'filename'     => $filename,
                    'user_id'      => $request->user()->id,
                    'cleaned_text' => Str::limit($existing->cleaned_text, 200),
                ]);

                return response()->json([
                    'success' => true,
                    'source'  => 'database',
                    'data'    => [
                        'filename'                => $existing->original_filename,
                        'original_filename'       => $existing->original_filename,
                        'ocr_filename'            => $existing->ocr_filename,
                        'cleaned_text'            => $existing->cleaned_text,
                        'raw_text'                => $existing->raw_text,
                        'lines'                   => $existing->lines,
                        'engine'                  => $existing->engine,
                        // Tuning RapidOCR (2026-09-12): nama model det/rec.
                        'det_model'               => $existing->det_model,
                        'rec_model'               => $existing->rec_model,
                        'text_present'            => $existing->text_present,
                        'language'                => $existing->language,
                        'status'                  => $existing->status,
                        'processing_time_seconds' => $existing->processing_time_seconds,
                        'processed_at'            => $existing->processed_at,
                        'file_size_bytes'         => $existing->file_size_bytes,
                    ],
                ], 200);
            }
        }

        // 2. Rate limit cache: jangan hit external jika baru 5 detik lalu (hemat 40% request)
        $cacheKey = "ocr:fetch:{$filename}:" . $request->user()->id;
        if (Cache::has($cacheKey)) {
            $cached = Cache::get($cacheKey);
            $cachedStatus = $cached['status'] ?? null;
            if ($cachedStatus === 'processing') {
                return response()->json([
                    'success' => true,
                    'status'  => 'processing',
                    'message' => 'OCR masih processing',
                    'data'    => ['filename' => $filename, 'status' => 'processing'],
                ], 202);
            }
            if ($cachedStatus === 'not_found') {
                return response()->json([
                    'success' => false,
                    'status'  => 'not_found',
                    'message' => 'OCR belum tersedia. Coba lagi atau tunggu callback.',
                    'data'    => ['filename' => $filename],
                ], 404);
            }
            if ($cachedStatus === 'rate_limited') {
                return response()->json([
                    'success' => false,
                    'status'  => 'rate_limited',
                    'message' => 'Too Many Requests',
                    'data'    => ['filename' => $filename],
                ], 429);
            }
            if ($cachedStatus === 'empty') {
                return response()->json([
                    'success' => false,
                    'status'  => 'empty',
                    'message' => 'OCR returned empty text — try retake with better lighting',
                    'data'    => ['filename' => $filename, 'status' => 'empty'],
                ], 422);
            }
        }

        // 3. Fetch sekali, 1 URL canonical (sudah disederhanakan di OcrClient::fetchOnce)
        // R4: $wait diteruskan sebagai ?wait= long-poll (null = default config).
        try {
            $client = app(OcrClient::class);
            $result = $client->fetchOnce($filename, $wait ?? null);

            // Cache 5 detik apapun hasilnya
            Cache::put($cacheKey, $result, 5);

            // Handle rate limited dari external
            if (isset($result['status']) && $result['status'] === 'rate_limited') {
                Log::warning('[OCR] rate limited from external', ['filename' => $filename]);
                return response()->json([
                    'success' => false,
                    'status'  => 'rate_limited',
                    'message' => 'Too Many Requests — backoff',
                    'data'    => ['filename' => $filename],
                ], 429);
            }

            if ($result['success'] && isset($result['data']['cleaned_text'])) {
                $ocr = $result['data'];
                // Guard empty (Fix A): PULL return kosong → jangan success, jangan insert
                // KECUALI 'none' (kontrak RapidOCR 2026-09-11): insert + serve sebagai success.
                $trimmedPull = trim((string) ($ocr['cleaned_text'] ?? ''));
                $isNonePull = strtolower($trimmedPull) === 'none';
                $linesPull = $ocr['lines'] ?? [];
                $isEmptyPull = !$isNonePull && $trimmedPull === '' && (empty($linesPull) || empty(array_filter((array) $linesPull, function ($l) { return trim((string) $l) !== ''; })));
                if ($isEmptyPull) {
                    Log::warning('[OCR] PULL returned empty cleaned_text', ['filename' => $filename, 'engine' => $ocr['engine'] ?? null]);
                    Cache::put($cacheKey, ['status' => 'empty'], 5);
                    return response()->json([
                        'success' => false,
                        'status'  => 'empty',
                        'message' => 'OCR returned empty text — try retake with better lighting',
                        'data'    => ['filename' => $filename, 'status' => 'empty'],
                    ], 422);
                }

                // Baru insert ke DB jika success non-empty (jangan insert processing/not_found/empty agar tidak stale)
                try {
                    $originalForDb = $ocr['original_filename'] ?? $filename;
                    ReferenceOcrResult::updateOrCreate(
                        ['original_filename' => $originalForDb],
                        [
                            'user_id'                 => $request->user()->id,
                            'ocr_filename'            => $ocr['filename'] ?? $ocr['ocr_filename'] ?? null,
                            'engine'                  => $ocr['engine'] ?? 'tesseract',
                            // Tuning RapidOCR (2026-09-12): simpan nama model det/rec bila ada.
                            'det_model'               => $ocr['det_model'] ?? null,
                            'rec_model'               => $ocr['rec_model'] ?? null,
                            'text_present'            => array_key_exists('text_present', $ocr) ? $ocr['text_present'] : null,
                            'language'                => $ocr['language'] ?? 'eng',
                            'cleaned_text'            => $ocr['cleaned_text'],
                            'raw_text'                => $ocr['raw_text'] ?? null,
                            'lines'                   => $ocr['lines'] ?? null,
                            'status'                  => $ocr['status'] ?? 'success',
                            'file_size_bytes'         => $ocr['file_size_bytes'] ?? null,
                            'processing_time_seconds' => $ocr['processing_time_seconds'] ?? null,
                            'processed_at'            => isset($ocr['processed_at']) ? \Carbon\Carbon::parse($ocr['processed_at']) : now(),
                            'external_response'       => $ocr,
                        ]
                    );

                    // Invalidate cache not_found/processing karena sudah success — biar next call langsung DB hit
                    Cache::forget($cacheKey);

                    Log::info('[OCR] cleaned_text retrieved via PULL (fallback)', [
                        'filename'     => $filename,
                        'user_id'      => $request->user()->id,
                        'cleaned_text' => Str::limit($ocr['cleaned_text'], 200),
                    ]);
                } catch (\Exception $e) {
                    Log::warning('[OCR] Gagal simpan PULL result', ['error' => $e->getMessage()]);
                }

                return response()->json([
                    'success' => true,
                    'source'  => 'external',
                    'data'    => $ocr,
                ], 200);
            }

            if (isset($result['status']) && $result['status'] === 'processing') {
                return response()->json([
                    'success' => true,
                    'status'  => 'processing',
                    'message' => 'OCR masih processing',
                    'data'    => ['filename' => $filename, 'status' => 'processing'],
                ], 202);
            }

            // Not found
            return response()->json([
                'success' => false,
                'status'  => 'not_found',
                'message' => 'OCR belum tersedia. Coba lagi atau tunggu callback.',
                'data'    => ['filename' => $filename],
            ], 404);
        } catch (\Exception $e) {
            Log::error('[OCR] ocrStatus error', ['error' => $e->getMessage()]);

            return response()->json([
                'success' => false,
                'message' => 'Gagal cek OCR',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Alias untuk GET /api/ocr/{filename} — sama dengan ocrStatus tapi param di path.
     */
    public function show(Request $request, $filename)
    {
        $sanitized = basename($filename);
        $request->merge(['filename' => $sanitized]);

        return $this->ocrStatus($request);
    }

    /**
     * Retake Cleanup — hapus pending OCR & file untuk filename tertentu.
     *
     * POST /api/ocr/retake-cleanup
     * Body: { "filename": "label-ref-l-20260901-144051.png" }
     *
     * Scope: hanya hapus milik auth user + reference_id IS NULL (pending).
     * External: forward DELETE ke FastAPI best effort (jangan 502 jika gagal).
     */
    public function retakeCleanup(Request $request)
    {
        $validated = $request->validate([
            'filename' => ['required', 'string', 'max:255', 'regex:/^label-ref-[a-z]+-\d{8}-\d{6}(-(front|back))?\.png$/'],
        ]);

        $filename = basename($validated['filename']);

        if (Str::contains($filename, '..')) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid filename',
            ], 422);
        }

        $uid = $request->user()->id;

        // 1. Hapus pending DB (jika ada) — scoped user_id + reference_id IS NULL
        $deletedDb = 0;
        try {
            $deletedDb = ReferenceOcrResult::where('user_id', $uid)
                ->where('original_filename', $filename)
                ->whereNull('reference_id')
                ->delete();
        } catch (\Exception $e) {
            Log::warning('[OCR] retake cleanup DB failed', ['error' => $e->getMessage(), 'filename' => $filename]);
        }

        // Invalidate cache untuk filename ini agar tidak stale
        try {
            Cache::forget("ocr:fetch:{$filename}:{$uid}");
            Cache::forget("ocr:fetch:{$filename}");
        } catch (\Exception $e) {}

        // 2. Forward hapus ke FastAPI (best effort, jangan fail jika FastAPI 404)
        $deletedExternal = false;
        $externalStatus = null;
        try {
            $timeout = (int) config('services.test_signal.retake_cleanup_timeout', config('services.ocr.retake_cleanup_timeout', 3));
            $client = new Client([
                'timeout'         => $timeout,
                'connect_timeout' => 2,
                'http_errors'     => false,
            ]);
            $baseDir = rtrim(dirname(config('services.test_signal.base_url')), '/');
            // Canonical DELETE: /api/test/ocr/{filename}
            $url = $baseDir . '/ocr/' . rawurlencode($filename);
            $headers = ['Accept' => 'application/json'];
            $apiKey = config('services.ocr.callback_api_key') ?? config('services.test_signal.api_key');
            if (!empty($apiKey)) {
                $headers['X-API-Key'] = $apiKey;
            }
            $res = $client->delete($url, ['headers' => $headers]);
            $externalStatus = $res->getStatusCode();
            $deletedExternal = $externalStatus === 200 || $externalStatus === 204;

            // Juga coba hapus via query param endpoint jika canonical tidak ada (best effort kedua)
            if (!$deletedExternal && $externalStatus === 404) {
                try {
                    $url2 = $baseDir . '/ocr/latest?filename=' . rawurlencode($filename);
                    // Beberapa FastAPI implement DELETE via query — coba
                    $res2 = $client->delete($url2, ['headers' => $headers]);
                    if ($res2->getStatusCode() === 200 || $res2->getStatusCode() === 204) {
                        $deletedExternal = true;
                        $externalStatus = $res2->getStatusCode();
                    }
                } catch (\Exception $e2) {}
            }

            Log::info('[OCR] retake cleanup external', [
                'filename' => $filename,
                'status'   => $externalStatus,
                'deleted'  => $deletedExternal,
            ]);
        } catch (\Exception $e) {
            Log::warning('[OCR] retake cleanup external failed', [
                'error'    => $e->getMessage(),
                'filename' => $filename,
            ]);
        }

        Log::info('[OCR] retake cleanup', [
            'filename'         => $filename,
            'user_id'          => $uid,
            'deleted_db'       => $deletedDb,
            'deleted_external' => $deletedExternal,
            'external_status'  => $externalStatus,
        ]);

        return response()->json([
            'success'          => true,
            'deleted_db'       => $deletedDb,
            'deleted_external' => $deletedExternal,
            'filename'         => $filename,
        ]);
    }

    /**
     * Cleanup Stale — hapus semua pending >30 menit milik user.
     *
     * POST /api/ocr/cleanup-stale
     * Optional untuk cron manual.
     */
    public function cleanupStale(Request $request)
    {
        $uid = $request->user()->id;

        try {
            $deleted = ReferenceOcrResult::where('user_id', $uid)
                ->whereNull('reference_id')
                ->where('status', 'processing')
                ->where('created_at', '<', now()->subMinutes(30))
                ->delete();

            Log::info('[OCR] cleanup stale', ['user_id' => $uid, 'deleted' => $deleted]);

            return response()->json([
                'success' => true,
                'deleted' => $deleted,
            ]);
        } catch (\Exception $e) {
            Log::error('[OCR] cleanup stale failed', ['error' => $e->getMessage()]);

            return response()->json([
                'success' => false,
                'message' => 'Gagal cleanup stale',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }
}
