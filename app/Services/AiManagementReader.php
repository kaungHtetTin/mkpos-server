<?php

namespace App\Services;

use App\Http\Controllers\Ecommerce\ShopCustomerController;
use App\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;

/** Explicit owner resource catalog; no model-selected table or credential columns. */
class AiManagementReader
{
    public const RESOURCES = ['products', 'customers', 'suppliers', 'sales', 'purchases', 'customer_payments', 'supplier_payments', 'expenses', 'shop_settings', 'shop_contacts', 'shop_delivery_zones', 'shop_payment_methods', 'shop_catalog', 'shop_orders', 'shop_order_requests', 'shop_promotions', 'shop_customers', 'shop_reviews', 'management_audits'];

    private function table(string $table)
    {
        return DB::table($table)->where($table.'.business_id', app(TenantContext::class)->requireId());
    }

    public function read(array $a): array
    {
        abort_if(array_diff(array_keys($a), ['resource', 'record_id', 'customer_key', 'query', 'limit', 'offset']), 422, 'Unsupported read fields.');
        $a = validator($a, ['resource' => ['required', Rule::in(self::RESOURCES)], 'record_id' => 'sometimes|integer|min:1', 'customer_key' => 'sometimes|string|max:150', 'query' => 'sometimes|string|max:120', 'limit' => 'sometimes|integer|min:1|max:20', 'offset' => 'sometimes|integer|min:0|max:1000000'])->validate();
        $resource = $a['resource'];
        $limit = $a['limit'] ?? 10;
        $offset = $a['offset'] ?? 0;
        $module = match (true) {
            str_starts_with($resource, 'shop_') => 'shop',in_array($resource, ['customer_payments', 'supplier_payments']) => 'transactions',$resource === 'management_audits' => null,default => $resource
        };
        abort_unless(! $module || in_array($module, config('mkpos.enabled_modules', []), true), 403, 'This business module is unavailable.');
        if (str_starts_with($resource, 'shop_')) {
            abort_unless($this->table('shop_owner_locations')->exists(), 403, 'Open My Shop and save the actual owner/shop location first.');
        }
        if ($resource === 'shop_customers') {
            $c = app(ShopCustomerController::class);

            return isset($a['customer_key']) ? $c->show($a['customer_key']) : $c->index(Request::create('/', 'GET', ['q' => $a['query'] ?? '', 'limit' => $limit, 'offset' => $offset]));
        }
        if (in_array($resource, ['shop_settings', 'shop_delivery_zones', 'shop_payment_methods'])) {
            $store = $this->table('storefront_settings')->first();
            if ($resource === 'shop_settings') {
                return ['settings' => $store ? array_intersect_key((array) $store, array_flip(['id', 'slug', 'display_name', 'description', 'phone', 'price_type', 'storefront_enabled', 'accepting_orders', 'marketplace_enabled', 'pickup_instructions', 'pickup_address', 'pickup_hours', 'opening_hours', 'announcement', 'closed_until'])) : null, 'configured' => (bool) $store];
            }
            $field = $resource === 'shop_delivery_zones' ? 'delivery_zones' : 'payment_methods';

            return ['items' => $store ? (json_decode($store->$field, true) ?: []) : []];
        }
        $table = match ($resource) {
            'shop_orders' => 'ecommerce_orders','shop_catalog' => 'products','management_audits' => 'ai_management_audits',default => $resource
        };
        $columns = match ($resource) {
            'products','shop_catalog' => ['id', 'name', 'sku', 'barcode', 'category', 'base_unit', 'purchase_unit', 'purchase_conversion_factor', 'cost', 'price', 'stock', 'low_stock_threshold', 'is_active'],
            'customers' => ['id', 'name', 'phone', 'email', 'address', 'note', 'is_active'],
            'suppliers' => ['id', 'name', 'phone', 'contact_person', 'address', 'note', 'is_active'],
            'sales' => ['id', 'receipt_no', 'customer_id', 'source', 'subtotal', 'discount', 'delivery_fee', 'total', 'paid_amount', 'credit_amount', 'payment_method', 'status', 'created_at'],
            'purchases' => ['id', 'supplier_id', 'supplier_name', 'reference_no', 'purchase_date', 'expected_delivery', 'note', 'total_cost', 'paid_amount', 'credit_amount', 'payment_method', 'status', 'created_at'],
            'customer_payments','supplier_payments' => ['id', 'customer_id', 'supplier_id', 'amount', 'direction', 'payment_method', 'note', 'status', 'created_at'],
            'expenses' => ['id', 'title', 'category', 'amount', 'payment_method', 'expense_date', 'note', 'status'],
            'shop_contacts' => ['id', 'platform', 'label', 'value'],
            'shop_orders' => ['id', 'reference', 'customer_name', 'phone', 'status', 'payment_status', 'fulfillment', 'total', 'currency', 'sale_id', 'sale_voided', 'created_at'],
            'shop_order_requests' => ['id', 'order_id', 'type', 'status', 'message', 'reason', 'staff_note', 'created_at'],
            'shop_promotions' => ['id', 'type', 'name', 'code', 'product_id', 'discount_type', 'discount_value', 'minimum_subtotal', 'usage_limit', 'used_count', 'quantity_limit', 'used_quantity', 'starts_at', 'ends_at', 'enabled', 'deleted_at'],
            'shop_reviews' => ['id', 'type', 'target_id', 'rating', 'comment', 'reply', 'replied_at', 'created_at'],
            'management_audits' => ['id', 'action_id', 'user_id', 'operation', 'created_at'],
        };
        $columns = array_values(array_intersect($columns, Schema::getColumnListing($table)));
        $q = $this->table($table);
        if (isset($a['record_id'])) {
            $q->where('id', $a['record_id']);
        }
        if (($a['query'] ?? '') !== '') {
            $search = array_values(array_intersect($columns, ['name', 'title', 'supplier_name', 'customer_name', 'reference', 'receipt_no', 'sku', 'barcode', 'phone', 'code', 'comment', 'operation']));
            if ($search) {
                $q->where(function ($w) use ($a, $search) {
                    foreach ($search as $field) {
                        $w->orWhere($field, 'like', '%'.$a['query'].'%');
                    }
                });
            }
        }
        $total = (clone $q)->count();
        $items = $q->orderBy('id')->offset($offset)->limit($limit)->get($columns)->map(fn ($r) => (array) $r)->all();
        if ($resource === 'shop_catalog') {
            foreach ($items as &$p) {
                $p['publication'] = (array) ($this->table('ecommerce_product_settings')->where('product_id', $p['id'])->first(['published', 'unit', 'description']) ?? []);
            }
        }unset($p);
        if (isset($a['record_id']) && in_array($resource, ['sales', 'purchases', 'shop_orders'])) {
            abort_unless($total > 0, 404, 'Record unavailable in this business.');
            $children = match ($resource) {
                'sales' => ['sale_items', 'sale_id'],'purchases' => ['purchase_items', 'purchase_id'],'shop_orders' => ['ecommerce_order_items', 'order_id']
            };
            $lines = $this->table($children[0])->where($children[1], $a['record_id'])->orderBy('id')->limit(101)->get()->map(fn ($r) => array_intersect_key((array) $r, array_flip(['id', 'product_id', 'product_name', 'name', 'price_type', 'unit_name', 'quantity', 'foc_quantity', 'conversion_factor', 'base_quantity', 'base_foc_quantity', 'unit_price', 'unit_cost', 'line_total'])))->all();

            return ['items' => $items, 'total' => $total, 'line_items' => array_slice($lines, 0, 100), 'line_items_incomplete' => count($lines) > 100, 'writes_performed' => false];
        }

        return ['items' => $items, 'total' => $total, 'limit' => $limit, 'offset' => $offset, 'has_more' => $offset + count($items) < $total, 'next_offset' => $offset + count($items) < $total ? $offset + count($items) : null, 'writes_performed' => false];
    }
}
