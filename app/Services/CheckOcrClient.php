<?php

namespace App\Services;

use App\Models\CheckingSession;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Client to trigger OCR batch 1-5 labels on external FastAPI (was 4-5).
 * Primary: JSON {batch_code, session_id, reference_id, template_id} — hemat 1KB.
 * Reuse pattern from CheckExternalClient (Guzzle + X-API-Key).
 */
class CheckOcrClient
{
    protected $ocrUrl;
    protected $timeout;
    protected $apiKey;
    protected $callbackUrl;
    protected $client;

    public function __construct()
    {
        $this->ocrUrl = config('services.check_ocr.ocr_url', 'http://127.0.0.1:9000/api/check/ocr/process');
        $this->timeout = (int) config('services.check_ocr.timeout', 15);
        $this->apiKey = config('services.check_ocr.api_key') ?? config('services.check_external.api_key') ?? config('services.ocr.callback_api_key', 'label-inspection-2026-key');
        $this->callbackUrl = config('services.check_ocr.callback_url', 'http://127.0.0.1:8000/api/check/ocr/callback');
        $this->client = new Client([
            'timeout'         => $this->timeout,
            'connect_timeout' => 5,
            'http_errors'     => false,
        ]);
    }

    protected function headers()
    {
        $h = [
            'Accept'       => 'application/json',
            'Content-Type' => 'application/json',
        ];
        if (!empty($this->apiKey)) {
            $h['X-API-Key'] = $this->apiKey;
        }
        return $h;
    }

