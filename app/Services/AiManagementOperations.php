<?php

namespace App\Services;

use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;

/** Registry of reviewable POS and online-shop operations. */
class AiManagementOperations
{
    public const OPERATIONS = ['manage_price_types', 'manage_business_timezone', ...AiPosManagement::OPERATIONS, ...AiShopManagement::OPERATIONS, ...AiSalesAiManagement::OPERATIONS];

    public function ready(): bool
    {
        return Schema::hasTable('ai_management_audits') && Schema::hasTable('ai_business_actions');
    }

    public function supports(string $operation): bool
    {
        return in_array($operation, self::OPERATIONS, true);
    }

    public function module(string $operation): string
    {
        return in_array($operation, [...AiShopManagement::OPERATIONS, ...AiSalesAiManagement::OPERATIONS], true) ? 'shop' : ($operation === 'manage_business_timezone' ? 'settings' : 'products');
    }

    public function build(string $operation, array $input, int $userId): array
    {
        abort_unless($this->ready(), 503, 'Business management setup is required.');
        if (in_array($operation, AiSalesAiManagement::OPERATIONS, true)) return app(AiSalesAiManagement::class)->build($operation, $input, $userId);
        if ($operation === 'manage_price_types') {
            abort_unless(in_array('products', config('mkpos.enabled_modules', []), true), 403, 'Products module is unavailable.');

            return app(AiPriceRuleActions::class)->build($input) + ['requires_pin' => app(AdminPinProtection::class)->configured()];
        }
        if ($operation === 'manage_business_timezone') {
            abort_unless(in_array('settings', config('mkpos.enabled_modules', []), true), 403, 'Settings module is unavailable.');
            abort_if(array_diff(array_keys($input), ['timezone']), 422, 'Only timezone is supported.');
            $data = validator($input, ['timezone' => ['required', 'string', Rule::in(\DateTimeZone::listIdentifiers(\DateTimeZone::ALL_WITH_BC))]])->validate();
            $before = (array) DB::table('businesses')->where('id', app(TenantContext::class)->requireId())->lockForUpdate()->first(['name', 'currency', 'timezone']);

            return ['payload' => ['input' => $input, 'timezone' => $data['timezone']], 'baseline' => $before, 'preview' => [['name' => 'Business time zone', 'before' => ['timezone' => $before['timezone']], 'after' => ['timezone' => $data['timezone'], 'effect' => 'Future business-local dates use this timezone; stored timestamps are unchanged.']]]];
        }

        $built = in_array($operation, AiShopManagement::OPERATIONS, true) ? app(AiShopManagement::class)->build($operation, $input, $userId) : app(AiPosManagement::class)->build($operation, $input, $userId);
        abort_if(count($built['preview']) > 5000, 422, 'This proposal exceeds 5,000 review rows. Narrow the request.');

        return $built;
    }

    public function unchanged(string $operation, array $payload, array $baseline, int $userId): bool
    {
        abort_unless($this->ready(), 503, 'Business management setup is required.');
        if (in_array($operation, AiSalesAiManagement::OPERATIONS, true)) return app(AiSalesAiManagement::class)->unchanged($operation, $payload, $baseline);
        if ($operation === 'manage_price_types') {
            abort_unless(in_array('products', config('mkpos.enabled_modules', []), true), 403, 'Products module is unavailable.');

            return app(AiPriceRuleActions::class)->baseline() == $baseline;
        }

        return $this->build($operation, $payload['input'], $userId)['baseline'] == $baseline;
    }

    public function execute(string $operation, array $payload, int $userId, array $approval = []): array
    {
        if (in_array($operation, AiSalesAiManagement::OPERATIONS, true)) return app(AiSalesAiManagement::class)->execute($operation, $payload, $userId);
        if ($operation === 'manage_price_types') {
            app(AdminPinProtection::class)->authorize(request(), $approval['admin_pin'] ?? '');
            return app(AiPriceRuleActions::class)->execute($payload);
        }
        if ($operation === 'manage_business_timezone') {
            DB::table('businesses')->where('id', app(TenantContext::class)->requireId())->update(['timezone' => $payload['timezone'], 'updated_at' => now()]);

            return ['settings' => ['timezone' => $payload['timezone']], 'modules' => ['settings', 'reports', 'shop', 'sales', 'purchases', 'expenses', 'transactions']];
        }

        return in_array($operation, AiShopManagement::OPERATIONS, true) ? app(AiShopManagement::class)->execute($operation, $payload, $userId, $approval) : app(AiPosManagement::class)->execute($operation, $payload, $userId, $approval);
    }
}
