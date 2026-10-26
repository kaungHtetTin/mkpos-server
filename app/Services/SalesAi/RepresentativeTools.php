<?php

namespace App\Services\SalesAi;

use App\Ai\Agents\SalesRepresentative;
use App\Http\Controllers\Ecommerce\CustomerAccountController;
use App\Http\Controllers\Ecommerce\ShopController;
use App\Services\EcommerceService;
use App\Services\ShopPaymentMethods;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/** Only public catalog facts and the authenticated conversation customer's records. */
class RepresentativeTools
{
    public const NAMES = ['get_product_details', 'compare_products', 'recommend_products', 'find_product_from_image', 'get_sales_policies', 'calculate_quote', 'manage_customer_cart', 'get_delivery_options', 'prepare_checkout', 'submit_confirmed_order', 'submit_order_payment_proof', 'get_customer_order_details', 'request_order_change', 'request_cancellation', 'request_return', 'get_customer_request_status', 'prepare_buy_again'];

    public static function schema(JsonSchema $s, string $name): array
    {
        $items = fn () => $s->array()->max(100)->items($s->object(['product_id' => $s->integer()->min(1)->required(), 'quantity' => $s->number()->min(.001)->max(100000)->required()]));
        $reference = fn () => $s->string()->max(40)->required();
        return match ($name) {
            'get_product_details' => ['product_id' => $s->integer()->min(1)->required()],
            'compare_products' => ['product_ids' => $s->array()->min(2)->max(5)->items($s->integer()->min(1))->required()],
            'recommend_products' => ['query' => $s->string()->max(120), 'category' => $s->string()->max(255), 'budget_min' => $s->integer()->min(0), 'budget_max' => $s->integer()->min(0), 'after_id' => $s->integer()->min(0)],
            'find_product_from_image' => ['query' => $s->string()->max(120)->description('Visible product text extracted from the attached image. Do not guess.')->required(), 'image_message_id' => $s->integer()->min(1)],
            'get_sales_policies', 'get_delivery_options' => [],
            'calculate_quote' => ['items' => $items(), 'fulfillment' => $s->string()->enum(['pickup', 'delivery'])->required(), 'payment_method_id' => $s->string()->max(50)->required(), 'delivery_zone_id' => $s->string()->max(50), 'coupon_code' => $s->string()->max(40)],
            'manage_customer_cart' => ['action' => $s->string()->enum(['read', 'update', 'clear'])->required(), 'items' => $items()->description('Exact complete replacement cart for update; omit for read/clear.')],
            'prepare_checkout' => ['items' => $items(), 'coupon_code' => $s->string()->max(40)],
            'submit_confirmed_order' => ['action_id' => $s->string()->format('uuid')->required()],
            'submit_order_payment_proof' => ['reference' => $reference(), 'image_message_id' => $s->integer()->min(1)],
            'get_customer_order_details', 'get_customer_request_status', 'prepare_buy_again' => ['reference' => $reference()],
            'request_cancellation' => ['reference' => $reference(), 'reason' => $s->string()->min(5)->max(2000)->required()],
            'request_return' => ['reference' => $reference(), 'reason' => $s->string()->min(5)->max(2000)->required(), 'image_message_id' => $s->integer()->min(1)],
            'request_order_change' => ['reference' => $reference(), 'reason' => $s->string()->min(5)->max(2000)->required(), 'changes' => $s->object(['address' => $s->string()->max(2000), 'phone' => $s->string()->max(80), 'notes' => $s->string()->max(2000), 'items' => $items()])->required()],
        };
    }

    public function fields(string $name): array
    {
        return array_keys(self::schema(new \Illuminate\JsonSchema\JsonSchemaTypeFactory, $name));
    }

    public function store(object $thread): object
    {
        $commerce = app(EcommerceService::class);
        $store = DB::table('storefront_settings')->where('business_id', $thread->business_id)->first();
        abort_unless($store && $store->storefront_enabled && $commerce->eligible((int) $thread->business_id), 404, 'Shop unavailable.');
        return $store;
    }

    public function order(object $thread, string $reference, bool $lock = false): object
    {
        abort_unless($thread->platform_customer_id, 403, 'Sign in to your customer account to view orders.');
        $order = DB::table('ecommerce_orders')->where('business_id', $thread->business_id)->where('platform_customer_id', $thread->platform_customer_id)->where('reference', $reference)->when($lock, fn ($q) => $q->lockForUpdate())->first();
        abort_unless($order, 404, 'Order unavailable in this customer conversation.');
        return $order;
    }

