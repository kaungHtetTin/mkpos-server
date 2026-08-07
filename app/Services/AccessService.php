<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;

class AccessService
{
    public const ASSIGNABLE_MODULES = [
        'sell' => 'Sell',
        'products' => 'Products',
        'purchases' => 'Purchases',
        'suppliers' => 'Suppliers',
        'customers' => 'Customers',
        'expenses' => 'Expenses',
        'transactions' => 'Transactions',
        'reports' => 'Reports',
    ];

    public function permissions(User $user): array
    {
        if ($user->role === 'owner') {
            return array_values(array_unique([...config('mkpos.enabled_modules', []), 'access']));
        }

        if ($user->role !== 'staff' || ! $user->access_role_id) {
            return [];
        }

        $role = DB::table('access_roles')
            ->where('id', $user->access_role_id)
            ->where('business_id', $user->business_id)
            ->first();
        $permissions = $role ? json_decode($role->permissions, true) : [];

        return array_values(array_intersect(
            is_array($permissions) ? $permissions : [],
            array_keys(self::ASSIGNABLE_MODULES)
        ));
    }

    public function roleName(User $user): string
    {
        if ($user->role === 'owner') {
            return 'Owner';
        }

        return (string) (DB::table('access_roles')
            ->where('id', $user->access_role_id)
            ->where('business_id', $user->business_id)
            ->value('name') ?: 'Staff');
    }
}
