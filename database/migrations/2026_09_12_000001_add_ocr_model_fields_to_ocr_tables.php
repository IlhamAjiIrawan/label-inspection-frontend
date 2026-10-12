<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddOcrModelFieldsToOcrTables extends Migration
{
    /**
     * Tuning RapidOCR FastAPI (2026-09-12 / PADDLEOCR_NATIVE_PLAN):
     * FastAPI kini kirim det_model/rec_model di callback
     * (small=cepat ~1s, medium=akurat ~5s, fix HANDPHONE->KANDPHONE).
     * Semua nullable agar baris lama + payload lama aman.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('reference_ocr_results', function (Blueprint $table) {
            $table->string('det_model', 100)->nullable()->after('engine');
            $table->string('rec_model', 100)->nullable()->after('det_model');
            $table->boolean('text_present')->nullable()->after('rec_model');
        });

        Schema::table('check_captures', function (Blueprint $table) {
            $table->string('ocr_det_model', 100)->nullable()->after('ocr_engine');
            $table->string('ocr_rec_model', 100)->nullable()->after('ocr_det_model');
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
            $table->dropColumn(['det_model', 'rec_model', 'text_present']);
        });

        Schema::table('check_captures', function (Blueprint $table) {
            $table->dropColumn(['ocr_det_model', 'ocr_rec_model']);
        });
    }
}
