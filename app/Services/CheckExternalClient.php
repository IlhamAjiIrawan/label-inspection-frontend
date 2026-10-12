<?php

namespace App\Services;

use App\Models\CheckingSession;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Client to forward check batch (4 images, compat 5) to external FastAPI.
 * Reuses pattern from OcrClient / TestSignalController.
 */
class CheckExternalClient
{
    protected $baseUrl;
    protected $timeout;
    protected $apiKey;
    protected $client;

    public function __construct()
    {
        $this->baseUrl = config('services.check_external.base_url', 'http://127.0.0.1:9000/api/check/captures');
        $this->timeout = (int) config('services.check_external.timeout', 10);
        $this->apiKey  = config('services.check_external.api_key') ?? config('services.ocr.callback_api_key') ?? config('services.test_signal.api_key', null);
        $this->client  = new Client([
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
     * Send batch 1-5 images via multipart to FastAPI (was 4-5).
     *
     * @param  \App\Models\CheckingSession  $session (must have captures loaded)
     * @return array{success: bool, status: string, response?: array, error?: string, http_code?: int}
     */
    public function sendBatch(CheckingSession $session) : array
    {
        $session->loadMissing('captures');
        $captures = $session->captures->sortBy('slot_number')->values();

        if (!in_array($captures->count(), [1,4,5], true)) {
            return ['success' => false, 'status' => 'failed', 'error' => 'Captures count != 1 (compat 4-5)'];
        }

        $multipart = [];

        // Text fields
        $multipart[] = ['name' => 'batch_code', 'contents' => $session->batch_code];
        $multipart[] = ['name' => 'reference_id', 'contents' => (string) $session->reference_id];
        $multipart[] = ['name' => 'template_id', 'contents' => (string) $session->template_id];
        $multipart[] = ['name' => 'user_id', 'contents' => (string) $session->user_id];
        $multipart[] = ['name' => 'timestamp', 'contents' => now()->toIso8601String()];

        // Arrays: slot_numbers[], filenames[]
        foreach ($captures as $i => $cap) {
            $multipart[] = ['name' => "slot_numbers[$i]", 'contents' => (string) $cap->slot_number];
            // Also support slot_numbers without brackets for FastAPI List[int] parsing variations
        }
        foreach ($captures as $i => $cap) {
            $multipart[] = ['name' => "filenames[$i]", 'contents' => $cap->filename_original];
        }

        // Files: images[0..n-1] (4 new, compat 5)
        foreach ($captures as $i => $cap) {
            $path = $cap->image_path;
            if (!Storage::disk('local')->exists($path)) {
                return ['success' => false, 'status' => 'failed', 'error' => "File not found for slot {$cap->slot_number}: $path"];
            }
            $contents = Storage::disk('local')->get($path);
            if ($contents === false) {
                return ['success' => false, 'status' => 'failed', 'error' => "Failed to read file for slot {$cap->slot_number}"];
            }
            // Optimasi R2/L1 (2026-10-06): downscale sebelum forward
            // (PNG-preserving, file asli di storage tidak diubah).
            try {
                $prepped = \App\Services\OcrImagePrep::prepare($contents, $cap->filename_original);
                $contents = $prepped['bytes'];
            } catch (\Exception $e) {
                Log::debug('[CHECK] image prep skip', ['slot' => $cap->slot_number, 'error' => $e->getMessage()]);
            }
            $multipart[] = [
                'name'     => "images[$i]",
                'contents' => $contents,
                'filename' => $cap->filename_original,
            ];
        }

        // Also add generic slot_numbers/images without index for FastAPI fallback (some expect images not images[0])
        // Keep both? We already sent indexed. For safety, duplicate as images without index may confuse; keep indexed only.

        Log::info('[CHECK] external forward POST', [
            'batch_code' => $session->batch_code,
            'session_id' => $session->id,
            'url'        => $this->baseUrl,
            'captures'   => $captures->count(),
        ]);

        try {
            $response = $this->client->post($this->baseUrl, [
                'headers'   => $this->getHeaders(),
                'multipart' => $multipart,
            ]);

            $httpCode = $response->getStatusCode();
            $bodyRaw  = $response->getBody()->getContents();
            $body     = json_decode($bodyRaw, true);

            // If body not JSON, keep raw
            if (json_last_error() !== JSON_ERROR_NONE) {
                $body = ['raw' => $bodyRaw];
            }

            Log::info('[CHECK] external forward response', [
                'batch_code' => $session->batch_code,
                'http_code'  => $httpCode,
                'response'   => $body,
            ]);

            if ($httpCode >= 200 && $httpCode < 300) {
                // Check success flag if present
                $isSuccess = true;
                if (isset($body['success']) && $body['success'] === false) {
                    $isSuccess = false;
                }
                if ($isSuccess) {
                    return ['success' => true, 'status' => 'sent', 'response' => $body, 'http_code' => $httpCode];
                }
                return ['success' => false, 'status' => 'failed', 'response' => $body, 'error' => $body['message'] ?? 'External returned success=false', 'http_code' => $httpCode];
            }

            // Non-2xx
            $err = $body['message'] ?? $body['detail'] ?? "HTTP $httpCode";
            if ($httpCode === 422) $err = "Validation failed: " . json_encode($body);
            if ($httpCode === 401) $err = "Invalid API key for external";
            // R6 (2026-10-06): antrean OCR penuh — caller (CheckCaptureController) backoff + retry.
            if ($httpCode === 503) $err = "OCR queue full, retry shortly";

            return ['success' => false, 'status' => 'failed', 'response' => $body, 'error' => $err, 'http_code' => $httpCode];

        } catch (ConnectException $e) {
            Log::error('[CHECK] external forward ConnectException', [
                'batch_code' => $session->batch_code,
                'error'      => $e->getMessage(),
            ]);
            return ['success' => false, 'status' => 'failed', 'error' => 'Gagal terhubung ke external service: ' . $e->getMessage()];
        } catch (RequestException $e) {
            Log::error('[CHECK] external forward RequestException', [
                'batch_code' => $session->batch_code,
                'error'      => $e->getMessage(),
            ]);
            $msg = $e->getMessage();
            if ($e->hasResponse()) {
                $msg .= ' | ' . $e->getResponse()->getBody()->getContents();
            }
            return ['success' => false, 'status' => 'failed', 'error' => $msg];
        } catch (\Exception $e) {
            Log::error('[CHECK] external forward Exception', [
                'batch_code' => $session->batch_code,
                'error'      => $e->getMessage(),
            ]);
            return ['success' => false, 'status' => 'failed', 'error' => $e->getMessage()];
        }
    }
}
