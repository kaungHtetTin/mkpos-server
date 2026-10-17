<?php
namespace App\Services;

use Illuminate\Http\Request;

class ShopCatalogFilters
{
    public function apply($query, Request $request): void
    {
        $data = validator($request->query(), ['sort' => ['sometimes', 'in:default,newest,price_asc,price_desc,rating'],
            'in_stock' => ['sometimes', 'boolean'], 'on_sale' => ['sometimes', 'boolean'],
            'min_price' => ['nullable', 'integer', 'min:0', 'max:1000000000'], 'max_price' => ['nullable', 'integer', 'min:0', 'max:1000000000'],
            'min_rating' => ['nullable', 'numeric', 'min:0', 'max:5']])->validate();
        abort_if(isset($data['min_price'], $data['max_price']) && $data['min_price'] > $data['max_price'], 422, 'Minimum price must not exceed maximum price.');
        $base = "ROUND(CASE WHEN ep.unit = 'purchase' THEN pp.price * p.purchase_conversion_factor ELSE pp.price END)";
        // Mirror ShopPromotions: best active non-exhausted sale, integer rounding,
        // at least one currency unit payable. Apply before pagination and sorting.
        $sale = "(SELECT MIN(GREATEST(1, $base - CASE WHEN sp.discount_type = 'percentage' THEN FLOOR(($base * sp.discount_value + 50) / 100) ELSE sp.discount_value END)) FROM shop_promotions sp WHERE sp.business_id = p.business_id AND sp.product_id = p.id AND sp.unit = ep.unit AND sp.type = 'flash_sale' AND sp.deleted_at IS NULL AND sp.enabled = 1 AND (sp.starts_at IS NULL OR sp.starts_at <= ?) AND (sp.ends_at IS NULL OR sp.ends_at > ?) AND (sp.quantity_limit IS NULL OR sp.used_quantity < sp.quantity_limit))";
        $price = "COALESCE($sale, $base)";
        $dates = [now()->utc()->toDateTimeString(), now()->utc()->toDateTimeString()];
        if (isset($data['min_price'])) $query->whereRaw("$price >= ?", [...$dates, $data['min_price']]);
        if (isset($data['max_price'])) $query->whereRaw("$price <= ?", [...$dates, $data['max_price']]);
        if ($request->boolean('on_sale')) $query->whereRaw("$sale < $base", $dates);
        if ($request->boolean('in_stock')) $query->whereRaw("p.stock > COALESCE((SELECT SUM(sr.quantity) FROM ecommerce_stock_reservations sr WHERE sr.product_id = p.id AND sr.business_id = p.business_id AND sr.status = 'active'), 0)");
        $rating = '(SELECT AVG(r.rating) FROM shop_reviews r WHERE r.business_id = p.business_id AND r.target_id = p.id)';
        if (!empty($data['min_rating'])) $query->whereRaw("$rating >= ?", [$data['min_rating']]);
        match ($data['sort'] ?? 'default') {
            'price_asc' => $query->orderByRaw("$price ASC", $dates),
            'price_desc' => $query->orderByRaw("$price DESC", $dates),
            'rating' => $query->orderByRaw("COALESCE($rating, 0) DESC"),
            'newest' => $query->orderByDesc('p.id'),
            default => null,
        };
        $query->orderBy('p.id');
    }
}
