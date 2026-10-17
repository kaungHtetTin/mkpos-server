<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class ShopPromotions
{
    public function activeQuery(int $businessId, string $type)
    {
        return DB::table('shop_promotions')->where('business_id', $businessId)->where('type', $type)
            ->whereNull('deleted_at')->where('enabled', true)
            ->where(fn ($q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', now()->utc()))
            ->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>', now()->utc()));
    }

    public function discount(int $amount, object $promotion): int
    {
        $value = $promotion->discount_type === 'percentage'
            ? intdiv($amount * (int) $promotion->discount_value + 50, 100) : (int) $promotion->discount_value;
        // Existing order/payment flows require a positive merchandise total.
        return max(0, min($value, $amount - 1));
    }

    public function flash(int $businessId, int $productId, string $unit, int $price): ?array
    {
        $promotions = $this->activeQuery($businessId, 'flash_sale')->where('product_id', $productId)->where('unit', $unit)
            ->where(fn ($q) => $q->whereNull('quantity_limit')->orWhereColumn('used_quantity', '<', 'quantity_limit'))->orderBy('id')->get();
        $best = null;
        foreach ($promotions as $promotion) {
            $discount = $this->discount($price, $promotion);
            if (!$discount || ($best && $best['price'] <= $price - $discount)) continue;
            $remaining = $promotion->quantity_limit === null ? null
                : (app(EcommerceService::class)->milli($promotion->quantity_limit) - app(EcommerceService::class)->milli($promotion->used_quantity)) / 1000;
            $best = ['id' => $promotion->id, 'name' => $promotion->name, 'price' => $price - $discount,
                'ends_at' => $promotion->ends_at ? \Carbon\Carbon::parse($promotion->ends_at, 'UTC')->toIso8601String() : null,
                'remaining_quantity' => $remaining];
        }
        return $best;
    }

    public function coupon(int $businessId, ?string $code, int $subtotal): ?array
    {
        $code = strtoupper(trim($code ?? ''));
        if ($code === '') return null;
        $promotion = $this->activeQuery($businessId, 'coupon')->where('code', $code)->first();
        abort_unless($promotion, 422, 'This coupon is invalid, paused, or outside its active dates.');
        abort_if($promotion->usage_limit !== null && $promotion->used_count >= $promotion->usage_limit, 422, 'This coupon has reached its usage limit.');
        abort_if($subtotal < $promotion->minimum_subtotal, 422, 'The order does not meet this coupon’s minimum merchandise subtotal.');
        $discount = $this->discount($subtotal, $promotion);
        abort_unless($discount > 0, 422, 'This order is too small to apply the coupon.');
        return ['id' => $promotion->id, 'type' => 'coupon', 'name' => $promotion->name, 'code' => $promotion->code, 'discount' => $discount];
    }

    // The storefront row is locked by checkout and every promotion mutation.
    // Redemption happens only after quote verification; checkout retries return first.
    public function consume(int $businessId, int $orderId, array $quote): void
    {
        foreach ($quote['promotions'] as $promotion) {
            $quantity = collect($quote['items'])->where('promotion_id', $promotion['id'])->sum('quantity');
            DB::table('shop_promotion_redemptions')->insert(['business_id' => $businessId, 'promotion_id' => $promotion['id'],
                'order_id' => $orderId, 'quantity' => $quantity, 'discount' => $promotion['discount'], 'created_at' => now()]);
            DB::table('shop_promotions')->where('business_id', $businessId)->where('id', $promotion['id'])
                ->increment('used_count', 1, ['used_quantity' => DB::raw('used_quantity + '.number_format($quantity, 3, '.', '')), 'updated_at' => now()]);
        }
    }
}
