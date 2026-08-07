<?php

namespace App\Console\Commands;

use App\Models\PlatformAdmin;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class CreateOfficeAdmin extends Command
{
    protected $signature = 'office:create-admin {name} {email} {--password=}';

    protected $description = 'Create or update an MKPOS platform office administrator';

    public function handle()
    {
        $password = $this->option('password') ?: $this->secret('Password (minimum 8 characters)');
        if (strlen((string) $password) < 8) {
            $this->error('Password must contain at least 8 characters.');

            return Command::FAILURE;
        }
        $admin = PlatformAdmin::updateOrCreate(
            ['email' => Str::lower(trim($this->argument('email')))],
            ['name' => trim($this->argument('name')), 'password' => Hash::make($password), 'is_active' => true]
        );
        $this->info("Office administrator ready: {$admin->email}");

        return Command::SUCCESS;
    }
}
