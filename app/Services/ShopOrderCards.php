<?php

namespace App\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ShopOrderCards
{
    public function enrich(Collection $orders, int $businessId, int $previewLimit = 4): Collection
    {
        if ($orders->isEmpty()) return $orders;
        $items = DB::table('ecommerce_order_items as i')
            ->leftJoin('products as p', fn ($join) => $join->on('p.id', '=', 'i.product_id')->on('p.business_id', '=', 'i.business_id'))
            ->where('i.business_id', $businessId)->whereIn('i.order_id', $orders->pluck('id'))
            ->orderBy('i.id')->get(['i.order_id', 'i.product_id', 'i.name', 'p.photo_path', 'p.updated_at'])->groupBy('order_id');
        foreach ($orders as $order) {
            if (isset($order->created_at)) $order->created_at = \App\Support\AppTime::utc($order->created_at);
            $lines = $items->get($order->id, collect());
            $order->item_count = $lines->count();
            $order->item_previews = $lines->take($previewLimit)->map(fn ($item) => [
                'product_id' => $item->product_id, 'name' => $item->name,
                'photo_url' => $item->photo_path ? '/shop-admin/orders/'.$order->id.'/items/'.$item->product_id.'/photo?v='.strtotime((string) $item->updated_at) : null,
            ])->values();
        }
        return $orders;
    }
}
