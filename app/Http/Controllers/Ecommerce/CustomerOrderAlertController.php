<?php

namespace App\Http\Controllers\Ecommerce;

use App\Http\Controllers\Controller;
use App\Services\EcommerceService;
use App\Services\ShopChatRealtime;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CustomerOrderAlertController extends Controller
{
    private function scope(Request $request): array
    {
        $data = $request->validate(['reference' => ['nullable', 'string', 'max:100'],
            'credential' => ['required_with:reference', 'nullable', 'string', 'max:200']]);
        if (!empty($data['reference'])) {
            $order = app(EcommerceService::class)->track($data['reference'], $data['credential']);
            return ['channel' => 'private-shop.orders.tracking.'.$order->reference, 'order' => $order,
                'identity' => ['role' => 'customer', 'business_id' => (int) $order->business_id]];
        }
        $customer = $request->attributes->get('platform_customer');
        abort_unless($customer, 401, 'Please sign in or provide private order tracking details.');
        return ['channel' => 'private-shop.orders.customer.'.$customer->id, 'customer_id' => (int) $customer->id,
            'identity' => ['role' => 'customer', 'customer_id' => (int) $customer->id]];
    }

    public function config(Request $request, ShopChatRealtime $realtime): array
    {
        return array_replace($realtime->settings(), ['enabled' => true, 'channel' => $this->scope($request)['channel']]);
    }

    public function auth(Request $request, ShopChatRealtime $realtime): array
    {
        $scope = $this->scope($request);
        $data = $request->validate(['channel_name' => ['required', 'string', 'max:200']]);
        abort_unless($data['channel_name'] === $scope['channel'], 403);
        return $realtime->auth($scope['channel'], $scope['identity'], false);
    }

    public function index(Request $request): array
    {
        $scope = $this->scope($request);
        $data = $request->validate(['after_id' => ['nullable', 'integer', 'min:0']]);
        $query = DB::table('ecommerce_order_events as event')
            ->join('ecommerce_orders as orders', fn ($join) => $join->on('orders.id', '=', 'event.order_id')->on('orders.business_id', '=', 'event.business_id'));
        if (isset($scope['customer_id'])) $query->where('orders.platform_customer_id', $scope['customer_id']);
        else $query->where('orders.id', $scope['order']->id)->where('orders.business_id', $scope['order']->business_id);
        if (!isset($data['after_id'])) return ['cursor' => (int) $query->max('event.id'), 'items' => [], 'has_more' => false];
        $rows = $query->leftJoin('storefront_settings as store', 'store.business_id', '=', 'orders.business_id')
            ->where('event.id', '>', $data['after_id'])->orderBy('event.id')->limit(51)
            ->get(['event.id', 'event.event', 'event.reason', 'event.created_at', 'orders.reference', 'store.display_name as store_name']);
        $items = $rows->take(50)->map(fn ($row) => array_replace((array) $row, ['id' => (int) $row->id]));
        return ['items' => \App\Support\AppTime::shopPayload($items->values()), 'cursor' => $items->isEmpty() ? (int) $data['after_id'] : $items->last()['id'], 'has_more' => $rows->count() > 50];
    }
}
