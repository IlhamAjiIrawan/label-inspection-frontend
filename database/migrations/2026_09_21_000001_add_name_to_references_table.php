<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class AddNameToReferencesTable extends Migration
{
    /**
     * Nama custom reference label dari popup form (STAGE-B).
     * - name: VARCHAR(80) nullable, selaras kontrak FastAPI ref_library.name (3..80 char).
     * - Index (user_id, name) untuk search daftar milik user tetap cepat (tanpa full scan).
     * - Backfill 1 query: baris lama (NULL) diisi template_name agar daftar tak kosong.
     */
    public function up()
    {
        Schema::table('references', function (Blueprint $table) {
            $table->string('name', 80)->nullable()->after('template_name');
            $table->index(['user_id', 'name'], 'references_user_name');
        });

        // Backfill efisien: 1x UPDATE, tanpa chunk/loop (hemat query).
        try {
            DB::table('references')->whereNull('name')->update([
                'name' => DB::raw('template_name'),
            ]);
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::warning('[REF-MIGRATION] backfill name gagal (diabaikan)', ['error' => $e->getMessage()]);
        }
    }

    public function down()
    {
        Schema::table('references', function (Blueprint $table) {
            $table->dropIndex('references_user_name');
            $table->dropColumn('name');
        });
    }
}
