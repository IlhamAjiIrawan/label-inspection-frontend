<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CheckCaptureController;
use App\Http\Controllers\Api\CheckSplitOcrController;
use App\Http\Controllers\Api\CheckCompareCallbackController;
use App\Http\Controllers\Api\CheckOcrCallbackController;
use App\Http\Controllers\Api\LabelCaptureController;
use App\Http\Controllers\Api\OcrCallbackController;
use App\Http\Controllers\Api\ReferenceController;
use App\Http\Controllers\Api\TestSignalController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| is assigned the "api" middleware group. Enjoy building your API!
|
*/

Route::middleware(['throttle:login'])->group(function () {
    Route::post('/login', [AuthController::class, 'login']);
});

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/user', function (Request $request) {
        return $request->user();
    });

    Route::get('/references', [ReferenceController::class, 'index'])->middleware('throttle:30,1');
    Route::get('/references/latest', [ReferenceController::class, 'latest'])->middleware('throttle:30,1');
    Route::get('/references/session/{session_key}', [ReferenceController::class, 'session'])->middleware('throttle:30,1');
    Route::delete('/references/session/{session_key}', [ReferenceController::class, 'destroySession'])->middleware('throttle:15,1');
    // Library Global FastAPI — WAJIB sebelum /references/{reference} agar tidak tertangkap sebagai {reference}=library
    Route::get('/references-library', [ReferenceController::class, 'library'])->middleware('throttle:30,1');
    Route::get('/references-library/health', [ReferenceController::class, 'libraryHealth'])->middleware('throttle:30,1');
    Route::post('/references/import', [ReferenceController::class, 'importFromLibrary'])->middleware('throttle:15,1');
    Route::post('/references/detect-regions', [ReferenceController::class, 'detectRegions'])->middleware('throttle:30,1');
    Route::post('/references/batch', [ReferenceController::class, 'storeBatch'])->middleware('throttle:10,1');
    Route::post('/references/activate', [ReferenceController::class, 'activate'])->middleware('throttle:15,1');
    Route::delete('/references-library/{ref_id}', [ReferenceController::class, 'destroyLibrary'])->middleware('throttle:15,1');
    Route::get('/references/{reference}/image', [ReferenceController::class, 'image'])->middleware('throttle:30,1');
    Route::get('/references/{reference}', [ReferenceController::class, 'show'])->middleware('throttle:30,1');
    Route::patch('/references/{reference}', [ReferenceController::class, 'update'])->middleware('throttle:15,1');
    Route::delete('/references/{reference}', [ReferenceController::class, 'destroy'])->middleware('throttle:15,1');
    Route::post('/references', [ReferenceController::class, 'store']);

    // Auto OCR preview — dipanggil otomatis setelah Capture (tanpa simpan DB)
    Route::post('/ocr/preview', [TestSignalController::class, 'preview'])
        ->middleware('throttle:30,1');

    // CP-016: Retake cleanup — hapus pending OCR + file untuk filename lama (single flight, anti stale)
    Route::post('/ocr/retake-cleanup', [TestSignalController::class, 'retakeCleanup'])
        ->middleware('throttle:15,1');

    // Checking split OCR per Label (Label 1 & Label 2 per sisi) — single flow FastAPI.
    // FIX 429 (2026-10-08): named limiter per-route agar polling status
    // tidak memakan kuota preview. Definisi di RouteServiceProvider.
    Route::post('/check/split-preview', [CheckSplitOcrController::class, 'preview'])
        ->middleware('throttle:split-preview');
    Route::get('/check/split-ocr-status', [CheckSplitOcrController::class, 'status'])
        ->middleware('throttle:split-status');
    Route::post('/check/split-retake-cleanup', [CheckSplitOcrController::class, 'retakeCleanup'])
        ->middleware('throttle:15,1');
    Route::post('/ocr/cleanup-stale', [TestSignalController::class, 'cleanupStale'])
        ->middleware('throttle:5,1');

    Route::post('/test', [TestSignalController::class, 'send']);
    // Fallback PULL — cek OCR via DB atau external (hybrid webhook fallback)
    Route::get('/test/ocr-status', [TestSignalController::class, 'ocrStatus'])
        ->middleware('throttle:30,1');
    Route::get('/ocr/{filename}', [TestSignalController::class, 'show'])
        ->middleware('throttle:30,1');

    Route::get('/captures', [LabelCaptureController::class, 'index']);
    Route::post('/captures/fetch', [LabelCaptureController::class, 'fetch']);
    Route::post('/captures/{capture}/retry', [LabelCaptureController::class, 'retry']);

    // Check batch upload — 5 label sekaligus ke DB (siap FastAPI OCR)
    Route::post('/check/captures', [CheckCaptureController::class, 'storeBatch'])->middleware('throttle:30,1');
    Route::get('/check/sessions', [CheckCaptureController::class, 'index'])->middleware('throttle:30,1');
    Route::get('/check/sessions/{session}', [CheckCaptureController::class, 'show'])->middleware('throttle:sessions-show');
    Route::post('/check/sessions/{session}/retry-external', [CheckCaptureController::class, 'retryExternal'])->middleware('throttle:10,1');
    // Process 5 labels → FastAPI OCR → callback 5× console log (CP-034)
    Route::post('/check/sessions/{session}/process', [CheckCaptureController::class, 'process'])->middleware('throttle:10,1');
    // Compare similarity Front-vs-Front & Back-vs-Back (4 pairs) — Phase 1 stub (auth:sanctum)
    Route::post('/check/sessions/{session}/compare-similarity', [CheckCaptureController::class, 'compareSimilarity'])->middleware('throttle:10,1');
    // Visual Compare Mode — hasil compare per batch untuk polling browser/web (sementara)
    Route::get('/check/sessions/{session}/compare-result', [CheckCompareCallbackController::class, 'show'])->middleware('throttle:30,1');
});

// PUBLIC webhook — FastAPI PUSH (tidak pakai sanctum, verifikasi HMAC di controller)
Route::post('/ocr/callback', [OcrCallbackController::class, 'handle'])
    ->middleware('throttle:60,1')
    ->name('ocr.callback');

// PUBLIC webhook — FastAPI Check OCR per label PUSH (5 POST per batch, retain 1 batch)
// Tidak pakai sanctum, verifikasi X-API-Key + HMAC di controller. Console log dulu, DB opsional fase 2.
Route::post('/check/ocr/callback', [CheckOcrCallbackController::class, 'store'])
    ->middleware('throttle:60,1')
    ->name('check.ocr.callback');

// PUBLIC webhook — FastAPI Visual Compare PUSH (1 POST per batch)
// Tidak pakai sanctum, verifikasi X-API-Key + HMAC di controller.
// Console log (pail --filter=COMPARE) + persist DB + cache untuk polling web.
Route::post('/check/compare/callback', [CheckCompareCallbackController::class, 'store'])
    ->middleware('throttle:60,1')
    ->name('check.compare.callback');