    public function product(object $store, int $id): array
    {
        $commerce = app(EcommerceService::class);
        $row = $commerce->catalogQuery($store)->where('p.id', $id)->first();
        abort_unless($row, 404, 'Published product unavailable in this shop.');
        return $commerce->productView($store, $row);
    }

    public function options(object $thread): array
    {
        $store = $this->store($thread);
        $profile = app(\App\Services\BusinessProfile::class)->read((int) $thread->business_id);
        return ['fulfillment' => ['pickup', 'delivery'], 'pickup_address' => $store->pickup_address ?: $profile['shop_address'], 'pickup_hours' => $store->pickup_hours, 'pickup_instructions' => $store->pickup_instructions,
            'delivery_zones' => json_decode($store->delivery_zones, true) ?: [], 'payment_methods' => array_values(array_filter(json_decode($store->payment_methods, true) ?: [], [ShopPaymentMethods::class, 'available'])),
            'external_courier' => ['id' => '__delivery_service__', 'name' => 'Pay delivery service directly', 'fee' => null], 'delivery_eta' => null, 'notice' => 'Delivery time is not published. External courier fees are payable separately and are not included in the shop total.'];
    }

    public function items(array $items, bool $empty = false): array
    {
        return validator(['items' => $items], ['items' => ($empty ? 'present' : 'required').'|array|min:'.($empty ? 0 : 1).'|max:100', 'items.*' => 'array:product_id,quantity', 'items.*.product_id' => 'required|integer|min:1|distinct', 'items.*.quantity' => ['required', 'numeric', 'between:0.001,100000', 'regex:/^\d+(\.\d{1,3})?$/']])->validate()['items'];
    }

    public function cartPreview(object $thread, array $items): array
    {
        $store = $this->store($thread); $commerce = app(EcommerceService::class); $lines = [];
        foreach ($this->items($items, true) as $item) {
            $p = $this->product($store, (int) $item['product_id']);
            abort_if($item['quantity'] > min($p['available_quantity'], $p['flash_sale']['remaining_quantity'] ?? $p['available_quantity']), 409, 'Not enough available quantity for '.$p['name'].'.');
            abort_if(($commerce->milli($item['quantity']) * $commerce->milli($p['conversion_factor'])) % 1000 !== 0, 422, 'Quantity cannot be represented in the product base unit.');
            $lines[] = ['product_id' => (int) $p['id'], 'name' => $p['name'], 'unit_name' => $p['unit_name'], 'quantity' => (float) $item['quantity'], 'unit_price' => $p['price'], 'original_unit_price' => $p['original_price'], 'available_quantity' => $p['available_quantity'], 'conversion_factor' => $p['conversion_factor'], 'line_total' => intdiv($commerce->milli($item['quantity']) * $p['price'] + 500, 1000), 'photo_url' => $p['photo_url']];
        }
        return ['items' => $lines, 'subtotal' => array_sum(array_column($lines, 'line_total')), 'currency' => DB::table('businesses')->where('id', $thread->business_id)->value('currency'), 'notice' => 'Draft cart only. No stock is reserved. Delivery fees and coupons are calculated at checkout.'];
    }

    public function image(object $thread, int $id): object
    {
        $image = DB::table('shop_chat_messages')->where('thread_id', $thread->id)->where('id', $id)->where('sender', 'customer')->first();
        abort_unless($image && $image->image_path && !$image->image_deleted_at && $image->image_expires_at && now('UTC')->lessThan($image->image_expires_at) && Storage::disk('local')->exists($image->image_path), 422, 'The customer image is unavailable or expired. Upload it again.');
        return $image;
    }

