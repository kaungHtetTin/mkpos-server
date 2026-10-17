<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ShopCustomerNotifications
{
    public function order(object $order, string $event, int $eventId, ?string $reason): void
    {
        if (!$order->platform_customer_id) return;
        $store = DB::table('storefront_settings')->where('business_id', $order->business_id)->first();
        $this->send((int) $order->platform_customer_id, (int) $order->business_id, $event, 'order-event:'.$eventId,
            ['reference' => $order->reference, 'store_name' => $store->display_name ?? '', 'slug' => $store->slug ?? '', 'reason' => $reason]);
    }

    public function send(int $customer, int $business, string $event, string $key, array $payload): void
    {
        DB::table('shop_customer_notifications')->insertOrIgnore(['customer_id' => $customer, 'business_id' => $business,
            'event' => $event, 'entity_key' => $key, 'payload' => json_encode($payload), 'created_at' => now(), 'updated_at' => now()]);
    }

    public function restocks(): int
    {
        $sent = 0;
        DB::table('shop_stock_alerts')->where('active', true)->orderBy('id')->chunkById(100, function ($rows) use (&$sent) {
            foreach ($rows as $row) {
                $settings = DB::table('storefront_settings')->where('business_id', $row->business_id)->first();
                if (!$settings) continue;
                try { $store = app(EcommerceService::class)->store($settings->slug); }
                catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) { if ($e->getStatusCode() === 404) continue; throw $e; }
                $p = app(EcommerceService::class)->catalogQuery($store)->where('p.id', $row->product_id)->first();
                if (!$p) continue;
                $view = app(EcommerceService::class)->productView($store, $p);
                if ($view['available_quantity'] <= 0) continue;
                DB::transaction(function () use ($row, $view, &$sent) {
                    $alert = DB::table('shop_stock_alerts')->where('id', $row->id)->lockForUpdate()->first();
                    if (!$alert->active) return;
                    $this->send((int) $row->customer_id, (int) $row->business_id, 'back_in_stock', 'restock:'.$row->id.':'.Str::uuid(),
                        ['slug' => $view['store_slug'], 'product_id' => $view['id'], 'name' => $view['name'], 'store_name' => $view['store_name']]);
                    DB::table('shop_stock_alerts')->where('id', $row->id)->update(['active' => false, 'notified_at' => now(), 'updated_at' => now()]);
                    $sent++;
                });
            }
        });
        return $sent;
    }
}
