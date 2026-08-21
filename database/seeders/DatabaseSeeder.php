<?php

namespace Database\Seeders;

use App\Models\PlatformAdmin;
// use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     *
     * @return void
     */
    public function run()
    {
        PlatformAdmin::firstOrCreate(
            ['email' => 'admin@mkposmyanmar.com'],
            [
                'name' => 'MKPOS Administrator',
                'password' => Hash::make('password'),
                'is_active' => true,
            ]
        );
    }
}
