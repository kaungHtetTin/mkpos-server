<?php

namespace App\Http\Controllers\Ecommerce;

use App\Http\Controllers\Controller;
use App\Services\EcommerceService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ShopRadarController extends Controller
{
    public function index(Request $request, EcommerceService $commerce): array
    {
        $data = $request->validate(['latitude' => ['required', 'numeric', 'between:-90,90'], 'longitude' => ['required', 'numeric', 'between:-180,180'],
            'radius_km' => ['nullable', 'numeric', 'between:1,50'], 'q' => ['nullable', 'string', 'max:120'],
            'offset' => ['nullable', 'integer', 'min:0'], 'limit' => ['nullable', 'integer', 'between:1,48']]);
        $radius = (float) ($data['radius_km'] ?? 5); $limit = (int) ($data['limit'] ?? 24); $offset = (int) ($data['offset'] ?? 0);
        // Clamp floating point rounding at zero distance and the antipodes.
        // Longitude difference is periodic, so searches cross the date line safely.
        $distance = '6371 * ACOS(LEAST(1.0, GREATEST(-1.0, COS(RADIANS(?)) * COS(RADIANS(l.latitude)) * COS(RADIANS(l.longitude) - RADIANS(?)) + SIN(RADIANS(?)) * SIN(RADIANS(l.latitude)))))';
        $bindings = [$data['latitude'], $data['longitude'], $data['latitude']];
        $query = DB::table('storefront_settings as sf')->join('businesses as b', 'b.id', '=', 'sf.business_id')
            ->join('shop_owner_locations as l', 'l.business_id', '=', 'sf.business_id')
            ->where('b.status', 'active')->where('sf.storefront_enabled', true)->where('sf.marketplace_enabled', true)->where('sf.marketplace_suspended', false)
            ->whereBetween('l.latitude', [-90, 90])->whereBetween('l.longitude', [-180, 180])
            ->whereExists(function ($q) {
                $q->selectRaw('1')->from('business_subscriptions as bs')->whereColumn('bs.business_id', 'b.id')->whereIn('bs.status', ['active', 'cancelled'])
                    ->where('bs.starts_at', '<=', now())->where(fn ($q) => $q->where('bs.ends_at', '>', now())->orWhere(fn ($q) => $q->whereNull('bs.ends_at')->where('bs.status', 'active')));
            })->whereRaw("($distance) <= ?", [...$bindings, $radius]);
        if ($q = trim($data['q'] ?? '')) $query->where('sf.display_name', 'like', '%'.$q.'%');
        $rows = $query->select('sf.*')->selectRaw("($distance) as distance_km", $bindings)->orderBy('distance_km')->orderBy('sf.id')->offset($offset)->limit($limit + 1)->get();
        return ['items' => $rows->take($limit)->map(fn ($row) => $commerce->storeView($row) + ['distance_km' => round((float) $row->distance_km, 3)])->values(),
            'has_more' => $rows->count() > $limit, 'radius_km' => $radius];
    }
}
