<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateReferencesTable extends Migration
{
    public function up()
    {
        Schema::create('references', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('template_id', 4);
            $table->string('template_name', 50);
            $table->unsignedInteger('template_width');
            $table->unsignedInteger('template_height');
            $table->string('image_path', 255);
            $table->string('filename', 255);
            $table->unsignedInteger('resolution_w');
            $table->unsignedInteger('resolution_h');
            $table->unsignedInteger('filesize');
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('references');
    }
}
