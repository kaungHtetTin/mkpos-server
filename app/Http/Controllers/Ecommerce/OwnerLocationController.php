<?php

namespace App\Http\Controllers\Ecommerce;

use App\Http\Controllers\Controller;
use App\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class OwnerLocationController extends Controller
{
    public function __construct(private TenantContext $tenant) {}

    public function show(): array
    {
        $location = DB::table('shop_owner_locations')->where('business_id', $this->tenant->requireId())->first();
        return ['submitted' => $location !== null, 'location' => $location ? [
            'latitude' => (float) $location->latitude, 'longitude' => (float) $location->longitude,
            'accuracy' => (float) $location->accuracy,
            'captured_at' => $location->captured_at ? \Carbon\CarbonImmutable::parse($location->captured_at, 'UTC')->toIso8601String() : null,
        ] : null];
    }

    public function store(Request $request): array
    {
        abort_unless($request->user('web')->role === 'owner', 403, 'Only the business owner can submit the shop location.');
        $data = $request->validate([
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
            'accuracy' => ['required', 'numeric', 'min:0', 'max:100000000'],
        ]);
        DB::table('shop_owner_locations')->updateOrInsert(['business_id' => $this->tenant->requireId()], $data + [
            'owner_id' => $request->user('web')->id, 'captured_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        return $this->show();
    }
}
