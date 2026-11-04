<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateReferenceOcrResultsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('reference_ocr_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('reference_id')->nullable()->constrained('references')->cascadeOnDelete();
            $table->foreignId('label_capture_id')->nullable()->constrained('label_captures')->nullOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('original_filename', 255)->index();
            $table->string('ocr_filename', 255)->nullable();
            $table->string('engine', 50)->default('tesseract');
            $table->string('language', 20)->default('eng');
            $table->boolean('preprocessed')->default(true);
            $table->json('preprocessing_steps')->nullable();
            $table->longText('raw_text')->nullable();
            $table->longText('cleaned_text')->nullable();
            $table->json('lines')->nullable();
            $table->string('status', 20)->default('pending')->index();
            $table->text('error')->nullable();
            $table->unsignedBigInteger('file_size_bytes')->nullable();
            $table->decimal('processing_time_seconds', 8, 3)->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->json('external_response')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('reference_ocr_results');
    }
}

