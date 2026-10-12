<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'mailgun' => [
        'domain' => env('MAILGUN_DOMAIN'),
        'secret' => env('MAILGUN_SECRET'),
        'endpoint' => env('MAILGUN_ENDPOINT', 'api.mailgun.net'),
    ],

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'test_signal' => [
        'base_url'               => env('TEST_SIGNAL_URL', 'http://127.0.0.1:9000/api/test/status'),
        'timeout'                => (int) env('TEST_SIGNAL_TIMEOUT', 10),
        'download_timeout'       => (int) env('LABEL_CAPTURE_DOWNLOAD_TIMEOUT', 15),
        // Fallback PULL (hanya dipakai jika webhook gagal)
        // FIX timeout Label 1/2 (2026-10-06): RapidOCR medium 60-90s/label.
        // Batas lama 45s selalu timeout sebelum FastAPI selesai.
        // Baru 150s = 2 label serial + antre + long-poll. ENV menang.
        'ocr_poll_timeout'       => (int) env('OCR_POLL_TIMEOUT', 150),
        'ocr_poll_interval'      => (int) env('OCR_POLL_INTERVAL', 2),
        // Optimasi FastAPI R4 (2026-10-06): long-poll GET /ocr/{fn}?wait=N.
        // 0 = perilaku lama (polling interval). 8 = tiap request tahan hingga
        // hasil siap (maks OCR_LONGPOLL_MAX_SECONDS=10 di FastAPI).
        'ocr_longpoll_wait'      => (float) env('OCR_LONGPOLL_WAIT', 8),
        // Optimasi FastAPI R2/L1 (2026-10-06): resize sisi terpanjang sebelum
        // forward ke FastAPI (PNG-preserving, tanpa ganti format agar magic
        // bytes tetap cocok ekstensi). 0 = nonaktif.
        'ocr_image_max_side'     => (int) env('OCR_IMAGE_MAX_SIDE', 1600),
        'ocr_enabled'            => (bool) env('OCR_ENABLED', true),
        'preview_timeout'        => (int) env('OCR_PREVIEW_TIMEOUT', 10),
        'retake_cleanup_timeout' => (int) env('OCR_RETAKE_CLEANUP_TIMEOUT', 3),
    ],

    'ocr' => [
        // Fallback: eksternal pakai LARAVEL_API_KEY, Laravel sebelumnya pakai OCR_CALLBACK_API_KEY
        'callback_api_key'  => env('OCR_CALLBACK_API_KEY', env('LARAVEL_API_KEY', 'label-inspection-2026-key')),
        'callback_secret'   => env('OCR_CALLBACK_SECRET', env('LARAVEL_HMAC_SECRET', 'change-me-64-char-secret-for-hmac')),
        'callback_url'      => env('OCR_CALLBACK_URL', 'http://127.0.0.1:8000/api/ocr/callback'),
        'callback_timeout'  => (int) env('OCR_CALLBACK_TIMEOUT', 5),
        'callback_retry'    => (int) env('OCR_CALLBACK_RETRY', 3),
        // Reconcile cron
        'reconcile_enabled'          => (bool) env('OCR_RECONCILE_ENABLED', true),
        'reconcile_interval_minutes' => (int) env('OCR_RECONCILE_INTERVAL', 5),
        // CP-016 retake cleanup
        'retake_cleanup_timeout'     => (int) env('OCR_RETAKE_CLEANUP_TIMEOUT', 3),
    ],

    'check_external' => [
        'base_url' => env('CHECK_EXTERNAL_URL', 'http://127.0.0.1:9000/api/check/captures'),
        'timeout'  => (int) env('CHECK_EXTERNAL_TIMEOUT', 10),
        'api_key'  => env('CHECK_EXTERNAL_API_KEY', env('OCR_CALLBACK_API_KEY', 'label-inspection-2026-key')),
    ],

    'check_ocr' => [
        'ocr_url'      => env('CHECK_OCR_URL', 'http://127.0.0.1:9000/api/check/process'),
        // Tuning RapidOCR (2026-09-12): trigger batch 5 slot bisa antre di engine
        // lock FastAPI (medium ~5.4s/slot). ACK tetap cepat, tapi longgarkan default.
        'timeout'      => (int) env('CHECK_OCR_TIMEOUT', 30),
        'api_key'      => env('CHECK_OCR_API_KEY', env('CHECK_EXTERNAL_API_KEY', env('OCR_CALLBACK_API_KEY', 'label-inspection-2026-key'))),
        'callback_url' => env('CHECK_OCR_CALLBACK_URL', 'http://127.0.0.1:8000/api/check/ocr/callback'),
    ],

    'check_compare' => [
        'callback_url' => env('CHECK_COMPARE_CALLBACK_URL', 'http://127.0.0.1:8000/api/check/compare/callback'),
    ],

    // Reference Library permanen di FastAPI (PLAN_REFERENCE_LIBRARY_FASTAPI).
    // Jalur A: Laravel push tiap Simpan Reference (best effort, dedup SHA256 di FastAPI).
    // Jalur B: Laravel proxy list + import untuk tab "Library Global" di menu Reference.
    'reference_library' => [
        'base_url' => env('FASTAPI_REF_LIBRARY_URL', 'http://127.0.0.1:9000/api/references'),
        'timeout'  => (int) env('FASTAPI_REF_LIBRARY_TIMEOUT', 8),
        'api_key'  => env('FASTAPI_REF_LIBRARY_API_KEY', env('CHECK_EXTERNAL_API_KEY', env('OCR_CALLBACK_API_KEY', 'label-inspection-2026-key'))),
    ],

];
