<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class AddActiveToReferencesTable extends Migration
{
    /**
     * Flag acuan aktif (1 sesi aktif per user) untuk proses Checking Label.
     * + backfill: baris terbaru tiap user menjadi aktif (tegakkan invarian §1 planning).
     */
    public function up()
    {
        Schema::table('references', function (Blueprint $table) {
            $table->boolean('active')->default(false)->after('fastapi_thumb_url');
            $table->index(['user_id', 'active'], 'references_user_active');
        });

        $userIds = DB::table('references')->select('user_id')->distinct()->pluck('user_id');
        foreach ($userIds as $uid) {
            $latestId = DB::table('references')->where('user_id', $uid)->max('id');
            if ($latestId) {
                DB::table('references')->where('id', $latestId)->update(['active' => true]);
            }
        }
    }

    public function down()
    {
        // MySQL memakai index komposit sebagai penopang FK user_id
        // (index tunggal user_id tidak ada di DB ini) — buat penggantinya dulu,
        // baru lepas index komposit agar rollback tidak error 1553.
        $keys = collect(DB::select('SHOW INDEX FROM `references`'))->pluck('Key_name')->all();
        if (!in_array('references_user_id_index', $keys, true)) {
            Schema::table('references', function (Blueprint $table) {
                $table->index('user_id');
            });
        }
        Schema::table('references', function (Blueprint $table) {
            $table->dropIndex('references_user_active');
            $table->dropColumn('active');
        });
    }
}
