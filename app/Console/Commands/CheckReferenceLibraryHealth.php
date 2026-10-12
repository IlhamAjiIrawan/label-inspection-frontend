<?php

namespace App\Console\Commands;

use App\Services\FastApiReferenceLibrary;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Health check Library FastAPI tiap 5 menit (PLAN_MYSQL_BACKED_REFERENCE_STORAGE §9).
 *
 * - 200 ok   → reset counter gagal, info log.
 * - Gagal 1x → warning log (belum alert, bisa jadi blip jaringan).
 * - Gagal 2x beruntun → error log (kaitkan ke notifikasi/alert yang sudah ada).
 *
 * Counter disimpan di Cache agar survive antar run scheduler.
 */
class CheckReferenceLibraryHealth extends Command
{
    protected $signature = 'ref:library-health';
    protected $description = 'Cek health Library FastAPI (DB ping) tiap 5 menit — alert jika gagal 2x beruntun';

    public const FAIL_KEY = 'ref-library:health:consecutive_failures';

    public function handle(FastApiReferenceLibrary $library)
    {
        $this->info('Checking FastAPI reference library health...');

        $health = $library->health();

        if ($health !== null && ($health['ok'] ?? false)) {
            Cache::forget(self::FAIL_KEY);
            $store = $health['store'] ?? 'unknown';
            $ms = $health['latency_ms'] ?? '?';
            $this->info("  OK (store={$store}, {$ms}ms)");
            Log::info('[REF-LIB] health ok', ['store' => $store, 'latency_ms' => $ms]);
            return 0;
        }

        $fails = (int) Cache::get(self::FAIL_KEY, 0) + 1;
        Cache::put(self::FAIL_KEY, $fails, now()->addHour());

        $detail = $health === null ? 'unreachable' : json_encode($health);
        $this->warn("  FAIL #{$fails} ({$detail})");
        Log::warning('[REF-LIB] health fail', ['consecutive' => $fails, 'detail' => $detail]);

        if ($fails >= 2) {
            // Hook alert: sambungkan ke kanal notifikasi yang sudah ada bila tersedia.
            // Default: error log (terbaca monitoring log yang sudah jalan).
            $this->error("  ALERT: library gagal {$fails}x beruntun — butuh tindakan!");
            Log::error('[REF-LIB] health ALERT: gagal beruntun', ['consecutive' => $fails]);
            return 1;
        }

        return 0;
    }
}
