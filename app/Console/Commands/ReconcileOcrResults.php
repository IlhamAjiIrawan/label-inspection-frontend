<?php

namespace App\Console\Commands;

use App\Models\ReferenceOcrResult;
use App\Services\OcrClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class ReconcileOcrResults extends Command
{
    protected $signature = 'ocr:reconcile {--limit=20 : Max files to check}';
    protected $description = 'Reconcile OCR results yang missed webhook — PULL fallback tiap 5 menit (hybrid)';

    public function handle(OcrClient $client)
    {
        $limit = (int) $this->option('limit');

        $this->info('Reconciling OCR results (hybrid PULL fallback)...');

        // 1. Cari yang masih processing atau belum ada cleaned_text
        $pending = ReferenceOcrResult::where('status', 'processing')
            ->orWhereNull('cleaned_text')
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get();

        $reconciled = 0;
        $stillPending = 0;
        $failed = 0;

        foreach ($pending as $record) {
            $filename = $record->original_filename;
            $this->line("  Checking: {$filename}");

            $result = $client->fetchOnce($filename);

            if ($result['success'] && isset($result['data']['cleaned_text'])) {
                $ocr = $result['data'];

                $record->update([
                    'ocr_filename'            => $ocr['filename'] ?? $record->ocr_filename,
                    'engine'                  => $ocr['engine'] ?? $record->engine,
                    'language'                => $ocr['language'] ?? $record->language,
                    'cleaned_text'            => $ocr['cleaned_text'],
                    'raw_text'                => $ocr['raw_text'] ?? $record->raw_text,
                    'lines'                   => $ocr['lines'] ?? $record->lines,
                    'status'                  => $ocr['status'] ?? 'success',
                    'file_size_bytes'         => $ocr['file_size_bytes'] ?? $record->file_size_bytes,
                    'processing_time_seconds' => $ocr['processing_time_seconds'] ?? $record->processing_time_seconds,
                    'processed_at'            => isset($ocr['processed_at']) ? \Carbon\Carbon::parse($ocr['processed_at']) : now(),
                    'external_response'       => $ocr,
                ]);

                Log::info('[OCR] reconciled via PULL', [
                    'filename'     => $filename,
                    'cleaned_text' => \Illuminate\Support\Str::limit($ocr['cleaned_text'], 200),
                ]);

                $this->info("    ✓ Reconciled");
                $reconciled++;
            } elseif (isset($result['status']) && $result['status'] === 'processing') {
                $this->line("    … still processing");
                $stillPending++;
            } else {
                $this->warn("    ✗ Not found: " . ($result['error'] ?? 'unknown'));
                $failed++;
            }
        }

        // 2. Coba list latest dari external untuk temukan file yang belum ada di DB sama sekali
        $listResult = $client->listLatest($limit);
        if ($listResult['success'] && !empty($listResult['files'])) {
            $files = $listResult['files'];
            $this->info("Found " . count($files) . " files on external (latest)");

            foreach ($files as $file) {
                $fname = $file['filename'] ?? $file['original_filename'] ?? null;
                if (!$fname) {
                    continue;
                }

                $exists = ReferenceOcrResult::where('original_filename', $fname)
                    ->orWhere('ocr_filename', $fname)
                    ->exists();

                if ($exists) {
                    continue;
                }

                // Jika file belum ada di DB, coba fetch detailnya
                $detail = $client->fetchOnce($fname);
                if ($detail['success'] && isset($detail['data']['cleaned_text'])) {
                    $ocr = $detail['data'];
                    $firstUser = \App\Models\User::first();

                    ReferenceOcrResult::create([
                        'user_id'                 => $firstUser ? $firstUser->id : 1,
                        'original_filename'       => $ocr['original_filename'] ?? $fname,
                        'ocr_filename'            => $ocr['filename'] ?? $fname,
                        'engine'                  => $ocr['engine'] ?? 'tesseract',
                        'language'                => $ocr['language'] ?? 'eng',
                        'cleaned_text'            => $ocr['cleaned_text'],
                        'raw_text'                => $ocr['raw_text'] ?? null,
                        'lines'                   => $ocr['lines'] ?? null,
                        'status'                  => $ocr['status'] ?? 'success',
                        'file_size_bytes'         => $ocr['file_size_bytes'] ?? null,
                        'processing_time_seconds' => $ocr['processing_time_seconds'] ?? null,
                        'processed_at'            => isset($ocr['processed_at']) ? \Carbon\Carbon::parse($ocr['processed_at']) : now(),
                        'external_response'       => $ocr,
                    ]);

                    $this->info("  + Imported: {$fname}");
                    $reconciled++;
                }
            }
        }

        $this->info("");
        $this->info("Done. Reconciled: {$reconciled}, Still pending: {$stillPending}, Failed: {$failed}");

        Log::info('[OCR] Reconcile completed', [
            'reconciled'    => $reconciled,
            'still_pending' => $stillPending,
            'failed'        => $failed,
        ]);

        return 0;
    }
}
