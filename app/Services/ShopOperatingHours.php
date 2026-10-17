<?php
namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class ShopOperatingHours
{
    public function view(object $store): array
    {
        $timezone = DB::table('businesses')->where('id', $store->business_id)->value('timezone') ?: 'Asia/Yangon';
        $hours = json_decode($store->opening_hours ?? 'null', true) ?: [];
        $now = CarbonImmutable::now($timezone);
        $today = $hours[$now->dayOfWeekIso - 1] ?? null;
        $closedUntil = !empty($store->closed_until) ? CarbonImmutable::parse($store->closed_until, 'UTC') : null;
        $temporaryClosed = $closedUntil && $closedUntil->isFuture();
        $open = $today && !$today['closed'] && $now->format('H:i') >= $today['opens'] && $now->format('H:i') < $today['closes'];
        return ['opening_hours' => $hours, 'timezone' => $timezone, 'announcement' => $store->announcement ?? '',
            'closed_until' => $temporaryClosed ? $closedUntil->toIso8601String() : null,
            'open_now' => $hours ? (bool) ($open && !$temporaryClosed) : null];
    }

    public function temporarilyClosed(object $store): bool
    {
        return !empty($store->closed_until) && CarbonImmutable::parse($store->closed_until, 'UTC')->isFuture();
    }
}
