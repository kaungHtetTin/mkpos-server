<?php

namespace App\Services;

use Illuminate\Http\Request;

class ShopFeaturedRanking
{
    public function apply($query, Request $request, bool $products): void
    {
        $data = $request->validate([
            'featured' => ['sometimes', 'boolean'],
            'latitude' => ['nullable', 'required_with:longitude', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'required_with:latitude', 'numeric', 'between:-180,180'],
        ]);
        if (! $request->boolean('featured')) return;

        $business = $products ? 'p.business_id' : 'sf.business_id';
        $target = $products ? 'p.id' : '0';
        $cutoff = now()->subDays(30)->toDateTimeString();
        // Count completed paid orders, rather than revenue across different currencies
        // or quantities across unrelated publication units. Exclude voided sales.
        $productCondition = $products ? " AND EXISTS (SELECT 1 FROM ecommerce_order_items fi WHERE fi.order_id = fo.id AND fi.business_id = fo.business_id AND fi.product_id = p.id)" : '';
        $sales = "(SELECT COUNT(*) FROM ecommerce_orders fo WHERE fo.business_id = $business AND fo.status = 'completed' AND fo.payment_status = 'paid' AND fo.sale_voided = 0 AND fo.created_at >= ?$productCondition)";
        $favorites = "(SELECT COUNT(*) FROM shop_customer_favorites ff WHERE ff.business_id = $business AND ff.product_id = $target)";
        $reviews = "(SELECT COUNT(*) FROM shop_reviews fr WHERE fr.business_id = $business AND fr.target_id = $target)";
        $ratingSum = "(SELECT COALESCE(SUM(fr.rating), 0) FROM shop_reviews fr WHERE fr.business_id = $business AND fr.target_id = $target)";

        // Bounded sales/favorites signals and a five-review, 3.5-star rating prior.
        $score = "40 * $sales / ($sales + 10.0) + 25 * $favorites / ($favorites + 5.0) + 25 * ($ratingSum + 17.5) / (($reviews + 5.0) * 5.0)";
        $bindings = [$cutoff, $cutoff];
        if (isset($data['latitude'], $data['longitude'])) {
            $query->leftJoin('shop_owner_locations as fl', 'fl.business_id', '=', $business);
            $distance = '6371 * ACOS(LEAST(1.0, GREATEST(-1.0, COS(RADIANS(?)) * COS(RADIANS(fl.latitude)) * COS(RADIANS(fl.longitude) - RADIANS(?)) + SIN(RADIANS(?)) * SIN(RADIANS(fl.latitude)))))';
            $score .= " + CASE WHEN fl.latitude IS NULL OR fl.longitude IS NULL THEN 0 ELSE 10 / (1 + ($distance) / 10.0) END";
            array_push($bindings, $data['latitude'], $data['longitude'], $data['latitude']);
        }
        // Ranking precedes pagination, with stable IDs breaking equal scores.
        $query->reorder()->orderByRaw("($score) DESC", $bindings)->orderBy($products ? 'p.id' : 'sf.id');
    }
}
