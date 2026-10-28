<?php

namespace App\Ai\Skills;

use App\Services\AgentSkills\SkillCatalog;

class OwnerOnboarding
{
    public const SCREENS = [
        'settings' => 'Settings', 'products' => 'Products', 'purchases' => 'Purchases',
        'sell' => 'Sell', 'sales' => 'Sales', 'customers' => 'Customers',
        'suppliers' => 'Suppliers', 'expenses' => 'Expenses', 'reports' => 'Reports', 'billing' => 'Billing',
    ];

    public static function guides(): array
    {
        return app(SkillCatalog::class)->session('wyne_wyne', ['role' => 'owner', 'modules' => config('mkpos.enabled_modules', [])])->guides('onboarding');
    }
}
