<?php

namespace App\Jobs;

use App\Models\CheckingSession;
use App\Services\CheckExternalClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessCheckExternalForwardJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * @var int
     */
    public $sessionId;

    /**
     * Number of tries for this job (including first attempt).
     *
     * @var int
     */
    public $tries = 3;

    /**
     * Backoff for retries in seconds.
     *
     * @var array<int>
     */
    public $backoff = [1, 2];

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
     * Execute the job.
     *
     * @param  \App\Services\CheckExternalClient  $client
     * @return void
     */
    public function handle(CheckExternalClient $client)
    {
        $session = CheckingSession::with('captures')->find($this->sessionId);

        if (!$session) {
            Log::warning('[CHECK] external forward job: session not found', ['session_id' => $this->sessionId]);
            return;
        }

        // Idempotent: if already sent, skip
        if ($session->external_status === 'sent') {
            Log::info('[CHECK] external forward job: already sent, skip', ['session_id' => $session->id, 'batch_code' => $session->batch_code]);
            return;
        }

        $session->update([
            'external_status' => 'queued',
            'retry_count'     => $this->attempts(),
        ]);

        Log::info('[CHECK] external forward job attempt', [
            'session_id' => $session->id,
            'batch_code' => $session->batch_code,
            'attempt'    => $this->attempts(),
        ]);

        $result = $client->sendBatch($session);

        if ($result['success'] ?? false) {
            $body = $result['response'] ?? [];
            $paths = $body['paths'] ?? $body['saved_paths'] ?? null;

            $session->update([
                'external_status'        => 'sent',
                'external_response'      => $body,
                'external_dispatched_at' => now(),
                'external_error'         => null,
            ]);

            // Update per-slot
            $captures = $session->captures->sortBy('slot_number')->values();
            foreach ($captures as $i => $cap) {
                $path = null;
                if (is_array($paths) && isset($paths[$i])) {
                    $path = $paths[$i];
                } elseif (is_array($paths) && isset($body['paths'][$cap->slot_number])) {
                    $path = $body['paths'][$cap->slot_number];
                }
                $cap->update([
                    'external_status'        => 'sent',
                    'external_path'          => $path,
                    'external_response'      => $body,
                    'external_dispatched_at' => now(),
                    'external_error'         => null,
                ]);
            }

            Log::info('[CHECK] external forward success', [
                'session_id' => $session->id,
                'batch_code' => $session->batch_code,
                'paths'      => $paths,
            ]);
        } else {
            $error = $result['error'] ?? $result['response']['message'] ?? 'Unknown error';
            $body  = $result['response'] ?? null;

            $session->update([
                'external_status' => 'failed',
                'external_error'  => $error,
                'external_response' => $body,
                'retry_count'     => $this->attempts(),
            ]);

            foreach ($session->captures as $cap) {
                $cap->update([
                    'external_status' => 'failed',
                    'external_error'  => $error,
                    'external_response' => $body,
                ]);
            }

            Log::warning('[CHECK] external forward failed', [
                'session_id' => $session->id,
                'batch_code' => $session->batch_code,
                'error'      => $error,
                'attempt'    => $this->attempts(),
            ]);

            // Throw to trigger retry if tries remain
            if ($this->attempts() < $this->tries) {
                throw new \Exception($error);
            }
        }
    }

    /**
     * Handle a job failure.
     *
     * @param  \Throwable  $exception
     * @return void
     */
    public function failed(\Throwable $exception)
    {
        try {
            $session = CheckingSession::find($this->sessionId);
            if ($session) {
                $session->update([
                    'external_status' => 'failed',
                    'external_error'  => $exception->getMessage(),
                ]);
                foreach ($session->captures as $cap) {
                    $cap->update([
                        'external_status' => 'failed',
                        'external_error'  => $exception->getMessage(),
                    ]);
                }
            }
        } catch (\Exception $e) {
            Log::error('[CHECK] external forward failed handler error', ['error' => $e->getMessage()]);
        }

        Log::error('[CHECK] external forward job failed', [
            'session_id' => $this->sessionId,
            'error'      => $exception->getMessage(),
        ]);
    }
}
