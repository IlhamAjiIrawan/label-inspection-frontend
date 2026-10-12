<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddFastapiLibraryFieldsToReferencesTable extends Migration
{
    /**
     * Jejak push ke Reference Library permanen FastAPI (Jalur A)
     * + dedup import dari library (Jalur B).
     */
    public function up()
    {
        Schema::table('references', function (Blueprint $table) {
            $table->string('fastapi_ref_id', 64)->nullable()->after('filename');
            $table->string('fastapi_ocr_status', 20)->nullable()->after('fastapi_ref_id');
            $table->string('fastapi_thumb_url', 255)->nullable()->after('fastapi_ocr_status');
            $table->index('fastapi_ref_id');
        });
    }

    public function down()
    {
        Schema::table('references', function (Blueprint $table) {
            $table->dropIndex(['fastapi_ref_id']);
            $table->dropColumn(['fastapi_ref_id', 'fastapi_ocr_status', 'fastapi_thumb_url']);
        });
    }
}
