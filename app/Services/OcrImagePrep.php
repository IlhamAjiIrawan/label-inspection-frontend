<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

/**
 * Persiapan gambar sebelum forward ke FastAPI (Optimasi R2/L1, 2026-10-06).
 *
 * - Downscale sisi terpanjang ke OCR_IMAGE_MAX_SIDE (default 1600) bila lebih besar.
 * - PNG-preserving: format TIDAK diubah (PNG tetap PNG, JPEG tetap JPEG) agar
 *   magic bytes tetap cocok dengan ekstensi (validasi FastAPI ketat ext vs magic).
 * - Tidak pernah upscale. Gagal (GD hilang/corrupt) → kembalikan bytes asli.
 * - Best effort: tidak pernah throw.
 */
class OcrImagePrep
{
    /**
     * @param string $bytes    Bytes gambar asli.
     * @param string $filename Nama file asli (untuk tentukan encoder).
     * @param int|null $maxSide Override batas sisi (null = dari config).
     * @return array{bytes: string, resized: bool, width: int|null, height: int|null}
     */
    public static function prepare(string $bytes, string $filename, ?int $maxSide = null): array
    {
        $fallback = ['bytes' => $bytes, 'resized' => false, 'width' => null, 'height' => null];

        try {
            $limit = $maxSide ?? (int) config('services.test_signal.ocr_image_max_side', 1600);
        } catch (\Exception $e) {
            $limit = 1600;
        }
        if ($limit <= 0) {
            return $fallback;
        }
        if ($bytes === '' || !function_exists('imagecreatefromstring')) {
            return $fallback;
        }

        try {
            $src = @imagecreatefromstring($bytes);
            if ($src === false) {
                return $fallback;
            }
            $w = imagesx($src);
            $h = imagesy($src);
            $m = max($w, $h);
            if ($m <= $limit || $m <= 0) {
                imagedestroy($src);
                $fallback['width'] = $w;
                $fallback['height'] = $h;
                return $fallback;
            }

            $scale = $limit / $m;
            $nw = max(1, (int) round($w * $scale));
            $nh = max(1, (int) round($h * $scale));
            $dst = imagecreatetruecolor($nw, $nh);
            if ($dst === false) {
                imagedestroy($src);
                return $fallback;
            }

            $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
            $isPng = ($ext === 'png');
            if ($isPng) {
                // Pertahankan transparansi saat resample.
                imagealphablending($dst, false);
                imagesavealpha($dst, true);
            }
            if (!imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h)) {
                imagedestroy($src);
                imagedestroy($dst);
                return $fallback;
            }
            imagedestroy($src);

            ob_start();
            if ($isPng) {
                imagepng($dst, null, 6);
            } else {
                // JPEG dengan kualitas 85 — artefak ringan, sudah divalidasi test set.
                imagejpeg($dst, null, 85);
            }
            $out = ob_get_clean();
            imagedestroy($dst);

            if (!is_string($out) || $out === '') {
                return $fallback;
            }
            if (strlen($out) >= strlen($bytes)) {
                // Recompress tidak mengecilkan (mis. PNG fotografis) → pakai
                // asli saja, tapi dimensi resize tetap dilaporkan.
                $fallback['width'] = $nw;
                $fallback['height'] = $nh;
                return $fallback;
            }

            Log::debug('[OCR] image prepped (resize)', [
                'from' => $w . 'x' . $h,
                'to'   => $nw . 'x' . $nh,
                'bytes_from' => strlen($bytes),
                'bytes_to'   => strlen($out),
            ]);

            return ['bytes' => $out, 'resized' => true, 'width' => $nw, 'height' => $nh];
        } catch (\Exception $e) {
            Log::debug('[OCR] image prep fallback ke asli', ['error' => $e->getMessage()]);
            return $fallback;
        }
    }
}
