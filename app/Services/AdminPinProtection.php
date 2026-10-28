<?php

namespace App\Services;

use App\Tenancy\TenantContext;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;

class AdminPinProtection
{
    public function configured(): bool
    {
        return $this->hash() !== '';
    }

    private function hash(): string
    {
        return (string) (DB::table('settings')->where('business_id', app(TenantContext::class)->requireId())->where('key', 'admin_pin_hash')->value('value') ?? '');
    }

    public function authorize(?Request $request = null, ?string $legacyPin = null, bool $allowOwnerRecovery = false): void
    {
        $hash = $this->hash();
        if ($hash === '') return;
        $request ??= request();
        $actor = $request->user('web');
        $recovery = $allowOwnerRecovery && $actor?->role === 'owner';
        $key = 'admin-pin:'.app(TenantContext::class)->requireId().':'.($actor?->id ?? 'unknown').':'.$request->ip();
        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw new HttpResponseException(response()->json(['message' => 'Too many incorrect PIN attempts. Try again shortly.', 'code' => 'admin_pin_locked'], 429)->header('Retry-After', RateLimiter::availableIn($key)));
        }
        $pin = $request->input('authorization_pin', $legacyPin ?? $request->input('admin_pin', ''));
        $password = $request->input('owner_password', '');
        if ((is_string($pin) && password_verify($pin, $hash)) || ($recovery && is_string($password) && $password !== '' && Hash::check($password, $actor->password))) {
            RateLimiter::clear($key);
            return;
        }
        $attempted = (is_string($pin) && $pin !== '') || (is_string($password) && $password !== '');
        if ($attempted) RateLimiter::hit($key, 60);
        throw new \App\Exceptions\AdminPinRequired($attempted ? 'Admin authorization is incorrect.' : 'Admin PIN is required for this action.', $recovery);
    }

    public function saleRequiresApproval(array $data, $products = null): bool
    {
        $products ??= DB::table('products')->whereIn('id', array_column($data['items'], 'product_id'))->get()->keyBy('id');
        $prices = DB::table('product_prices')->whereIn('product_id', $products->keys())->get()->groupBy('product_id');
        $subtotal = 0;
        $cost = 0;
        foreach ($data['items'] as $item) {
            $product = $products[$item['product_id']] ?? null;
            if (! $product) continue; // Product existence is validated by the posting service.
            $quantity = (float) $item['quantity'];
            $type = trim((string) ($item['price_type'] ?? 'Retail')) ?: 'Retail';
            $rows = $prices->get($product->id, collect());
            $expected = $rows->firstWhere('name', $type)?->price ?? $rows->firstWhere('name', 'Retail')?->price ?? $product->price;
            $price = (int) $item['unit_price'];
            if ($quantity > 0 && ($price !== (int) $expected || $price < (int) $product->cost)) return true;
            $subtotal += (int) round($quantity * $price);
            $cost += ($quantity + (float) ($item['foc_quantity'] ?? 0)) * (int) $product->cost;
        }
        $discount = min($subtotal, (int) ($data['discount'] ?? 0));
        $factor = $subtotal > 0 ? 1 - $discount / $subtotal : 0;
        foreach ($data['items'] as $item) {
            $product = $products[$item['product_id']] ?? null;
            if (! $product) continue;
            $quantity = (float) $item['quantity'];
            $lineCost = ($quantity + (float) ($item['foc_quantity'] ?? 0)) * (int) $product->cost;
            if ($quantity * (int) $item['unit_price'] * $factor + 0.0005 < $lineCost) return true;
        }
        return $subtotal - $discount < $cost;
    }
}
