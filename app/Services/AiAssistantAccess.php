<?php

namespace App\Services;

use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/** Safe authorization facts for the assistant, never credentials or business figures. */
class AiAssistantAccess
{
    public function context(User $actor): array
    {
        $stored = User::query()->where('id', $actor->id)->where('business_id', $actor->business_id)->first();
        $business = DB::table('businesses')->where('id', $actor->business_id)->first(['status']);
        // Never promote a restricted session merely because the database role was upgraded.
        $role = $actor->role === 'owner' ? ($stored?->role ?? 'unknown') : $actor->role;
        $effective = $stored ? clone $stored : clone $actor;
        $effective->role = $role;
        $validRole = in_array($role, ['owner', 'staff'], true);
        $reason = match (true) {
            ! $stored || ! $business || (int) $actor->business_id !== app(TenantContext::class)->id() => 'session_unavailable',
            ! $stored->is_active => 'user_inactive',
            $business->status !== 'active' => 'business_inactive',
            $role !== 'owner' => $validRole ? 'owner_required' : 'role_not_supported',
            default => null,
        };
        $permissions = $stored && $validRole ? app(AccessService::class)->permissions($effective) : [];

        return [
            'role' => $validRole ? $role : 'unknown',
            'module_permissions' => $permissions,
            'enabled_modules' => array_values(config('mkpos.enabled_modules', [])),
            'ai_business_data_policy' => 'owner_only',
            'can_read_business_data' => $reason === null,
            'can_prepare_owner_actions' => $reason === null,
            'access_reason' => $reason,
            'can_change_permissions_in_chat' => false,
        ];
    }
}
