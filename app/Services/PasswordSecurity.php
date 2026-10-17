<?php

namespace App\Services;

use App\Models\PlatformAdmin;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class PasswordSecurity
{
    public function change(User|PlatformAdmin $account, string $password, array $attributes = []): void
    {
        DB::transaction(function () use ($account, $password, $attributes) {
            $current = $account->newQuery()->whereKey($account->id)->lockForUpdate()->firstOrFail();
            abort_unless(hash_equals($current->password, $account->getRawOriginal('password')), 409, 'Your account changed. Please sign in again.');
            $account->forceFill(array_merge($attributes, [
                'password' => Hash::make($password),
                'remember_token' => Str::random(60),
                'password_reset_at' => now(),
            ]))->save();
            $account->tokens()->delete();
        });
    }
}
