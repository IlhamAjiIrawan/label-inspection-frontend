<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Reference;
use App\Models\ReferenceOcrResult;
use App\Services\FastApiReferenceLibrary;
use GuzzleHttp\Client;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ReferenceController extends Controller
{
    /**
     * Derivasi sesi + sisi dari filename capture.
     * - label-ref-tl-20260918-100528-front.png -> [label-ref-tl-20260918-100528, front]
     * - label-ref-tl-20260918-100528-back.png  -> [label-ref-tl-20260918-100528, back]
     * - label-ref-xl-20260930-103331-front-2.png -> [label-ref-xl-20260930-103331, front] (belahan Label 2)
     * - label-ref-tl-20260918-100528.png       -> [label-ref-tl-20260918-100528, single]
     * - selain itu -> [single-{fallback}, single]
     * Selalu max 64 char, aman untuk kolom session_key.
     */
    private function parseSideAndSession(string $filename, int $fallbackId = 0): array
    {
        $fn = trim($filename);
        if (preg_match('/^(.*)-(front|back)(-\d+)?(\.[a-z0-9]+)?$/i', $fn, $m)) {
            $session = substr($m[1], 0, 64);
            $side = strtolower($m[2]);
            if ($session === '') {
                $session = substr('single-' . $fallbackId . '-' . time(), 0, 64);
                $side = 'single';
            }
            return [$session !== '' ? $session : substr('sess-' . time(), 0, 64), $side];
        }
        $base = preg_replace('/\.[a-z0-9]+$/i', '', $fn);
        if ($base === '' || $base === null) {
            $base = 'ref-' . time();
        }
        // single lama: sesi unik per baris agar tidak tercampur
        $session = $fallbackId > 0
            ? substr('single-' . $fallbackId . '-' . $base, 0, 64)
            : substr($base, 0, 64);
        return [$session, 'single'];
    }

    /**
     * Aktifkan 1 sesi penuh (1-2 baris) secara atomik; nonaktifkan sesi lain milik user.
     * Pakai lockForUpdate pada baris aktif agar 2 tab/2 request bersamaan tidak balapan.
     * Return active_ids yang diaktifkan.
     */
    private function activateSession(int $uid, string $sessionKey): array
    {
        $sessionKey = substr(trim($sessionKey), 0, 64);
        $activeIds = [];
        DB::transaction(function () use ($uid, $sessionKey, &$activeIds) {
            // Kunci baris aktif user agar aktivasi bersamaan serial (anti race).
            try {
                Reference::where('user_id', $uid)->where('active', true)->lockForUpdate()->get();
            } catch (\Exception $e) {
                // lockForUpdate gagal di driver tertentu — lanjut tanpa lock (best effort).
            }
            Reference::where('user_id', $uid)->where('active', true)->update(['active' => false]);
            Reference::where('user_id', $uid)->where('session_key', $sessionKey)->update(['active' => true]);
            $activeIds = Reference::where('user_id', $uid)->where('session_key', $sessionKey)->pluck('id')->all();
        });
        try { Cache::forget("ref:latest:{$uid}"); } catch (\Exception $e) {}
        return array_values(array_map('intval', $activeIds));
    }

    private function forgetLatestCache(int $uid): void
    {
        try { Cache::forget("ref:latest:{$uid}"); } catch (\Exception $e) {}
    }

    /**
     * Store a newly created reference in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'image'                     => 'required|image|max:5120',
            'template_id'               => 'required|string|in:S,M,L,XL,TL',
            'template_name'             => 'required|string|max:50',
            // Nama custom dari popup refname-modal (STAGE-B fungsional).
            // Selaras kontrak FastAPI ref_library.name 3..80. Nullable agar
            // klien lama tanpa popup tetap bisa simpan (fallback template_name).
            'name'                      => 'nullable|string|min:3|max:80',
            'template_width'            => 'required|integer|min:1',
            'template_height'           => 'required|integer|min:1',
            'filename'                  => 'required|string|max:255',
            'resolution_w'              => 'required|integer|min:1',
            'resolution_h'              => 'required|integer|min:1',
            'filesize'                  => 'required|integer|min:1',
            // OCR data — dikirim dari frontend pendingOcrData setelah auto OCR sukses (nullable)
            'cleaned_text'              => 'nullable|string',
            'raw_text'                  => 'nullable|string',
            'lines'                     => 'nullable',
            'engine'                    => 'nullable|string|max:50',
            'language'                  => 'nullable|string|max:20',
            'ocr_filename'              => 'nullable|string|max:255',
            'processing_time_seconds'   => 'nullable|numeric',
            'processed_at'              => 'nullable|string|max:50',
        ]);

        // Handle lines: bisa string JSON atau array
        $lines = $validated['lines'] ?? null;
        if (is_string($lines)) {
            $decoded = json_decode($lines, true);
            $lines = json_last_error() === JSON_ERROR_NONE ? $decoded : null;
        } elseif (!is_array($lines) && $lines !== null) {
            $lines = null;
        }

        $file = $request->file('image');
        $timestamp = now()->format('Ymd-His');
        $safeName = 'ref-' . strtolower($validated['template_id']) . '-' . $timestamp . '-' . \Illuminate\Support\Str::random(6) . '.' . $file->getClientOriginalExtension();
        $path = $file->storeAs('references', $safeName, 'local');

        $reference = null;
        $ocrResult = null;
        $sessionKey = '';
        $side = 'single';

        // Derivasi sesi/sisi dari filename capture (front/back/single) — ground truth pairing.
        // ex: label-ref-tl-20260918-100528-front.png -> sesi label-ref-tl-20260918-100528, side front.
        try {
            [$derivedSession, $derivedSide] = $this->parseSideAndSession($validated['filename']);
            $sessionKey = $derivedSession;
            $side = in_array($derivedSide, ['front', 'back', 'single'], true) ? $derivedSide : 'single';
        } catch (\Exception $e) {
            $sessionKey = substr('sess-' . time() . '-' . $request->user()->id, 0, 64);
            $side = 'single';
        }

        DB::transaction(function () use ($validated, $path, $request, $lines, $sessionKey, $side, &$reference, &$ocrResult) {
            // CP-016: cleanup pending stale >5 menit milik user (hemat storage, anti leak) — best effort, jangan gagalkan store
            try {
                ReferenceOcrResult::where('user_id', $request->user()->id)
                    ->whereNull('reference_id')
                    ->where('original_filename', '!=', $validated['filename'])
                    ->where('created_at', '<', now()->subMinutes(5))
                    ->delete();
            } catch (\Exception $e) {
                Log::warning('[OCR] cleanup stale before store failed', ['error' => $e->getMessage()]);
            }

            // Nama custom dari popup; fallback template_name agar baris lama/klien lama tetap berlabel.
            $customName = mb_substr(trim((string) ($validated['name'] ?? '')), 0, 80);
            if ($customName === '') {
                $customName = mb_substr(trim((string) $validated['template_name']), 0, 80);
            }
            $reference = Reference::create([
                'user_id'         => $request->user()->id,
                'template_id'     => $validated['template_id'],
                'template_name'   => $validated['template_name'],
                'name'            => $customName,
                'template_width'  => $validated['template_width'],
                'template_height' => $validated['template_height'],
                'image_path'      => $path,
                'filename'        => $validated['filename'],
                'session_key'     => $sessionKey,
                'side'            => $side,
                'active'          => true,
                'resolution_w'    => $validated['resolution_w'],
                'resolution_h'    => $validated['resolution_h'],
                'filesize'        => $validated['filesize'],
            ]);

            // Single Source of Truth: baris baru langsung jadi acuan aktif,
            // sesi lain milik user dinonaktifkan dalam transaksi yang SAMA.
            // Untuk pasangan front/back (2x POST), POST kedua akan mengaktifkan
            // sesi yang sama sehingga akhir: front+back keduanya aktif.
            // Kunci baris aktif dulu agar 2 request bersamaan tidak balapan.
            try {
                Reference::where('user_id', $request->user()->id)
                    ->where('active', true)
                    ->where('id', '!=', $reference->id)
                    ->lockForUpdate()->get();
            } catch (\Exception $e) {
            }
            Reference::where('user_id', $request->user()->id)
                ->where('active', true)
                ->where('id', '!=', $reference->id)
                ->where(function ($q) use ($sessionKey) {
                    // Jangan matikan saudara 1 sesi (front menanti back / sebaliknya).
                    $q->where('session_key', '!=', $sessionKey)->orWhere('session_key', '')->orWhereNull('session_key');
                })->update(['active' => false]);

            // Jika ada OCR data (cleaned_text atau raw_text atau ocr_filename), simpan ke reference_ocr_results
            // Jika cleaned_text kosong tapi field OCR lain ada, tetap simpan dengan status failed untuk audit
            $hasOcr = !empty($validated['cleaned_text']) || !empty($validated['raw_text']) || !empty($validated['ocr_filename']);
            // Juga jika frontend mengirim cleaned_text meski kosong string (explicit), tetap buat record agar tau percobaan OCR
            if ($hasOcr || array_key_exists('cleaned_text', $validated)) {
                $cleaned = $validated['cleaned_text'] ?? null;
                $status = !empty($cleaned) ? 'success' : 'failed';

                try {
                    $processedAt = null;
                    if (!empty($validated['processed_at'])) {
                        try {
                            $processedAt = \Carbon\Carbon::parse($validated['processed_at']);
                        } catch (\Exception $e) {
                            $processedAt = now();
                        }
                    } else {
                        $processedAt = now();
                    }

                    $ocrResult = ReferenceOcrResult::updateOrCreate(
                        ['original_filename' => $validated['filename']],
                        [
                            'reference_id'            => $reference->id,
                            'user_id'                 => $request->user()->id,
                            'ocr_filename'            => $validated['ocr_filename'] ?? null,
                            'engine'                  => $validated['engine'] ?? 'tesseract',
                            'language'                => $validated['language'] ?? 'eng',
                            'raw_text'                => $validated['raw_text'] ?? null,
                            'cleaned_text'            => $cleaned,
                            'lines'                   => $lines,
                            'status'                  => $status,
                            'error'                   => $status === 'failed' ? 'OCR failed or empty cleaned_text' : null,
                            'file_size_bytes'         => $validated['filesize'],
                            'processing_time_seconds' => $validated['processing_time_seconds'] ?? null,
                            'processed_at'            => $processedAt,
                            'external_response'       => [
                                'filename'          => $validated['ocr_filename'] ?? null,
                                'original_filename' => $validated['filename'],
                                'cleaned_text'      => $cleaned,
                                'raw_text'          => $validated['raw_text'] ?? null,
                                'lines'             => $lines,
                                'engine'            => $validated['engine'] ?? 'tesseract',
                                'saved_via'         => 'store_transaction',
                            ],
                        ]
                    );

                    Log::info('[OCR] cleaned_text saved with reference (via Store)', [
                        'reference_id'      => $reference->id,
                        'original_filename' => $validated['filename'],
                        'ocr_filename'      => $validated['ocr_filename'] ?? null,
                        'cleaned_text'      => Str::limit($cleaned ?? '', 500),
                        'status'            => $status,
                        'user_id'           => $request->user()->id,
                    ]);
                } catch (\Exception $e) {
                    Log::warning('[OCR] Gagal simpan OCR result saat store reference', [
                        'error'    => $e->getMessage(),
                        'filename' => $validated['filename'],
                    ]);
                    // Jangan rollback reference — reference sudah terbuat, hanya OCR yang gagal
                }
            }
        });

        $responseData = $reference->toArray();
        if ($ocrResult) {
            $responseData['ocr_result'] = $ocrResult->toArray();
        }
        // Sesi baru langsung aktif — kembalikan agar frontend tak perlu aktivasi susulan.
        // Check Label dan Daftar memakai sumber yang sama (active flag).
        $responseData['session_key'] = $sessionKey;
        $responseData['side'] = $side;
        $responseData['active'] = true;
        try {
            $responseData['active_ids'] = Reference::where('user_id', $request->user()->id)
                ->where('session_key', $sessionKey)->pluck('id')->all();
        } catch (\Exception $e) {
            $responseData['active_ids'] = [$reference->id];
        }

        // Invalidate latest cache agar /check langsung dapat data terbaru
        try { Cache::forget("ref:latest:{$request->user()->id}"); } catch (\Exception $e) {}
        Log::info('[REF] store auto-activate ok', ['user_id' => $request->user()->id, 'reference_id' => $reference->id, 'session_key' => $sessionKey, 'side' => $side]);

        // Forward gambar ke FastAPI OCR MODE (2026-09-11): POST /api/test/status
        // memicu process_ocr_background → raw teks di-PUSH ke /api/ocr/callback
        // + bisa di-poll GET /api/test/ocr/{filename}. Best effort —
        // tidak pernah menggagalkan 201 walau FastAPI mati/timeout.
        $fastapi = $this->forwardReferenceImageToFastApi($path, $validated);
        if ($fastapi) {
            $responseData['fastapi'] = $fastapi;
        }

        // Jalur A — push ke Reference Library permanen FastAPI (2026-09-15):
        // library terdedup SHA256 + terkompres, jadi scan label yang sama
        // berikutnya cukup pilih dari library (Jalur B) tanpa scan ulang.
        // Best effort: tidak pernah menggagalkan 201.
        try {
            $library = new FastApiReferenceLibrary();
            $absolute = Storage::disk('local')->path($path);
            // Nama custom popup dipakai sebagai nama library FastAPI (fallback template_name).
            // user_id dikirim untuk audit kolom user_ref (tanpa query tambahan).
            $libraryName = mb_substr(trim((string) ($validated['name'] ?? $validated['template_name'])), 0, 80);
            if ($libraryName === '') {
                $libraryName = $validated['template_name'];
            }
            // FIX_REFERENCE_OCR_ACUAN_PER_LABEL (2026-10-07) L1: teruskan sisi
            // ('single'/legacy = '' agar FastAPI pakai default front).
            $pushSide = in_array($side, ['front', 'back'], true) ? $side : '';
            $pushed = $library->pushReference($absolute, $libraryName, $validated['template_id'], (string) $request->user()->id, $pushSide);
            if ($pushed && !empty($pushed['ref_id'])) {
                $this->bumpLibraryVersion(); // item baru harus langsung muncul di daftar
                $reference->update([
                    'fastapi_ref_id'     => $pushed['ref_id'],
                    'fastapi_ocr_status' => $pushed['ocr_status'] ?? 'pending',
                    'fastapi_thumb_url'  => $pushed['thumb_url'] ?? null,
                ]);
                $responseData['fastapi_ref_id'] = $pushed['ref_id'];
                $responseData['fastapi_duplicate'] = $pushed['duplicate'] ?? false;
                if (!empty($pushed['duplicate'])) {
                    Log::info('[REF-LIB] label sudah ada di library (dedup)', [
                        'reference_id' => $reference->id,
                        'fastapi_ref_id' => $pushed['ref_id'],
                    ]);
                }
            }
        } catch (\Exception $e) {
            Log::warning('[REF-LIB] push gagal (best effort, diabaikan)', ['error' => $e->getMessage()]);
        }

        return response()->json([
            'message' => 'Reference berhasil disimpan',
            'data'    => $responseData,
        ], 201);
    }

    /**
     * Simpan 1 sesi front+back secara ATOMIK (1 transaksi, 1x aktivasi).
     * POST /api/references/batch (multipart):
     *   template_id, template_name, template_width, template_height (shared),
     *   image_front + image_back (files), filename_front/back, resolution_w/h_front/back,
     *   filesize_front/back, session_key opsional, ocr_*_front/back opsional.
     * Return {session_key, ids:[front,back], active_ids}.
     * Lebih hemat 1 RTT + tanpa state antara dibanding 2x POST /references.
     */
    public function storeBatch(Request $request)
    {
        $validated = $request->validate([
            'template_id'     => 'required|string|in:S,M,L,XL,TL',
            'template_name'   => 'required|string|max:50',
            // Nama custom popup 1 sesi front+back (selaras FastAPI 3..80).
            'name'            => 'nullable|string|min:3|max:80',
            'template_width'  => 'required|integer|min:1',
            'template_height' => 'required|integer|min:1',
            'session_key'     => 'nullable|string|max:64|regex:/^[A-Za-z0-9\-_]+$/',
            'image_front'     => 'required|image|max:5120',
            'image_back'      => 'required|image|max:5120',
            'filename_front'  => 'required|string|max:255',
            'filename_back'   => 'required|string|max:255',
            'resolution_w_front' => 'required|integer|min:1',
            'resolution_h_front' => 'required|integer|min:1',
            'filesize_front'     => 'required|integer|min:1',
            'resolution_w_back'  => 'required|integer|min:1',
            'resolution_h_back'  => 'required|integer|min:1',
            'filesize_back'      => 'required|integer|min:1',
            // OCR opsional per sisi (dikirim dari pendingOcrFront/Back bila ada).
            'cleaned_text_front' => 'nullable|string',
            'raw_text_front'     => 'nullable|string',
            'cleaned_text_back'  => 'nullable|string',
            'raw_text_back'      => 'nullable|string',
            'engine'             => 'nullable|string|max:50',
        ]);

        $uid = $request->user()->id;
        $sessionKey = substr(trim((string) ($validated['session_key'] ?? '')), 0, 64);
        if ($sessionKey === '') {
            // Derivasi dari filename_front (basis tanpa -front) agar konsisten dengan 2x POST.
            try {
                [$derived,] = $this->parseSideAndSession($validated['filename_front']);
                $sessionKey = preg_match('/^[A-Za-z0-9\-_]+$/', $derived) ? $derived : ('sess-' . now()->format('Ymd-His') . '-' . $uid);
            } catch (\Exception $e) {
                $sessionKey = substr('sess-' . now()->format('Ymd-His') . '-' . $uid, 0, 64);
            }
        }

        $ts = now()->format('Ymd-His');
        $rand = Str::random(6);
        $pathFront = $request->file('image_front')->storeAs('references', 'ref-' . strtolower($validated['template_id']) . '-' . $ts . '-' . $rand . '-front.' . $request->file('image_front')->getClientOriginalExtension(), 'local');
        $pathBack = $request->file('image_back')->storeAs('references', 'ref-' . strtolower($validated['template_id']) . '-' . $ts . '-' . $rand . '-back.' . $request->file('image_back')->getClientOriginalExtension(), 'local');

        $ids = [];
        $frontId = 0; $backId = 0;
        // Nama sesi custom; fallback template_name agar klien lama tetap berlabel.
        $sessionName = mb_substr(trim((string) ($validated['name'] ?? $validated['template_name'])), 0, 80);
        if ($sessionName === '') {
            $sessionName = $validated['template_name'];
        }
        try {
            DB::transaction(function () use ($validated, $uid, $sessionKey, $sessionName, $pathFront, $pathBack, &$ids, &$frontId, &$backId) {
                try {
                    Reference::where('user_id', $uid)->where('active', true)->lockForUpdate()->get();
                } catch (\Exception $e) {
                }
                $front = Reference::create([
                    'user_id' => $uid, 'template_id' => $validated['template_id'],
                    'template_name' => $validated['template_name'], 'name' => $sessionName,
                    'template_width' => $validated['template_width'],
                    'template_height' => $validated['template_height'], 'image_path' => $pathFront,
                    'filename' => $validated['filename_front'], 'session_key' => $sessionKey, 'side' => 'front',
                    'active' => true, 'resolution_w' => $validated['resolution_w_front'],
                    'resolution_h' => $validated['resolution_h_front'], 'filesize' => $validated['filesize_front'],
                ]);
                $back = Reference::create([
                    'user_id' => $uid, 'template_id' => $validated['template_id'],
                    'template_name' => $validated['template_name'], 'name' => $sessionName,
                    'template_width' => $validated['template_width'],
                    'template_height' => $validated['template_height'], 'image_path' => $pathBack,
                    'filename' => $validated['filename_back'], 'session_key' => $sessionKey, 'side' => 'back',
                    'active' => true, 'resolution_w' => $validated['resolution_w_back'],
                    'resolution_h' => $validated['resolution_h_back'], 'filesize' => $validated['filesize_back'],
                ]);
                // OCR per sisi (best-effort, jangan gagalkan sesi bila OCR gagal).
                foreach ([[$front, 'front', $validated['filename_front'], $validated['filesize_front']], [$back, 'back', $validated['filename_back'], $validated['filesize_back']]] as [$refRow, $sd, $fn, $fs]) {
                    $ct = $validated['cleaned_text_' . $sd] ?? null;
                    $rt = $validated['raw_text_' . $sd] ?? null;
                    if ($ct !== null || $rt !== null) {
                        try {
                            ReferenceOcrResult::updateOrCreate(['original_filename' => $fn], [
                                'reference_id' => $refRow->id, 'user_id' => $uid,
                                'engine' => $validated['engine'] ?? 'tesseract', 'language' => 'eng',
                                'raw_text' => $rt, 'cleaned_text' => $ct, 'lines' => null,
                                'status' => !empty($ct) ? 'success' : 'failed',
                                'error' => !empty($ct) ? null : 'OCR failed or empty cleaned_text',
                                'file_size_bytes' => $fs, 'processed_at' => now(),
                                'external_response' => ['saved_via' => 'store_batch', 'side' => $sd],
                            ]);
                        } catch (\Exception $e) {
                        }
                    }
                }
                // Nonaktifkan sesi lain dalam transaksi yang sama.
                Reference::where('user_id', $uid)->where('active', true)
                    ->whereNotIn('id', [$front->id, $back->id])->update(['active' => false]);
                $ids = [(int) $front->id, (int) $back->id];
                $frontId = (int) $front->id; $backId = (int) $back->id;
            });
        } catch (\Exception $e) {
            // Cleanup file bila DB gagal agar tak yatim.
            try { Storage::disk('local')->delete($pathFront); } catch (\Exception $ex) {}
            try { Storage::disk('local')->delete($pathBack); } catch (\Exception $ex) {}
            Log::warning('[REF] storeBatch gagal', ['user_id' => $uid, 'error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => 'Gagal menyimpan sesi reference'], 500);
        }

        $this->forgetLatestCache($uid);
        // Push library best-effort untuk kedua sisi (agar tab Library langsung segar).
        // Nama sesi custom dipakai agar daftar library menampilkan nama popup user.
        try {
            $library = new FastApiReferenceLibrary();
            // FIX_REFERENCE_OCR_ACUAN_PER_LABEL (2026-10-07) L1: push kedua sisi
            // dengan side masing-masing agar FastAPI mengisi kolom per-sisi.
            foreach (['front' => $pathFront, 'back' => $pathBack] as $pushSide => $p) {
                try {
                    $absolute = Storage::disk('local')->path($p);
                    $pushed = $library->pushReference($absolute, $sessionName, $validated['template_id'], (string) $uid, $pushSide);
                    if ($pushed && !empty($pushed['ref_id'])) { $this->bumpLibraryVersion(); }
                } catch (\Exception $e) {}
            }
        } catch (\Exception $e) {}
        Log::info('[REF] storeBatch ok', ['user_id' => $uid, 'session_key' => $sessionKey, 'ids' => $ids]);
        return response()->json([
            'success' => true, 'message' => '2 Label tersimpan sebagai 1 sesi acuan.',
            'data' => ['session_key' => $sessionKey, 'ids' => $ids, 'active_ids' => $ids],
        ], 201);
    }

    /**
     * Forward gambar reference yang baru disimpan ke FastAPI external service
     * (POST /api/test/status) TANPA menunggu OCR (OCR jalan di background).
     *
     * FastAPI mode OCR: file diproses process_ocr_background dan hasil raw teks
     * di-PUSH ke /api/ocr/callback + bisa di-poll GET /api/test/ocr/{filename}.
     *
     * Best effort: return null jika gagal, tidak pernah throw.
     *
     * @param  string  $storagePath  Path di disk local (mis. references/ref-tl-....png)
     * @param  array   $validated    Field tervalidasi dari store()
     * @return array|null  ['filename' => saved FastAPI, 'status_code' => int] atau null
     */
    private function forwardReferenceImageToFastApi(string $storagePath, array $validated): ?array
    {
        try {
            $externalUrl = config('services.test_signal.base_url');
            if (empty($externalUrl)) {
                Log::warning('[REF] forward FastAPI skip: TEST_SIGNAL_URL kosong');
                return null;
            }

            $disk = Storage::disk('local');
            if (!$disk->exists($storagePath)) {
                Log::warning('[REF] forward FastAPI skip: file tidak ada', ['path' => $storagePath]);
                return null;
            }

            $contents = $disk->get($storagePath);
            // Optimasi R2/L1 (2026-10-06): downscale sebelum forward
            // (PNG-preserving, file asli di storage tidak diubah).
            try {
                $prepped = \App\Services\OcrImagePrep::prepare($contents, $validated['filename']);
                $contents = $prepped['bytes'];
            } catch (\Exception $e) {
                Log::debug('[REF] image prep skip', ['error' => $e->getMessage()]);
            }
            $now = now()->toIso8601String();

            $client = new Client([
                'timeout'         => 8,
                'connect_timeout' => 3,
                'http_errors'     => false,
            ]);

            $response = $client->post($externalUrl, [
                'headers' => ['Accept' => 'application/json'],
                'multipart' => [
                    ['name' => 'image',           'contents' => $contents, 'filename' => $validated['filename']],
                    ['name' => 'source',          'contents' => 'label-inspection'],
                    ['name' => 'timestamp',       'contents' => $now],
                    ['name' => 'template_id',     'contents' => (string) $validated['template_id']],
                    ['name' => 'template_name',   'contents' => (string) $validated['template_name']],
                    ['name' => 'template_width',  'contents' => (string) $validated['template_width']],
                    ['name' => 'template_height', 'contents' => (string) $validated['template_height']],
                    ['name' => 'filename',        'contents' => (string) $validated['filename']],
                    ['name' => 'resolution_w',    'contents' => (string) $validated['resolution_w']],
                    ['name' => 'resolution_h',    'contents' => (string) $validated['resolution_h']],
                    ['name' => 'filesize',        'contents' => (string) $validated['filesize']],
                ],
            ]);

            $statusCode = $response->getStatusCode();
            $body = json_decode($response->getBody()->getContents(), true);

            Log::info('[REF] forward FastAPI (tanpa OCR)', [
                'status_code'       => $statusCode,
                'original_filename' => $validated['filename'],
                'fastapi_filename'  => $body['filename'] ?? null,
                'ocr_status'        => $body['ocr_status'] ?? null,
            ]);

            if ($statusCode >= 200 && $statusCode < 300) {
                return [
                    'filename'    => $body['filename'] ?? null,
                    'status_code' => $statusCode,
                ];
            }

            Log::warning('[REF] forward FastAPI non-2xx (best effort, diabaikan)', [
                'status_code' => $statusCode,
                'response'    => $body,
            ]);
            return null;
        } catch (\Exception $e) {
            Log::warning('[REF] forward FastAPI gagal (best effort, diabaikan)', [
                'error' => $e->getMessage(),
                'path'  => $storagePath,
            ]);
            return null;
        }
    }

    /**
     * Stream file gambar reference milik user — scoped user_id (auth sanctum).
     * GET /api/references/{reference}/image
     * Frontend fetch dengan Bearer token lalu render sebagai blob URL.
     */
    public function image(Request $request, $id)
    {
        $ref = Reference::where('user_id', $request->user()->id)->find($id);

        if (!$ref) {
            return response()->json([
                'success' => false,
                'message' => 'Reference tidak ditemukan',
            ], 404);
        }

        $disk = Storage::disk('local');
        if (!$ref->image_path || !$disk->exists($ref->image_path)) {
            return response()->json([
                'success' => false,
                'message' => 'File gambar acuan tidak tersedia',
            ], 404);
        }

        $absolute = $disk->path($ref->image_path);

        return response()->file($absolute, [
            'Cache-Control' => 'private, max-age=3600',
            'Content-Disposition' => 'inline; filename="' . basename($absolute) . '"',
        ]);
    }

    /**
     * List references milik user — untuk Check & Dashboard & tab Choose (Jalur B).
     * GET /api/references?q=&per_page=
     * Search server-side di name / template_name / filename / template_id (hemat payload).
     * Index komposit (user_id, name) membuat filter nama tetap 1 range-scan.
     */
    public function index(Request $request)
    {
        $perPage = (int) $request->query('per_page', 10);
        $perPage = max(1, min(50, $perPage));
        $q = trim((string) $request->query('q', ''));

        $query = Reference::with('ocrResult')
            ->where('user_id', $request->user()->id);

        if ($q !== '') {
            $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q) . '%';
            $query->where(function ($w) use ($like) {
                $w->where('name', 'like', $like)
                  ->orWhere('template_name', 'like', $like)
                  ->orWhere('filename', 'like', $like)
                  ->orWhere('template_id', 'like', $like);
            });
        }

        $refs = $query->latest()->paginate($perPage);

        Log::info('[REF] index', ['user_id' => $request->user()->id, 'count' => $refs->count()]);

        $data = collect($refs->items())->map(function ($ref) {
            return array_merge($ref->toArray(), [
                'ocr_result' => $ref->ocrResult ? $ref->ocrResult->toArray() : null,
                'image_url'  => '/api/references/' . $ref->id . '/image',
            ]);
        })->all();

        return response()->json([
            'success' => true,
            'data'    => $data,
            'meta'    => [
                'current_page' => $refs->currentPage(),
                'per_page'     => $refs->perPage(),
                'total'        => $refs->total(),
                'last_page'    => $refs->lastPage(),
            ],
        ], 200);
    }

    /**
     * Get single reference dengan ocrResult — scoped user_id.
     * GET /api/references/{reference}
     */
    public function show(Request $request, $id)
    {
        $ref = Reference::with('ocrResult')
            ->where('user_id', $request->user()->id)
            ->find($id);

        if (!$ref) {
            return response()->json([
                'success' => false,
                'message' => 'Reference tidak ditemukan',
            ], 404);
        }

        Log::info('[REF] show', ['user_id' => $request->user()->id, 'reference_id' => $ref->id]);

        $payload = array_merge($ref->toArray(), [
            'ocr_result' => $ref->ocrResult ? $ref->ocrResult->toArray() : null,
            'image_url'  => '/api/references/' . $ref->id . '/image',
        ]);
        try {
            $sk = trim((string) ($ref->session_key ?? ''));
            if ($sk !== '') {
                $siblings = Reference::with('ocrResult')
                    ->where('user_id', $request->user()->id)
                    ->where('session_key', $sk)
                    ->where('id', '!=', $ref->id)
                    ->get();
                $payload['pair'] = $siblings->map(function ($s) {
                    return array_merge($s->toArray(), [
                        'ocr_result' => $s->ocrResult ? $s->ocrResult->toArray() : null,
                        'image_url'  => '/api/references/' . $s->id . '/image',
                    ]);
                })->all();
                $payload['session_key'] = $sk;
            } else {
                $payload['pair'] = [];
            }
        } catch (\Exception $e) {
            $payload['pair'] = [];
        }

        return response()->json([
            'success' => true,
            'data'    => $payload,
        ], 200);
    }

    /**
     * Rename 1 reference milik user (dipakai popup refname untuk koreksi nama).
     * PATCH /api/references/{reference} {name}
     * - Validasi 3..80 selaras FastAPI. 1 UPDATE lokal (indexed PK, tanpa N+1).
     * - Sinkron best-effort ke library FastAPI bila baris menautkan fastapi_ref_id
     *   (gagal remote tidak menggagalkan rename lokal).
     */
    public function update(Request $request, $id)
    {
        $validated = $request->validate([
            'name' => 'required|string|min:3|max:80',
        ]);

        $ref = Reference::where('user_id', $request->user()->id)->find($id);
        if (!$ref) {
            return response()->json([
                'success' => false,
                'message' => 'Reference tidak ditemukan',
            ], 404);
        }

        $clean = mb_substr(trim((string) $validated['name']), 0, 80);
        $ref->update(['name' => $clean]);
        try { Cache::forget("ref:latest:{$request->user()->id}"); } catch (\Exception $e) {}

        // Sinkron nama ke library global bila tertaut (best effort, timeout pendek di client).
        $librarySynced = null;
        if (!empty($ref->fastapi_ref_id)) {
            try {
                $library = new FastApiReferenceLibrary();
                $remote = $library->renameReference((string) $ref->fastapi_ref_id, $clean);
                $librarySynced = $remote !== null && ($remote['http_code'] ?? 0) >= 200 && ($remote['http_code'] ?? 0) < 300;
                if ($librarySynced) {
                    $this->bumpLibraryVersion();
                }
            } catch (\Exception $e) {
                Log::warning('[REF] rename sync library gagal (best effort)', ['reference_id' => $ref->id, 'error' => $e->getMessage()]);
            }
        }

        Log::info('[REF] rename ok', ['user_id' => $request->user()->id, 'reference_id' => $ref->id, 'name' => $clean]);

        return response()->json([
            'success' => true,
            'message' => 'Nama reference berhasil diperbarui.',
            'data'    => array_merge($ref->fresh()->toArray(), ['library_synced' => $librarySynced]),
        ], 200);
    }

    /**
     * Resolve 1 sesi eksplisit (front+back) untuk /check?session=xxx.
     * GET /api/references/session/{session_key} — scoped user_id.
     */
    public function session(Request $request, $sessionKey)
    {
        $sk = substr(trim((string) $sessionKey), 0, 64);
        if ($sk === '' || !preg_match('/^[A-Za-z0-9\-_]+$/', $sk)) {
            return response()->json(['success' => false, 'message' => 'Session tidak valid'], 422);
        }
        $rows = Reference::with('ocrResult')
            ->where('user_id', $request->user()->id)
            ->where('session_key', $sk)
            ->orderBy('id')
            ->get();
        if ($rows->isEmpty()) {
            return response()->json(['success' => false, 'message' => 'Reference tidak ditemukan'], 404);
        }
        $items = $rows->map(function ($r) {
            return array_merge($r->toArray(), [
                'ocr_result' => $r->ocrResult ? $r->ocrResult->toArray() : null,
                'image_url'  => '/api/references/' . $r->id . '/image',
            ]);
        })->all();
        // Depan dulu: front, single, back — agar Check konsisten.
        usort($items, function ($a, $b) {
            $order = ['front' => 0, 'single' => 1, 'back' => 2];
            return ($order[$a['side'] ?? 'single'] ?? 1) <=> ($order[$b['side'] ?? 'single'] ?? 1);
        });
        return response()->json([
            'success' => true,
            'data' => [
                'session_key' => $sk,
                'count' => count($items),
                'items' => $items,
                'front' => collect($items)->firstWhere('side', 'front') ?? $items[0],
                'back' => collect($items)->firstWhere('side', 'back'),
            ],
        ], 200);
    }

    /**
     * Aktifkan 1 sesi acuan (1-4 baris) milik user; baris lain dinonaktifkan atomik.
     * POST /api/references/activate  {ids: [int]} | {session_key: string}
     * - ids: legacy (tombol Gunakan) — bila ids dalam 1 sesi, seluruh sesi diaktifkan
     *   (front1+front2+back1+back2), bukan hanya id yang dikirim. Bila lintas sesi -> 422.
     * - session_key: cara baru yang disarankan (1 sesi = 1 card).
     */
    public function activate(Request $request)
    {
        $validated = $request->validate([
            'ids'         => 'sometimes|required|array|min:1|max:4',
            'ids.*'       => 'required|integer|min:1|distinct',
            'session_key' => 'sometimes|required|string|max:64|regex:/^[A-Za-z0-9\-_]+$/',
        ]);

        $uid = $request->user()->id;

        if (!empty($validated['session_key']) && empty($validated['ids'])) {
            $sessionKey = substr(trim($validated['session_key']), 0, 64);
            $exists = Reference::where('user_id', $uid)->where('session_key', $sessionKey)->exists();
            if (!$exists) {
                // Fallback: sesi lama yang belum backfill (session_key='') — coba derivasi via filename.
                return response()->json([
                    'success' => false,
                    'message' => 'Reference tidak ditemukan',
                ], 404);
            }
            $activeIds = $this->activateSession($uid, $sessionKey);
            Log::info('[REF] activate ok (by session)', ['user_id' => $uid, 'session_key' => $sessionKey, 'active_ids' => $activeIds]);
            return response()->json([
                'success' => true,
                'message' => 'Reference acuan diaktifkan.',
                'data'    => ['active_ids' => $activeIds, 'session_key' => $sessionKey],
            ], 200);
        }

        if (empty($validated['ids'])) {
            return response()->json([
                'success' => false,
                'message' => 'ids atau session_key wajib diisi',
            ], 422);
        }

        $ids = array_values(array_unique(array_map('intval', $validated['ids'])));

        $rows = Reference::where('user_id', $uid)->whereIn('id', $ids)->get(['id', 'session_key', 'filename']);
        if (count($rows) !== count($ids)) {
            return response()->json([
                'success' => false,
                'message' => 'Reference tidak ditemukan',
            ], 404);
        }

        // Normalisasi sesi kosong (baris pra-migrasi): derivasi dari filename lalu simpan.
        $sessions = [];
        foreach ($rows as $r) {
            $sk = trim((string) $r->session_key);
            if ($sk === '') {
                try {
                    [$derived,] = $this->parseSideAndSession((string) $r->filename, (int) $r->id);
                    $sk = $derived;
                    Reference::where('user_id', $uid)->where('id', $r->id)->update(['session_key' => $sk]);
                    $r->session_key = $sk;
                } catch (\Exception $e) {
                    $sk = 'single-' . $r->id;
                }
            }
            $sessions[$sk] = true;
        }
        if (count($sessions) !== 1) {
            return response()->json([
                'success' => false,
                'message' => 'ids harus dalam 1 sesi yang sama. Pilih 1 card sesi.',
            ], 422);
        }
        $sessionKey = array_keys($sessions)[0];

        // Aktifkan SELURUH sesi (termasuk saudara yang tak dikirim) agar front+back selalu kompak.
        $activeIds = $this->activateSession($uid, $sessionKey);
        Log::info('[REF] activate ok', ['user_id' => $uid, 'session_key' => $sessionKey, 'active_ids' => $activeIds, 'requested_ids' => $ids]);

        return response()->json([
            'success' => true,
            'message' => 'Reference acuan diaktifkan.',
            'data'    => ['active_ids' => $activeIds, 'session_key' => $sessionKey],
        ], 200);
    }

    /**
     * Get latest reference milik user — fallback untuk /check tanpa ?ref.
     * Urutan aktif-dulu: acuan aktif selalu menang atas baris terbaru.
     * GET /api/references/latest?session_key=xxx (opsional: resolve 1 sesi eksplisit)
     * Response selalu sertakan pair (saudara 1 sesi) + session_key agar
     * Check Label tak perlu 2x fetch (?ref= & ?ref_back=).
     */
    public function latest(Request $request)
    {
        $uid = $request->user()->id;
        $sessionKey = substr(trim((string) $request->query('session_key', '')), 0, 64);

        if ($sessionKey !== '') {
            $ref = Reference::with('ocrResult')
                ->where('user_id', $uid)
                ->where('session_key', $sessionKey)
                ->orderByDesc('active')
                ->latest()
                ->first();
            if (!$ref) {
                return response()->json([
                    'success' => false,
                    'message' => 'Belum ada Reference',
                ], 404);
            }
            return $this->latestResponse($ref);
        }

        $cacheKey = "ref:latest:{$uid}";

        $ref = Cache::remember($cacheKey, 30, function () use ($uid) {
            return Reference::with('ocrResult')
                ->where('user_id', $uid)
                ->orderByDesc('active')
                ->latest()
                ->first();
        });

        if (!$ref) {
            return response()->json([
                'success' => false,
                'message' => 'Belum ada Reference',
            ], 404);
        }

        Log::info('[REF] latest', ['user_id' => $uid, 'reference_id' => $ref->id]);

        return $this->latestResponse($ref);
    }

    private function latestResponse(Reference $ref)
    {
        $data = array_merge($ref->toArray(), [
            'ocr_result' => $ref->ocrResult ? $ref->ocrResult->toArray() : null,
            'image_url'  => '/api/references/' . $ref->id . '/image',
        ]);
        // Sertakan pasangan 1 sesi (front<->back) agar Check render 2 sisi sekaligus.
        try {
            $sk = trim((string) ($ref->session_key ?? ''));
            if ($sk !== '') {
                $siblings = Reference::with('ocrResult')
                    ->where('user_id', $ref->user_id)
                    ->where('session_key', $sk)
                    ->where('id', '!=', $ref->id)
                    ->get();
                $pair = $siblings->map(function ($s) {
                    return array_merge($s->toArray(), [
                        'ocr_result' => $s->ocrResult ? $s->ocrResult->toArray() : null,
                        'image_url'  => '/api/references/' . $s->id . '/image',
                    ]);
                })->all();
                $data['pair'] = $pair;
                $data['session_key'] = $sk;
            } else {
                $data['pair'] = [];
            }
        } catch (\Exception $e) {
            $data['pair'] = [];
        }
        return response()->json([
            'success' => true,
            'data'    => $data,
        ], 200);
    }

    /**
     * Proxy list Library Global FastAPI (Jalur B, tab "Library Global").
     * GET /api/references-library?q=&template_id=&page=&per_page=
     *
     * Hasil di-cache 60 detik per kombinasi query agar dropdown tetap cepat
     * dan tidak membanjiri FastAPI. Tidak pernah bocor API key ke browser.
     */
    public function library(Request $request)
    {
        $validated = $request->validate([
            'q'           => 'nullable|string|max:80',
            'template_id' => 'nullable|string|max:32',
            'page'        => 'nullable|integer|min:1|max:1000',
            'per_page'    => 'nullable|integer|min:1|max:50',
        ]);

        $q = trim($validated['q'] ?? '');
        $templateId = trim($validated['template_id'] ?? '');
        $page = (int) ($validated['page'] ?? 1);
        $perPage = (int) ($validated['per_page'] ?? 20);
        $perPage = max(1, min(50, $perPage));

        // Versi cache ikut kunci: tiap mutasi library (push/import/delete) menaikkan
        // versi agar daftar langsung segar (tanpa ini basi hingga 60 detik).
        $libVer = 0;
        try { $libVer = (int) Cache::get('ref-library:version', 0); } catch (\Exception $e) {}
        $cacheKey = 'ref-library:v' . $libVer . ':' . md5(json_encode([$q, $templateId, $page, $perPage]));

        $result = Cache::remember($cacheKey, 60, function () use ($q, $templateId, $page, $perPage) {
            $library = new FastApiReferenceLibrary();
            return $library->listLibrary($q, $templateId, $page, $perPage);
        });

        if ($result === null) {
            return response()->json([
                'success' => false,
                'message' => 'Library global tidak tersedia (FastAPI tidak merespon). Coba lagi atau pakai tab Milik Saya.',
                'error'   => 'library_unavailable',
            ], 502);
        }

        // Enrich per-user: tandai item library yang sedang jadi acuan aktif.
        // Dilakukan DI LUAR cache (cache global milik semua user) — 1 query WHERE IN, indexed.
        try {
            $items = $result['items'] ?? [];
            $refIds = collect($items)->pluck('ref_id')->filter()->values()->all();
            if (!empty($refIds)) {
                $activeMap = Reference::where('user_id', $request->user()->id)
                    ->whereIn('fastapi_ref_id', $refIds)
                    ->where('active', true)
                    ->pluck('session_key', 'fastapi_ref_id')
                    ->all();
                foreach ($items as &$it) {
                    $rid = $it['ref_id'] ?? '';
                    $it['active'] = isset($activeMap[$rid]);
                }
                unset($it);
                $result['items'] = $items;
            }
        } catch (\Exception $e) {
            // Best-effort: daftar tetap tampil tanpa badge aktif.
        }

        return response()->json(array_merge(['success' => true], $result), 200);
    }

    /**
     * Proxy health Library FastAPI (L2 plan + monitoring §9).
     * GET /api/references-library/health
     *
     * Untuk dashboard + smoke test tanpa menyentuh data. Tidak di-cache
     * (harus real-time). FastAPI down → 502 (konsisten dengan library()).
     */
    public function libraryHealth(Request $request)
    {
        $health = (new FastApiReferenceLibrary())->health();

        if ($health === null) {
            return response()->json([
                'success' => false,
                'message' => 'FastAPI tidak terjangkau.',
                'error'   => 'library_unavailable',
            ], 502);
        }

        if (!$health['ok']) {
            return response()->json([
                'success' => false,
                'message' => 'FastAPI hidup tapi database library bermasalah.',
                'error'   => 'library_db_down',
                'data'    => $health,
            ], 502);
        }

        return response()->json([
            'success' => true,
            'data'    => $health,
        ], 200);
    }

    /**
     * Import 1 item Library Global menjadi reference lokal (Jalur B "Gunakan").
     * POST /api/references/import {fastapi_ref_id, template_id, template_name, template_width, template_height}
     *
     * Mengunduh gambar dari FastAPI server-to-server, menyimpan lokal +
     * menyalin cleaned_text/lines agar /check bisa langsung pakai TANPA
     * capture + OCR ulang. Dedup by (user_id, fastapi_ref_id).
     */
    public function importFromLibrary(Request $request)
    {
        $validated = $request->validate([
            'fastapi_ref_id'  => 'required|string|max:64|regex:/^ref-[A-Za-z0-9\-_]+$/',
            'template_id'     => 'required|string|in:S,M,L,XL,TL',
            'template_name'   => 'required|string|max:50',
            'template_width'  => 'required|integer|min:1',
            'template_height' => 'required|integer|min:1',
            'session_key'     => 'nullable|string|max:64|regex:/^[A-Za-z0-9\-_]+$/',
            'side'            => 'nullable|string|in:front,back,single',
        ]);

        $uid = $request->user()->id;
        $fastapiRefId = $validated['fastapi_ref_id'];

        // Dedup: sudah pernah diimport user ini → langsung pakai yang ada
        $existing = Reference::with('ocrResult')
            ->where('user_id', $uid)
            ->where('fastapi_ref_id', $fastapiRefId)
            ->first();
        if ($existing) {
            return response()->json([
                'success'   => true,
                'duplicate' => true,
                'message'   => 'Sudah ada di daftar Anda — langsung dipakai.',
                'data'      => array_merge($existing->toArray(), [
                    'ocr_result' => $existing->ocrResult ? $existing->ocrResult->toArray() : null,
                    'image_url'  => '/api/references/' . $existing->id . '/image',
                ]),
            ], 200);
        }

        $library = new FastApiReferenceLibrary();
        $detail = $library->getDetail($fastapiRefId);

        if ($detail === null) {
            return response()->json([
                'success' => false,
                'message' => 'Item library tidak ditemukan di FastAPI.',
                'error'   => 'not_found',
            ], 404);
        }

        $ocrStatus = $detail['ocr_status'] ?? 'pending';
        if ($ocrStatus === 'pending') {
            return response()->json([
                'success' => false,
                'message' => 'OCR item ini masih diproses di FastAPI. Tunggu beberapa detik lalu coba lagi.',
                'error'   => 'still_processing',
            ], 422);
        }
        if ($ocrStatus === 'failed') {
            return response()->json([
                'success' => false,
                'message' => 'OCR item ini gagal (gambar gelap/kontras rendah). Pilih item lain atau buat scan baru.',
                'error'   => 'ocr_failed',
            ], 422);
        }

        $imageBytes = $library->downloadImage($fastapiRefId);
        if ($imageBytes === null) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal mengunduh gambar dari FastAPI. Coba lagi.',
                'error'   => 'download_failed',
            ], 502);
        }

        // Simpan gambar lokal (selalu .jpg — FastAPI library menyimpan JPEG terkompres)
        // Random suffix cegah tabrakan bila 2 import pair dalam 1 detik yang sama.
        $timestamp = now()->format('Ymd-His');
        $safeName = 'ref-' . strtolower($validated['template_id']) . '-' . $timestamp . '-' . Str::random(6) . '-lib.jpg';
        $storagePath = 'references/' . $safeName;
        Storage::disk('local')->put($storagePath, $imageBytes);

        $cleanedText = $detail['cleaned_text'] ?? null;
        $lines = $detail['lines'] ?? null;
        if (!is_array($lines)) {
            $lines = null;
        }
        // FIX_REFERENCE_OCR_ACUAN_PER_LABEL (2026-10-07): salin kolom per-sisi
        // dari FastAPI agar acuan lokal benar per sisi (fallback null = lama).
        $cleanedFront = $detail['cleaned_text_front'] ?? null;
        $cleanedBack = $detail['cleaned_text_back'] ?? null;
        $linesFront = $detail['lines_front'] ?? null;
        $linesBack = $detail['lines_back'] ?? null;
        if (!is_array($linesFront)) { $linesFront = null; }
        if (!is_array($linesBack)) { $linesBack = null; }
        $hasBack = !empty($detail['has_back']);
        $filename = 'label-lib-' . strtolower($validated['template_id']) . '-' . $timestamp . '-' . Str::random(4) . '.jpg';
        // Sesi import: bila frontend kirim session_key+side (pair global), pakai itu;
        // bila tidak, tiap import 1 sesi single unik agar tak tercampur.
        $importSide = $validated['side'] ?? 'single';
        if (!in_array($importSide, ['front', 'back', 'single'], true)) $importSide = 'single';
        $importSession = substr(trim((string) ($validated['session_key'] ?? '')), 0, 64);
        if ($importSession === '' || !preg_match('/^[A-Za-z0-9\-_]+$/', $importSession)) {
            $importSession = substr('lib-' . $timestamp . '-' . Str::random(6), 0, 64);
            $importSide = 'single';
        }

        $reference = null;
        $ocrResult = null;
        // Salin nama library FastAPI agar daftar lokal menampilkan nama asli popup user.
        $importName = mb_substr(trim((string) ($detail['name'] ?? $validated['template_name'])), 0, 80);
        if ($importName === '') {
            $importName = $validated['template_name'];
        }

        DB::transaction(function () use ($validated, $storagePath, $filename, $imageBytes, $uid, $fastapiRefId, $detail, $cleanedText, $lines, $cleanedFront, $linesFront, $cleanedBack, $linesBack, $hasBack, $importSession, $importSide, $importName, &$reference, &$ocrResult) {
            $reference = Reference::create([
                'user_id'            => $uid,
                'template_id'        => $validated['template_id'],
                'template_name'      => $validated['template_name'],
                'name'               => $importName,
                'template_width'     => $validated['template_width'],
                'template_height'    => $validated['template_height'],
                'image_path'         => $storagePath,
                'filename'           => $filename,
                'session_key'        => $importSession,
                'side'               => $importSide,
                'fastapi_ref_id'     => $fastapiRefId,
                'fastapi_ocr_status' => $detail['ocr_status'] ?? 'success',
                'resolution_w'       => 0,
                'resolution_h'       => 0,
                'filesize'           => strlen($imageBytes),
            ]);

            $ocrResult = ReferenceOcrResult::create([
                'reference_id'            => $reference->id,
                'user_id'                 => $uid,
                'original_filename'       => $filename,
                'ocr_filename'            => $fastapiRefId,
                'engine'                  => $detail['engine'] ?? 'rapidocr',
                'language'                => 'eng',
                'raw_text'                => null,
                'cleaned_text'            => $cleanedText,
                'lines'                   => $lines,
                // FIX_REFERENCE_OCR_ACUAN_PER_LABEL (2026-10-07): per sisi.
                'cleaned_text_front'      => $cleanedFront,
                'lines_front'             => $linesFront,
                'cleaned_text_back'       => $cleanedBack,
                'lines_back'              => $linesBack,
                'has_back'                => $hasBack,
                'status'                  => 'success',
                'error'                   => null,
                'file_size_bytes'         => strlen($imageBytes),
                'processing_time_seconds' => $detail['processing_time_seconds'] ?? null,
                'processed_at'            => now(),
                'external_response'       => [
                    'fastapi_ref_id' => $fastapiRefId,
                    'saved_via'      => 'library_import',
                ],
            ]);
        });

        try { Cache::forget("ref:latest:{$uid}"); } catch (\Exception $e) {}

        Log::info('[REF-LIB] import ok', ['user_id' => $uid, 'reference_id' => $reference->id, 'fastapi_ref_id' => $fastapiRefId]);
        $this->bumpLibraryVersion(); // item baru harus langsung muncul di daftar

        $responseData = $reference->toArray();
        if ($ocrResult) {
            $responseData['ocr_result'] = $ocrResult->toArray();
        }

        return response()->json([
            'success' => true,
            'duplicate' => false,
            'message' => 'Reference dari library berhasil dipakai.',
            'data'    => $responseData,
        ], 201);
    }

    /**
     * Hapus 1 reference lokal milik user (dipakai tombol Delete card Choose).
     * DELETE /api/references/{reference}[?force=1]
     *
     * Aturan baru (pisah master vs riwayat, FK SET NULL):
     * - active=true + masih ada reference lain → 409 active_guarded
     *   (aktifkan sesi lain dulu via Gunakan). Bila ini data terakhir → diizinkan.
     * - dipakai Check <10 menit terakhir + tanpa ?force=1 → 409 recently_used
     *   (cegah hapus di tengah alur Check). Ulangi dengan ?force=1 untuk paksa.
     * - selain itu (non-aktif, riwayat lama/tanpa riwayat) → diizinkan hapus;
     *   riwayat Check ter-detach (reference_id=NULL) dan tetap bisa dibuka.
     * File storage dihapus best-effort setelah commit.
     */
    public function destroy(Request $request, $id)
    {
        $ref = Reference::where('user_id', $request->user()->id)->find($id);
        if (!$ref) {
            return response()->json([
                'success' => false,
                'message' => 'Reference tidak ditemukan',
            ], 404);
        }
        $uid = (int) $request->user()->id;

        if ($ref->active) {
            $hasOther = Reference::where('user_id', $uid)->where('id', '!=', $ref->id)->exists();
            if ($hasOther) {
                return response()->json([
                    'success' => false,
                    'message' => 'Ini acuan aktif. Aktifkan sesi lain dulu via Gunakan, baru hapus ini.',
                    'error'   => 'active_guarded',
                ], 409);
            }
        }

        $force = ((string) $request->query('force', $request->input('force', ''))) === '1';
        $usage = $this->referenceUsage($uid, $ref->id);
        if ($usage['recent'] !== null && !$force) {
            return response()->json([
                'success' => false,
                'message' => 'Check baru saja memakai acuan ini (' . $usage['recent']['batch_code'] . '). Tunggu selesai atau ulangi dengan Tetap hapus.',
                'error'   => 'recently_used',
                'data'    => [
                    'batch_code'    => $usage['recent']['batch_code'],
                    'seconds_ago'   => $usage['recent']['seconds_ago'],
                    'history_count' => $usage['history_count'],
                ],
            ], 409);
        }

        $imagePath = $ref->image_path;
        $refId = $ref->id;
        $detachedSessions = (int) $usage['history_count'];
        $detachedCaptures = 0;
        try {
            $detachedCaptures = (int) DB::table('check_captures')->where('user_id', $uid)->where('reference_id', $refId)->count();
        } catch (\Exception $e) {}

        DB::transaction(function () use ($ref) {
            try {
                $ref->ocrResult()->delete();
            } catch (\Exception $e) {
                Log::warning('[REF] hapus ocr_result gagal (diabaikan)', ['reference_id' => $ref->id, 'error' => $e->getMessage()]);
            }
            $ref->delete();
        });

        if ($imagePath) {
            try {
                Storage::disk('local')->delete($imagePath);
            } catch (\Exception $e) {
                Log::warning('[REF] hapus file gagal (best effort)', ['path' => $imagePath, 'error' => $e->getMessage()]);
            }
        }
        try { Cache::forget("ref:latest:{$request->user()->id}"); } catch (\Exception $e) {}

        Log::info('[REF] delete ok', ['user_id' => $request->user()->id, 'reference_id' => $refId, 'detached_sessions' => $detachedSessions, 'detached_captures' => $detachedCaptures]);
        $this->promoteLatestActive((int) $request->user()->id);

        $msg = 'Reference berhasil dihapus.';
        if ($detachedSessions > 0) {
            $msg .= ' ' . $detachedSessions . ' riwayat Check tetap tersimpan (tanpa acuan).';
        }
        return response()->json([
            'success' => true,
            'message' => $msg,
            'data'    => ['id' => $refId, 'detached_sessions' => $detachedSessions, 'detached_captures' => $detachedCaptures],
        ], 200);
    }

    /**
     * Hapus 1 sesi penuh (front+back) secara atomik.
     * DELETE /api/references/session/{session_key}[?force=1]
     * Aturan sama dengan destroy() per baris: sesi aktif ditolak kecuali data terakhir;
     * pemakaian <10 menit butuh ?force=1. Semua baris sesi dihapus 1 transaksi
     * (tidak ada state yatim front-terhapus-back-gagal).
     */
    public function destroySession(Request $request, $sessionKey)
    {
        $sk = substr(trim((string) $sessionKey), 0, 64);
        if ($sk === '' || !preg_match('/^[A-Za-z0-9\-_]+$/', $sk)) {
            return response()->json(['success' => false, 'message' => 'Session tidak valid'], 422);
        }
        $uid = (int) $request->user()->id;
        $rows = Reference::where('user_id', $uid)->where('session_key', $sk)->get();
        if ($rows->isEmpty()) {
            return response()->json(['success' => false, 'message' => 'Reference tidak ditemukan'], 404);
        }

        $hasActive = $rows->contains(function ($r) { return (bool) $r->active; });
        if ($hasActive) {
            $hasOther = Reference::where('user_id', $uid)->where('session_key', '!=', $sk)->exists();
            if ($hasOther) {
                return response()->json([
                    'success' => false,
                    'message' => 'Ini acuan aktif. Aktifkan sesi lain dulu via Gunakan, baru hapus sesi ini.',
                    'error'   => 'active_guarded',
                ], 409);
            }
        }

        $force = ((string) $request->query('force', $request->input('force', ''))) === '1';
        $ids = $rows->pluck('id')->all();
        $recent = null;
        $historyCount = 0;
        foreach ($ids as $rid) {
            $u = $this->referenceUsage($uid, (int) $rid);
            $historyCount += (int) $u['history_count'];
            if ($u['recent'] !== null && ($recent === null || $u['recent']['seconds_ago'] < $recent['seconds_ago'])) {
                $recent = $u['recent'];
            }
        }
        if ($recent !== null && !$force) {
            return response()->json([
                'success' => false,
                'message' => 'Check baru saja memakai sesi ini (' . $recent['batch_code'] . '). Tunggu selesai atau ulangi dengan Tetap hapus.',
                'error'   => 'recently_used',
                'data'    => ['batch_code' => $recent['batch_code'], 'seconds_ago' => $recent['seconds_ago'], 'history_count' => $historyCount],
            ], 409);
        }

        $capturesCount = 0;
        try {
            $capturesCount = (int) DB::table('check_captures')->where('user_id', $uid)->whereIn('reference_id', $ids)->count();
        } catch (\Exception $e) {}

        $paths = $rows->pluck('image_path')->filter()->all();
        DB::transaction(function () use ($rows) {
            foreach ($rows as $ref) {
                try { $ref->ocrResult()->delete(); } catch (\Exception $e) {
                    Log::warning('[REF] hapus ocr_result gagal (diabaikan)', ['reference_id' => $ref->id, 'error' => $e->getMessage()]);
                }
                $ref->delete();
            }
        });
        foreach ($paths as $p) {
            try { Storage::disk('local')->delete($p); } catch (\Exception $e) {}
        }
        try { Cache::forget("ref:latest:{$uid}"); } catch (\Exception $e) {}

        Log::info('[REF] delete session ok', ['user_id' => $uid, 'session_key' => $sk, 'deleted_ids' => $ids, 'detached_sessions' => $historyCount, 'detached_captures' => $capturesCount]);
        $this->promoteLatestActive($uid);

        $msg = 'Sesi reference berhasil dihapus (' . count($ids) . ' file).';
        if ($historyCount > 0) {
            $msg .= ' ' . $historyCount . ' riwayat Check tetap tersimpan (tanpa acuan).';
        }
        return response()->json([
            'success' => true,
            'message' => $msg,
            'data'    => ['session_key' => $sk, 'deleted_ids' => array_values($ids), 'detached_sessions' => $historyCount, 'detached_captures' => $capturesCount],
        ], 200);
    }

    /**
     * Hapus 1 item Library Global FastAPI + baris lokal milik user yang menautkannya.
     * DELETE /api/references-library/{ref_id}  (dipakai tombol Delete card sesi/global)
     *
     * Keamanan: hanya boleh bila user punya baris lokal (user_id, fastapi_ref_id) — mencegah
     * hapus item milik user lain. Urutan: FastAPI dulu (gagal -> 502, lokal utuh, bisa retry),
     * lalu lokal dalam transaksi. Tolak 409 bila baris lokal masih dipakai menu Check.
     */
    public function destroyLibrary(Request $request, $refId)
    {
        if (!preg_match('/^ref-[A-Za-z0-9\-_]+$/', (string) $refId)) {
            return response()->json([
                'success' => false,
                'message' => 'ID library tidak valid.',
            ], 422);
        }

        $uid = $request->user()->id;
        $locals = Reference::where('user_id', $uid)->where('fastapi_ref_id', $refId)->get();
        if ($locals->isEmpty()) {
            return response()->json([
                'success' => false,
                'message' => 'Tidak bisa dihapus — item ini bukan dari sesi capture Anda.',
                'error'   => 'forbidden',
            ], 403);
        }

        foreach ($locals as $ref) {
            if ((bool) $ref->active) {
                $hasOther = Reference::where('user_id', $uid)->where('id', '!=', $ref->id)->exists();
                if ($hasOther) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Ini acuan aktif. Aktifkan sesi lain dulu via Gunakan, baru hapus ini.',
                        'error'   => 'active_guarded',
                    ], 409);
                }
            }
        }

        $force = ((string) $request->query('force', $request->input('force', ''))) === '1';
        $recent = null;
        $historyCount = 0;
        foreach ($locals as $ref) {
            $u = $this->referenceUsage((int) $uid, (int) $ref->id);
            $historyCount += (int) $u['history_count'];
            if ($u['recent'] !== null && ($recent === null || $u['recent']['seconds_ago'] < $recent['seconds_ago'])) {
                $recent = $u['recent'];
            }
        }
        if ($recent !== null && !$force) {
            return response()->json([
                'success' => false,
                'message' => 'Check baru saja memakai item ini (' . $recent['batch_code'] . '). Tunggu selesai atau ulangi dengan Tetap hapus.',
                'error'   => 'recently_used',
                'data'    => ['batch_code' => $recent['batch_code'], 'seconds_ago' => $recent['seconds_ago'], 'history_count' => $historyCount],
            ], 409);
        }

        // 1. FastAPI dulu — gagal berarti daftar tak berubah, aman untuk retry.
        $library = new FastApiReferenceLibrary();
        $remote = $library->deleteReference($refId);
        if ($remote === null) {
            return response()->json([
                'success' => false,
                'message' => 'Library global tidak merespon. Coba lagi (data lokal tidak diubah).',
                'error'   => 'library_unavailable',
            ], 502);
        }
        $remoteGone = ($remote['http_code'] >= 200 && $remote['http_code'] < 300) || $remote['http_code'] === 404;
        if (!$remoteGone) {
            Log::warning('[REF-LIB] delete ditolak FastAPI', ['ref_id' => $refId, 'http_code' => $remote['http_code']]);
            return response()->json([
                'success' => false,
                'message' => 'Library global menolak penghapusan. Coba lagi nanti.',
                'error'   => 'library_rejected',
            ], 502);
        }

        // 2. Lokal dalam transaksi (file storage best-effort sesudahnya).
        // FK sudah SET NULL: riwayat Check ter-detach otomatis, tidak ikut terhapus.
        $paths = $locals->pluck('image_path')->filter()->all();
        $ids = $locals->pluck('id')->all();
        $detachedSessions = (int) $historyCount;
        $detachedCaptures = 0;
        try {
            $detachedCaptures = (int) DB::table('check_captures')->where('user_id', $uid)->whereIn('reference_id', $ids)->count();
        } catch (\Exception $e) {}
        DB::transaction(function () use ($locals) {
            foreach ($locals as $ref) {
                try {
                    $ref->ocrResult()->delete();
                } catch (\Exception $e) {
                    // Abaikan — jangan gagalkan hapus reference.
                }
                $ref->delete();
            }
        });
        foreach ($paths as $p) {
            try {
                Storage::disk('local')->delete($p);
            } catch (\Exception $e) {
                // Best effort — DB sudah konsisten.
            }
        }
        try { Cache::forget("ref:latest:{$uid}"); } catch (\Exception $e) {}

        Log::info('[REF-LIB] delete ok', ['user_id' => $uid, 'fastapi_ref_id' => $refId, 'local_ids' => $ids, 'detached_sessions' => $detachedSessions, 'detached_captures' => $detachedCaptures]);
        $this->promoteLatestActive($uid);
        $this->bumpLibraryVersion(); // daftar library harus langsung segar

        $msgLib = 'Capture reference berhasil dihapus.';
        if ($detachedSessions > 0) {
            $msgLib .= ' ' . $detachedSessions . ' riwayat Check tetap tersimpan (tanpa acuan).';
        }
        return response()->json([
            'success' => true,
            'message' => $msgLib,
            'data'    => ['fastapi_ref_id' => $refId, 'deleted_local_ids' => $ids, 'detached_sessions' => $detachedSessions, 'detached_captures' => $detachedCaptures],
        ], 200);
    }

    /**
     * Naikkan versi cache daftar library agar mutasi langsung terlihat.
     * Best-effort: gagal berarti daftar basi maksimal 60 detik (TTL alami).
     */
    private function bumpLibraryVersion(): void
    {
        try {
            $ver = (int) Cache::get('ref-library:version', 0) + 1;
            Cache::forever('ref-library:version', $ver);
        } catch (\Exception $e) {
            // Abaikan — bukan kegagalan operasi utama.
        }
    }

    /**
     * Promosikan sesi terbaru menjadi aktif bila user masih punya baris
     * tetapi tidak ada yang aktif (dipakai setelah delete). Jaga invarian:
     * ada baris => tepat satu sesi acuan aktif (1-2 baris front/back).
     */
    private function promoteLatestActive(int $uid): void
    {
        try {
            if (!Reference::where('user_id', $uid)->exists()) return;
            if (Reference::where('user_id', $uid)->where('active', true)->exists()) return;
            $latest = Reference::where('user_id', $uid)->orderByDesc('id')->first(['id', 'session_key', 'filename']);
            if (!$latest) return;
            $sk = trim((string) ($latest->session_key ?? ''));
            if ($sk === '') {
                try {
                    [$derived,] = $this->parseSideAndSession((string) ($latest->filename ?? ''), (int) $latest->id);
                    $sk = $derived;
                    Reference::where('user_id', $uid)->where('id', $latest->id)->update(['session_key' => $sk]);
                } catch (\Exception $e) {
                    $sk = 'single-' . $latest->id;
                }
            }
            $this->activateSession($uid, $sk);
            Log::info('[REF] promote active ok (session)', ['user_id' => $uid, 'session_key' => $sk]);
        } catch (\Exception $e) {
            Log::warning('[REF] promote active gagal (diabaikan)', ['user_id' => $uid, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Ringkasan pemakaian reference oleh menu Check (scoped user, indexed).
     * - history_count: jumlah checking_sessions milik user yang memakai reference ini.
     * - recent: session terbaru bila dibuat <10 menit lalu (jendela Check berjalan),
     *   berisi batch_code + seconds_ago. Dipakai sebagai gate ?force=1, bukan blokir permanen.
     * Catatan: checking_sessions.status tidak pernah transisi (selalu pending),
     * jadi jendela waktu adalah sinyal running yang paling andal.
     */
    private function referenceUsage(int $uid, int $referenceId): array
    {
        $historyCount = 0;
        $recent = null;
        try {
            $historyCount = (int) DB::table('checking_sessions')
                ->where('user_id', $uid)
                ->where('reference_id', $referenceId)
                ->count();
            if ($historyCount > 0) {
                $latest = DB::table('checking_sessions')
                    ->where('user_id', $uid)
                    ->where('reference_id', $referenceId)
                    ->orderByDesc('id')
                    ->first(['batch_code', 'created_at']);
                if ($latest && !empty($latest->created_at)) {
                    try {
                        $ts = \Carbon\Carbon::parse($latest->created_at)->timestamp;
                        $ago = time() - $ts;
                        if ($ago >= 0 && $ago < 600) {
                            $recent = [
                                'batch_code'  => $latest->batch_code ?? '-',
                                'seconds_ago' => $ago,
                            ];
                        }
                    } catch (\Exception $e) {
                    }
                }
            }
        } catch (\Exception $e) {
            // Best-effort: gagal hitung dianggap tanpa riwayat agar hapus tidak buntu.
        }
        return ['history_count' => $historyCount, 'recent' => $recent];
    }
}
