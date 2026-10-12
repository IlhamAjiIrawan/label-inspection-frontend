<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddCompareFieldsToCheckTables extends Migration
{
    /**
     * Visual Compare Mode (PLAN_VISUAL_COMPARE_MODE §7): kolom hasil komparasi
     * visual per slot + ringkasan per session. Semua nullable agar baris lama aman.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('check_captures', function (Blueprint $table) {
            $table->boolean('compare_match')->nullable()->after('ocr_engine');
            $table->float('compare_confidence', 3, 2)->nullable()->after('compare_match');
            $table->text('compare_notes')->nullable()->after('compare_confidence');
            $table->string('compare_model', 50)->nullable()->after('compare_notes');
            $table->timestamp('compared_at')->nullable()->after('compare_model');
        });

        Schema::table('checking_sessions', function (Blueprint $table) {
            // match = semua slot cocok, mismatch = tidak ada yang cocok, partial = sebagian
            $table->string('compare_status', 20)->nullable()->index()->after('status');
            $table->json('compare_summary')->nullable()->after('compare_status');
            $table->timestamp('compared_at')->nullable()->after('compare_summary');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('check_captures', function (Blueprint $table) {
            $table->dropColumn(['compare_match', 'compare_confidence', 'compare_notes', 'compare_model', 'compared_at']);
        });

        Schema::table('checking_sessions', function (Blueprint $table) {
            $table->dropColumn(['compare_status', 'compare_summary', 'compared_at']);
        });
    }
}
