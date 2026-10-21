<?php

namespace App\Support;

use Carbon\CarbonImmutable;

/** SQL timestamps use app.timezone; shop clients expect UTC SQL strings. */
final class AppTime
{
    public static function businessTimezone(): string
    {
        $id = app(\App\Tenancy\TenantContext::class)->id();
        return ($id ? \Illuminate\Support\Facades\DB::table('businesses')->where('id', $id)->value('timezone') : null) ?: config('app.timezone');
    }

    public static function filterDays($query, ?string $from, ?string $to, string $column = 'created_at'): void
    {
        validator(['from' => $from, 'to' => $to], [
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => array_filter(['nullable', 'date_format:Y-m-d', $from ? 'after_or_equal:from' : null]),
        ])->validate();
        $timezone = self::businessTimezone();
        if ($from) $query->where($column, '>=', self::day($from, $timezone));
        if ($to) $query->where($column, '<', self::storage(CarbonImmutable::createFromFormat('!Y-m-d', $to, $timezone)->addDay()));
    }

    public static function storage($value, ?string $timezone = null): CarbonImmutable
    {
        return CarbonImmutable::parse($value, $timezone ?: config('app.timezone'))
            ->setTimezone(config('app.timezone'));
    }

    public static function utc($value): ?string
    {
        return $value === null || $value === '' ? null : self::storage($value)->utc()->format('Y-m-d H:i:s');
    }

    public static function iso($value): ?string
    {
        return $value === null || $value === '' ? null : self::storage($value)->utc()->toISOString();
    }

    public static function day(string $date, string $timezone): CarbonImmutable
    {
        return CarbonImmutable::createFromFormat('!Y-m-d', $date, $timezone)->setTimezone(config('app.timezone'));
    }

    // Called only at the shop response boundary, never on database rows used by cursors.
    public static function shopPayload($value)
    {
        if ($value instanceof \Illuminate\Support\Collection) return $value->map(fn ($item) => self::shopPayload($item));
        if (is_object($value)) return (object) self::shopPayload((array) $value);
        if (! is_array($value)) return $value;
        foreach ($value as $key => $item) {
            $value[$key] = in_array($key, ['created_at', 'updated_at', 'expires_at', 'owner_last_seen_at', 'customer_last_seen_at', 'first_purchase_at', 'last_purchase_at', 'last_purchase'], true)
                ? self::utc($item) : self::shopPayload($item);
        }
        return $value;
    }
}
