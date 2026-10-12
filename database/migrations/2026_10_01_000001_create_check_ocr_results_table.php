<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateCheckOcrResultsTable extends Migration
{
    /**
     * Hasil ekstraksi teks per split Label (Checking 2-capture).
     *
     * PLAN_CHECKING_LABEL_2_CAPTURE_OCR_CALLBACK §6 (varian alter-style):
     * tabel batch existing (checking_sessions/check_captures) TIDAK diubah.
     * Tabel ringan ini menyimpan korelasi split crop -> FastAPI single OCR
     * (/api/test/status) -> callback cleaned_text, 1 baris per sisi+slot.
     * Retake = UPDATE baris yang sama (unique user+session+side+slot).
     *
     * @return void
     */
    public function up()
    {
        Schema::create('check_ocr_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // Kode sesi dual Checking (CHK-YYYYMMDD-His-xxxx), dibuat 1x per Start Scanning.
            $table->string('check_session_code', 64)->index()->comment('CHK session, 1 sesi = maks 4 baris');
            // front / back
            $table->string('side', 10)->default('front');
            // 1/2 per sisi (global 1..4: front1=1, front2=2, back1=3, back2=4)
            $table->unsignedTinyInteger('label_slot');
            $table->string('client_filename', 255)->comment('nama crop dari browser');
            // dari FastAPI POST /api/test/status (idempotency key callback)
            $table->string('saved_filename', 255)->nullable()->unique();
            $table->string('request_id', 255)->nullable();
            // callback cleaned_text — tampil di card Hasil ekstraksi teks
            $table->text('cleaned_text')->nullable();
            $table->json('lines')->nullable();
            $table->string('status', 20)->default('pending')->index()->comment('pending|success|failed');
            $table->boolean('is_empty')->default(false);
            $table->string('engine', 50)->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'check_session_code', 'side', 'label_slot'], 'uq_check_ocr_user_session_side_slot');
            $table->index(['user_id', 'check_session_code']);
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('check_ocr_results');
    }
}
