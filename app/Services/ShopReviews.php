<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class ShopReviews
{
    public function query(int $businessId, int $targetId = 0)
    {
        return DB::table('shop_reviews')->where('business_id', $businessId)->where('target_id', $targetId);
    }

    public function summary(int $businessId, int $targetId = 0): array
    {
        $row = $this->query($businessId, $targetId)->selectRaw('COUNT(*) as count, AVG(rating) as average')->first();
        return ['count' => (int) $row->count, 'average' => $row->average === null ? null : round((float) $row->average, 1)];
    }

    public function eligible(int $businessId, int $customerId, int $targetId): bool
    {
        $orders = DB::table('ecommerce_orders as o')->where('o.business_id', $businessId)
            ->where('o.platform_customer_id', $customerId)->where('o.status', 'completed');
        if ($targetId) $orders->whereExists(function ($query) use ($targetId, $businessId) {
            $query->selectRaw('1')->from('ecommerce_order_items as i')->whereColumn('i.order_id', 'o.id')
                ->where('i.business_id', $businessId)->where('i.product_id', $targetId);
        });
        return $orders->exists();
    }

    public function view(object $review): array
    {
        return ['id' => (int) $review->id, 'target_id' => (int) $review->target_id,
            'rating' => (int) $review->rating, 'comment' => $review->comment,
            'customer_name' => $review->customer_name ?? 'Customer',
            'verified_purchase' => true, 'reply' => $review->reply,
            'replied_at' => \App\Support\AppTime::utc($review->replied_at), 'created_at' => \App\Support\AppTime::utc($review->created_at), 'updated_at' => \App\Support\AppTime::utc($review->updated_at)];
    }
}
