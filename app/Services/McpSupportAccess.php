<?php

namespace App\Services;

use App\Models\PlatformAdmin;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class McpSupportAccess
{
    public function issue(string $type, object $actor, array $scopes): array
    {
        $token = 'mkcs_'.Str::random(64);
        $id = (string) Str::uuid();
        $expires = now()->addHour();
        DB::table('mcp_support_tokens')->insert([
            'id' => $id, 'token_hash' => hash('sha256', $token), 'actor_type' => $type,
            'actor_id' => $actor->id, 'credential_version' => hash('sha256', $actor->password),
            'business_id' => $type === 'owner' ? $actor->business_id : null,
            'scopes' => json_encode($scopes), 'expires_at' => $expires, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return ['access_token' => $token, 'token_id' => $id, 'expires_at' => $expires->toIso8601String(),
            'endpoint' => url($type === 'admin' ? '/mcp/admin' : '/mcp/account'), 'scopes' => $scopes];
    }

    public function resolve(?string $bearer, string $audience): ?array
    {
        if (! $bearer || ! str_starts_with($bearer, 'mkcs_')) {
            return null;
        }
        $token = DB::table('mcp_support_tokens')->where('token_hash', hash('sha256', $bearer))
            ->where('expires_at', '>', now())->first();
        if (! $token || ($audience === 'admin') !== ($token->actor_type === 'admin')) {
            return null;
        }
        $actor = match ($token->actor_type) {
            'owner' => User::find($token->actor_id),
            'admin' => PlatformAdmin::find($token->actor_id),
            'customer' => DB::table('platform_customers')->where('id', $token->actor_id)->first(),
            default => null,
        };
        if (! $actor || ! $actor->is_active || ! hash_equals($token->credential_version, hash('sha256', $actor->password))) {
            return null;
        }
        if ($token->actor_type === 'owner' && ($actor->role !== 'owner' || $actor->business?->status !== 'active'
            || (int) $actor->business_id !== (int) $token->business_id)) {
            return null;
        }

        return ['type' => $token->actor_type, 'id' => (int) $actor->id, 'actor' => $actor,
            'token_id' => $token->id, 'scopes' => json_decode($token->scopes, true)];
    }
}
