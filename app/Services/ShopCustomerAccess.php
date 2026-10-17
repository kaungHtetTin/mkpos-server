<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class ShopCustomerAccess
{
    public static function phone(string $phone): string
    {
        return str_replace([' ', '-', '(', ')', '+'], '', trim($phone));
    }

    public static function key(string $phone, ?int $accountId): string
    {
        return $accountId ? 'account:'.$accountId : 'guest:'.hash('sha256', self::phone($phone));
    }

    public static function blocked(int $businessId, string $phone, ?string $customerKey = null): bool
    {
        $phone = self::phone($phone);
        return DB::table('shop_customer_controls')->where('business_id', $businessId)->where('blocked', true)
            ->where(function ($query) use ($phone, $customerKey) {
                $query->where('customer_key', $customerKey ?: 'guest:'.hash('sha256', $phone));
                if ($phone !== '') $query->orWhere('phone', $phone);
            })->exists();
    }
}
