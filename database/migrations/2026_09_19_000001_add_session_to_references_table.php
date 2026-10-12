<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class AddSessionToReferencesTable extends Migration
{
    /**
     * Sesi acuan eksplisit (1 sesi = 1-2 baris front/back).
     * - session_key: basis filename tanpa -front/-back + ekstensi,
     *   ex: label-ref-tl-20260918-100528-front.png -> label-ref-tl-20260918-100528
     * - side: front|back|single (single = capture lama / tanpa pasangan)
     * + backfill dari filename yang ada + index untuk performa.
     */
    public function up()
    {
        Schema::table('references', function (Blueprint $table) {
            $table->string('session_key', 64)->default('')->after('filename');
            $table->string('side', 10)->default('single')->after('session_key');
            $table->index(['user_id', 'session_key'], 'references_user_session');
        });

        // Backfill best-effort baris lama (tanpa lock lama, per-user chunk).
        try {
            $refs = DB::table('references')->select('id', 'filename')->get();
            foreach ($refs as $r) {
                $fn = (string) ($r->filename ?? '');
                $side = 'single';
                $session = '';
                if (preg_match('/^(.*)-(front|back)(\.[a-z0-9]+)?$/i', $fn, $m)) {
                    $side = strtolower($m[2]);
                    $session = substr($m[1], 0, 64);
                } else {
                    // single: pakai filename tanpa ekstensi sebagai sesi unik
                    $base = preg_replace('/\.[a-z0-9]+$/i', '', $fn);
                    $session = substr('single-' . $r->id . '-' . $base, 0, 64);
                    if ($session === 'single-' . $r->id . '-') {
                        $session = substr('single-' . $r->id, 0, 64);
                    }
                }
                DB::table('references')->where('id', $r->id)->update([
                    'session_key' => $session,
                    'side' => $side,
                ]);
            }
        } catch (\Exception $e) {
            // Jangan gagalkan migrasi bila backfill gagal — kolom sudah ada,
            // sesi kosong akan diisi lazy oleh controller (ensureSessionColumns).
            \Illuminate\Support\Facades\Log::warning('[REF-MIGRATION] backfill session gagal (diabaikan)', ['error' => $e->getMessage()]);
        }
    }

    public function down()
    {
        Schema::table('references', function (Blueprint $table) {
            $table->dropIndex('references_user_session');
            $table->dropColumn(['session_key', 'side']);
        });
    }
}
