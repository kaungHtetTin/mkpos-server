<?php

namespace App\Services\SalesAi;

use App\Ai\Agents\SalesRepresentative;
use App\Services\EcommerceService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class PublicTools
{
    public function handle(SalesRepresentative $agent, string $name, array $arguments): array
    {
        $thread = DB::table('shop_chat_threads')->where('id', $agent->thread->id)->where('business_id', $agent->thread->business_id)->first();
        $settings = app(Settings::class)->get((int) $agent->thread->business_id);
        if ($thread) $thread = app(\App\Services\SalesTelegram\Identity::class)->context($thread);
        abort_unless($thread && $thread->sales_ai_mode === 'ai' && (int) $thread->sales_ai_version === (int) $agent->thread->sales_ai_version && $settings['enabled'] && $settings['version'] === $agent->settings['version'], 403);
        abort_unless($name === 'request_human' || in_array($name, $settings['tool_permissions'], true), 403);
        abort_unless(\App\Services\SalesTelegram\Identity::permits($thread, $name), 403, 'This action is not available in this Telegram conversation.');
        abort_unless(app(\App\Services\SalesTelegram\Inbox::class)->current($thread, $agent->messageId),403);
        if (($thread->sales_ai_channel ?? 'web')==='telegram') {
            if ($name==='prepare_checkout') return app(\App\Services\SalesTelegram\Checkout::class)->prepare($agent,$thread,$arguments);
            if ($name==='submit_order_payment_proof') return app(\App\Services\SalesTelegram\Checkout::class)->prepareProof($agent,$thread,$arguments);
            if (in_array($name,\App\Services\SalesTelegram\Identity::ACCOUNT_TOOLS,true)) return app(\App\Services\SalesTelegram\Identity::class)->orders($thread,$name,$arguments);
        }
        abort_if($agent->actionEvent && !in_array($name, SalesRepresentative::FOLLOW_UP_TOOLS, true), 403, 'Form follow-ups can only read order/request status or request human help.');
        if (in_array($name, RepresentativeTools::NAMES, true)) return app(RepresentativeTools::class)->handle($agent, $thread, $name, $arguments);
        $data = Validator::make($arguments, ['query' => 'sometimes|nullable|string|max:80', 'reference' => 'sometimes|nullable|string|max:40', 'after_id' => 'sometimes|integer|min:0', 'reason' => 'sometimes|string|max:160'])->validate();
        if ($name === 'request_human') {
            $agent->handoffReason = trim($data['reason'] ?? '') ?: 'Customer needs human assistance';
            return ['handoff' => 'requested', 'message' => 'The shop team will be notified when this response is saved. Do not promise immediate availability.'];
        }
        $businessId = (int) $thread->business_id;
        $commerce = app(EcommerceService::class);
        return $commerce->scoped($businessId, function () use ($commerce, $thread, $businessId, $name, $data) {
            abort_unless($commerce->eligible($businessId), 403);
            $store = DB::table('storefront_settings')->where('business_id', $businessId)->where('storefront_enabled', true)->first();
            abort_unless($store, 404);
            if ($name === 'search_products') {
                $rows = $commerce->catalogQuery($store)->where('p.id', '>', $data['after_id'] ?? 0)
                    ->when(filled($data['query'] ?? null), fn ($q) => $q->where('p.name', 'like', '%'.$data['query'].'%'))->orderBy('p.id')->limit(6)->get();
                $items = $rows->take(5)->map(function ($product) use ($store, $commerce) {
                    $view = $commerce->productView($store, $product);
                    $public = array_intersect_key($view, array_flip(['id', 'name', 'price', 'original_price', 'currency', 'unit_name', 'available_quantity', 'store_slug']));
                    $public['name'] = mb_substr($public['name'], 0, 160);
                    $public['description'] = mb_substr($view['description'] ?? '', 0, 240);
                    if ($view['flash_sale']) $public['flash_sale'] = [
                        'name' => mb_substr($view['flash_sale']['name'], 0, 160), 'ends_at' => $view['flash_sale']['ends_at'],
                        'remaining_quantity' => $view['flash_sale']['remaining_quantity'],
                    ];
                    return $public;
                })->values();
                return ['items' => $items->all(), 'next_after_id' => $rows->count() > 5 ? $items->last()['id'] : null];
            }
            if ($name === 'shop_information') {
                $profile = app(\App\Services\BusinessProfile::class)->read($businessId);
                return ['name' => mb_substr($profile['shop_name'], 0, 160), 'phone' => mb_substr($profile['shop_phone'] ?? '', 0, 120), 'address' => mb_substr($profile['shop_address'] ?? '', 0, 600),
                    'description' => mb_substr($store->description ?? '', 0, 600), 'pickup_hours' => mb_substr($store->pickup_hours ?? '', 0, 240), 'pickup_instructions' => mb_substr($store->pickup_instructions ?? '', 0, 600),
                    'pickup_address' => mb_substr($store->pickup_address ?? '', 0, 255), 'accepting_orders' => (bool) $store->accepting_orders,
                    'announcement' => mb_substr($store->announcement ?? '', 0, 600), 'closed_until' => $store->closed_until ?? null,
                    'opening_hours' => array_slice(json_decode($store->opening_hours ?? '[]', true) ?: [], 0, 7),
                    'delivery_zones' => array_slice(json_decode($store->delivery_zones ?: '[]', true), 0, 8),
                    'delivery_zones_may_be_truncated' => count(json_decode($store->delivery_zones ?: '[]', true)) > 8,
                    'payment_methods' => collect(json_decode($store->payment_methods ?: '[]', true))->filter(fn ($method) => \App\Services\ShopPaymentMethods::available($method))->take(5)->map(function ($method) {
                        $public = array_intersect_key($method, array_flip(['name', 'type', 'bank', 'account_name', 'account_no', 'instructions']));
                        return array_map(fn ($value) => mb_substr((string) $value, 0, 600), $public);
                    })->values()->all()];
            }
            abort_unless($name === 'customer_orders' && $thread->platform_customer_id, 403);
            return ['items' => DB::table('ecommerce_orders')->where('business_id', $businessId)->where('platform_customer_id', $thread->platform_customer_id)
                ->when(filled($data['reference'] ?? null), fn ($q) => $q->where('reference', $data['reference']))->orderByDesc('id')->limit(5)
                ->get(['reference', 'status', 'payment_status', 'fulfillment', 'total', 'currency', 'created_at'])->map(fn ($row) => (array) $row)->all()];
        });
    }
}
