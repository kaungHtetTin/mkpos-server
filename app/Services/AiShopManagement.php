<?php

namespace App\Services;

use App\Http\Controllers\Ecommerce\AdminController;
use App\Http\Controllers\Ecommerce\ContactController;
use App\Http\Controllers\Ecommerce\DeliveryZoneController;
use App\Http\Controllers\Ecommerce\OrderRequestController;
use App\Http\Controllers\Ecommerce\PaymentMethodController;
use App\Http\Controllers\Ecommerce\PromotionController;
use App\Http\Controllers\Ecommerce\ReviewController;
use App\Http\Controllers\Ecommerce\ShopCustomerController;
use App\Models\User;
use App\Support\AppTime;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/** Online-shop proposals preserve native order, publication and settlement workflows. */
class AiShopManagement
{
    public const OPERATIONS = ['manage_shop_settings', 'manage_shop_contacts', 'manage_shop_delivery_zones', 'manage_shop_payment_methods', 'manage_shop_catalog', 'manage_shop_orders', 'manage_shop_promotions', 'manage_shop_customers', 'manage_shop_reviews'];

    private function table(string $name)
    {
        return DB::table($name)->where($name.'.business_id', app(TenantContext::class)->requireId());
    }

    private function rows(string $table): array
    {
        return $this->table($table)->orderBy('id')->lockForUpdate()->get()->map(fn ($r) => (array) $r)->all();
    }

    private function row(string $table, int $id): array
    {
        $row = $this->table($table)->where('id', $id)->lockForUpdate()->first();
        abort_unless($row, 404, 'Shop record unavailable in this business.');

        return (array) $row;
    }

    private function keys(array $a, array $keys): void
    {
        abort_if(array_diff(array_keys($a), $keys), 422, 'Unsupported shop management fields.');
    }

