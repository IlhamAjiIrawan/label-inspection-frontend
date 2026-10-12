<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddGeminiCompareFieldsToCheckCapturesTable extends Migration
{
    /**
     * PLAN_GEMINI_API_TEXT_COMPARE (2026-10-07): kolom audit hasil compare
     * Gemini Flash dari FastAPI (app/compare_text/gemini_compare.py).
     *
     * FastAPI kini kirim similarity = {
     *   version: "v2-gemini", verdict: PASS|FAIL, score: 0..1,
     *   compare_engine: "gemini_flash"|"difflib",
     *   gemini_diffs: [{id, teks_acuan, teks_check, severity, category}],
     *   critical_count, minor_count, total_diffs, diff_details (compat)
     * }.
     * Semua nullable agar baris lama + payload difflib lama tetap aman.
     * Hanya ADITIF — tidak mengubah/menghapus kolom yang sudah ada.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('check_captures', function (Blueprint $table) {
            if (!Schema::hasColumn('check_captures', 'compare_engine')) {
                $table->string('compare_engine', 20)->nullable()->after('compared_at');
            }
            if (!Schema::hasColumn('check_captures', 'compare_version')) {
                $table->string('compare_version', 20)->nullable()->after('compare_engine');
            }
            if (!Schema::hasColumn('check_captures', 'compare_verdict')) {
                $table->string('compare_verdict', 10)->nullable()->after('compare_version');
            }
            if (!Schema::hasColumn('check_captures', 'compare_score')) {
                $table->float('compare_score', 8, 3)->nullable()->after('compare_verdict');
            }
            if (!Schema::hasColumn('check_captures', 'critical_diff_count')) {
                $table->unsignedInteger('critical_diff_count')->nullable()->after('compare_score');
            }
            if (!Schema::hasColumn('check_captures', 'minor_diff_count')) {
                $table->unsignedInteger('minor_diff_count')->nullable()->after('critical_diff_count');
            }
            if (!Schema::hasColumn('check_captures', 'total_diffs')) {
                $table->unsignedInteger('total_diffs')->nullable()->after('minor_diff_count');
            }
            if (!Schema::hasColumn('check_captures', 'gemini_diffs_json')) {
                $table->json('gemini_diffs_json')->nullable()->after('total_diffs');
            }
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
            $drop = [];
            foreach (['compare_engine', 'compare_version', 'compare_verdict', 'compare_score', 'critical_diff_count', 'minor_diff_count', 'total_diffs', 'gemini_diffs_json'] as $col) {
                if (Schema::hasColumn('check_captures', $col)) {
                    $drop[] = $col;
                }
            }
            if (!empty($drop)) {
                $table->dropColumn($drop);
            }
        });
    }
}
