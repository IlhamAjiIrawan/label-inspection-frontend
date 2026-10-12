<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateCheckCapturesTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('check_captures', function (Blueprint $table) {
            $table->id();
            $table->foreignId('checking_session_id')->constrained('checking_sessions')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('reference_id')->constrained('references')->cascadeOnDelete();
            $table->unsignedTinyInteger('slot_number');
            $table->string('image_path', 255);
            $table->string('filename_original', 255);
            $table->string('filename_stored', 255);
            $table->unsignedInteger('resolution_w');
            $table->unsignedInteger('resolution_h');
            $table->unsignedInteger('filesize');
            $table->string('mime_type', 50)->default('image/png');
            $table->string('status', 20)->default('pending')->index();
            $table->string('ocr_status', 20)->nullable();
            $table->text('cleaned_text')->nullable();
            $table->text('raw_text')->nullable();
            $table->string('ocr_engine', 50)->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->unique(['checking_session_id', 'slot_number']);
            $table->index('filename_original');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('check_captures');
    }
}
