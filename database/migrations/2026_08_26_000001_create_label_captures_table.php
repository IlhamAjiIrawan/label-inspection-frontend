<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateLabelCapturesTable extends Migration
{
    public function up()
    {
        Schema::create('label_captures', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('template_id', 4);
            $table->string('template_name', 50);
            $table->unsignedInteger('template_width');
            $table->unsignedInteger('template_height');
            $table->string('original_filename', 255);
            $table->string('local_path', 255);
            $table->unsignedInteger('resolution_w');
            $table->unsignedInteger('resolution_h');
            $table->unsignedInteger('filesize');
            $table->string('source_timestamp', 50);
            $table->string('status', 20)->default('pending');
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->index('status');
            $table->index('original_filename');
            $table->unique('original_filename');
        });
    }

    public function down()
    {
        Schema::dropIfExists('label_captures');
    }
}
