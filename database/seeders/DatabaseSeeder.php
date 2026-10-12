<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     *
     * @return void
     */
    public function run()
    {
        // Dev account — Employee NIK 9 digits (test login: 000000000)
        User::updateOrCreate(
            ['nik' => '000000000'],
            [
                'name'     => 'Dev Operator',
                'username' => 'dev',
                'email'    => 'dev@labelinspection.test',
                'password' => Hash::make(Str::random(32)),
            ]
        );

        // Admin QC — keep for backward compat (also 9 digits)
        User::updateOrCreate(
            ['username' => 'admin'],
            [
                'name'     => 'Admin Label Inspection',
                'email'    => 'admin@labelinspection.test',
                'nik'      => '000000001',
                'password' => Hash::make('password123'),
            ]
        );

        // Operator QC 1 — 9 digits
        User::updateOrCreate(
            ['username' => 'operator1'],
            [
                'name'     => 'Operator QC 1',
                'nik'      => '000000002',
                'email'    => 'operator1@labelinspection.test',
                'password' => Hash::make(Str::random(32)),
            ]
        );
    }
}
