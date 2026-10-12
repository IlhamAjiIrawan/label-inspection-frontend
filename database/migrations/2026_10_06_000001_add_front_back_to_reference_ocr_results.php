<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddFrontBackToReferenceOcrResults extends Migration
{
    /**
     * REVIEW_FLOW_COMPARE_FRONT_BACK_PLAN (2026-10-05) TAHAP L1:
     * Reference label kini punya 2 sisi (front + back) agar compare
     * Front-vs-Front & Back-vs-Back bisa dilakukan di FastAPI.
     * Semua kolom nullable/default agar baris lama + payload lama aman.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('reference_ocr_results', function (Blueprint $table) {
            // Kolom existing: cleaned_text, lines (JSON)
            // BARU: per sisi
            $table->longText('cleaned_text_front')->nullable()->after('cleaned_text');
            $table->json('lines_front')->nullable()->after('cleaned_text_front');
            $table->longText('cleaned_text_back')->nullable()->after('lines_front');
            $table->json('lines_back')->nullable()->after('cleaned_text_back');
            $table->boolean('has_back')->default(false)->after('lines_back');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('reference_ocr_results', function (Blueprint $table) {
            $table->dropColumn([
                'cleaned_text_front', 'lines_front',
                'cleaned_text_back', 'lines_back', 'has_back',
            ]);
        });
    }
}