    /**
     * Trigger OCR batch 1-5 via JSON {batch_code} primary (was 4-5).
     *
     * @param  \App\Models\CheckingSession  $session (must have captures loaded)
     * @return array{success: bool, status: string, response?: array, error?: string, http_code?: int}
     */
    public function requestOcr(CheckingSession $session): array
    {
        $session->loadMissing('captures', 'reference', 'reference.ocrResult');

        if (!in_array($session->captures->count(), [1,4,5], true)) {
            return ['success' => false, 'status' => 'failed', 'error' => 'Captures count != 1 (compat 4-5) — batch tidak lengkap'];
        }

        // Urutkan slot agar pluck konsisten (perilaku lama dipertahankan).
        $captures = $session->captures->sortBy('slot_number')->values();

        // COMPARE_TEXT_SIMILARITY_PLAN (2026-09-12): kirim teks reference ke FastAPI
        // agar verdict PASS/FAIL dihitung di sana (difflib, tanpa HTTP tambahan).
        // Source of truth = reference_ocr_results.cleaned_text. null aman → SKIP.
        // REVIEW_FLOW_COMPARE_FRONT_BACK_PLAN (2026-10-05) TAHAP L3: kirim per sisi.
        $refOcr      = $session->reference ? $session->reference->ocrResult : null;
        $referenceText     = $refOcr ? $refOcr->cleaned_text : null;
        $referenceFilename = $refOcr ? $refOcr->ocr_filename : null;
        // BARU per sisi (accessor front fallback ke cleaned_text bila kolom baru null).
        $referenceTextFront = $refOcr ? $refOcr->cleaned_text_front : null;
        $referenceTextBack  = $refOcr ? $refOcr->cleaned_text_back : null;

        // FIX_REFERENCE_OCR_ACUAN_PER_LABEL (2026-10-07) L2: lengkapi sisi yang
        // hilang dari saudara 1 sesi (pair sibling). Tanpa ini, sesi 2 baris
        // (front+back) selalu mengirim back=null → FastAPI fallback ke teks
        // FRONT untuk semua slot → Label 1 BACK + semua Label 2 FAIL.
        // Lokal DB adalah source of truth (fresh dari callback), tanpa HTTP tambahan.
        if (($referenceTextFront === null || $referenceTextBack === null) && $session->reference) {
            try {
                $pairTexts = self::resolvePairTexts($session);
                if ($referenceTextFront === null && $pairTexts['front'] !== null) {
                    $referenceTextFront = $pairTexts['front'];
                }
                if ($referenceTextBack === null && $pairTexts['back'] !== null) {
                    $referenceTextBack = $pairTexts['back'];
                }
                // Backward compat: tunggal = front bila ada, else teks lama.
                if ($referenceText === null || $referenceText === '') {
                    $referenceText = $referenceTextFront ?? $referenceText;
                }
            } catch (\Exception $e) {
                Log::warning('[CHECK OCR] resolvePairTexts gagal — pakai acuan tunggal', [
                    'batch_code' => $session->batch_code,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // FIX_WRONG_REFERENCE (2026-10-07): peta acuan per slot untuk batch multi-label.
        // Slot check 1..4 = front1/front2/back1/back2 (konvensi check.js + split controller).
        // Map hanya dikirim bila korespondensi 1:1 terjamin struktural (4 slot + 4 acuan
        // non-kosong); selain itu backend fallback seperti biasa (tunggal/sisi).
        $referenceTexts = self::buildReferenceTextsMap($session, $captures->count());

        $payload = [
            'batch_code'   => $session->batch_code,
            'session_id'   => $session->id,
            'reference_id' => $session->reference_id,
            'template_id'  => (string) $session->template_id,
            'user_id'      => $session->user_id,
            'timestamp'    => now()->toIso8601String(),
            'callback_url' => $this->callbackUrl,
            'slot_numbers' => $captures->pluck('slot_number')->all(),
            'filenames'    => $captures->pluck('filename_original')->all(),
            // COMPARE_TEXT_SIMILARITY_PLAN (2026-09-12)
            'reference_text'     => $referenceText,
            'reference_filename' => $referenceFilename,
            // REVIEW_FLOW_COMPARE_FRONT_BACK_PLAN (2026-10-05): per sisi.
            // Backward compat: reference_text lama tetap dikirim (FastAPI fallback).
            'reference_text_front' => $referenceTextFront,
            'reference_text_back'  => $referenceTextBack,
        ];

        // FIX_WRONG_REFERENCE (2026-10-07): map per slot (FastAPI prioritas tertinggi).
        // Dihilangkan bila null/kosong agar payload lama byte-identik.
        if (!empty($referenceTexts)) {
            $payload['reference_texts'] = $referenceTexts;
        }

        Log::info('[CHECK] OCR request POST', [
            'batch_code' => $session->batch_code,
            'session_id' => $session->id,
            'url'        => $this->ocrUrl,
            'slots'      => $captures->count(),
            // COMPARE_TEXT_SIMILARITY_PLAN (2026-09-12): ringkas saja (bukan teks penuh)
            'ref_present' => !empty($referenceText),
            'ref_lines'   => $referenceText ? substr_count((string) $referenceText, "\n") + 1 : 0,
            'ref_excerpt' => $referenceText ? mb_substr((string) $referenceText, 0, 120) : null,
            // REVIEW_FLOW_COMPARE_FRONT_BACK_PLAN (2026-10-05): panjang per sisi.
            // FIX_REFERENCE_OCR_ACUAN_PER_LABEL (2026-10-07) L3: + head per sisi
            // untuk verifikasi front != back di log.
            'ref_front_len'  => strlen((string) ($referenceTextFront ?? '')),
            'ref_back_len'   => strlen((string) ($referenceTextBack ?? '')),
            'ref_single_len' => strlen((string) ($referenceText ?? '')),
            'ref_front_head' => $referenceTextFront ? mb_substr((string) $referenceTextFront, 0, 80) : null,
            'ref_back_head'  => $referenceTextBack ? mb_substr((string) $referenceTextBack, 0, 80) : null,
            // FIX_WRONG_REFERENCE (2026-10-07): ringkas map per slot (bukan teks penuh).
            'ref_texts_slots' => $referenceTexts ? array_keys($referenceTexts) : [],
            'ref_texts_lens'  => $referenceTexts ? array_map('strlen', array_values($referenceTexts)) : [],
        ]);

        try {
            $response = $this->client->post($this->ocrUrl, [
                'headers' => $this->headers(),
                'json'    => $payload,
            ]);

            $httpCode = $response->getStatusCode();
            $bodyRaw  = $response->getBody()->getContents();
            $body     = json_decode($bodyRaw, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                $body = ['raw' => $bodyRaw];
            }

            Log::info('[CHECK] OCR request response', [
                'batch_code' => $session->batch_code,
                'http_code'  => $httpCode,
                'response'   => $body,
            ]);

            // Cache batch JSON untuk polling browser (tanpa migration) — FastAPI baru kirim results[is_match]
            if (isset($body['results']) && is_array($body['results'])) {
                $cacheBatch = 'check:compare:batch:' . $session->batch_code;
                Cache::put($cacheBatch, $body, 300);
                foreach ($body['results'] as $r) {
                    $slot = $r['slot'] ?? null;
                    if ($slot) {
                        Cache::put('check:compare:slot:' . $session->batch_code . ':' . $slot, $r, 300);
                        Log::info('[CHECK] Compare slot cached', ['batch' => $session->batch_code, 'slot' => $slot, 'is_match' => $r['is_match'] ?? null, 'confidence' => $r['confidence_score'] ?? null]);
                    }
                }
                Log::info('[CHECK] Compare batch cached', ['batch' => $session->batch_code, 'total_checked' => $body['total_checked'] ?? count($body['results']), 'model' => $body['model'] ?? null]);
            }
            if (isset($body['results'])) {
                Log::info('[CHECK] Compare results table', ['batch' => $session->batch_code, 'results' => $body['results']]);
            }

            if ($httpCode >= 200 && $httpCode < 300) {
                $isSuccess = true;
                if (isset($body['success']) && $body['success'] === false) {
                    $isSuccess = false;
                }
                if ($isSuccess) {
                    return ['success' => true, 'status' => 'processing', 'response' => $body, 'http_code' => $httpCode];
                }
                return ['success' => false, 'status' => 'failed', 'response' => $body, 'error' => $body['message'] ?? 'External returned success=false', 'http_code' => $httpCode];
            }

            $err = $body['message'] ?? $body['detail'] ?? "HTTP $httpCode";
            if ($httpCode === 404 && stripos(strtolower($err), 'not found') !== false) {
                Log::warning('[CHECK] OCR 404 files not found — maybe FastAPI folder missing, fallback multipart not yet implemented', [
                    'batch_code' => $session->batch_code,
                ]);
            }
            if ($httpCode === 422) {
                $err = 'Validation failed: ' . json_encode($body);
            }
            if ($httpCode === 401) {
                $err = 'Invalid API key for OCR service';
            }

            return ['success' => false, 'status' => 'failed', 'response' => $body, 'error' => $err, 'http_code' => $httpCode];
        } catch (ConnectException $e) {
            Log::error('[CHECK] OCR ConnectException', [
                'batch_code' => $session->batch_code,
                'error'      => $e->getMessage(),
            ]);
            return ['success' => false, 'status' => 'failed', 'error' => 'Gagal terhubung ke eksternal OCR: ' . $e->getMessage()];
        } catch (RequestException $e) {
            $msg = $e->getMessage();
            if ($e->hasResponse()) {
                $msg .= ' | ' . $e->getResponse()->getBody()->getContents();
            }
            Log::error('[CHECK] OCR RequestException', [
                'batch_code' => $session->batch_code,
                'error'      => $msg,
            ]);
            return ['success' => false, 'status' => 'failed', 'error' => $msg];
        } catch (\Exception $e) {
            Log::error('[CHECK] OCR Exception', [
                'batch_code' => $session->batch_code,
                'error'      => $e->getMessage(),
            ]);
            return ['success' => false, 'status' => 'failed', 'error' => 'Gagal terhubung ke eksternal OCR: ' . $e->getMessage()];
        }
    }

    /**
     * Ambil teks acuan FRONT dan BACK dari sepasang reference lokal (pair sibling).
     *
     * FIX_REFERENCE_OCR_ACUAN_PER_LABEL (2026-10-07) L2. Strategi:
     * 1. Baris reference utama sesi ($session->reference): ambil teks sesuai
     *    kolom `side`-nya (front → front, back → back, single → front).
     * 2. Cari saudara 1 sesi (user_id + session_key sama, id berbeda):
     *    tiap saudara menyumbang teks sesuai `side`-nya.
     * 3. Prefer kolom per-sisi (cleaned_text_front/back accessor), fallback
     *    ke cleaned_text tunggal bila kolom baru masih null.
     *
     * @param  \App\Models\CheckingSession  $session (reference relation tersedia)
     * @return array{front: string|null, back: string|null}
     */
    public static function resolvePairTexts($session): array
    {
        $frontText = null;
        $backText = null;

        $ref = $session->reference ?? null;
        if (!$ref) {
            return ['front' => null, 'back' => null];
        }

        $collect = function ($row) {
            $ocr = null;
            try {
                $ocr = $row->ocrResult;
            } catch (\Exception $e) {
                $ocr = null;
            }
            if (!$ocr) {
                return [null, null];
            }
            // Accessor front sudah fallback ke cleaned_text; back null bila kosong.
            $f = $ocr->cleaned_text_front ?? null;
            $b = $ocr->cleaned_text_back ?? null;
            if (($f === null || $f === '') && !empty($ocr->cleaned_text)) {
                $f = $ocr->cleaned_text;
            }
            $f = is_string($f) && trim($f) !== '' ? $f : null;
            $b = is_string($b) && trim($b) !== '' ? $b : null;
            return [$f, $b, $ocr];
        };

        // 1. Baris utama: hormati kolom side agar baris BACK tidak disangka FRONT.
        try {
            $mainSide = strtolower(trim((string) ($ref->side ?? '')));
            [$mf, $mb, $mocr] = $collect($ref);
            if ($mainSide === 'back') {
                $backText = $mb ?? ($mocr ? ($mocr->cleaned_text ?? null) : null);
                if (is_string($backText) && trim($backText) === '') {
                    $backText = null;
                }
                // Baris back kadang menyimpan front juga (kolom baru) — ambil bila ada.
                if ($frontText === null && $mf !== null) {
                    $frontText = $mf;
                }
            } else {
                // front / single / kosong = front (backward compat, sama seperti FastAPI).
                $frontText = $mf;
                if ($backText === null && $mb !== null) {
                    $backText = $mb;
                }
            }
        } catch (\Exception $e) {
            // Lanjut ke sibling lookup.
        }

        // 2. Saudara 1 sesi: isi sisi yang masih kosong.
        try {
            $sk = trim((string) ($ref->session_key ?? ''));
            if ($sk !== '') {
                $siblings = \App\Models\Reference::where('user_id', $session->user_id)
                    ->where('session_key', $sk)
                    ->where('id', '!=', $ref->id)
                    ->with('ocrResult')
                    ->get();
                foreach ($siblings as $sib) {
                    $sibSide = strtolower(trim((string) ($sib->side ?? '')));
                    [$sf, $sb, $socr] = $collect($sib);
                    $singleText = $socr ? ($socr->cleaned_text ?? null) : null;
                    if (is_string($singleText) && trim($singleText) === '') {
                        $singleText = null;
                    }
                    if ($sibSide === 'back') {
                        if ($backText === null) {
                            $backText = $sb ?? $singleText;
                        }
                        if ($frontText === null && $sf !== null) {
                            $frontText = $sf;
                        }
                    } elseif ($sibSide === 'front' || $sibSide === 'single' || $sibSide === '') {
                        if ($frontText === null) {
                            $frontText = $sf ?? $singleText;
                        }
                        if ($backText === null && $sb !== null) {
                            $backText = $sb;
                        }
                    }
                    if ($frontText !== null && $backText !== null) {
                        break;
                    }
                }
            }
        } catch (\Exception $e) {
            Log::warning('[CHECK OCR] resolvePairTexts sibling failed', ['error' => $e->getMessage()]);
        }

        Log::info('[CHECK OCR] resolvePairTexts', [
            'reference_id' => $ref->id ?? null,
            'front_len'    => strlen((string) ($frontText ?? '')),
            'back_len'     => strlen((string) ($backText ?? '')),
        ]);

        return ['front' => $frontText, 'back' => $backText];
    }

    /**
     * Klasifikasi 1 baris reference menjadi belahan (sisi + index).
     *
     * FIX_WRONG_REFERENCE (2026-10-07). Cermin server-side dari
     * check.js parseRefSideIdx + pola FastAPI detect_side:
     * "-front" / "-front-N" / "-back" / "-back-N" sebelum ekstensi.
     * Fallback ke kolom `side` bila filename tak berpola.
     *
     * @return array{side: string, idx: int}|null  idx selalu 1|2 (selain itu = 1).
     */
    public static function classifyReferencePiece(string $filename, ?string $sideCol): ?array
    {
        try {
            if (preg_match('/-(front|back)(-\d+)?(\.[a-z0-9]+)?$/i', trim($filename), $m)) {
                $idx = isset($m[2]) && $m[2] !== '' ? (int) ltrim($m[2], '-') : 1;
                if ($idx !== 2) {
                    $idx = 1;
                }
                return ['side' => strtolower($m[1]), 'idx' => $idx];
            }
            $s = strtolower(trim((string) ($sideCol ?? '')));
            if ($s === 'front' || $s === 'back') {
                return ['side' => $s, 'idx' => 1];
            }
            return null;
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Bangun map acuan per slot {1: teks, ...} untuk batch check.
     *
     * FIX_WRONG_REFERENCE (2026-10-07). Konvensi slot check 1..4 =
     * front1/front2/back1/back2 (check.js dual flow + split controller
     * globalSlot). Map HANYA dikembalikan bila korespondensi 1:1 terjamin:
     * tepat 4 slot check DAN keempat belahan acuan (f1/f2/b1/b2) ada teksnya.
     * Selain itu return null (backend fallback tunggal/sisi seperti biasa).
     *
     * @param  \App\Models\CheckingSession  $session (reference relation tersedia)
     * @return array<int,string>|null
     */
    public static function buildReferenceTextsMap($session, int $slotCount): ?array
    {
        try {
            if ($slotCount !== 4) {
                return null;
            }
            $ref = $session->reference ?? null;
            if (!$ref) {
                return null;
            }
            $sk = trim((string) ($ref->session_key ?? ''));
            if ($sk === '') {
                return null;
            }
            $siblings = \App\Models\Reference::where('user_id', $session->user_id)
                ->where('session_key', $sk)
                ->orderBy('id')
                ->with('ocrResult')
                ->get();
            if ($siblings->count() < 2) {
                return null;
            }
            $quad = ['f1' => null, 'f2' => null, 'b1' => null, 'b2' => null];
            $usedIds = [];
            foreach ($siblings as $sib) {
                if (isset($usedIds[$sib->id])) {
                    continue;
                }
                $cls = self::classifyReferencePiece((string) ($sib->filename ?? ''), $sib->side ?? null);
                if (!$cls) {
                    return null;
                }
                // FIX_ACUAN_PER_LABEL (2026-10-08): 'none'/kosong adalah hasil
                // OCR yang VALID (kontrak RapidOCR: tidak ada teks terdeteksi),
                // BUKAN data hilang. Tetap dirutekan apa adanya agar tiap slot
                // check mendapat acuannya sendiri; verdict diputuskan FastAPI
                // (SKIP bila acuan 'none'/kosong, bukan FAIL salah pasangan).
                // Hanya baris TANPA row OCR yang menggugurkan map 1:1.
                if (!$sib->ocrResult) {
                    return null;
                }
                $text = trim((string) ($sib->ocrResult->cleaned_text ?? ''));
                $key = ($cls['side'] === 'front' ? 'f' : 'b') . $cls['idx'];
                if ($quad[$key] !== null) {
                    return null;
                }
                $quad[$key] = $text;
                $usedIds[$sib->id] = true;
            }
            if (in_array(null, [$quad['f1'], $quad['f2'], $quad['b1'], $quad['b2']], true)) {
                return null;
            }
            return [1 => $quad['f1'], 2 => $quad['f2'], 3 => $quad['b1'], 4 => $quad['b2']];
        } catch (\Exception $e) {
            Log::warning('[CHECK] buildReferenceTextsMap gagal — fallback acuan lama', [
                'session_id' => $session->id ?? null,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }
}
