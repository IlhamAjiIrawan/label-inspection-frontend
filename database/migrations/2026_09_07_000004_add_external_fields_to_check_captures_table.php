<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddExternalFieldsToCheckCapturesTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('check_captures', function (Blueprint $table) {
            $table->string('external_status', 20)->default('pending')->index()->after('status');
            $table->string('external_path', 255)->nullable()->after('external_status');
            $table->json('external_response')->nullable()->after('external_path');
            $table->timestamp('external_dispatched_at')->nullable()->after('external_response');
            $table->text('external_error')->nullable()->after('external_dispatched_at');
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
            $table->dropColumn(['external_status', 'external_path', 'external_response', 'external_dispatched_at', 'external_error']);
        });
    }
}
