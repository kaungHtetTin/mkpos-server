<?php

namespace App\Services;

use Illuminate\Validation\ValidationException;

/** Public receiving details only. Gateway credentials must never live here. */
class ShopPaymentMethods
{
    public const FIELDS = ['id', 'name', 'type', 'instructions', 'bank', 'account_name', 'account_no', 'is_active', 'provider'];

    public static function defaultCash(): array
    {
        return ['id' => 'cash', 'name' => 'Cash on delivery', 'type' => 'cash',
            'instructions' => 'Pay on delivery or pickup.', 'is_active' => true, 'provider' => 'manual'];
    }

    public static function withDefaultCash(array $methods): array
    {
        foreach ($methods as $index => $method) {
            if (($method['type'] ?? null) === 'cash') {
                unset($methods[$index]);
                return [self::normalize(array_replace(self::defaultCash(), $method, [
                    'name' => 'Cash on delivery', 'is_active' => true, 'provider' => 'manual',
                ])), ...array_values($methods)];
            }
        }
        $cash = self::defaultCash();
        if (collect($methods)->contains('id', $cash['id'])) $cash['id'] = (string) \Illuminate\Support\Str::uuid();
        return [$cash, ...$methods];
    }

    public static function rules(string $prefix = ''): array
    {
        return collect([
            'name' => ['required', 'string', 'max:100'],
            'type' => ['required', 'in:cash,transfer'],
            'instructions' => ['nullable', 'string', 'max:2000'],
            'bank' => ['nullable', 'string', 'max:100'],
            'account_name' => ['nullable', 'string', 'max:255'],
            'account_no' => ['nullable', 'string', 'max:100'],
            'is_active' => ['sometimes', 'boolean'],
            // Future providers need their own verified callback and refund flow.
            'provider' => ['sometimes', 'in:manual'],
        ])->mapWithKeys(fn ($rules, $field) => [$prefix.$field => $rules])->all();
    }

    public static function normalize(array $method): array
    {
        $method = array_intersect_key($method, array_flip(self::FIELDS));
        $method['provider'] = $method['provider'] ?? 'manual';
        $method['is_active'] = (bool) ($method['is_active'] ?? true);
        if ($method['type'] === 'cash') {
            unset($method['bank'], $method['account_name'], $method['account_no']);
        }
        return $method;
    }

    public static function available(array $method): bool
    {
        return ($method['is_active'] ?? true) && ($method['provider'] ?? 'manual') === 'manual'
            && in_array($method['type'], ['cash', 'transfer'], true);
    }

    public static function validateAvailability(array $methods): void
    {
        if (! collect($methods)->contains(fn ($method) => self::available($method))) {
            throw ValidationException::withMessages(['payment_methods' => 'Keep at least one active manual payment method.']);
        }
    }
}
