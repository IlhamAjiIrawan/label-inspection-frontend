<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CheckCapture;
use App\Models\CheckingSession;
use App\Models\Reference;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class CheckCaptureController extends Controller
{
    /**
     * Store batch 4 label captures at once (radius luas +10mm tol, was 5).
     *
     * POST /api/check/captures
     * Content-Type: multipart/form-data
     * Fields: images[0..n] (1-5 files, was 4-5), reference_id, batch_code (nullable), filenames[0..n] (nullable), slot_numbers[0..n] (nullable)
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function storeBatch(Request $request)
    {
        $validated = $request->validate([
            'images'            => 'required|array|between:1,5',
            'images.*'          => 'required|image|mimes:png,jpg,jpeg|max:5120',
            'reference_id'      => 'required|integer|exists:references,id',
            'batch_code'        => 'nullable|string|max:40|regex:/^[A-Za-z0-9\-_]+$/',
            'filenames'         => 'nullable|array|between:1,5',
            'filenames.*'       => 'nullable|string|max:255',
            'slot_numbers'      => 'nullable|array|between:1,5',
            'slot_numbers.*'    => 'nullable|integer|min:1|max:5',
        ]);

        // Guard acuan aktif: checking wajib punya 1 sesi acuan aktif (flag active)
        if (!Reference::where('user_id', $request->user()->id)->where('active', true)->exists()) {
            return response()->json([
                'success' => false,
                'message' => 'Belum ada Reference acuan aktif. Aktifkan dulu lewat menu Reference → Choose Label Reference → Gunakan.',
            ], 422);
        }

        // Scope reference must belong to auth user (IDOR guard S-05)
        $reference = Reference::where('id', $validated['reference_id'])
            ->where('user_id', $request->user()->id)
            ->first();

        if (!$reference) {
            return response()->json([
                'success' => false,
                'message' => 'Reference tidak ditemukan',
            ], 404);
        }

        // Idempotency: batch_code unique scoped user — if exists return existing (duplicate)
        $batchCode = $validated['batch_code'] ?? null;
        if (!empty($batchCode)) {
            $existing = CheckingSession::where('batch_code', $batchCode)
                ->where('user_id', $request->user()->id)
                ->with(['captures' => function ($q) {
                    $q->orderBy('slot_number');
                }, 'reference'])
                ->first();

            if ($existing) {
                Log::info('[CHECK] batch duplicate — returning existing', [
                    'batch_code' => $batchCode,
                    'session_id' => $existing->id,
                    'user_id'    => $request->user()->id,
                ]);

                return response()->json([
                    'success'   => true,
                    'duplicate' => true,
                    'message'   => 'Batch already exists',
                    'data'      => [
                        'session'  => $existing->toArray(),
                        'captures' => $existing->captures->toArray(),
                    ],
                ], 200);
            }
        } else {
            // Generate unique batch_code server-side: CHK-YYYYMMDD-His-xxxx
            $attempts = 0;
            do {
                $batchCode = 'CHK-' . now()->format('Ymd-His') . '-' . Str::random(4);
                $exists = CheckingSession::where('batch_code', $batchCode)->exists();
                $attempts++;
            } while ($exists && $attempts < 3);
        }

        // Normalize filenames & slot_numbers — ensure N entries ordered (1-5)
        $filenames = $validated['filenames'] ?? [];
        $slotNumbers = $validated['slot_numbers'] ?? [];

        // Fill defaults if arrays not fully provided
        // Use re-indexed arrays to guarantee 0..(n-1) keys
        $images = $request->file('images');
        $images = array_values($images);
        $imageCount = count($images); // 1 (new) sampai 5 (compat lama)

        // Build slot mapping: if slot_numbers provided, use it; else 1..n
        $slots = [];
        for ($i = 0; $i < $imageCount; $i++) {
            $slot = isset($slotNumbers[$i]) && $slotNumbers[$i] !== null ? (int) $slotNumbers[$i] : ($i + 1);
            $slots[$i] = $slot;
        }
        if (count(array_unique($slots)) !== $imageCount) {
            return response()->json([
                'success' => false,
                'message' => 'Slot numbers must be unique 1..' . $imageCount,
                'errors'  => ['slot_numbers' => ['Slot numbers must be unique.']],
            ], 422);
        }
        // Sort by slot to keep deterministic order, but keep file association correct
        $indices = range(0, $imageCount - 1);
        usort($indices, function ($a, $b) use ($slots) {
            return $slots[$a] <=> $slots[$b];
        });

        $session = null;
        $captures = [];

        try {
            DB::transaction(function () use ($request, $reference, $batchCode, $images, $filenames, $slots, $indices, &$session, &$captures, $imageCount) {
                $session = CheckingSession::create([
                    'user_id'         => $request->user()->id,
                    'reference_id'    => $reference->id,
                    'batch_code'      => $batchCode,
                    'template_id'     => $reference->template_id,
                    'template_name'   => $reference->template_name,
                    'template_width'  => $reference->template_width,
                    'template_height' => $reference->template_height,
                    'total_slots'     => $imageCount, // 1 new, compat sampai 5 old
                    'status'          => 'pending',
                ]);

                // Sequential file write — loop sorted indices
                foreach ($indices as $idx) {
                    $file = $images[$idx];
                    $slotNumber = $slots[$idx];

                    // Resolve filename_original via basename sanitization (S-06)
                    $rawOriginal = $filenames[$idx] ?? null;
                    if (!empty($rawOriginal)) {
                        $filenameOriginal = basename($rawOriginal);
                    } else {
                        // Fallback server name
                        $ext = $file->getClientOriginalExtension() ?: 'png';
                        $filenameOriginal = 'check-' . strtolower($reference->template_id) . '-' . now()->format('Ymd-His') . '-slot' . $slotNumber . '.' . $ext;
                    }
                    // Sanitize extension & ensure safe
                    $ext = strtolower($file->getClientOriginalExtension());
                    if (!in_array($ext, ['png', 'jpg', 'jpeg'], true)) {
                        // Fallback from mime
                        $mime = $file->getMimeType();
                        if ($mime === 'image/jpeg') $ext = 'jpg';
                        elseif ($mime === 'image/png') $ext = 'png';
                        else $ext = 'png';
                    }
                    if ($ext === 'jpeg') $ext = 'jpg';

                    // Server stored name unique
                    $storedName = 'check-' . $request->user()->id . '-' . $session->id . '-slot' . $slotNumber . '-' . Str::random(6) . '.' . $ext;
                    $dir = 'check-captures/' . $request->user()->id . '/' . $session->id;
                    $path = $dir . '/' . $storedName;

                    // Read file contents safely
                    $contents = file_get_contents($file->getRealPath());
                    if ($contents === false) {
                        throw new \RuntimeException('Failed to read uploaded file for slot ' . $slotNumber);
                    }

                    // Validate mime real (S-03)
                    $mime = $file->getMimeType() ?: 'image/png';
                    if (!in_array($mime, ['image/png', 'image/jpeg', 'image/jpg'], true)) {
                        // Double check via finfo if needed
                        $finfo = finfo_open(FILEINFO_MIME_TYPE);
                        $detected = finfo_file($finfo, $file->getRealPath());
                        finfo_close($finfo);
                        if (!in_array($detected, ['image/png', 'image/jpeg'], true)) {
                            throw new \RuntimeException('Invalid image mime for slot ' . $slotNumber . ': ' . $mime);
                        }
                        $mime = $detected;
                    }

                    // Get image dimensions — try getimagesize, fallback to 0
                    $resolutionW = 0;
                    $resolutionH = 0;
                    try {
                        $size = @getimagesize($file->getRealPath());
                        if ($size) {
                            $resolutionW = (int) $size[0];
                            $resolutionH = (int) $size[1];
                        }
                    } catch (\Exception $e) {
                        // ignore, keep 0 and let DB still store (but filesize must be valid)
                    }

                    $filesize = (int) $file->getSize();
                    if ($filesize <= 0) {
                        $filesize = strlen($contents);
                    }

                    // Store file — disk local (S-07 private)
                    $stored = Storage::disk('local')->put($path, $contents);
                    if (!$stored) {
                        throw new \RuntimeException('Failed to store image for slot ' . $slotNumber);
                    }

                    // Verify stored
                    if (!Storage::disk('local')->exists($path)) {
                        throw new \RuntimeException('Stored file not found for slot ' . $slotNumber);
                    }

                    $capture = CheckCapture::create([
                        'checking_session_id' => $session->id,
                        'user_id'             => $request->user()->id,
                        'reference_id'        => $reference->id,
                        'slot_number'         => $slotNumber,
                        'image_path'          => $path,
                        'filename_original'   => $filenameOriginal,
                        'filename_stored'     => $storedName,
                        'resolution_w'        => $resolutionW,
                        'resolution_h'        => $resolutionH,
                        'filesize'            => $filesize,
                        'mime_type'           => $mime,
                        'status'              => 'pending',
                    ]);

                    $captures[] = $capture;
                }
            });
        } catch (\Illuminate\Validation\ValidationException $e) {
            throw $e;
        } catch (\Exception $e) {
            Log::error('[CHECK] batch store failed', [
                'user_id'    => $request->user()->id,
                'reference_id' => $validated['reference_id'],
                'batch_code' => $batchCode,
                'error'      => $e->getMessage(),
            ]);

            // Cleanup any stored files if transaction partially wrote before exception (outside transaction files remain)
            if ($session && $session->id) {
                try {
                    $dir = 'check-captures/' . $request->user()->id . '/' . $session->id;
                    Storage::disk('local')->deleteDirectory($dir);
                } catch (\Exception $cleanupEx) {
                    Log::warning('[CHECK] cleanup failed', ['error' => $cleanupEx->getMessage()]);
                }
            }

            return response()->json([
                'success' => false,
                'message' => 'Gagal menyimpan batch. Coba lagi.',
                'error'   => $e->getMessage(),
            ], 500);
        }

        // Sort captures by slot_number for consistent response
        usort($captures, function ($a, $b) {
            return $a->slot_number <=> $b->slot_number;
        });

        Log::info('[CHECK] batch stored', [
            'session_id'   => $session->id,
            'batch_code'   => $batchCode,
            'user_id'      => $request->user()->id,
            'reference_id' => $reference->id,
            'total'        => count($captures),
        ]);

        // --- Forward ke FastAPI external (async Job) — CP-030 ---
        $external = ['status' => 'pending', 'message' => 'Forwarding pending'];
        try {
            // Set queued dulu (kolom baru CP-030)
            try {
                $session->update(['external_status' => 'queued']);
                foreach ($captures as $c) {
                    $c->update(['external_status' => 'queued']);
                }
            } catch (\Exception $e) {
                // Kolom belum ada jika migration belum jalan — ignore, akan diisi Job
                Log::warning('[CHECK] external_status queued update failed (migration?)', ['error' => $e->getMessage()]);
            }

            \App\Jobs\ProcessCheckExternalForwardJob::dispatch($session->id);

            // Jika QUEUE_CONNECTION=sync, job sudah run inline — reload untuk dapat sent/failed
            if (config('queue.default') === 'sync') {
                $session->refresh();
                $session->load(['captures' => function ($q) { $q->orderBy('slot_number'); }]);
                $captures = $session->captures->all();
                $external = [
                    'status'      => $session->external_status ?? 'queued',
                    'saved_count' => $session->external_status === 'sent' ? count($captures) : 0,
                    'paths'       => collect($captures)->pluck('external_path')->filter()->values()->all(),
                    'response'    => $session->external_response,
                    'error'       => $session->external_error,
                    'batch_code'  => $session->batch_code,
                ];
            } else {
                $external = ['status' => 'queued', 'message' => 'Forwarding to external queued', 'batch_code' => $session->batch_code];
            }
        } catch (\Exception $e) {
            Log::warning('[CHECK] dispatch external failed', ['error' => $e->getMessage(), 'session_id' => $session->id]);
            $external = ['status' => 'failed', 'error' => $e->getMessage(), 'batch_code' => $session->batch_code];
            try {
                $session->update(['external_status' => 'failed', 'external_error' => $e->getMessage()]);
                foreach ($captures as $c) {
                    $c->update(['external_status' => 'failed', 'external_error' => $e->getMessage()]);
                }
            } catch (\Exception $ignore) {}
        }

        // Refresh final state for response (if sync already sent)
        try { $session->refresh(); } catch (\Exception $e) {}
        // Rebuild captures array with fresh external fields
        $freshCaptures = CheckCapture::where('checking_session_id', $session->id)->orderBy('slot_number')->get();

        $labelCount = count($freshCaptures);
        return response()->json([
            'success' => true,
            'message' => $labelCount . ' label berhasil disimpan',
            'data'    => [
                'session'  => $session->toArray(),
                'captures' => $freshCaptures->map(function ($c) { return $c->toArray(); })->all(),
                'external' => $external,
            ],
        ], 201);
    }

    /**
     * List checking sessions for authenticated user.
     *
     * GET /api/check/sessions
     */
    public function index(Request $request)
    {
        $perPage = (int) $request->query('per_page', 10);
        $perPage = max(1, min(50, $perPage));

        $sessions = CheckingSession::withCount('captures')
            ->with('reference')
            ->where('user_id', $request->user()->id)
            ->latest()
            ->paginate($perPage);

        // Optionally filter by reference_id
        if ($request->has('reference_id')) {
            $refId = (int) $request->query('reference_id');
            $sessions = CheckingSession::withCount('captures')
                ->with('reference')
                ->where('user_id', $request->user()->id)
                ->where('reference_id', $refId)
                ->latest()
                ->paginate($perPage);
        }

        return response()->json([
            'success' => true,
            'data'    => $sessions->items(),
            'meta'    => [
                'current_page' => $sessions->currentPage(),
                'per_page'     => $sessions->perPage(),
                'total'        => $sessions->total(),
                'last_page'    => $sessions->lastPage(),
            ],
        ], 200);
    }

    /**
     * Show single checking session with its captures (1 new, compat 4-5).
     *
     * GET /api/check/sessions/{session}
     */
    public function show(Request $request, $id)
    {
        $session = CheckingSession::with(['captures' => function ($q) {
            $q->orderBy('slot_number');
        }, 'reference', 'reference.ocrResult'])
            ->where('user_id', $request->user()->id)
            ->find($id);

        if (!$session) {
            return response()->json([
                'success' => false,
                'message' => 'Checking session tidak ditemukan',
            ], 404);
        }

        // Merge compare batch dari Cache (tanpa migration) agar polling Browser baca is_match
        $batch = $session->batch_code;
        $compareBatch = Cache::get('check:compare:batch:' . $batch);
        if ($compareBatch && isset($compareBatch['results']) && is_array($compareBatch['results'])) {
            $bySlot = collect($compareBatch['results'])->keyBy('slot');
            $capsArray = $session->captures->map(function ($c) use ($bySlot, $batch) {
                $arr = $c->toArray();
                $r = $bySlot->get($c->slot_number);
                if ($r) {
                    $arr['is_match'] = $r['is_match'] ?? null;
                    $arr['confidence_score'] = $r['confidence_score'] ?? null;
                    $arr['compare_notes'] = $r['notes'] ?? null;
                    $arr['notes'] = $r['notes'] ?? null;
                    $arr['compare_file_name'] = $r['file_name'] ?? null;
                    $arr['compare_result'] = $r;
                }
                // COMPARE_TEXT_SIMILARITY_PLAN (2026-09-12): merge similarity dari cache slot.
                // REVIEW_FLOW_COMPARE_FRONT_BACK_PLAN (2026-10-05): + side per sisi.
                // FIX_WRONG_REFERENCE (2026-10-07): + ref_source diagnostik.
                // PLAN_GEMINI_API_TEXT_COMPARE (2026-10-07): similarity kini
                // membawa compare_engine/gemini_diffs/critical/minor (aditif,
                // diteruskan apa adanya — kolom DB audit ikut via toArray()).
                $sim = Cache::get('check:ocr:result:' . $batch . ':' . $c->slot_number);
                if (is_array($sim)) {
                    if (isset($sim['similarity'])) {
                        $arr['similarity'] = $sim['similarity'];
                    }
                    // PLAN_COMPARE_PARAGRAPH_GLOBAL (2026-10-07): teruskan blok kata.
                    if (array_key_exists('perbandingan', $sim)) {
                        $arr['perbandingan'] = $sim['perbandingan'];
                    }
                    $arr['side'] = $sim['side'] ?? 'unknown';
                    $arr['ref_source'] = $sim['ref_source'] ?? null;
                    $arr['reference_excerpt'] = $sim['reference_excerpt'] ?? null;
                    // PROGRESSIVE (2026-10-09): teruskan event/progress/is_final
                    // agar check.js render progres hidup per slot (1/4 → 4/4).
                    if (array_key_exists('event', $sim)) {
                        $arr['event'] = $sim['event'];
                    }
                    if (array_key_exists('progress', $sim)) {
                        $arr['progress'] = $sim['progress'];
                    }
                    if (array_key_exists('is_final', $sim)) {
                        $arr['is_final'] = $sim['is_final'];
                    }
                }
                return $arr;
            })->all();
            $sessionArray = $session->toArray();
            $sessionArray['compare_batch'] = $compareBatch;
            // PROGRESSIVE (2026-10-09): ringkasan akhir batch (event batch_completed).
            $ocrBatchSummary = Cache::get('check:ocr:batch_summary:' . $batch);
            if (is_array($ocrBatchSummary)) {
                $sessionArray['ocr_batch_summary'] = $ocrBatchSummary;
            }
            return response()->json([
                'success' => true,
                'data'    => [
                    'session'   => $sessionArray,
                    'captures'  => $capsArray,
                    'reference' => $session->reference ? array_merge($session->reference->toArray(), ['ocr_result' => $session->reference->ocrResult ? $session->reference->ocrResult->toArray() : null]) : null,
                    'compare_batch' => $compareBatch,
                    'ocr_batch_summary' => $ocrBatchSummary,
                ],
            ], 200);
        }

        // Fallback: inject alias FLAT agar kontrak cocok dengan check.js polling
        // (c.is_match / c.confidence_score / c.notes). Alias hanya dipasang bila
        // kolom compare_* NON-NULL — key absen = data belum ada, sehingga kondisi
        // hasNew di browser tidak terpicu dini oleh null (bug undefined 2026-09-10).
        $capsArray = $session->captures->map(function ($c) use ($session) {
            $arr = $c->toArray();
            if ($c->compare_match !== null) {
                $arr['is_match'] = (bool) $c->compare_match;
            }
            if ($c->compare_confidence !== null) {
                $arr['confidence_score'] = (float) $c->compare_confidence;
            }
            if ($c->compare_notes !== null) {
                $arr['notes'] = $c->compare_notes;
            }
            // COMPARE_TEXT_SIMILARITY_PLAN (2026-09-12): merge similarity dari cache slot.
            // REVIEW_FLOW_COMPARE_FRONT_BACK_PLAN (2026-10-05): + side per sisi.
            // FIX_WRONG_REFERENCE (2026-10-07): + ref_source diagnostik.
            // PLAN_GEMINI_API_TEXT_COMPARE (2026-10-07): similarity kini
            // membawa compare_engine/gemini_diffs/critical/minor (aditif).
            $sim = Cache::get('check:ocr:result:' . $session->batch_code . ':' . $c->slot_number);
            if (is_array($sim)) {
                if (isset($sim['similarity'])) {
                    $arr['similarity'] = $sim['similarity'];
                }
                // PLAN_COMPARE_PARAGRAPH_GLOBAL (2026-10-07): teruskan blok kata.
                if (array_key_exists('perbandingan', $sim)) {
                    $arr['perbandingan'] = $sim['perbandingan'];
                }
                $arr['side'] = $sim['side'] ?? 'unknown';
                $arr['ref_source'] = $sim['ref_source'] ?? null;
                $arr['reference_excerpt'] = $sim['reference_excerpt'] ?? null;
                // PROGRESSIVE (2026-10-09): teruskan event/progress/is_final.
                if (array_key_exists('event', $sim)) {
                    $arr['event'] = $sim['event'];
                }
                if (array_key_exists('progress', $sim)) {
                    $arr['progress'] = $sim['progress'];
                }
                if (array_key_exists('is_final', $sim)) {
                    $arr['is_final'] = $sim['is_final'];
                }
            }
            return $arr;
        })->all();

        // PROGRESSIVE (2026-10-09): ringkasan akhir batch untuk polling browser.
        $ocrBatchSummary = Cache::get('check:ocr:batch_summary:' . $session->batch_code);
        $sessionArray = $session->toArray();
        if (is_array($ocrBatchSummary)) {
            $sessionArray['ocr_batch_summary'] = $ocrBatchSummary;
        }

        return response()->json([
            'success' => true,
            'data'    => [
                'session'   => $sessionArray,
                'captures'  => $capsArray,
                'reference' => $session->reference ? array_merge($session->reference->toArray(), ['ocr_result' => $session->reference->ocrResult ? $session->reference->ocrResult->toArray() : null]) : null,
                'ocr_batch_summary' => $ocrBatchSummary,
            ],
        ], 200);
    }

    /**
     * Trigger OCR for 4 labels batch → FastAPI OCR → callback console log (was 5, compat 4-5).
     *
     * POST /api/check/sessions/{session}/process
     * Fase console log: hanya queue Job, callback 1× (compat sampai 5×) akan Log::info [CHECK CALLBACK] di pail.
     */
    public function process(Request $request, $id)
    {
        $session = CheckingSession::with(['captures' => function ($q) {
            $q->orderBy('slot_number');
        }])
            ->where('user_id', $request->user()->id)
            ->find($id);

        if (!$session) {
            return response()->json([
                'success' => false,
                'message' => 'Checking session tidak ditemukan',
            ], 404);
        }

        // Guard acuan aktif: proses hanya jalan bila 1 sesi acuan aktif (flag active)
        if (!Reference::where('user_id', $request->user()->id)->where('active', true)->exists()) {
            return response()->json([
                'success' => false,
                'message' => 'Belum ada Reference acuan aktif. Aktifkan dulu lewat menu Reference → Choose Label Reference → Gunakan.',
            ], 422);
        }

        $captureCount = $session->captures ? $session->captures->count() : 0;
        if (empty($session->captures) || !in_array($captureCount, [1,4,5], true)) {
            return response()->json([
                'success' => false,
                'message' => 'Batch harus 1 label (compat 4-5)',
            ], 422);
        }

        if ($session->external_status !== 'sent') {
            return response()->json([
                'success' => false,
                'message' => 'Gambar belum tersimpan di FastAPI (external_status=' . $session->external_status . ') — tunggu atau Retry',
            ], 422);
        }

        // Idempotency: dedup 120s via Cache
        $cacheKey = 'check:ocr:processing:' . $session->id;
        if (Cache::has($cacheKey)) {
            return response()->json([
                'success' => true,
                'message' => 'OCR already queued — cek console log',
                'data'    => [
                    'session'    => $session->toArray(),
                    'batch_code' => $session->batch_code,
                    'status'     => 'processing',
                ],
            ], 200);
        }

        try {
            \App\Jobs\ProcessCheckOcrJob::dispatch($session->id);
            Cache::put($cacheKey, true, 120);

            Log::info('[CHECK] Process queued', [
                'session_id' => $session->id,
                'batch_code' => $session->batch_code,
                'user_id'    => $request->user()->id,
            ]);

            $cnt = $session->captures->count();
            // Jika sync, ambil Cache batch yang baru diisi Job (untuk console.log langsung)
            $fastapiResult = null;
            if (config('queue.default') === 'sync') {
                $fastapiResult = Cache::get('check:compare:batch:' . $session->batch_code);
            }

            return response()->json([
                'success' => true,
                'message' => 'OCR queued — ' . $cnt . ' labels processing, cek console log (php artisan pail --filter=CHECK)',
                'data'    => [
                    'session'    => $session->fresh()->toArray(),
                    'batch_code' => $session->batch_code,
                    'status'     => 'processing',
                ],
                'fastapi_result' => $fastapiResult,
            ], 200);
        } catch (\Exception $e) {
            Log::error('[CHECK] Process dispatch failed', ['session_id' => $id, 'error' => $e->getMessage()]);

            return response()->json([
                'success' => false,
                'message' => 'Gagal queue OCR',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Retry forward batch to external FastAPI.
     *
     * POST /api/check/sessions/{session}/retry-external
     */
    public function retryExternal(Request $request, $id)
    {
        $session = CheckingSession::where('user_id', $request->user()->id)->find($id);

        if (!$session) {
            return response()->json([
                'success' => false,
                'message' => 'Checking session tidak ditemukan',
            ], 404);
        }

        if ($session->external_status === 'sent') {
            return response()->json([
                'success' => true,
                'message' => 'Already sent to external',
                'data'    => ['session' => $session->toArray()],
            ], 200);
        }

        try {
            \App\Jobs\ProcessCheckExternalForwardJob::dispatch($session->id);
            $session->update(['external_status' => 'queued', 'external_error' => null]);

            return response()->json([
                'success' => true,
                'message' => 'Retry queued',
                'data'    => ['session' => $session->fresh()->toArray()],
            ], 200);
        } catch (\Exception $e) {
            Log::error('[CHECK] retry dispatch failed', ['session_id' => $id, 'error' => $e->getMessage()]);

            return response()->json([
                'success' => false,
                'message' => 'Gagal queue retry',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Compare similarity Front-vs-Front & Back-vs-Back (4 pairs) — Phase 1 stub.
     *
     * POST /api/check/sessions/{session}/compare-similarity
     * Content-Type: application/json
     * Auth: sanctum
     */
    public function compareSimilarity(Request $request, $id)
    {
        $session = CheckingSession::where('user_id', $request->user()->id)->find($id);

        if (!$session) {
            return response()->json([
                'success' => false,
                'message' => 'Checking session tidak ditemukan',
            ], 404);
        }

        $validated = $request->validate([
            'batch_code' => 'nullable|string|max:40',
            'pairs'      => 'nullable|array|max:4',
            'pairs.*.side' => 'nullable|in:front,back',
            'pairs.*.idx'  => 'nullable|integer|min:1|max:2',
            'threshold'  => 'nullable|numeric|min:0|max:1',
            'weights'    => 'nullable|array',
        ]);

        $batchCode = $validated['batch_code'] ?? $session->batch_code;

        // Build 4 pairs default (F1,F2,B1,B2) jika tidak dikirim
        $pairsIn = $validated['pairs'] ?? [];
        $pairsOut = [];
        $sidesIdx = [
            ['side'=>'front','idx'=>1],
            ['side'=>'front','idx'=>2],
            ['side'=>'back','idx'=>1],
            ['side'=>'back','idx'=>2],
        ];

        foreach ($sidesIdx as $def) {
            $p = null;
            foreach ($pairsIn as $pi) {
                if (($pi['side'] ?? null) === $def['side'] && ($pi['idx'] ?? null) === $def['idx']) {
                    $p = $pi; break;
                }
            }
            $side = $def['side'];
            $idx  = $def['idx'];
            // Stub verdict deterministik ringan (PASS) — Phase 1, non-breaking
            $sim = 0.93;
            $verdict = 'PASS';
            $pairsOut[] = [
                'side'           => $side,
                'idx'            => $idx,
                'ref_id'         => $p['ref_id'] ?? null,
                'prod_filename'  => $p['prod_filename'] ?? null,
                'similarity'     => round($sim, 3),
                'verdict'        => $verdict,
                'text_sim'       => 0.96,
                'layout_sim'     => 0.88,
                'image_sim'      => 0.90,
                'reason'         => 'stub_phase1',
            ];
        }

        $pass = collect($pairsOut)->where('verdict','PASS')->count();
        $fail = collect($pairsOut)->where('verdict','FAIL')->count();
        $skip = collect($pairsOut)->where('verdict','SKIP')->count();
        $err  = collect($pairsOut)->where('verdict','ERROR')->count();

        Log::info('[CHECK] compare-similarity stub', [
            'session_id' => $session->id,
            'batch_code' => $batchCode,
            'pairs'      => count($pairsOut),
            'verdicts'   => ['pass'=>$pass,'fail'=>$fail,'skip'=>$skip,'error'=>$err],
        ]);

        return response()->json([
            'success'       => true,
            'batch_code'    => $batchCode,
            'compare_status'=> 'completed',
            'pairs'         => $pairsOut,
            'elapsed_ms'    => 120,
            'summary'       => ['total'=>count($pairsOut),'pass'=>$pass,'fail'=>$fail,'skip'=>$skip,'error'=>$err],
        ], 200);
    }
}
