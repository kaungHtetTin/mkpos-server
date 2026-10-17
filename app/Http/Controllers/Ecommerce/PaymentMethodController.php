<?php

namespace App\Http\Controllers\Ecommerce;

use App\Http\Controllers\Controller;
use App\Services\ShopPaymentMethods;
use App\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PaymentMethodController extends Controller
{
    public function __construct(private TenantContext $tenant, private AdminController $admin) {}

    public function index(): array
    {
        return ['items' => array_map([ShopPaymentMethods::class, 'normalize'], $this->admin->settings()['payment_methods'])];
    }

    public function store(Request $request)
    {
        $method = ShopPaymentMethods::normalize($request->validate(ShopPaymentMethods::rules()) + ['id' => (string) Str::uuid()]);
        $this->mutate(function ($methods) use ($method) {
            abort_if(count($methods) >= 20, 422, 'A shop can have up to 20 payment methods.');
            return [...$methods, $method];
        });
        return response()->json($method, 201);
    }

    public function update(Request $request, string $methodId): array
    {
        $method = ShopPaymentMethods::normalize($request->validate(ShopPaymentMethods::rules()) + ['id' => $methodId]);
        $this->mutate(function ($methods) use ($methodId, $method) {
            abort_unless(collect($methods)->contains('id', $methodId), 404, 'Payment method unavailable.');
            return array_map(fn ($item) => $item['id'] === $methodId ? $method : $item, $methods);
        });
        return $method;
    }

    public function destroy(string $methodId): array
    {
        $this->mutate(function ($methods) use ($methodId) {
            abort_unless(collect($methods)->contains('id', $methodId), 404, 'Payment method unavailable.');
            // Existing orders retain their receiving details in an immutable snapshot.
            return array_values(array_filter($methods, fn ($item) => $item['id'] !== $methodId));
        });
        return ['ok' => true];
    }

    private function mutate(callable $change): void
    {
        $this->admin->settings();
        $businessId = $this->tenant->requireId();
        DB::transaction(function () use ($businessId, $change) {
            $store = DB::table('storefront_settings')->where('business_id', $businessId)->lockForUpdate()->first();
            $methods = $change(json_decode($store->payment_methods, true));
            ShopPaymentMethods::validateAvailability($methods);
            DB::table('storefront_settings')->where('business_id', $businessId)->update([
                'payment_methods' => json_encode($methods), 'updated_at' => now(),
            ]);
        });
    }
}
