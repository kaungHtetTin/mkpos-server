<?php

namespace App\Http\Controllers\Api;

use App\Tenancy\TenantContext;
use DateTimeZone;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class BusinessTimezoneController extends ApiController
{
    private const DEFAULT_TIMEZONE = 'Asia/Yangon';

    public function show(): array
    {
        $timezone = DB::table('businesses')->where('id', app(TenantContext::class)->requireId())->value('timezone') ?: self::DEFAULT_TIMEZONE;
        $options = DateTimeZone::listIdentifiers(DateTimeZone::ALL);
        // Keep an existing legacy alias selectable without changing old records.
        if (in_array($timezone, DateTimeZone::listIdentifiers(DateTimeZone::ALL_WITH_BC), true) && !in_array($timezone, $options, true)) $options[] = $timezone;
        sort($options);
        return ['timezone' => $timezone, 'default_timezone' => self::DEFAULT_TIMEZONE,
            'storage_timezone' => config('app.timezone'), 'options' => $options];
    }

    public function update(Request $request): array
    {
        $allowed = DateTimeZone::listIdentifiers(DateTimeZone::ALL_WITH_BC);
        $data = $request->validate([
            'timezone' => ['required', 'string', Rule::in($allowed)],
            'expected_timezone' => ['sometimes', 'required', 'string', Rule::in($allowed)],
        ]);
        $businessId = app(TenantContext::class)->requireId();
        DB::transaction(function () use ($businessId, $data) {
            $business = DB::table('businesses')->where('id', $businessId)->lockForUpdate()->first();
            $current = $business->timezone ?: self::DEFAULT_TIMEZONE;
            abort_if(isset($data['expected_timezone']) && $data['expected_timezone'] !== $current && $data['timezone'] !== $current,
                409, 'The business time zone changed on another device. Refresh this page before saving.');
            if ($data['timezone'] !== $business->timezone) DB::table('businesses')->where('id', $businessId)->update(['timezone' => $data['timezone'], 'updated_at' => now()]);
        });
        // Reuse the existing session contract, including fresh business metadata.
        return ['timezone' => $data['timezone'], 'session' => app(AuthController::class)->me($request)];
    }
}
