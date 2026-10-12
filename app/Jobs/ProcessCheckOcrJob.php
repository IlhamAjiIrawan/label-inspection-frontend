<?php

namespace App\Jobs;

use App\Models\CheckingSession;
use App\Services\CheckOcrClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class ProcessCheckOcrJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * @var int
     */
    public $sessionId;

    /**
     * Number of tries including first attempt.
     *
     * @var int
     */
    public $tries = 3;

    /**
     * Backoff for retries in seconds.
     *
     * @var array<int>
     */
    public $backoff = [2, 5];

    /**
     * Create a new job instance.
     *
     * @param  int  $sessionId
     * @return void
     */
    public function __construct(int $sessionId)
    {
        $this->sessionId = $sessionId;
    }

    /**
     * Execute the job — trigger OCR batch 5 on FastAPI.
     *
     * @param  \App\Services\CheckOcrClient  $client
     * @return void
     */
    public function handle(CheckOcrClient $client)
    {
        $session = CheckingSession::with('captures')->find($this->sessionId);

        if (!$session) {
            Log::warning('[CHECK] OCR job session not found', ['session_id' => $this->sessionId]);
            return;
        }

        // Guard: external must be sent (folder exists on FastAPI)
        if ($session->external_status !== 'sent') {
            Log::warning('[CHECK] OCR job blocked — external not sent', [
                'session_id'      => $session->id,
                'batch_code'      => $session->batch_code,
                'external_status' => $session->external_status,
            ]);
            return;
        }

        Log::info('[CHECK] OCR job attempt', [
            'session_id' => $session->id,
            'batch_code' => $session->batch_code,
            'attempt'    => $this->attempts(),
        ]);

        $result = $client->requestOcr($session);

        if ($result['success'] ?? false) {
            Log::info('[CHECK] OCR request success', [
                'session_id' => $session->id,
                'batch_code' => $session->batch_code,
                'response'   => $result['response'] ?? null,
            ]);
            $body = $result['response'] ?? [];
            if (isset($body['results']) && is_array($body['results'])) {
                Cache::put('check:compare:batch:' . $session->batch_code, $body, 300);
                try { $session->update(['compare_json' => json_encode($body)]); } catch (\Exception $e) { /* column belum ada → ignore, Cache saja */ }
                Log::info('[CHECK] Job compare success', ['batch' => $session->batch_code, 'results_count' => count($body['results'])]);
            }
        } else {
            $error = $result['error'] ?? 'Unknown OCR trigger error';
            $httpCode = $result['http_code'] ?? 0;
            Log::warning('[CHECK] OCR request failed', [
                'session_id' => $session->id,
                'batch_code' => $session->batch_code,
                'error'      => $error,
                'http_code'  => $httpCode,
                'attempt'    => $this->attempts(),
                'response'   => $result['response'] ?? null,
            ]);

            // 404 batch not found = tidak retry (folder sudah ter-replace atau salah URL)
            // 422 validation = tidak retry
            $noRetryCodes = [404, 422, 401];
            if (in_array($httpCode, $noRetryCodes, true)) {
                Log::error('[CHECK] OCR job non-retryable failed', [
                    'session_id' => $session->id,
                    'batch_code' => $session->batch_code,
                    'http_code'  => $httpCode,
                    'error'      => $error,
                ]);
                // Tandai session agar frontend dapat 422, bukan 500
                try {
                    $session->update(['external_error' => $error]);
                } catch (\Exception $e) {}
                return;
            }

            if ($this->attempts() < $this->tries) {
                throw new \Exception($error);
            }

            Log::error('[CHECK] OCR job permanent failed', [
                'session_id' => $session->id,
                'batch_code' => $session->batch_code,
                'error'      => $error,
            ]);
        }
    }

    /**
     * Handle a job failure after all retries.
     *
     * @param  \Throwable  $exception
     * @return void
     */
    public function failed(\Throwable $exception)
    {
        Log::error('[CHECK] OCR job failed', [
            'session_id' => $this->sessionId,
            'error'      => $exception->getMessage(),
        ]);
    }
}
