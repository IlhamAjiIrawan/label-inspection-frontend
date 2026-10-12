<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddExternalFieldsToCheckingSessionsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('checking_sessions', function (Blueprint $table) {
            $table->string('external_status', 20)->default('pending')->index()->after('status');
            $table->string('external_batch_id', 100)->nullable()->after('external_status');
            $table->json('external_response')->nullable()->after('external_batch_id');
            $table->timestamp('external_dispatched_at')->nullable()->after('external_response');
            $table->text('external_error')->nullable()->after('external_dispatched_at');
            $table->unsignedTinyInteger('retry_count')->default(0)->after('external_error');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('checking_sessions', function (Blueprint $table) {
            $table->dropColumn(['external_status', 'external_batch_id', 'external_response', 'external_dispatched_at', 'external_error', 'retry_count']);
        });
    }
}
