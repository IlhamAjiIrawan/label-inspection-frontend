<?php

namespace App\Services;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class OcrClient
{
    protected $baseUrl;
    protected $timeout;
    protected $pollTimeout;
    protected $pollInterval;
    protected $client;
    protected $apiKey;
    protected $hmacSecret;

    public function __construct()
    {
        $this->baseUrl = config('services.test_signal.base_url');
        $this->timeout = (int) config('services.test_signal.timeout', 10);
        // FIX timeout Label 1/2 (2026-10-06): OCR RapidOCR medium bisa 60-90s per label.
        // Batas lama 30/45s selalu kalah -> status timeout padahal FastAPI akhirnya success.
        // Baru: 150s agar 2 label serial (2x60s) + antre + jitter masih muat.
        $this->pollTimeout = (int) config('services.test_signal.ocr_poll_timeout', 150);
        $this->pollInterval = (int) config('services.test_signal.ocr_poll_interval', 2);
        $this->apiKey = config('services.ocr.callback_api_key') ?? config('services.test_signal.api_key');
        $this->hmacSecret = config('services.ocr.callback_secret');
        $this->client = new Client([
            'timeout'         => $this->timeout,
            'connect_timeout' => 5,
            'http_errors'     => false,
        ]);
    }

    protected function getHeaders()
    {
        $headers = ['Accept' => 'application/json'];
        if (!empty($this->apiKey)) {
            $headers['X-API-Key'] = $this->apiKey;
        }
        return $headers;
    }

    /**
     * Resolve detik long-poll R4: min(request, config, 10s cap FastAPI).
     * Tanpa ?wait (0) = perilaku lama.
     */
    protected function resolveWait($wait): float
    {
        try {
            $cfg = (float) config('services.test_signal.ocr_longpoll_wait', 8);
        } catch (\Exception $e) {
            $cfg = 8.0;
        }
        $w = $wait === null ? $cfg : (float) $wait;
        if ($w < 0) {
            $w = 0;
        }
        // Cap FastAPI OCR_LONGPOLL_MAX_SECONDS (default 10).
        if ($w > 10) {
            $w = 10;
        }
        return $w;
    }

