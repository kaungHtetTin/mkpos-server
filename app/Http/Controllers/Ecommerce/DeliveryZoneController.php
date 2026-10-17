<?php

namespace App\Http\Controllers\Ecommerce;

use App\Http\Controllers\Controller;
use App\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class DeliveryZoneController extends Controller
{
    public function __construct(private TenantContext $tenant, private AdminController $admin) {}

    public function index(): array
    {
        return ['items' => $this->admin->settings()['delivery_zones']];
    }

    public function store(Request $request)
    {
        $zone = $request->validate($this->rules()) + ['id' => (string) Str::uuid()];
        $this->mutate(function ($zones) use ($zone) {
            abort_if(count($zones) >= 100, 422, 'A shop can have up to 100 delivery zones.');
            return [...$zones, $zone];
        });
        return response()->json($zone, 201);
    }

    public function update(Request $request, string $zoneId): array
    {
        $zone = $request->validate($this->rules()) + ['id' => $zoneId];
        $this->mutate(function ($zones) use ($zone, $zoneId) {
            abort_unless(collect($zones)->contains('id', $zoneId), 404, 'Delivery zone unavailable.');
            return array_map(fn ($item) => $item['id'] === $zoneId ? $zone : $item, $zones);
        });
        return $zone;
    }

    public function destroy(string $zoneId): array
    {
        $this->mutate(function ($zones) use ($zoneId) {
            abort_unless(collect($zones)->contains('id', $zoneId), 404, 'Delivery zone unavailable.');
            return array_values(array_filter($zones, fn ($zone) => $zone['id'] !== $zoneId));
        });
        return ['ok' => true];
    }

    private function rules(): array
    {
        return ['name' => ['required', 'string', 'max:255'], 'fee' => ['required', 'integer', 'min:0', 'max:1000000000']];
    }

    private function mutate(callable $change): void
    {
        $this->admin->settings();
        DB::transaction(function () use ($change) {
            $store = DB::table('storefront_settings')->where('business_id', $this->tenant->requireId())->lockForUpdate()->first();
            $zones = $change(json_decode($store->delivery_zones, true));
            DB::table('storefront_settings')->where('id', $store->id)->update(['delivery_zones' => json_encode($zones), 'updated_at' => now()]);
        });
    }
}