    public function handle(SalesRepresentative $agent, object $thread, string $name, array $a): array
    {
        abort_if(array_diff(array_keys($a), $this->fields($name)), 422, 'Unsupported sales tool fields.');
        $store = $this->store($thread); $commerce = app(EcommerceService::class);
        $result = $commerce->scoped((int) $thread->business_id, function () use ($agent, $thread, $name, $a, $store, $commerce) {
            if ($name === 'get_product_details') return $this->product($store, validator($a, ['product_id' => 'required|integer|min:1'])->validate()['product_id']);
            if ($name === 'compare_products') {
                $ids = validator($a, ['product_ids' => 'required|array|min:2|max:5', 'product_ids.*' => 'required|integer|min:1|distinct'])->validate()['product_ids'];
                return ['items' => array_map(fn ($id) => $this->product($store, $id), $ids), 'comparison_basis' => 'Published facts and current selling units/prices only.'];
            }
            if (in_array($name, ['recommend_products', 'find_product_from_image'])) {
                $data = validator($a, ['query' => ($name === 'find_product_from_image' ? 'required' : 'sometimes').'|string|max:120', 'category' => 'sometimes|string|max:255', 'budget_min' => 'sometimes|integer|min:0', 'budget_max' => 'sometimes|integer|min:0', 'after_id' => 'sometimes|integer|min:0', 'image_message_id' => 'sometimes|integer|min:1'])->validate();
                if ($name === 'find_product_from_image') {
                    $id = $data['image_message_id'] ?? $agent->messageId;
                    abort_unless($id && $id === $agent->messageId, 422, 'Attach the product image to the current message so it can be read.');
                    $this->image($thread, $id);
                }
                abort_if(isset($data['budget_min'], $data['budget_max']) && $data['budget_min'] > $data['budget_max'], 422, 'Budget maximum must be at least its minimum.');
                $q = $commerce->catalogQuery($store)->where('p.id', '>', $data['after_id'] ?? 0)->when($data['category'] ?? null, fn ($q, $category) => $q->where('p.category', $category));
                foreach (array_slice(preg_split('/\s+/u', trim($data['query'] ?? ''), -1, PREG_SPLIT_NO_EMPTY), 0, 6) as $term) $q->where(fn ($q) => $q->where('p.name', 'like', '%'.$term.'%')->orWhere('ep.description', 'like', '%'.$term.'%'));
                $rows = $q->orderBy('p.id')->limit(21)->get();
                $matches = $rows->take(20)->map(fn ($p) => $commerce->productView($store, $p))->filter(fn ($p) => $p['available_quantity'] > 0 && $p['price'] >= ($data['budget_min'] ?? 0) && (!isset($data['budget_max']) || $p['price'] <= $data['budget_max']))->values();
                $items = $matches->take(5)->all();
                $next = $matches->count() > 5 ? end($items)['id'] : ($rows->count() > 20 ? $rows[19]->id : null);
                return ['items' => $items, 'next_after_id' => $next, 'scan_complete' => $next === null, 'customer_confirmation_required' => $name === 'find_product_from_image', 'basis' => $name === 'find_product_from_image' ? 'Candidate catalog matches for visible image text; not confirmed product identification.' : 'Published catalog matches for supplied requirements; no undisclosed product claims.'];
            }
            if ($name === 'get_sales_policies') {
                $policies = array_filter(app(Settings::class)->get((int) $thread->business_id)['sales_policies'], fn ($v) => is_string($v) && trim($v) !== '');
                return ['policies' => $policies, 'unpublished' => array_values(array_diff(Settings::POLICY_FIELDS, array_keys($policies))), 'notice' => 'Unpublished terms require human clarification. These policies do not grant discounts, credit or refund approval.'];
            }
            if ($name === 'get_delivery_options') return $this->options($thread);
            if ($name === 'get_customer_order_details' || $name === 'get_customer_request_status') {
                $reference = validator($a, ['reference' => 'required|string|max:40'])->validate()['reference'];
                $order = $this->order($thread, $reference);
                return $name === 'get_customer_order_details' ? $commerce->orderView($order) : ['reference' => $reference, 'requests' => $commerce->orderView($order)['customer_requests']];
            }
            if ($name === 'calculate_quote') {
                $a['items'] = $this->items($a['items'] ?? app(CustomerActions::class)->cart($thread)['items']);
                return app(ShopController::class)->quote(Request::create('/', 'POST', $a), $store->slug) + ['stock_reserved' => false];
            }
            if ($name === 'manage_customer_cart' && ($a['action'] ?? '') === 'read') return app(CustomerActions::class)->cart($thread);
            if ($name === 'submit_confirmed_order') {
                $id = validator($a, ['action_id' => 'required|uuid'])->validate()['action_id'];
                return app(CustomerActions::class)->modelStatus($thread, $id);
            }
            return app(CustomerActions::class)->prepare($agent, $thread, $name, $a);
        });
        if (in_array($name, ['get_product_details', 'compare_products', 'recommend_products', 'find_product_from_image'])) {
            $agent->publicContent['products'] = array_slice($name === 'get_product_details' ? [$result] : $result['items'], 0, 5);
            $agent->publicContent['image_candidates'] = $name === 'find_product_from_image';
        }
        if ($name === 'calculate_quote') $agent->publicContent['quote'] = array_diff_key($result, ['quote_hash' => true]);
        return $result;
    }

    public function buyAgain(object $thread, string $reference): array
    {
        $this->order($thread, $reference);
        $r = new Request;
        $r->attributes->set('platform_customer', (object) ['id' => $thread->platform_customer_id]);
        return app(CustomerAccountController::class)->buyAgain($r, $reference);
    }
}
