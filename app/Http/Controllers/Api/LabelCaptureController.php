<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\LabelCapture;
use App\Services\ExternalImageClient;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class LabelCaptureController extends Controller
{
    /**
     * List semua label captures milik user.
     *
     * GET /api/captures
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function index(Request $request)
    {
        $captures = LabelCapture::where('user_id', $request->user()->id)
            ->orderByDesc('created_at')
            ->paginate(20);

        return response()->json([
            'success' => true,
            'data'    => $captures,
        ]);
    }

    /**
     * Trigger manual fetch dari external service.
     *
     * POST /api/captures/fetch
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \App\Services\ExternalImageClient  $client
     * @return \Illuminate\Http\JsonResponse
     */
    public function fetch(Request $request, ExternalImageClient $client)
    {
        $listResult = $client->listFiles();

        if (!$listResult['success']) {
            Log::error('[LabelCapture] Fetch failed at list', [
                'error' => $listResult['error'],
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Gagal mengambil daftar file dari external service',
                'error'   => $listResult['error'],
            ], 502);
        }

        $files = $listResult['files'];
        $user = $request->user();
        $downloaded = 0;
        $skipped = 0;
        $failed = 0;

        foreach ($files as $file) {
            $filename = $file['filename'] ?? null;

            if (!$filename || !$this->isValidFilename($filename)) {
                $skipped++;
                continue;
            }

            $exists = LabelCapture::where('original_filename', $filename)->exists();
            if ($exists) {
                $skipped++;
                continue;
            }

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

            $downloaded++;
        }

        Log::info('[LabelCapture] Manual fetch completed', [
            'user_id'    => $user->id,
            'downloaded' => $downloaded,
            'skipped'    => $skipped,
            'failed'     => $failed,
        ]);

        return response()->json([
            'success'    => true,
            'message'    => "Fetch selesai. Downloaded: {$downloaded}, Skipped: {$skipped}, Failed: {$failed}",
            'downloaded' => $downloaded,
            'skipped'    => $skipped,
            'failed'     => $failed,
        ]);
    }

    /**
     * Download ulang file yang gagal.
     *
     * POST /api/captures/{capture}/retry
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \App\Models\LabelCapture  $capture
     * @param  \App\Services\ExternalImageClient  $client
     * @return \Illuminate\Http\JsonResponse
     */
    public function retry(Request $request, LabelCapture $capture, ExternalImageClient $client)
    {
        if ($capture->user_id !== $request->user()->id) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized',
            ], 403);
        }

        if ($capture->status !== 'failed') {
            return response()->json([
                'success' => false,
                'message' => 'Hanya capture dengan status "failed" yang bisa di-retry',
            ], 422);
        }

        $result = $client->downloadImage($capture->original_filename);

        if (!$result['success']) {
            $capture->update([
                'error_message' => $result['error'],
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Gagal mengunduh: ' . $result['error'],
            ], 502);
        }

        $safeName = $this->generateLocalName($capture->original_filename);
        Storage::disk('label_capture')->put($safeName, $result['content']);

        $capture->update([
            'local_path'    => 'label-capture/' . $safeName,
            'status'        => 'downloaded',
            'error_message' => null,
            'filesize'      => strlen($result['content']),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Retry berhasil',
            'data'    => $capture,
        ]);
    }

    /**
     * Validate filename format: label-ref-{id}-{Ymd-His}[-front|-back].png
     * FIX dual-side (2026-09-15): terima suffix -front/-back 1 basis stamp.
     */
    protected function isValidFilename(string $filename): bool
    {
        return (bool) preg_match('/^label-ref-[a-z]+-\d{8}-\d{6}(-(front|back))?\.png$/', $filename);
    }

    /**
     * Generate unique local filename to prevent collision.
     */
    protected function generateLocalName(string $original): string
    {
        $ext  = pathinfo($original, PATHINFO_EXTENSION);
        $name = pathinfo($original, PATHINFO_FILENAME);
        $stamp = now()->format('Ymd-His');
        $rand = substr(bin2hex(random_bytes(4)), 0, 8);

        return $name . '-' . $stamp . '-' . $rand . '.' . $ext;
    }

    /**
     * Resolve template name from ID.
     */
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

    /**
     * Resolve template width (mm) from ID.
     */
    protected function resolveTemplateWidth(string $templateId): int
    {
        $map = ['S' => 30, 'M' => 40, 'L' => 50, 'XL' => 60, 'TL' => 35];

        return $map[strtoupper($templateId)] ?? 0;
    }

    /**
     * Resolve template height (mm) from ID.
     */
    protected function resolveTemplateHeight(string $templateId): int
    {
        $map = ['S' => 60, 'M' => 80, 'L' => 100, 'XL' => 120, 'TL' => 80];

        return $map[strtoupper($templateId)] ?? 0;
    }
}