    /**
     * GET dengan timeout dinamis: timeout dasar vs wait+5s (long-poll menahan koneksi).
     * Return [statusCode, body] atau null bila koneksi gagal total.
     */
    protected function longPollGet(string $url, $wait = null): ?array
    {
        $wait = $this->resolveWait($wait);
        $timeout = $this->timeout;
        if ($wait > 0) {
            $timeout = max($timeout, (int) ceil($wait) + 5);
        }
        try {
            $client = $this->client;
            if ($timeout !== $this->timeout) {
                $client = new Client([
                    'timeout'         => $timeout,
                    'connect_timeout' => 5,
                    'http_errors'     => false,
                ]);
            }
            $response = $client->get($url, ['headers' => $this->getHeaders()]);
            $body = json_decode($response->getBody()->getContents(), true);
            return [$response->getStatusCode(), $body];
        } catch (ConnectException $e) {
            Log::error('[OCR] ConnectException saat long-poll', ['error' => $e->getMessage(), 'url' => $url]);
            return null;
        } catch (RequestException $e) {
            // Timeout Guzzle saat long-poll (server masih proses) → anggap processing, bukan gagal.
            $msg = $e->getMessage();
            if (stripos($msg, 'timed out') !== false || stripos($msg, 'timeout') !== false) {
                Log::info('[OCR] long-poll timeout (masih processing)', ['url' => $url]);
                return [202, ['status' => 'processing']];
            }
            Log::debug('[OCR] long-poll RequestException', ['error' => $msg]);
            return null;
        } catch (\Exception $e) {
            Log::debug('[OCR] long-poll error', ['error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Fallback PULL: fetch OCR result via GET polling.
     * Coba 3 URL fallback: ?filename=, /ocr/{filename}, /result/{filename}
     *
     * @param string $originalFilename
     * @return array{success: bool, data?: array, error?: string, status?: string}
     */
    public function fetchOcrResult(string $originalFilename, $wait = null)
    {
        $wait = $this->resolveWait($wait);
        $urls = $this->buildFetchUrls($originalFilename, $wait);

        $deadline = time() + $this->pollTimeout;
        $attempt = 0;

        while (time() < $deadline) {
            $attempt++;

            foreach ($urls as $url) {
                Log::debug('[OCR] Polling OCR result', [
                    'url'      => $url,
                    'filename' => $originalFilename,
                    'attempt'  => $attempt,
                ]);

                try {
                    $lp = $this->longPollGet($url, $wait);
                    if ($lp === null) {
                        continue;
                    }
                    [$statusCode, $body] = $lp;

                    if ($statusCode === 200 && isset($body['status']) && $body['status'] === 'success') {
                        $cleaned = $body['cleaned_text'] ?? null;

                        Log::info('[OCR] cleaned_text retrieved via PULL', [
                            'original_filename' => $originalFilename,
                            'ocr_filename'      => $body['filename'] ?? null,
                            'cleaned_text'      => Str::limit($cleaned ?? '', 300),
                            'engine'            => $body['engine'] ?? null,
                            'status'            => $body['status'] ?? null,
                            'processing_time'   => $body['processing_time_seconds'] ?? null,
                        ]);

                        return [
                            'success' => true,
                            'data'    => $body,
                            'status'  => 'success',
                        ];
                    }

                    if ($statusCode === 503) {
                        Log::warning('[OCR] OCR queue full saat polling — backoff', [
                            'filename' => $originalFilename,
                            'attempt'  => $attempt,
                        ]);
                        break; // keluar dari foreach, sleep lalu retry
                    }

                    if ($statusCode === 202 || (isset($body['status']) && in_array($body['status'], ['processing', 'pending'], true))) {
                        Log::info('[OCR] OCR still processing', [
                            'filename' => $originalFilename,
                            'attempt'  => $attempt,
                        ]);
                        break; // keluar dari foreach, sleep lalu retry
                    }

                    if ($statusCode === 404) {
                        Log::debug('[OCR] OCR not found yet (direct)', [
                            'filename' => $originalFilename,
                            'url'      => $url,
                        ]);
                        continue; // coba URL fallback berikutnya
                    }

                    if ($statusCode >= 400) {
                        Log::warning('[OCR] Non-200 response saat fetch', [
                            'status_code' => $statusCode,
                            'response'    => $body,
                            'url'         => $url,
                        ]);
                    }
                } catch (ConnectException $e) {
                    Log::error('[OCR] ConnectException saat fetch', [
                        'error' => $e->getMessage(),
                        'url'   => $url,
                    ]);

                    return [
                        'success' => false,
                        'error'   => 'Gagal terhubung ke external service: ' . $e->getMessage(),
                        'status'  => 'failed',
                    ];
                } catch (RequestException $e) {
                    Log::error('[OCR] RequestException saat fetch', [
                        'error' => $e->getMessage(),
                        'url'   => $url,
                    ]);
                }
            }

            // Fallback via latest/files jika direct 404 semua — coba match original_filename
            $fallback = $this->fetchOnce($originalFilename);
            if ($fallback['success'] && isset($fallback['data']['cleaned_text'])) {
                Log::info('[OCR] cleaned_text retrieved via PULL (fallback latest)', [
                    'original_filename' => $originalFilename,
                    'ocr_filename'      => $fallback['data']['filename'] ?? null,
                    'cleaned_text'      => Str::limit($fallback['data']['cleaned_text'] ?? '', 300),
                ]);
                return [
                    'success' => true,
                    'data'    => $fallback['data'],
                    'status'  => 'success',
                ];
            }

            if (time() + $this->pollInterval >= $deadline) {
                break;
            }

            sleep($this->pollInterval);
        }

        Log::warning('[OCR] Polling timeout', [
            'filename' => $originalFilename,
            'timeout'  => $this->pollTimeout,
        ]);

        return [
            'success' => false,
            'error'   => 'OCR polling timeout setelah ' . $this->pollTimeout . ' detik',
            'status'  => 'timeout',
        ];
    }

    /**
     * Fetch sekali tanpa polling (untuk reconcile cron & frontend poll).
     * FIX CP-016: hanya 1 canonical URL — GET /api/test/ocr/latest?filename= — tanpa loop files list.
     * Mengurangi 60 req/30s -> 10 req/30s, hilangkan 429 & 404 noise.
     * Optimasi R4 (2026-10-06): tambah ?wait=N (long-poll, default dari
     * OCR_LONGPOLL_WAIT) agar 1 request menunggu hingga hasil siap.
     *
     * @param string $originalFilename
     * @param float|null $wait Override detik long-poll (null = config, 0 = mati)
     * @return array{success: bool, data?: array, error?: string, status?: string}
     */
    public function fetchOnce(string $originalFilename, $wait = null)
    {
        $encoded = rawurlencode($originalFilename);
        $dir = rtrim(dirname($this->baseUrl), '/');
        $wait = $this->resolveWait($wait);
        $url = $dir . '/ocr/latest?filename=' . $encoded;
        if ($wait > 0) {
            $url .= '&wait=' . $wait;
        }

        try {
            $response = $this->longPollGet($url);
            if ($response === null) {
                return ['success' => false, 'error' => 'Gagal terhubung ke external service', 'status' => 'failed'];
            }
            [$statusCode, $body] = $response;

            // 429 handling (http_errors false -> status code, not exception)
            if ($statusCode === 429) {
                Log::warning('[OCR] fetchOnce rate limited', ['filename' => $originalFilename]);
                return ['success' => false, 'error' => 'Too Many Requests', 'status' => 'rate_limited'];
            }

            // 503 antrean OCR penuh (R6) → perlakukan seperti rate-limit agar caller backoff.
            if ($statusCode === 503) {
                Log::warning('[OCR] fetchOnce OCR queue full', ['filename' => $originalFilename]);
                return ['success' => false, 'error' => 'OCR queue full, retry shortly', 'status' => 'rate_limited'];
            }

            if ($statusCode === 200 && isset($body['status']) && $body['status'] === 'success' && isset($body['cleaned_text'])) {
                // Pastikan original_filename cocok jika ada
                if (isset($body['original_filename']) && $body['original_filename'] !== $originalFilename) {
                    Log::debug('[OCR] fetchOnce mismatch original_filename', ['expected' => $originalFilename, 'got' => $body['original_filename']]);
                    return ['success' => false, 'error' => 'Mismatch original_filename', 'status' => 'not_found'];
                }
                return ['success' => true, 'data' => $body, 'status' => 'success'];
            }

            if ($statusCode === 200 && isset($body['cleaned_text'])) {
                return ['success' => true, 'data' => $body, 'status' => 'success'];
            }

            // FastAPI pending = 200 {success:false, status:'pending'}; samakan jadi processing.
            if ($statusCode === 202 || (isset($body['status']) && in_array($body['status'], ['processing', 'pending'], true))) {
                return ['success' => false, 'error' => 'Still processing', 'status' => 'processing'];
            }

            if ($statusCode === 404) {
                // Fallback 1 URL tambahan hanya jika canonical 404 — coba direct /ocr/{filename}
                try {
                    $fallbackUrl = $dir . '/ocr/' . $encoded;
                    if ($wait > 0) {
                        $fallbackUrl .= '?wait=' . $wait;
                    }
                    $fb = $this->longPollGet($fallbackUrl, $wait);
                    if ($fb === null) {
                        return ['success' => false, 'error' => 'Not found', 'status' => 'not_found'];
                    }
                    [$fbCode, $fallbackBody] = $fb;
                    if ($fbCode === 429 || $fbCode === 503) {
                        return ['success' => false, 'error' => 'Too Many Requests', 'status' => 'rate_limited'];
                    }
                    if ($fbCode === 200) {
                        if (isset($fallbackBody['cleaned_text'])) {
                            return ['success' => true, 'data' => $fallbackBody, 'status' => 'success'];
                        }
                        if (isset($fallbackBody['status']) && $fallbackBody['status'] === 'processing') {
                            return ['success' => false, 'error' => 'Still processing', 'status' => 'processing'];
                        }
                    }
                } catch (\Exception $e) {
                    Log::debug('[OCR] fetchOnce fallback error', ['error' => $e->getMessage()]);
                }
                return ['success' => false, 'error' => 'Not found', 'status' => 'not_found'];
            }
        } catch (ConnectException $e) {
            return ['success' => false, 'error' => $e->getMessage(), 'status' => 'failed'];
        } catch (RequestException $e) {
            // Jika external 429, return status khusus agar controller bisa backoff
            if ($e->hasResponse() && $e->getResponse()->getStatusCode() === 429) {
                return ['success' => false, 'error' => 'Too Many Requests', 'status' => 'rate_limited'];
            }
            Log::debug('[OCR] fetchOnce RequestException', ['error' => $e->getMessage()]);
        } catch (\Exception $e) {
            Log::debug('[OCR] fetchOnce error', ['error' => $e->getMessage()]);
        }

        return ['success' => false, 'error' => 'Not found', 'status' => 'not_found'];
    }

    /**
     * List latest OCR results from external service (untuk reconcile).
     * Coba beberapa URL yang umum.
     *
     * @param int $limit
     * @return array{success: bool, files?: array, error?: string}
     */
    public function listLatest(int $limit = 20)
    {
        $baseDir = dirname($this->baseUrl);
        $candidates = [
            rtrim($baseDir, '/') . '/ocr/latest?limit=' . $limit,
            rtrim($baseDir, '/') . '/ocr/list?limit=' . $limit,
            rtrim($this->baseUrl, '/') . '/files?limit=' . $limit,
            rtrim($baseDir, '/') . '/files?limit=' . $limit,
        ];

        foreach ($candidates as $url) {
            try {
                $response = $this->client->get($url, [
                    'headers' => $this->getHeaders(),
                ]);

                $statusCode = $response->getStatusCode();
                $body = json_decode($response->getBody()->getContents(), true);

                if ($statusCode === 200) {
                    // Normalisasi: external bisa return {files: [...]}, {data: [...]}, atau array langsung
                    $files = $body['files'] ?? $body['data'] ?? $body;
                    if (is_array($files)) {
                        return ['success' => true, 'files' => $files];
                    }
                }
            } catch (ConnectException $e) {
                return ['success' => false, 'error' => $e->getMessage()];
            } catch (RequestException $e) {
                continue;
            }
        }

        return ['success' => false, 'error' => 'Failed to list latest OCR'];
    }

    protected function buildFetchUrls(string $filename, $wait = null)
    {
        $encoded = rawurlencode($filename);
        $dir = rtrim(dirname($this->baseUrl), '/');
        $wait = $this->resolveWait($wait);
        $qs = $wait > 0 ? '?wait=' . $wait : '';

        // FIX CP-016: hanya 1 canonical + 1 fallback — hilangkan 429 & 404 noise.
        // Log menunjukkan hanya /ocr/latest?filename= yang 200, sisanya 404.
        // R4: tambah ?wait= agar tiap request long-poll (tanpa wait = lama).
        return [
            $dir . '/ocr/latest?filename=' . $encoded . ($wait > 0 ? '&wait=' . $wait : ''), // canonical — 200 OK
            $dir . '/ocr/' . $encoded . $qs,                 // fallback direct
        ];
    }

    public function logCleanedText(array $ocrData, string $originalFilename)
    {
        Log::info('[OCR] cleaned_text retrieved', [
            'original_filename' => $originalFilename,
            'ocr_filename'      => $ocrData['filename'] ?? null,
            'cleaned_text'      => Str::limit($ocrData['cleaned_text'] ?? '', 300),
            'engine'            => $ocrData['engine'] ?? null,
            'status'            => $ocrData['status'] ?? null,
            'processing_time'   => $ocrData['processing_time_seconds'] ?? null,
        ]);
    }
}
