<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class MakeCheckReferenceNullableSetNull extends Migration
{
    /**
     * Pisahkan master (references) dari riwayat (checking_sessions/check_captures).
     * - reference_id jadi NULLABLE + ON DELETE SET NULL: hapus reference non-aktif
     *   tidak menghapus riwayat Check; session/capture tetap ada dengan
     *   template/batch_code denormalisasi + reference=null (sudah null-safe di show()).
     * - Tambah index reference_id agar guard exists O(log n).
     */
    public function up()
    {
        // --- checking_sessions.reference_id ---
        try {
            Schema::table('checking_sessions', function (Blueprint $table) {
                try { $table->dropForeign(['reference_id']); } catch (\Exception $e) {}
            });
        } catch (\Exception $e) {}

        try {
            DB::statement('ALTER TABLE `checking_sessions` MODIFY `reference_id` BIGINT UNSIGNED NULL');
        } catch (\Exception $e) {
            // Fallback SQLite/testing (tanpa MODIFY): abaikan, lanjut pasang FK.
        }

        Schema::table('checking_sessions', function (Blueprint $table) {
            try {
                $table->foreign('reference_id')->references('id')->on('references')->nullOnDelete();
            } catch (\Exception $e) {
            }
            try {
                $table->index('reference_id', 'checking_sessions_reference_id_index');
            } catch (\Exception $e) {
            }
            try {
                $table->index(['user_id', 'status'], 'checking_sessions_user_status');
            } catch (\Exception $e) {
            }
        });

        // --- check_captures.reference_id ---
        try {
            Schema::table('check_captures', function (Blueprint $table) {
                try { $table->dropForeign(['reference_id']); } catch (\Exception $e) {}
            });
        } catch (\Exception $e) {}

        try {
            DB::statement('ALTER TABLE `check_captures` MODIFY `reference_id` BIGINT UNSIGNED NULL');
        } catch (\Exception $e) {
        }

        Schema::table('check_captures', function (Blueprint $table) {
            try {
                $table->foreign('reference_id')->references('id')->on('references')->nullOnDelete();
            } catch (\Exception $e) {
            }
            try {
                $table->index('reference_id', 'check_captures_reference_id_index');
            } catch (\Exception $e) {
            }
        });
    }

    public function down()
    {
        // Kembalikan ke CASCADE tapi TETAP nullable agar tidak ada data loss
        // bila ada riwayat yang sudah ter-detach (reference_id NULL).
        try {
            Schema::table('check_captures', function (Blueprint $table) {
                try { $table->dropForeign(['reference_id']); } catch (\Exception $e) {}
            });
        } catch (\Exception $e) {}
        Schema::table('check_captures', function (Blueprint $table) {
            try {
                $table->foreign('reference_id')->references('id')->on('references')->cascadeOnDelete();
            } catch (\Exception $e) {
            }
            try { $table->dropIndex('check_captures_reference_id_index'); } catch (\Exception $e) {}
        });

        try {
            Schema::table('checking_sessions', function (Blueprint $table) {
                try { $table->dropForeign(['reference_id']); } catch (\Exception $e) {}
            });
        } catch (\Exception $e) {}
        Schema::table('checking_sessions', function (Blueprint $table) {
            try {
                $table->foreign('reference_id')->references('id')->on('references')->cascadeOnDelete();
            } catch (\Exception $e) {
            }
            try { $table->dropIndex('checking_sessions_reference_id_index'); } catch (\Exception $e) {}
            try { $table->dropIndex('checking_sessions_user_status'); } catch (\Exception $e) {}
        });
    }
}
