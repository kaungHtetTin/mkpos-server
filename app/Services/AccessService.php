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
        'sales' => 'Sales',
        'suppliers' => 'Suppliers',
        'customers' => 'Customers',
        'expenses' => 'Expenses',
        'transactions' => 'Transactions',
        'reports' => 'Reports',
        'ecommerce_settings' => 'Shop settings',
        'ecommerce_catalog' => 'Online catalog',
        'ecommerce_orders' => 'Online orders',
        'ecommerce_process' => 'Process online orders',
        'ecommerce_payments' => 'Confirm online payments',
        'ecommerce_refunds' => 'Record online refunds',
    ];

    public function permissions(User $user): array
    {
        if ($user->role === 'owner') {
            return array_values(array_unique([...config('mkpos.enabled_modules', []),
                ...array_filter(array_keys(self::ASSIGNABLE_MODULES), fn ($p) => str_starts_with($p, 'ecommerce_')), 'access']));
        }

        if ($user->role !== 'staff' || ! $user->access_role_id) {
            return [];
        }

        $role = DB::table('access_roles')
            ->where('id', $user->access_role_id)
            ->where('business_id', $user->business_id)
            ->first();
        $permissions = $role ? json_decode($role->permissions, true) : [];

        $permissions = array_values(array_intersect(
            is_array($permissions) ? $permissions : [],
            array_keys(self::ASSIGNABLE_MODULES)
        ));
        if (array_filter($permissions, fn ($p) => str_starts_with($p, 'ecommerce_'))) $permissions[] = 'shop';
        return $permissions;
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