    public function build(string $operation, array $input, int $userId): array
    {
        abort_unless(in_array('shop', config('mkpos.enabled_modules', []), true), 403, 'Shop module is unavailable.');
        abort_unless($this->table('shop_owner_locations')->exists(), 403, 'Save the real owner/shop location in My Shop before using shop management. AI cannot invent location consent.');
        $this->keys($input, ['action', 'record_id', 'customer_key', 'data']);
        $a = validator($input, ['action' => 'required|string|max:40', 'record_id' => 'sometimes|integer|min:1', 'customer_key' => 'sometimes|string|max:150', 'data' => 'sometimes|array'])->validate();
        $action = $a['action'];
        $id = $a['record_id'] ?? null;
        $data = $a['data'] ?? [];
        $before = [];
        $effect = [];
        $preview = [];
        $paymentVerification = false;
        $goodsVerification = false;
        $store = $this->table('storefront_settings')->lockForUpdate()->first();
        $baseline = ['store' => $store ? (array) $store : null];
        if ($operation === 'manage_shop_settings') {
            abort_unless($action === 'update', 422, 'Shop settings use update.');
            $fields = ['slug', 'display_name', 'description', 'phone', 'address', 'currency', 'storefront_enabled', 'accepting_orders', 'marketplace_enabled', 'price_type', 'pickup_instructions', 'pickup_address', 'pickup_hours', 'announcement', 'closed_until', 'opening_hours'];
            $this->keys($data, $fields);
            $business = DB::table('businesses')->where('id', app(TenantContext::class)->requireId())->lockForUpdate()->first();
            $profile = app(BusinessProfile::class)->read($business->id);
            $baseline['profile'] = $profile;
            $baseline['business'] = ['name' => $business->name, 'currency' => $business->currency, 'timezone' => $business->timezone];
            $current = $store ? (array) $store : ['slug' => ($business->slug ?? 'shop').'-'.$business->id, 'description' => '', 'storefront_enabled' => false, 'accepting_orders' => true, 'marketplace_enabled' => false, 'price_type' => 'Retail', 'pickup_instructions' => '', 'delivery_zones' => '[]', 'opening_hours' => '[]'];
            $current += ['display_name' => $profile['shop_name'], 'phone' => $profile['shop_phone'], 'address' => $profile['shop_address'], 'currency' => $profile['currency']];
            $current['display_name'] = $profile['shop_name'];
            $current['phone'] = $profile['shop_phone'];
            $current['address'] = $profile['shop_address'];
            $current['currency'] = $profile['currency'];
            $current['opening_hours'] = is_string($current['opening_hours'] ?? null) ? json_decode($current['opening_hours'], true) : ($current['opening_hours'] ?? []);
            $current['closed_until'] = ! empty($current['closed_until']) ? CarbonImmutable::parse($current['closed_until'], 'UTC')->toIso8601String() : null;
            $merged = array_intersect_key(array_replace($current, $data), array_flip($fields));
            $rules = ['slug' => ['required', 'string', 'max:100', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', Rule::unique('storefront_settings')->ignore($store?->id)], 'display_name' => 'required|string|max:255', 'description' => 'nullable|string|max:4000', 'phone' => 'nullable|string|max:255', 'address' => 'nullable|string|max:2000', 'currency' => 'required|string|max:20', 'storefront_enabled' => 'required|boolean', 'accepting_orders' => 'required|boolean', 'marketplace_enabled' => 'required|boolean', 'price_type' => ['required', 'string', Rule::exists('price_type_rules', 'name')->where('business_id', $business->id)], 'pickup_instructions' => 'nullable|string|max:2000', 'pickup_address' => 'sometimes|nullable|string|max:255', 'pickup_hours' => 'sometimes|nullable|string|max:255', 'announcement' => 'sometimes|nullable|string|max:1000', 'closed_until' => 'sometimes|nullable|date', 'opening_hours' => 'sometimes|array|max:7', 'opening_hours.*' => 'array:closed,opens,closes', 'opening_hours.*.closed' => 'required|boolean', 'opening_hours.*.opens' => 'required|date_format:H:i', 'opening_hours.*.closes' => 'required|date_format:H:i'];
            $merged = validator($merged, $rules)->validate();
            abort_if($store && $store->slug !== $merged['slug'] && $this->table('ecommerce_orders')->exists(), 409, 'Shop URL cannot change after receiving orders.');
            if (isset($merged['opening_hours'])) {
                abort_unless(in_array(count($merged['opening_hours']), [0, 7]), 422, 'Provide all seven opening-hour days.');
                foreach ($merged['opening_hours'] as $day) {
                    abort_if(! $day['closed'] && $day['opens'] >= $day['closes'], 422, 'Closing time must be after opening time.');
                }
            }
            $effect = array_intersect_key($merged, $data);
            $before = array_intersect_key($current, $data);
            $data = $merged + ['delivery_zones' => json_decode($store?->delivery_zones ?? '[]', true)];
        } elseif (in_array($operation, ['manage_shop_contacts', 'manage_shop_delivery_zones', 'manage_shop_payment_methods'], true)) {
            abort_unless(in_array($action, ['create', 'update', 'delete']), 422, 'Use create/update/delete.');
            if ($operation === 'manage_shop_contacts') {
                $baseline['contacts'] = $this->rows('shop_contacts');
                if ($id) {
                    $before = $this->row('shop_contacts', $id);
                }
                abort_unless(($action === 'create' && ! $id) || ($action !== 'create' && $id), 422, 'Existing contacts require record_id; create omits it.');
                $this->keys($data, $action === 'delete' ? [] : ['platform', 'label', 'value']);
                if ($action !== 'delete') {
                    $data = ShopContacts::validate(Request::create('/', 'POST', array_replace($before, $data)));
                    abort_if($action === 'create' && count($baseline['contacts']) >= 100, 422, 'Shop contact limit reached.');
                    $effect = $data;
                } else {
                    $effect = ['deleted' => true];
                }
            } else {
                abort_unless($store, 422, 'Configure the storefront first.');
                $listKey = $operation === 'manage_shop_delivery_zones' ? 'delivery_zones' : 'payment_methods';
                $list = json_decode($store->$listKey, true) ?: [];
                $entryId = $data['entry_id'] ?? null;
                unset($data['entry_id']);
                abort_unless(($action === 'create' && ! $entryId) || ($action !== 'create' && is_string($entryId)), 422, 'Existing entries require data.entry_id; create omits it.');
                $before = $entryId ? (collect($list)->firstWhere('id', $entryId) ?? []) : [];
                abort_if($action !== 'create' && ! $before, 404, 'Shop entry unavailable.');
                $fields = $listKey === 'delivery_zones' ? ['name', 'fee'] : array_values(array_diff(ShopPaymentMethods::FIELDS, ['id']));
                $this->keys($data, $action === 'delete' ? [] : $fields);
                if ($action !== 'delete') {
                    $rules = $listKey === 'delivery_zones' ? ['name' => 'required|string|max:255', 'fee' => 'required|integer|min:0|max:1000000000'] : ShopPaymentMethods::rules();
                    $data = validator(array_replace($before, $data), $rules)->validate();
                    $effect = $listKey === 'payment_methods' ? ShopPaymentMethods::normalize($data) : $data;
                    if ($listKey === 'payment_methods' && $effect['type'] === 'cash') {
                        $effect += ['bank' => null, 'account_name' => null, 'account_no' => null];
                    }
                } else {
                    $effect = ['deleted' => true];
                }
                if ($action === 'create') {
                    abort_if(count($list) >= ($listKey === 'payment_methods' ? 20 : 100), 422, 'Shop entry limit reached.');
                }
                if ($listKey === 'payment_methods') {
                    $remaining = array_values(array_filter($list, fn ($entry) => $entry['id'] !== $entryId));
                    if ($action !== 'delete') {
                        $remaining[] = $effect;
                    }ShopPaymentMethods::validateAvailability($remaining);
                }
                $data['entry_id'] = $entryId;
            }
        } elseif ($operation === 'manage_shop_catalog') {
            abort_unless($action === 'update', 422, 'Catalog uses update.');
            $this->keys($data, ['items']);
            $data = validator($data, ['items' => 'required|array|min:1|max:100', 'items.*' => 'array:product_id,published,unit,description', 'items.*.product_id' => 'required|integer|min:1|distinct', 'items.*.published' => 'required|boolean', 'items.*.unit' => 'required|in:base,purchase', 'items.*.description' => 'sometimes|nullable|string|max:4000'])->validate();
            foreach ($data['items'] as $item) {
                $p = $this->row('products', $item['product_id']);
                abort_unless($p['is_active'], 422, 'Choose active products.');
                $baseline['products'][$p['id']] = $p;
                $current = $this->table('ecommerce_product_settings')->where('product_id', $p['id'])->lockForUpdate()->first();
                $baseline['publications'][$p['id']] = $current ? (array) $current : null;
                $price = $this->table('product_prices')->where('product_id', $p['id'])->where('name', $store?->price_type ?? 'Retail')->lockForUpdate()->first();
                $baseline['prices'][$p['id']] = $price ? (array) $price : null;
                if ($item['published']) {
                    abort_unless($store && $price && $price->price > 0, 422, 'Configure storefront and valid named prices first.');
                    $factor = $item['unit'] === 'purchase' ? (float) $p['purchase_conversion_factor'] : 1;
                    abort_if($item['unit'] === 'purchase' && (! $p['purchase_unit'] || $factor <= 0), 422, 'Product has no supported buying unit.');
                    abort_unless($price->price * $factor >= 1 && $price->price * $factor <= 1000000000, 422, 'Online price is out of range.');
                }
                $preview[] = ['record_id' => $p['id'], 'name' => $p['name'], 'before' => $current ? array_intersect_key((array) $current, array_flip(['published', 'unit', 'description'])) : null, 'after' => array_diff_key($item, ['product_id' => true])];
            }
        } elseif ($operation === 'manage_shop_orders') {
            abort_unless($id, 422, 'Choose an order record_id.');
            $before = $this->row('ecommerce_orders', $id);
            $baseline['record'] = $before;
            foreach (['ecommerce_order_items', 'ecommerce_payments', 'shop_order_requests', 'ecommerce_stock_reservations'] as $table) {
                $baseline[$table] = $this->table($table)->where('order_id', $id)->orderBy('id')->lockForUpdate()->get()->map(fn ($r) => (array) $r)->all();
            }
            foreach ($baseline['ecommerce_order_items'] as $line) {
                $pid = $line['product_id'];
                $baseline['products'][$pid] = $this->row('products', $pid);
            }
            if (! empty($before['sale_id'])) {
                $baseline['sale'] = $this->row('sales', $before['sale_id']);
                $baseline['sale_items'] = $this->table('sale_items')->where('sale_id', $before['sale_id'])->orderBy('id')->lockForUpdate()->get()->map(fn ($r) => (array) $r)->all();
            }
            $rules = match ($action) {
                'transition' => ['status' => 'required|in:accepted,packing,ready_for_pickup,dispatched,return_pending,completed,cancelled', 'reason' => 'sometimes|nullable|string|max:2000'],'reopen' => [], 'confirm_payment','record_refund' => ['amount' => 'required|integer|min:0|max:1000000000000', 'reference' => 'sometimes|nullable|string|max:255', 'review_note' => 'sometimes|nullable|string|max:2000'],'reject_payment' => ['reason' => 'required|string|max:2000'],'resolve_request' => ['request_id' => 'required|integer|min:1', 'status' => 'required|in:approved,rejected,resolved', 'staff_note' => 'required|string|min:5|max:2000'],default => null
            };
            abort_unless($rules !== null, 422, 'Unsupported order action.');
            $this->keys($data, array_keys($rules));
            $data = validator($data, $rules)->validate();
            if ($action === 'resolve_request') {
                abort_unless(collect($baseline['shop_order_requests'])->contains('id', $data['request_id']), 404, 'Order request unavailable.');
            }
            $paymentVerification = in_array($action, ['confirm_payment', 'record_refund']);
            $goodsVerification = ($action === 'transition' && ($data['status'] ?? '') === 'cancelled' && in_array($before['status'], ['dispatched', 'return_pending'])) || ($action === 'resolve_request' && ($data['status'] ?? '') === 'approved' && in_array($before['status'], ['dispatched', 'return_pending']));
            $effect = $data + ['action' => $action, 'order_reference' => $before['reference'], 'current_order_status' => $before['status'], 'current_payment_status' => $before['payment_status'], 'payment_verification' => $paymentVerification ? 'Owner must verify actual funds; screenshot alone is insufficient.' : 'Not applicable'];
            $this->orderImpacts($action, $data, $baseline, $effect, $preview);
        } elseif ($operation === 'manage_shop_promotions') {
            abort_unless($store, 422, 'Configure the storefront first.');
            abort_unless(in_array($action, ['create', 'update', 'delete']), 422, 'Promotions use create/update/delete.');
            $baseline['promotions'] = $this->rows('shop_promotions');
            if ($id) {
                $before = $this->row('shop_promotions', $id);
                abort_if($before['deleted_at'], 404, 'Promotion unavailable.');
            }
            abort_unless(($action === 'create' && ! $id) || ($action !== 'create' && $id), 422, 'Existing promotions require record_id.');
            $fields = ['type', 'name', 'code', 'product_id', 'discount_type', 'discount_value', 'minimum_subtotal', 'usage_limit', 'quantity_limit', 'starts_at', 'ends_at', 'enabled'];
            $this->keys($data, $action === 'delete' ? [] : $fields);
            if ($action === 'delete') {
                $effect = ['deleted' => true];
            } else {
                foreach (['starts_at', 'ends_at'] as $field) {
                    if (! empty($before[$field])) {
                        $before[$field] = CarbonImmutable::parse($before[$field], 'UTC')->setTimezone(AppTime::businessTimezone())->format('Y-m-d\TH:i');
                    }
                }
                $rules = ['type' => 'required|in:flash_sale,coupon', 'name' => 'required|string|max:255', 'code' => 'sometimes|nullable|string|max:40|regex:/^[A-Za-z0-9_-]+$/', 'product_id' => 'sometimes|nullable|integer|min:1', 'discount_type' => 'required|in:percentage,fixed', 'discount_value' => 'required|integer|min:1|max:1000000000', 'minimum_subtotal' => 'sometimes|nullable|integer|min:0|max:1000000000', 'usage_limit' => 'sometimes|nullable|integer|min:1|max:1000000', 'quantity_limit' => 'sometimes|nullable|numeric|min:0.001|max:1000000', 'starts_at' => 'sometimes|nullable|date_format:Y-m-d\TH:i', 'ends_at' => 'sometimes|nullable|date_format:Y-m-d\TH:i', 'enabled' => 'required|boolean'];
                $data = validator(array_intersect_key(array_replace($before, $data), array_flip($fields)), $rules)->validate();
                abort_if($data['discount_type'] === 'percentage' && $data['discount_value'] > 99, 422, 'Percentage discounts must be 1-99.');
                abort_if($id && $before['type'] !== $data['type'], 422, 'Promotion type cannot change.');
                $data['name'] = trim($data['name']);
                abort_if($data['name'] === '', 422, 'Enter a promotion name.');
                $start = empty($data['starts_at']) ? null : CarbonImmutable::createFromFormat('Y-m-d\TH:i', $data['starts_at'], AppTime::businessTimezone())->second(0)->utc();
                $end = empty($data['ends_at']) ? null : CarbonImmutable::createFromFormat('Y-m-d\TH:i', $data['ends_at'], AppTime::businessTimezone())->second(0)->utc();
                abort_if($start && $end && $end <= $start, 422, 'End time must be later than start time.');
                if ($data['type'] === 'coupon') {
                    $data['code'] = strtoupper(trim($data['code'] ?? ''));
                    abort_if($data['code'] === '' || collect($baseline['promotions'])->contains(fn ($p) => $p['id'] != $id && $p['code'] === $data['code']), 422, 'Choose a unique nonempty coupon code.');
                    abort_if(isset($data['usage_limit']) && $data['usage_limit'] < ($before['used_count'] ?? 0), 422, 'Usage limit cannot be less than used count.');
                    $data['product_id'] = null;
                    $data['quantity_limit'] = null;
                }
                if ($data['type'] === 'flash_sale') {
                    abort_unless(! empty($data['product_id']), 422, 'Flash sales need a matched product.');
                    $baseline['product'] = $this->row('products', $data['product_id']);
                    abort_unless($baseline['product']['is_active'], 404, 'Product unavailable. Wyne Wyne ignores archived products.');
                    $baseline['product_prices'] = $this->table('product_prices')->where('product_id', $data['product_id'])->orderBy('name')->lockForUpdate()->get()->map(fn ($r) => (array) $r)->all();
                    $publication = $this->table('ecommerce_product_settings')->where('product_id', $data['product_id'])->lockForUpdate()->first();
                    abort_unless($publication && $baseline['product']['is_active'], 422, 'Choose an active product in the online catalog.');
                    $baseline['publication'] = (array) $publication;
                    abort_if(($before['used_count'] ?? 0) && ((int) $before['product_id'] !== (int) $data['product_id'] || $before['unit'] !== $publication->unit), 422, 'Used flash sales must retain their product and online unit.');
                    abort_if(isset($data['quantity_limit']) && $data['quantity_limit'] < ($before['used_quantity'] ?? 0), 422, 'Quantity limit cannot be less than used quantity.');
                    if ($data['enabled']) {
                        foreach ($baseline['promotions'] as $other) {
                            if ($other['id'] == $id || $other['type'] !== 'flash_sale' || $other['product_id'] != $data['product_id'] || $other['deleted_at'] || ! $other['enabled']) {
                                continue;
                            }
                            abort_if((! $end || ! $other['starts_at'] || $other['starts_at'] < $end->toDateTimeString()) && (! $start || ! $other['ends_at'] || $other['ends_at'] > $start->toDateTimeString()), 422, 'This product already has an overlapping flash sale.');
                        }
                    }
                    $data['code'] = null;
                    $data['usage_limit'] = null;
                    $data['minimum_subtotal'] = 0;
                }
                $effect = $data;
            }
        } elseif ($operation === 'manage_shop_customers') {
            abort_unless(isset($a['customer_key']) && in_array($action, ['block', 'unblock', 'link_credit']), 422, 'Choose a returned customer_key and block/unblock/link_credit.');
            $customer = app(ShopCustomerController::class)->show($a['customer_key']);
            $baseline['customer'] = json_decode(json_encode($customer), true);
            $before = $baseline['customer']['customer'];
            $baseline['controls'] = $this->rows('shop_customer_controls');
            if ($action === 'link_credit') {
                $this->keys($data, ['contact_id', 'create_new']);
                $data = validator($data, ['contact_id' => 'sometimes|nullable|integer|min:1', 'create_new' => 'sometimes|boolean'])->validate();
                abort_unless(! empty($data['contact_id']) xor ! empty($data['create_new']), 422, 'Explicitly choose an existing POS contact or create_new=true.');
                $baseline['contacts'] = $this->rows('customers');
                if (! empty($data['contact_id'])) {
                    $baseline['contact'] = $this->row('customers', $data['contact_id']);
                }$effect = $data + ['effect' => 'Link customer identity only; do not create credit debt or merge balances.'];
            } else {
                $this->keys($data, ['reason']);
                $data = validator($data, ['reason' => 'sometimes|nullable|string|max:200'])->validate() + ['blocked' => $action === 'block'];
                $effect = $data;
            }
        } else {
            abort_unless($action === 'reply' && $id, 422, 'Review replies need action=reply and record_id.');
            $before = $this->row('shop_reviews', $id);
            $baseline['record'] = $before;
            $this->keys($data, ['reply']);
            $data = validator($data, ['reply' => 'present|nullable|string|max:2000'])->validate();
            $data['reply'] = trim($data['reply'] ?? '') ?: null;
            $effect = $data + ['rating' => $before['rating'], 'comment' => $before['comment'], 'audience' => 'Published shop review response'];
        }
        if ($operation === 'manage_shop_customers') {
            $effect += array_intersect_key($before, array_flip(['customer_name', 'phone', 'customer_key']));
        }
        if ($operation !== 'manage_shop_catalog') {
            array_unshift($preview, ['record_id' => $id, 'name' => ucwords(str_replace('_', ' ', $operation)).' — '.$action, 'before' => $before ? array_intersect_key($before, array_flip(array_keys($effect))) : null, 'after' => $effect]);
        }

        $requiresPin = $operation === 'manage_shop_orders' && ($action === 'record_refund' || ($action === 'transition' && (($data['status'] ?? '') === 'return_pending' || $goodsVerification)) || ($action === 'resolve_request' && ($data['status'] ?? '') !== 'rejected' && ($goodsVerification || (collect($baseline['shop_order_requests'] ?? [])->firstWhere('id', $data['request_id'])['type'] ?? '') === 'return')));
        return ['payload' => ['input' => $input, 'action' => $action, 'record_id' => $id, 'customer_key' => $a['customer_key'] ?? null, 'data' => $data], 'baseline' => $baseline, 'preview' => $preview, 'requires_pin' => $requiresPin && app(AdminPinProtection::class)->configured(), 'requires_payment_verification' => $paymentVerification, 'requires_goods_verification' => $goodsVerification];
    }

    private function orderImpacts(string $action, array $data, array $baseline, array &$effect, array &$preview): void
    {
        $order = $baseline['record'];
        $next = $order['status'];
        $payment = $order['payment_status'];
        $reservation = null;
        if ($action === 'transition') {
            $next = $data['status'];
            $allowed = ['pending' => ['accepted', 'cancelled'], 'accepted' => ['packing', 'cancelled'], 'packing' => ['ready_for_pickup', 'dispatched', 'cancelled'], 'ready_for_pickup' => ['completed', 'cancelled'], 'dispatched' => ['completed', 'return_pending'], 'return_pending' => ['cancelled']];
            abort_unless($next !== $order['status'] && in_array($next, $allowed[$order['status']] ?? []), 409, 'This order transition is unavailable.');
            abort_if(($next === 'ready_for_pickup' && $order['fulfillment'] !== 'pickup') || (in_array($next, ['dispatched', 'return_pending']) && $order['fulfillment'] !== 'delivery'), 409, 'Transition does not match fulfillment.');
            abort_if(in_array($next, ['cancelled', 'return_pending']) && trim($data['reason'] ?? '') === '', 422, 'A reason is required.');
            abort_if($next === 'completed' && $payment !== 'paid', 409, 'Verify full payment before completing this order.');
            if ($next === 'completed') {
                $reservation = 'consumed';
            }
            if ($next === 'cancelled') {
                $reservation = 'released';
                if ($payment === 'paid') {
                    $payment = 'refund_pending';
                }
            }
        } elseif (in_array($action, ['confirm_payment', 'record_refund', 'reject_payment'])) {
            abort_unless((json_decode($order['payment_method'], true)['provider'] ?? 'manual') === 'manual', 409, 'Use the provider verification workflow.');
            if ($action !== 'reject_payment') {
                abort_unless((int) $data['amount'] === (int) $order['total'], 422, 'Record the full order amount.');
            }
            if ($action === 'confirm_payment') {
                abort_unless(in_array($payment, ['unpaid', 'proof_submitted']) && $next !== 'completed' && (! in_array($next, ['cancelled', 'expired']) || $payment === 'proof_submitted'), 409, 'This payment cannot be confirmed.');
                $payment = in_array($next, ['cancelled', 'expired']) ? 'refund_pending' : 'paid';
            } elseif ($action === 'record_refund') {
                abort_unless($payment === 'refund_pending', 409, 'There is no refund obligation.');
                $payment = 'refunded';
            } else {
                abort_unless($payment === 'proof_submitted', 409, 'There is no pending payment proof.');
                $payment = 'unpaid';
            }
        } elseif ($action === 'reopen') {
            abort_unless($next === 'expired' && $payment === 'proof_submitted', 409, 'Only expired orders with pending payment proof can reopen.');
            abort_unless(collect($baseline['ecommerce_payments'])->contains(fn ($p) => $p['type'] === 'proof' && $p['status'] === 'pending'), 409, 'There is no pending payment proof.');
            $next = 'pending';
            $reservation = 'active';
        } elseif ($action === 'resolve_request') {
            $request = collect($baseline['shop_order_requests'])->firstWhere('id', $data['request_id']);
            abort_unless($request['status'] === 'pending' || ($request['type'] === 'return' && $request['status'] === 'approved' && $data['status'] === 'resolved'), 409, 'This request has already been reviewed.');
            if ($request['type'] === 'change') abort_unless(in_array($data['status'], ['resolved', 'rejected']), 422, 'Handle the change with the customer, then resolve or reject the request.');
            if ($data['status'] === 'resolved' && $request['type'] !== 'change') {
                abort_unless($request['type'] === 'return' && $order['sale_voided'] && $payment === 'refunded', 409, 'Void the sale and record the full refund first.');
            }
            if ($request['type'] === 'cancellation' && $data['status'] === 'approved') {
                abort_if(in_array($next, ['completed', 'expired']), 409, 'This order can no longer be cancelled.');
                $next = 'cancelled';
                $reservation = 'released';
                if ($payment === 'paid') {
                    $payment = 'refund_pending';
                }
            }
        }
        $effect['resulting_order_status'] = $next;
        $effect['resulting_payment_status'] = $payment;
        $effect['creates_pos_sale'] = $action === 'transition' && $next === 'completed';
        if ($reservation) {
            foreach ($baseline['ecommerce_order_items'] as $line) {
                $p = $baseline['products'][$line['product_id']];
                $stock = (float) $p['stock'];
                $after = $effect['creates_pos_sale'] ? $stock - (float) $line['base_quantity'] : $stock;
                $current = collect($baseline['ecommerce_stock_reservations'])->firstWhere('product_id', $p['id']);
                $preview[] = ['record_id' => $p['id'], 'name' => 'Stock: '.$p['name'], 'before' => ['stock' => $stock, 'reservation' => $current['status'] ?? 'none'], 'after' => ['stock' => $after, 'base_unit' => $p['base_unit'], 'reservation' => $reservation, 'order_quantity' => (float) $line['base_quantity']]];
            }
        }
    }

    public function execute(string $operation, array $p, int $userId, array $approval = []): array
    {
        $actor = User::findOrFail($userId);
        $data = $p['data'];
        if (isset($approval['admin_pin'])) $data['admin_pin'] = $approval['admin_pin'];
        $entryId = $data['entry_id'] ?? null;
        unset($data['entry_id']);
        $id = $p['record_id'];
        $action = $p['action'];
        if (in_array($action, ['confirm_payment', 'record_refund'])) {
            $data['verified'] = $approval['verified_payment'] ?? false;
        }
        if ($operation === 'manage_shop_orders') {
            $data['return_confirmed'] = $approval['verified_goods_return'] ?? false;
        }
        $r = Request::create('/api/ai-approved-shop', 'POST', $data);
        $r->setUserResolver(fn () => $actor);
        $original = app('request');
        app()->instance('request', $r);
        $r->setUserResolver(fn () => $actor);
        try {
            $result = match ($operation) {
                'manage_shop_settings' => app(AdminController::class)->updateSettings($r),
                'manage_shop_contacts' => $this->crud(app(ContactController::class), $r, $action, $id),
                'manage_shop_delivery_zones' => $this->crud(app(DeliveryZoneController::class), $r, $action, $entryId),
                'manage_shop_payment_methods' => $this->crud(app(PaymentMethodController::class), $r, $action, $entryId),
                'manage_shop_promotions' => $this->crud(app(PromotionController::class), $r, $action, $id),
                'manage_shop_catalog' => $this->catalog($r, $p['data']['items']),
                'manage_shop_orders' => match ($action) {
                    'transition' => app(AdminController::class)->transition($r, $id),'reopen' => app(AdminController::class)->reopen($r, $id),'resolve_request' => app(OrderRequestController::class)->resolve($r, $id, $data['request_id']),default => app(AdminController::class)->payment($r, $id, match ($action) {
                        'confirm_payment' => 'confirm','reject_payment' => 'reject','record_refund' => 'refund'
                    })
                },
                'manage_shop_customers' => $action === 'link_credit' ? app(ShopCustomerController::class)->creditContact($r, $p['customer_key']) : app(ShopCustomerController::class)->block($r, $p['customer_key']),
                'manage_shop_reviews' => app(ReviewController::class)->reply($r, $id),
            };
            if ($result instanceof JsonResponse) {
                $result = $result->getData(true);
            }

            return ['record_id' => $id ?? ($result['id'] ?? null), 'saved' => true, 'action' => $action, 'modules' => ['shop', 'products', 'sales', 'customers', 'transactions', 'settings', 'reports'], ...($operation === 'manage_shop_settings' ? ['settings' => ['shop_settings_updated' => true]] : [])];
        } finally {
            app()->instance('request', $original);
        }
    }

    private function crud(object $controller, Request $r, string $action, int|string|null $id): mixed
    {
        return match ($action) {
            'create' => $controller->store($r),'update' => $controller->update($r, $id),'delete' => $controller->destroy($id)
        };
    }

    private function catalog(Request $r, array $items): array
    {
        foreach ($items as $item) {
            $r->replace(array_diff_key($item, ['product_id' => true]));
            app(AdminController::class)->publish($r, $item['product_id']);
        }

        return ['updated' => count($items)];
    }
}
