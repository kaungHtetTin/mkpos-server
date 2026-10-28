<?php

namespace App\Services;

use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class EcommerceService
{
    public function scoped(int $businessId, callable $operation)
    {
        $context = app(TenantContext::class);
        $previous = $context->id();
        $context->set($businessId);
        try { return $operation(); }
        finally { $previous === null ? $context->clear() : $context->set($previous); }
    }

    public function eligible(int $businessId): bool
    {
        return DB::table('businesses')->where('id', $businessId)->where('status', 'active')->exists()
            && app(SubscriptionService::class)->status($businessId)['is_valid'];
    }

    public function store(string $slug): object
    {
        $store = DB::table('storefront_settings')->where('slug', $slug)->where('storefront_enabled', true)->first();
        abort_unless($store && $this->eligible($store->business_id), 404, 'Shop unavailable.');
        return $store;
    }

    public function storeView(object $store): array
    {
        $profile = app(BusinessProfile::class)->read($store->business_id);
        $location = DB::table('shop_owner_locations')->where('business_id', $store->business_id)->first();
        return [
            'slug' => $store->slug, 'display_name' => $profile['shop_name'], 'description' => $store->description,
            'phone' => $profile['shop_phone'], 'address' => $profile['shop_address'], 'currency' => $profile['currency'],
            'contacts' => ShopContacts::list($store->business_id),
            'rating' => app(ShopReviews::class)->summary($store->business_id),
            'location' => $location ? ['latitude' => (float) $location->latitude, 'longitude' => (float) $location->longitude] : null,
            'accepting_orders' => (bool) $store->accepting_orders && !app(ShopOperatingHours::class)->temporarilyClosed($store),
            'pickup_instructions' => $store->pickup_instructions,
            'pickup_address' => $store->pickup_address ?: $profile['shop_address'],
            'pickup_hours' => $store->pickup_hours,
            'delivery_zones' => json_decode($store->delivery_zones, true),
            'payment_methods' => array_values(array_filter(json_decode($store->payment_methods, true), [ShopPaymentMethods::class, 'available'])),
            'logo_url' => url('/api/ecommerce/stores/'.$store->slug.'/logo'),
            'cover_url' => ($cover = DB::table('settings')->where('business_id', $store->business_id)->where('key', 'shop_cover_path')->value('value'))
                ? url('/api/ecommerce/stores/'.$store->slug.'/cover').'?v='.substr(hash('sha256', $cover), 0, 12) : null,
        ] + app(ShopOperatingHours::class)->view($store);
    }

    public function catalogQuery(object $store)
    {
        return DB::table('products as p')
            ->join('ecommerce_product_settings as ep', function ($join) {
                $join->on('ep.product_id', '=', 'p.id')->on('ep.business_id', '=', 'p.business_id');
            })
            ->join('product_prices as pp', function ($join) use ($store) {
                $join->on('pp.product_id', '=', 'p.id')->on('pp.business_id', '=', 'p.business_id')->where('pp.name', $store->price_type);
            })
            ->where('p.business_id', $store->business_id)->where('p.is_active', true)
            ->where('ep.published', true)->where('pp.price', '>', 0)
            ->whereRaw("(CASE WHEN ep.unit = 'purchase' THEN pp.price * p.purchase_conversion_factor ELSE pp.price END) BETWEEN 1 AND 1000000000")
            ->where(function ($q) {
                $q->where('ep.unit', 'base')->orWhere(function ($q) {
                    $q->where('ep.unit', 'purchase')->whereNotNull('p.purchase_unit')->where('p.purchase_unit', '<>', '')->where('p.purchase_conversion_factor', '>', 0);
                });
            })
            ->select('p.*', 'pp.price as selected_price', 'ep.unit as selected_unit', 'ep.description as public_description')
            ->selectSub(DB::table('shop_reviews as r')->selectRaw('COUNT(*)')->whereColumn('r.business_id', 'p.business_id')->whereColumn('r.target_id', 'p.id'), 'review_count')
            ->selectSub(DB::table('shop_reviews as r')->selectRaw('AVG(r.rating)')->whereColumn('r.business_id', 'p.business_id')->whereColumn('r.target_id', 'p.id'), 'review_average');
    }

    public function productView(object $store, object $p): array
    {
        $factor = $p->selected_unit === 'purchase' ? (string) $p->purchase_conversion_factor : '1.000';
        $factorMilli = $this->milli($factor);
        $available = max(0, $this->milli($p->stock) - $this->milli(app(EcommerceInventory::class)->reserved($p->id, $p->business_id)));
        $originalPrice = intdiv((int) $p->selected_price * $factorMilli + 500, 1000);
        $flash = app(ShopPromotions::class)->flash((int) $p->business_id, (int) $p->id, $p->selected_unit, $originalPrice);
        return [
            'original_price' => $originalPrice, 'flash_sale' => $flash,
            'rating' => ['count' => (int) ($p->review_count ?? 0), 'average' => isset($p->review_average) ? round((float) $p->review_average, 1) : null],
            'id' => $p->id, 'name' => $p->name, 'description' => $p->public_description, 'category' => $p->category,
            'unit_name' => $p->selected_unit === 'purchase' ? $p->purchase_unit : $p->base_unit,
            'conversion_factor' => $factor, 'price' => $flash['price'] ?? $originalPrice,
            'currency' => DB::table('businesses')->where('id', $p->business_id)->value('currency'),
            'available_quantity' => intdiv($available * 1000, $factorMilli) / 1000,
            'photo_url' => $p->photo_path ? url('/api/ecommerce/stores/'.$store->slug.'/products/'.$p->id.'/photo') : null,
            'store_slug' => $store->slug, 'store_name' => $store->display_name,
        ];
    }

    // Decimal quantities use integer thousandths; money is integer currency units,
    // matching the existing POS schema. Never calculate monetary totals with floats.
    public function milli($value): int
    {
        $parts = explode('.', (string) $value, 2);
        return (int) $parts[0] * 1000 + (int) str_pad(substr($parts[1] ?? '', 0, 3), 3, '0');
    }

    public function quote(object $store, array $data, bool $lock = false): array
    {
        abort_unless($store->accepting_orders, 409, 'This shop is not accepting orders.');
        abort_if(app(ShopOperatingHours::class)->temporarilyClosed($store), 409, 'This shop is temporarily closed. Please order after it reopens.');
        if (($data['origin'] ?? 'storefront') === 'marketplace') {
            abort_unless($store->marketplace_enabled && ! $store->marketplace_suspended, 409, 'This shop is no longer available in the marketplace.');
        }
        $ids = array_column($data['items'], 'product_id');
        $query = $this->catalogQuery($store)->whereIn('p.id', $ids)->orderBy('p.id');
        $products = ($lock ? $query->lockForUpdate() : $query)->get()->keyBy('id');
        abort_unless($products->count() === count($ids), 422, 'One or more items are unavailable or belong to another shop.');
        $items = []; $promotions = [];
        foreach ($data['items'] as $item) {
            $p = $products[$item['product_id']];
            $view = $this->productView($store, $p);
            $qty = $this->milli($item['quantity']);
            $factor = $this->milli($view['conversion_factor']);
            if ($view['flash_sale'] && $view['flash_sale']['remaining_quantity'] !== null) {
                abort_if($qty > $this->milli($view['flash_sale']['remaining_quantity']), 409, "The flash sale for {$p->name} has only {$view['flash_sale']['remaining_quantity']} units remaining. Reduce the quantity and review again.");
            }
            abort_if($qty > $this->milli($view['available_quantity']), 409, "Not enough available stock for {$p->name}.");
            abort_if(($qty * $factor) % 1000 !== 0, 422, 'Quantity cannot be represented in the product base unit.');
            $items[] = [
                'product_id' => $p->id, 'name' => $p->name, 'unit_name' => $view['unit_name'],
                'price_type' => $store->price_type, 'conversion_factor' => $view['conversion_factor'],
                'quantity' => $qty / 1000, 'base_quantity' => intdiv($qty * $factor, 1000) / 1000,
                'original_unit_price' => $view['original_price'], 'promotion_id' => $view['flash_sale']['id'] ?? null, 'coupon_discount' => 0,
                'unit_price' => $view['price'], 'line_total' => intdiv($qty * $view['price'] + 500, 1000),
            ];
            if ($view['flash_sale']) $promotions[] = ['id' => $view['flash_sale']['id'], 'type' => 'flash_sale', 'name' => $view['flash_sale']['name'], 'discount' => intdiv($qty * $view['original_price'] + 500, 1000) - end($items)['line_total']];
        }
        usort($items, fn ($a, $b) => $a['product_id'] <=> $b['product_id']);
        $fee = 0;
        $feeRecipient = 'shop';
        $pickupAddress = $store->pickup_address ?: app(BusinessProfile::class)->read($store->business_id)['shop_address'];
        $instructions = implode("\n", array_filter([$pickupAddress, $store->pickup_hours, $store->pickup_instructions], fn ($value) => $value !== null && $value !== ''));
        if ($data['fulfillment'] === 'delivery') {
            if (($data['delivery_zone_id'] ?? '') === '__delivery_service__') {
                $feeRecipient = 'delivery_service';
                $instructions = 'Customer pays the delivery service directly.';
            } else {
                $zone = collect(json_decode($store->delivery_zones, true))->firstWhere('id', $data['delivery_zone_id'] ?? '');
                abort_unless($zone, 422, 'Choose a supported delivery zone.');
                $fee = (int) $zone['fee'];
                $instructions = $zone['name'];
            }
        }
        $method = collect(json_decode($store->payment_methods, true))->firstWhere('id', $data['payment_method_id']);
        abort_unless($method && ShopPaymentMethods::available($method), 422, 'Choose an active supported payment method.');
        $subtotal = array_sum(array_column($items, 'line_total'));
        $coupon = app(ShopPromotions::class)->coupon((int) $store->business_id, $data['coupon_code'] ?? null, $subtotal);
        $discount = $coupon['discount'] ?? 0;
        if ($coupon) $promotions[] = $coupon;
        $cumulative = 0; $allocated = 0;
        foreach ($items as &$item) {
            $cumulative += $item['line_total'];
            $target = $discount ? \Brick\Math\BigInteger::of($discount)->multipliedBy($cumulative)->quotient($subtotal)->toInt() : 0;
            $item['coupon_discount'] = $target - $allocated; $allocated = $target;
        }
        unset($item);
        $quote = ['discount' => $discount, 'promotions' => $promotions, 'items' => $items, 'subtotal' => $subtotal, 'delivery_fee' => $fee, 'delivery_fee_recipient' => $feeRecipient,
            'total' => $subtotal - $discount + ($feeRecipient === 'shop' ? $fee : 0),
            'currency' => DB::table('businesses')->where('id', $store->business_id)->value('currency'),
            'fulfillment' => $data['fulfillment'], 'fulfillment_instructions' => $instructions, 'payment_method' => $method];
        $quote['quote_hash'] = hash_hmac('sha256', $store->business_id.json_encode($quote), config('app.key'));
        return $quote;
    }

    public function checkout(object $store, array $data, ?int $platformCustomerId = null): array
    {
        return $this->scoped($store->business_id, fn () => DB::transaction(function () use ($store, $data, $platformCustomerId) {
            // A per-shop lock serializes checkout keys, configuration changes and
            // stock reservations. Product locks also serialize all POS stock writes.
            $store = DB::table('storefront_settings')->where('id', $store->id)->lockForUpdate()->first();
            $requestHash = hash('sha256', json_encode($data));
            $existing = DB::table('ecommerce_orders')->where('business_id', $store->business_id)->where('idempotency_key', $data['idempotency_key'])->first();
            if ($existing) {
                abort_unless(($existing->platform_customer_id === null ? null : (int) $existing->platform_customer_id) === $platformCustomerId, 409, 'Retry this order using the same customer account or guest checkout.');
                abort_unless(hash_equals($existing->request_hash, $requestHash), 409, 'This checkout key was already used with different details.');
                return $this->checkoutResult($existing);
            }
            abort_unless($store->storefront_enabled && $this->eligible($store->business_id), 404, 'Shop unavailable.');
            abort_if(ShopCustomerAccess::blocked((int) $store->business_id, $data['phone'], $platformCustomerId ? 'account:'.$platformCustomerId : null), 403, 'This shop is not accepting new orders from this customer.');
            $quote = $this->quote($store, $data, true);
            abort_unless(hash_equals($quote['quote_hash'], $data['quote_hash']), 409, 'Prices or fulfillment details changed. Review a new quote before placing your order.');
            $reference = 'O-'.strtoupper(Str::random(16));
            $credential = $this->credential($reference, $data['idempotency_key']);
            $id = DB::table('ecommerce_orders')->insertGetId([
                'business_id' => $store->business_id, 'reference' => $reference, 'origin' => $data['origin'] ?? 'storefront',
                'platform_customer_id' => $platformCustomerId,
                'customer_name' => $data['customer_name'], 'phone' => $data['phone'], 'email' => $data['email'] ?? null,
                'fulfillment' => $data['fulfillment'], 'address' => $data['address'] ?? null, 'notes' => $data['notes'] ?? null,
                'fulfillment_instructions' => $quote['fulfillment_instructions'], 'payment_method' => json_encode($quote['payment_method']),
                'discount' => $quote['discount'], 'promotions' => json_encode($quote['promotions']),
                'currency' => $quote['currency'], 'subtotal' => $quote['subtotal'], 'delivery_fee' => $quote['delivery_fee'], 'total' => $quote['total'],
                'delivery_fee_recipient' => $quote['delivery_fee_recipient'],
                'tracking_hash' => hash('sha256', $credential), 'idempotency_key' => $data['idempotency_key'], 'request_hash' => $requestHash,
                'expires_at' => null, 'created_at' => now(), 'updated_at' => now(),
            ]);
            foreach ($quote['items'] as $item) {
                $cost = DB::table('products')->where('id', $item['product_id'])->value('cost');
                DB::table('ecommerce_order_items')->insert($item + ['order_id' => $id, 'business_id' => $store->business_id, 'unit_cost' => $cost]);
                DB::table('ecommerce_stock_reservations')->insert(['business_id' => $store->business_id, 'order_id' => $id,
                    'product_id' => $item['product_id'], 'quantity' => $item['base_quantity'], 'created_at' => now(), 'updated_at' => now()]);
            }
            app(ShopPromotions::class)->consume((int) $store->business_id, $id, $quote);
            $this->event($store->business_id, $id, 'submitted');
            // Never announce rolled-back orders or repeat announcements on checkout retries.
            DB::afterCommit(fn () => app(ShopChatRealtime::class)->publish(
                ['private-shop.orders.business.'.$store->business_id], 'order.submitted', ['order_id' => (int) $id]));
            return $this->checkoutResult(DB::table('ecommerce_orders')->where('id', $id)->first());
        }, 3));
    }

    private function credential(string $reference, string $key): string
    {
        return hash_hmac('sha256', $reference.':'.$key, config('app.key'));
    }

    private function checkoutResult(object $order): array
    {
        return ['reference' => $order->reference, 'tracking_credential' => $this->credential($order->reference, $order->idempotency_key), 'total' => $order->total];
    }

    public function customerTrackingCredential(object $order): string
    {
        return $this->credential($order->reference, $order->idempotency_key);
    }

    public function track(string $reference, string $credential): object
    {
        $order = DB::table('ecommerce_orders')->where('reference', $reference)->first();
        abort_unless($order && hash_equals($order->tracking_hash, hash('sha256', $credential)), 404, 'Order unavailable.');
        return $order;
    }

    public function orderView(object $order, bool $staff = false): array
    {
        $result = array_intersect_key((array) $order, array_flip([
            'reference', 'status', 'payment_status', 'fulfillment', 'fulfillment_instructions', 'payment_method',
            'currency', 'subtotal', 'discount', 'delivery_fee', 'delivery_fee_recipient', 'total', 'expires_at', 'created_at', 'sale_voided',
        ]));
        $result['promotions'] = json_decode($order->promotions ?? '[]', true) ?: [];
        $result['payment_method'] = json_decode($order->payment_method, true);
        $settings = DB::table('storefront_settings')->where('business_id', $order->business_id)->first();
        $receivingMethods = collect(json_decode($settings->payment_methods ?? '[]', true) ?: [])
            ->contains(fn ($method) => ($method['type'] ?? '') === 'transfer' && ShopPaymentMethods::available($method));
        $result['can_submit_payment_proof'] = ($result['payment_method']['type'] === 'transfer' || ! $receivingMethods)
            && ($result['payment_method']['provider'] ?? 'manual') === 'manual' && $order->payment_status === 'unpaid'
            && ! in_array($order->status, ['completed', 'cancelled', 'expired']);
        $profile = app(BusinessProfile::class)->read($order->business_id);
        $result['store_slug'] = $settings->slug ?? '';
        $result['store_name'] = $profile['shop_name'];
        $result['receipt'] = ['shop_name' => $profile['shop_name'], 'shop_phone' => $profile['shop_phone'], 'shop_address' => $profile['shop_address'],
            'customer_name' => $order->customer_name, 'phone' => $order->phone, 'address' => $order->address];
        $result['customer_requests'] = DB::table('shop_order_requests')->where('business_id', $order->business_id)->where('order_id', $order->id)
            ->orderBy('id')->get(['id', 'type', 'status', 'reason', 'staff_note', 'created_at', 'updated_at', 'photo_path', ...(\Illuminate\Support\Facades\Schema::hasColumn('shop_order_requests', 'requested_changes') ? ['requested_changes'] : [])])
            ->map(function ($r) { $r->has_photo = (bool) $r->photo_path; unset($r->photo_path); if (isset($r->requested_changes)) $r->requested_changes = json_decode($r->requested_changes, true); return $r; })->values();
        $result['items'] = DB::table('ecommerce_order_items')->where('business_id', $order->business_id)->where('order_id', $order->id)
            ->get(['product_id', 'name', 'unit_name', 'quantity', 'unit_price', 'original_unit_price', 'coupon_discount', 'line_total'])->all();
        $result['payment_reviews'] = DB::table('ecommerce_payments')->where('business_id', $order->business_id)->where('order_id', $order->id)
            ->where('type', 'proof')->orderBy('id')->get(['id', 'status', 'reason', 'created_at'])->all();
        if ($staff) {
            $result['customer_key'] = ShopCustomerAccess::key($order->phone, $order->platform_customer_id === null ? null : (int) $order->platform_customer_id);
            $result += array_intersect_key((array) $order, array_flip(['id', 'origin', 'customer_name', 'phone', 'email', 'address', 'notes', 'sale_id']));
            $result['payments'] = DB::table('ecommerce_payments')->where('business_id', $order->business_id)->where('order_id', $order->id)
                ->get(['id', 'type', 'amount', 'reference', 'payment_provider', 'status', 'actor_id', 'reason', 'created_at', 'ai_validation'])
                ->map(function ($payment) { $payment->ai_validation = $payment->ai_validation ? json_decode($payment->ai_validation, true) : null; return $payment; })->all();
            $result['events'] = DB::table('ecommerce_order_events')->where('business_id', $order->business_id)->where('order_id', $order->id)->orderBy('id')->get()->all();
        }
        return \App\Support\AppTime::shopPayload($result);
    }

    public function event(int $businessId, int $id, string $event, ?int $actor = null, ?string $reason = null): void
    {
        $eventId = DB::table('ecommerce_order_events')->insertGetId(['business_id' => $businessId, 'order_id' => $id, 'event' => $event,
            'actor_id' => $actor, 'reason' => $reason, 'created_at' => now()]);
        $order = DB::table('ecommerce_orders')->where('business_id', $businessId)->where('id', $id)->first();
        if ($order) {
            if (in_array($event, ['proof_submitted', 'cancellation_requested', 'return_requested', 'change_requested'])) {
                $title = ['proof_submitted' => 'Payment proof submitted', 'cancellation_requested' => 'Cancellation requested', 'return_requested' => 'Return/refund requested', 'change_requested' => 'Order change requested'][$event];
                app(RealtimeActivity::class)->record('owner', $businessId, $event, ['title' => $title,
                    'message' => $order->reference.' · '.$order->customer_name, 'target' => 'order', 'order_id' => $id], 'order-event:'.$eventId);
            }
            app(ShopCustomerNotifications::class)->order($order, $event, $eventId, $reason);
            $channels = ['private-shop.orders.tracking.'.$order->reference];
            if ($order->platform_customer_id) $channels[] = 'private-shop.orders.customer.'.$order->platform_customer_id;
            DB::afterCommit(fn () => app(ShopChatRealtime::class)->publish($channels, 'order.updated',
                ['event_id' => (int) $eventId, 'reference' => $order->reference]));
            DB::afterCommit(fn () => app(ShopChatRealtime::class)->publish(['private-shop.orders.business.'.$businessId], 'order.changed',
                ['event_id' => (int) $eventId, 'order_id' => $id]));
        }
    }

    public function transition(int $businessId, int $id, string $next, ?int $actor, ?string $reason = null, bool $returned = false): array
    {
        return $this->scoped($businessId, fn () => DB::transaction(function () use ($businessId, $id, $next, $actor, $reason, $returned) {
            $order = DB::table('ecommerce_orders')->where('business_id', $businessId)->where('id', $id)->lockForUpdate()->first();
            abort_unless($order, 404, 'Order unavailable.');
            if ($order->status === $next) return $this->orderView($order, true);
            if ($next === 'return_pending' || $returned) app(AdminPinProtection::class)->authorize();
            $allowed = ['pending' => ['accepted', 'cancelled'], 'accepted' => ['packing', 'cancelled'],
                'packing' => ['ready_for_pickup', 'dispatched', 'cancelled'], 'ready_for_pickup' => ['completed', 'cancelled'],
                'dispatched' => ['completed', 'return_pending'], 'return_pending' => ['cancelled']];
            abort_unless(in_array($next, $allowed[$order->status] ?? [], true), 409, 'This order transition is unavailable.');
            abort_if(($next === 'ready_for_pickup' && $order->fulfillment !== 'pickup') || (in_array($next, ['dispatched', 'return_pending']) && $order->fulfillment !== 'delivery'), 409, 'Transition does not match fulfillment.');
            abort_if($order->status === 'return_pending' && ! $returned, 422, 'Confirm the goods have physically returned before cancelling.');
            abort_if(in_array($next, ['cancelled', 'return_pending']) && ! trim((string) $reason), 422, 'A reason is required.');
            $items = DB::table('ecommerce_order_items')->where('business_id', $businessId)->where('order_id', $id)->orderBy('product_id')->get();
            DB::table('products')->where('business_id', $businessId)->whereIn('id', $items->pluck('product_id'))->orderBy('id')->lockForUpdate()->get();
            $update = ['status' => $next, 'updated_at' => now()];
            if ($next === 'completed') {
                abort_unless($order->payment_status === 'paid', 409, 'Confirm full payment before completing this order.');
                // Consume the current order reservations inside the transaction so
                // shared sale posting still protects every other order reservation.
                DB::table('ecommerce_stock_reservations')->where('business_id', $businessId)->where('order_id', $id)->where('status', 'active')
                    ->update(['status' => 'consumed', 'updated_at' => now()]);
                $saleId = app(SalePostingService::class)->save(null, [
                    'items' => $items->map(fn ($i) => ['product_id' => $i->product_id, 'product_name' => $i->name,
                        'price_type' => $i->price_type, 'quantity' => (float) $i->base_quantity, 'unit_price' => $i->unit_price,
                        'snapshot' => (array) $i])->all(),
                    'paid_amount' => $order->total, 'payment_method' => json_decode($order->payment_method, true)['name'],
                    'ecommerce' => true, 'discount' => $order->discount, 'delivery_fee' => $order->delivery_fee_recipient === 'delivery_service' ? 0 : $order->delivery_fee,
                ], 'E-'.$order->reference);
                $update['sale_id'] = $saleId;
            }
            if (in_array($next, ['cancelled', 'expired'])) {
                DB::table('ecommerce_stock_reservations')->where('business_id', $businessId)->where('order_id', $id)->where('status', 'active')
                    ->update(['status' => 'released', 'updated_at' => now()]);
                if ($order->payment_status === 'paid') $update['payment_status'] = 'refund_pending';
            }
            DB::table('ecommerce_orders')->where('business_id', $businessId)->where('id', $id)->update($update);
            $this->event($businessId, $id, $next, $actor, $reason);
            return $this->orderView(DB::table('ecommerce_orders')->where('business_id', $businessId)->where('id', $id)->first(), true);
        }, 3));
    }

    public function payment(int $businessId, int $id, string $action, array $data, int $actor): array
    {
        if ($action === 'refund') app(AdminPinProtection::class)->authorize();
        return DB::transaction(function () use ($businessId, $id, $action, $data, $actor) {
            DB::table('ai_balance_accounts')->where('id', 1)->lockForUpdate()->first();
            $order = DB::table('ecommerce_orders')->where('business_id', $businessId)->where('id', $id)->lockForUpdate()->first();
            abort_unless($order, 404, 'Order unavailable.');
            $method = json_decode($order->payment_method, true);
            abort_unless(($method['provider'] ?? 'manual') === 'manual', 409, 'This payment requires its provider verification flow.');
            if ($action !== 'reject') {
                abort_unless(filter_var($data['verified'] ?? false, FILTER_VALIDATE_BOOLEAN), 422, 'Verify receipt or refund before approving payment.');
                abort_unless((int) $data['amount'] === (int) $order->total, 422, 'Record the full order amount.');
            }
            if (($action === 'confirm' && in_array($order->payment_status, ['paid', 'refund_pending', 'refunded'])
                && DB::table('ecommerce_payments')->where('business_id', $businessId)->where('order_id', $id)->where('type', 'confirm')->exists())
                || ($action === 'refund' && $order->payment_status === 'refunded')) return $this->orderView($order, true);
            if ($action === 'reject') {
                abort_unless($order->payment_status === 'proof_submitted', 409, 'There is no pending payment proof.');
                DB::table('ecommerce_payments')->where('business_id', $businessId)->where('order_id', $id)->where('type', 'proof')->where('status', 'pending')
                    ->update(['status' => 'rejected', 'reason' => $data['reason'], 'actor_id' => $actor, 'updated_at' => now()]);
                $state = 'unpaid';
            } else {
                if ($action === 'confirm') {
                    $closed = in_array($order->status, ['cancelled', 'expired']);
                    abort_if($order->status === 'completed' || ($closed && $order->payment_status !== 'proof_submitted'), 409, 'This order cannot receive payment.');
                    abort_unless(in_array($order->payment_status, ['unpaid', 'proof_submitted']), 409, 'This payment cannot be confirmed.');
                    DB::table('ecommerce_payments')->where('business_id', $businessId)->where('order_id', $id)->where('type', 'proof')->where('status', 'pending')
                        ->update(['status' => 'approved', 'actor_id' => $actor, 'updated_at' => now()]);
                    // Released stock is never restored by acknowledging money.
                    // Closed orders with verified funds must be refunded instead.
                    $state = $closed ? 'refund_pending' : 'paid';
                } else {
                    abort_unless($order->payment_status === 'refund_pending', 409, 'There is no refund obligation.');
                    $state = 'refunded';
                }
                $reviewer = app(ShopPaymentReceiptReview::class);
                $reference = (string) ($data['reference'] ?? '');
                $provider = ($method['type'] ?? '') === 'transfer' ? (string) ($method['bank'] ?? '') : '';
                if ($action === 'confirm' && $reference !== '') {
                    $providers = [];
                    foreach (DB::table('ecommerce_payments')->where('business_id', $businessId)->where('order_id', $id)
                        ->where('type', 'proof')->whereIn('status', ['pending', 'approved'])->whereNotNull('reference')->get() as $proof) {
                        if (app(SystemPaymentReview::class)->transactionKey('', $proof->reference) === app(SystemPaymentReview::class)->transactionKey('', $reference)) {
                            $providers[] = app(PaymentReceiptRules::class)->provider($reviewer->storedProvider($proof, $method));
                        }
                    }
                    $providers = array_unique($providers);
                    if ($providers) $provider = count($providers) === 1 ? reset($providers) : '';
                }
                $identity = $reviewer->transactionIdentity($provider, $reference);
                if ($reference !== '' && $identity['payment_provider']) {
                    abort_unless($identity['transaction_key'], 422, 'Enter a valid bank transaction ID of at most 120 characters.');
                    $claims = app(PaymentReceiptClaims::class);
                    $claims->assertAvailable($identity['payment_provider'], $reference, $action === 'confirm' ? 'shop' : null, $action === 'confirm' ? $id : null);
                    $claims->claim($identity['transaction_key'], $action === 'confirm' ? 'shop' : 'shop_refund', $id, $identity['payment_provider'], $reference);
                }
                DB::table('ecommerce_payments')->insert(['business_id' => $businessId, 'order_id' => $id, 'type' => $action,
                    'amount' => $data['amount'], 'reference' => $data['reference'] ?? null, 'status' => 'confirmed', 'actor_id' => $actor,
                    ...$identity, 'reason' => $data['review_note'] ?? null, 'created_at' => now(), 'updated_at' => now()]);
            }
            $update = ['payment_status' => $state, 'updated_at' => now()];
            $update['expires_at'] = null;
            DB::table('ecommerce_orders')->where('business_id', $businessId)->where('id', $id)->update($update);
            $this->event($businessId, $id, 'payment_'.$action, $actor, $data['reason'] ?? $data['review_note'] ?? null);
            return $this->orderView(DB::table('ecommerce_orders')->where('business_id', $businessId)->where('id', $id)->first(), true);
        });
    }

    public function reopen(int $businessId, int $id, int $actor): array
    {
        return $this->scoped($businessId, fn () => DB::transaction(function () use ($businessId, $id, $actor) {
            $order = DB::table('ecommerce_orders')->where('business_id', $businessId)->where('id', $id)->lockForUpdate()->first();
            abort_unless($order, 404, 'Order unavailable.');
            if ($order->status === 'pending' && DB::table('ecommerce_order_events')->where('business_id', $businessId)->where('order_id', $id)->where('event', 'reopened')->exists()) return $this->orderView($order, true);
            abort_unless($order->status === 'expired' && $order->payment_status === 'proof_submitted'
                && DB::table('ecommerce_payments')->where('business_id', $businessId)->where('order_id', $id)->where('type', 'proof')->where('status', 'pending')->exists(), 409, 'Only expired orders with a pending payment proof can be reopened.');
            $items = DB::table('ecommerce_order_items')->where('business_id', $businessId)->where('order_id', $id)->orderBy('product_id')->get();
            $products = DB::table('products')->where('business_id', $businessId)->whereIn('id', $items->pluck('product_id'))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            foreach ($items as $item) {
                $product = $products->get($item->product_id);
                abort_unless($product && $product->is_active, 409, 'An ordered product is no longer available. Resolve the received payment through a refund.');
                $unitsMatch = ($item->unit_name === $product->base_unit && $this->milli($item->conversion_factor) === 1000)
                    || ($item->unit_name === $product->purchase_unit && $this->milli($item->conversion_factor) === $this->milli($product->purchase_conversion_factor));
                abort_unless($unitsMatch, 409, 'Product units changed after expiry. Resolve the received payment through a refund.');
                $available = $this->milli($product->stock) - $this->milli(app(EcommerceInventory::class)->reserved($product->id, $businessId));
                abort_unless($available >= $this->milli($item->base_quantity), 409, 'Not enough stock to reopen this order. Resolve the received payment through a refund.');
            }
            foreach ($items as $item) {
                DB::table('ecommerce_stock_reservations')->upsert([['business_id' => $businessId, 'order_id' => $id, 'product_id' => $item->product_id,
                    'quantity' => $item->base_quantity, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]],
                    ['order_id', 'product_id'], ['quantity', 'status', 'updated_at']);
            }
            DB::table('ecommerce_orders')->where('business_id', $businessId)->where('id', $id)->update(['status' => 'pending', 'expires_at' => null, 'updated_at' => now()]);
            $this->event($businessId, $id, 'reopened', $actor, 'Reopened after expiry to review submitted payment proof; stock reserved again.');
            return $this->orderView(DB::table('ecommerce_orders')->where('business_id', $businessId)->where('id', $id)->first(), true);
        }, 3));
    }
}
