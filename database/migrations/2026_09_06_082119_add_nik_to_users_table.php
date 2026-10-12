<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddNikToUsersTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('users', function (Blueprint $table) {
            // NIK KTP: 16 digit numerik disimpan sebagai string (jaga leading 0)
            // nullable agar migrasi tidak gagal untuk row existing (admin lama tanpa NIK)
            // unique untuk index + cegah duplikat; MySQL izinkan multiple NULL
            $table->string('nik', 16)->nullable()->unique()->after('username')->comment('NIK 16 digit');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['nik']);
            $table->dropColumn('nik');
        });
    }
}
