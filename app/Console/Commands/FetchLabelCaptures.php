<?php

namespace App\Console\Commands;

use App\Models\LabelCapture;
use App\Models\User;
use App\Services\ExternalImageClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class FetchLabelCaptures extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'label-inspection:fetch-captures {--user-id= : Only fetch for specific user ID}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Fetch captured label images from external service and save locally';

    /**
     * Execute the console command.
     *
     * @param  \App\Services\ExternalImageClient  $client
     * @return int
     */
    public function handle(ExternalImageClient $client)
    {
        $this->info('Fetching label captures from external service...');

        $listResult = $client->listFiles();

        if (!$listResult['success']) {
            $this->error('Failed to list files: ' . $listResult['error']);

            Log::error('[FetchCaptures] List files failed', [
                'error' => $listResult['error'],
            ]);

            return 1;
        }

        $files = $listResult['files'];
        $this->info('Found ' . count($files) . ' files on external service');

        $userId = $this->option('user-id');
        $user = $userId ? User::find($userId) : User::first();

        if (!$user) {
            $this->error('No user found. Cannot assign captures.');

            return 1;
        }

        $downloaded = 0;
        $skipped = 0;
        $failed = 0;

        foreach ($files as $file) {
            $filename = $file['filename'] ?? null;

            if (!$filename || !$this->isValidFilename($filename)) {
                $this->warn('Skipping invalid filename: ' . ($filename ?? 'null'));
                $skipped++;
                continue;
            }

            $exists = LabelCapture::where('original_filename', $filename)->exists();
            if ($exists) {
                $this->line('  Skipping (already exists): ' . $filename);
                $skipped++;
                continue;
            }

            $this->line('  Downloading: ' . $filename);
            $result = $client->downloadImage($filename);

            if (!$result['success']) {
                LabelCapture::create([
                    'user_id'           => $user->id,
                    'template_id'       => $file['template_id'] ?? 'unknown',
                    'template_name'     => $this->resolveTemplateName($file['template_id'] ?? ''),
                    'template_width'    => 0,
                    'template_height'   => 0,
                    'original_filename' => $filename,
                    'local_path'        => '',
                    'resolution_w'      => 0,
                    'resolution_h'      => 0,
                    'filesize'          => $file['size'] ?? 0,
                    'source_timestamp'  => $file['timestamp'] ?? '',
                    'status'            => 'failed',
                    'error_message'     => $result['error'],
                ]);

                $this->error('    Failed: ' . $result['error']);
                $failed++;
                continue;
            }

            $safeName = $this->generateLocalName($filename);
            Storage::disk('label_capture')->put($safeName, $result['content']);

            LabelCapture::create([
                'user_id'           => $user->id,
                'template_id'       => $file['template_id'] ?? 'unknown',
                'template_name'     => $this->resolveTemplateName($file['template_id'] ?? ''),
                'template_width'    => $this->resolveTemplateWidth($file['template_id'] ?? ''),
                'template_height'   => $this->resolveTemplateHeight($file['template_id'] ?? ''),
                'original_filename' => $filename,
                'local_path'        => 'label-capture/' . $safeName,
                'resolution_w'      => 0,
                'resolution_h'      => 0,
                'filesize'          => $file['size'] ?? strlen($result['content']),
                'source_timestamp'  => $file['timestamp'] ?? '',
                'status'            => 'downloaded',
                'error_message'     => null,
            ]);

            $this->info('    Saved: label-capture/' . $safeName);
            $downloaded++;
        }

        $this->info('');
        $this->info("Done. Downloaded: {$downloaded}, Skipped: {$skipped}, Failed: {$failed}");

        Log::info('[FetchCaptures] Command completed', [
            'downloaded' => $downloaded,
            'skipped'    => $skipped,
            'failed'     => $failed,
        ]);

        return 0;
    }

    /**
     * Validate filename format.
     */
    protected function isValidFilename(string $filename): bool
    {
        return (bool) preg_match('/^label-ref-[a-z]+-\d{8}-\d{6}\.png$/', $filename);
    }

    /**
     * Generate unique local filename.
     */
    protected function generateLocalName(string $original): string
    {
        $ext  = pathinfo($original, PATHINFO_EXTENSION);
        $name = pathinfo($original, PATHINFO_FILENAME);
        $stamp = now()->format('Ymd-His');
        $rand = substr(bin2hex(random_bytes(4)), 0, 8);

        return $name . '-' . $stamp . '-' . $rand . '.' . $ext;
    }

    protected function resolveTemplateName(string $templateId): string
    {
        $map = [
            'S'  => 'Small',
            'M'  => 'Medium',
            'L'  => 'Large',
            'XL' => 'Extra Large',
            'TL' => 'Tall Label',
        ];

        return $map[strtoupper($templateId)] ?? 'Unknown';
    }

    protected function resolveTemplateWidth(string $templateId): int
    {
        $map = ['S' => 30, 'M' => 40, 'L' => 50, 'XL' => 60, 'TL' => 35];

        return $map[strtoupper($templateId)] ?? 0;
    }

    protected function resolveTemplateHeight(string $templateId): int
    {
        $map = ['S' => 60, 'M' => 80, 'L' => 100, 'XL' => 120, 'TL' => 80];

        return $map[strtoupper($templateId)] ?? 0;
    }
}
