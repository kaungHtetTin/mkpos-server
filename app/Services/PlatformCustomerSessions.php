<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PlatformCustomerSessions
{
    public function resolve(?string $token): ?object
    {
        if (! $token || ! str_starts_with($token, 'pc_')) return null;
        return DB::table('platform_customer_tokens as t')->join('platform_customers as c', 'c.id', '=', 't.platform_customer_id')
            ->where('t.token_hash', hash('sha256', $token))->where('t.expires_at', '>', now())->where('c.is_active', true)
            ->first(['c.*', 't.id as session_token_id']);
    }

    public function issue(object $customer): array
    {
        $token = 'pc_'.Str::random(64);
        $expires = now()->addDays(30);
        DB::table('platform_customer_tokens')->insert(['platform_customer_id' => $customer->id,
            'token_hash' => hash('sha256', $token), 'expires_at' => $expires, 'created_at' => now(), 'updated_at' => now()]);
        return ['customer' => $this->view($customer), 'access_token' => $token, 'expires_at' => $expires->toIso8601String()];
    }

    public function view(object $customer): array
    {
        return array_intersect_key((array) $customer, array_flip(['id', 'name', 'email', 'phone', 'address']))
            + ['avatar_url' => PlatformCustomerAvatar::url($customer->avatar_path ?? null)];
    }
}
