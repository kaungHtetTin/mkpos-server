<?php

namespace App\Services;

use App\Http\Controllers\Api\CustomerController;
use App\Http\Controllers\Api\ExpenseController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\PurchaseController;
use App\Http\Controllers\Api\SaleController;
use App\Http\Controllers\Api\SupplierController;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Closed POS contracts, native posting, explicit review of stock and account effects. */
class AiPosManagement
{
    public const OPERATIONS = ['manage_sales', 'manage_purchases', 'manage_account_payments', 'manage_inventory', 'manage_business_records'];

    private function table(string $name)
    {
        return DB::table($name)->where($name.'.business_id', app(TenantContext::class)->requireId());
    }

    private function row(string $table, int $id): array
    {
        $r = $this->table($table)->where('id', $id)->lockForUpdate()->first();
        abort_unless($r, 404, 'Record unavailable in this business.');

        return (array) $r;
    }

    private function keys(array $a, array $keys): void
    {
        abort_if(array_diff(array_keys($a), $keys), 422, 'Unsupported operation fields.');
    }

    public function build(string $operation, array $a, int $userId): array
    {
        $this->keys($a, ['action', 'record_id', 'party', 'party_id', 'type', 'data']);
        $v = validator($a, ['action' => 'required|in:create,update,delete,archive,restore,adjust', 'record_id' => 'sometimes|integer|min:1', 'party' => 'sometimes|in:customer,supplier', 'party_id' => 'sometimes|integer|min:1', 'type' => 'sometimes|in:product,customer,supplier,expense', 'data' => 'sometimes|array'])->validate();
        $required = match ($operation) {
            'manage_sales' => ($v['action'] === 'create' ? ['sell'] : ['sales', 'customers']),'manage_purchases' => ['purchases', 'transactions'],'manage_account_payments' => [($v['party'] ?? 'customer').'s', 'transactions'],'manage_business_records' => [($v['type'] ?? 'product') === 'expense' ? 'expenses' : ($v['type'] ?? 'product').'s'],default => ['products']
        };
        abort_unless(array_intersect($required, config('mkpos.enabled_modules', [])), 403, 'The required business module is unavailable.');
        $data = $v['data'] ?? [];
        $action = $v['action'];
        $id = $v['record_id'] ?? null;
        $before = [];
        $baseline = [];
        $preview = [];
        $requiresPin = false;
        $requiresGoods = false;
        $effect = [];
        $route = null;
        if (in_array($operation, ['manage_sales', 'manage_purchases'], true)) {
            $purchase = $operation === 'manage_purchases';
            $table = $purchase ? 'purchases' : 'sales';
            $itemsTable = $purchase ? 'purchase_items' : 'sale_items';
            $key = $purchase ? 'purchase_id' : 'sale_id';
            $controller = $purchase ? PurchaseController::class : SaleController::class;
            abort_unless(in_array($action, ['create', 'update', 'delete']), 422, 'Use create/update/delete for transactions.');
            abort_unless(($action === 'create' && ! $id) || ($action !== 'create' && $id), 422, 'Existing transactions require record_id; create must omit it.');
            if ($id) {
                $before = $this->row($table, $id);
                abort_unless($before['status'] === 'completed', 409, 'Only completed transactions may be changed.');
                $baseline['record'] = $before;
                $baseline['items'] = $this->table($itemsTable)->where($key, $id)->orderBy('id')->lockForUpdate()->get()->map(fn ($r) => (array) $r)->all();
            }
            $requiresPin = (! $purchase && $action !== 'create') || ($purchase && $action === 'delete');
            if ($action === 'delete') {
                $this->keys($data, []);
                $effect = ['deleted' => true, 'stock_effect' => $purchase ? 'Reverse received stock' : 'Restore sold and FOC stock', 'account_effect' => 'Remove the original invoice credit; account payments are not deleted.'];
                $route = [$controller, 'destroy'];
            } else {
                abort_if(! $purchase && $action === 'update' && ($before['source'] ?? '') === 'ecommerce', 409, 'Online order sales use their controlled order workflow.');
                $fields = $purchase ? ['supplier_id', 'supplier_name', 'note', 'reference_no', 'purchase_date', 'expected_delivery', 'payment_method', 'paid_amount', 'items'] : ['customer_id', 'discount', 'payment_type', 'payment_method', 'paid_amount', 'items'];
                $this->keys($data, $fields);
                foreach ($data['items'] ?? [] as $line) {
                    $this->keys($line, $purchase ? ['id', 'product_id', 'unit_name', 'quantity', 'foc_quantity', 'unit_cost'] : ['product_id', 'product_name', 'price_type', 'quantity', 'foc_quantity', 'unit_price']);
                }
                $merged = array_intersect_key(array_replace($before, $data), array_flip($fields));
                if (! isset($merged['items']) && $id) {
                    $merged['items'] = array_map(fn ($line) => array_intersect_key($line, array_flip($purchase ? ['id', 'product_id', 'unit_name', 'quantity', 'foc_quantity', 'unit_cost'] : ['product_id', 'product_name', 'price_type', 'quantity', 'foc_quantity', 'unit_price'])), $baseline['items']);
                }
                validator($merged, ['items' => 'required|array|min:1|max:100', 'note' => 'sometimes|nullable|string|max:2000', 'paid_amount' => 'required|integer|min:0|max:1000000000000', 'payment_method' => 'required|string|max:255'])->validate();
                $data = app($controller)->validated(Request::create('/', 'POST', $merged));
                if ($purchase) {
                    validator($data, ['items.*.product_id' => 'distinct'])->validate();
                }
                $party = $purchase ? 'supplier' : 'customer';
                $partyId = $data[$party.'_id'] ?? null;
                if ($partyId) {
                    $baseline['party'] = $this->row($party.'s', $partyId);
                    abort_unless($baseline['party']['is_active'], 422, 'Choose an active account.');
                    if ($purchase) {
                        $data['supplier_name'] = $baseline['party']['name'];
                    }
                }
                $total = 0;
                $effectItems = [];
                $stockDeltas = [];
                foreach ($baseline['items'] ?? [] as $old) {
                    $pid = (int) $old['product_id'];
                    $stockDeltas[$pid] = ($stockDeltas[$pid] ?? 0) + ($purchase ? -1 : 1) * ($purchase ? (float) $old['base_quantity'] + (float) $old['base_foc_quantity'] : (float) $old['quantity'] + (float) $old['foc_quantity']);
                }
                foreach ($data['items'] as &$line) {
                    $product = $this->row('products', (int) $line['product_id']);
                    $baseline['products'][$product['id']] = $product;
                    abort_unless($product['is_active'], 422, 'Choose active products.');
                    $factor = 1;
                    $unit = $product['base_unit'];
                    if ($purchase) {
                        $unit = trim($line['unit_name'] ?? '') ?: $product['base_unit'];
                        $historical = isset($line['id']) ? collect($baseline['items'] ?? [])->firstWhere('id', $line['id']) : null;
                        if ($historical && (int) $historical['product_id'] === (int) $product['id'] && strcasecmp($historical['unit_name'], $unit) === 0) {
                            $factor = (float) $historical['conversion_factor'];
                        } else {
                            $factor = strcasecmp($unit, $product['base_unit']) === 0 ? 1 : ($product['purchase_unit'] && strcasecmp($unit, $product['purchase_unit']) === 0 ? (float) $product['purchase_conversion_factor'] : 0);
                            if ($factor > 0) {
                                $unit = strcasecmp($unit, $product['base_unit']) === 0 ? $product['base_unit'] : $product['purchase_unit'];
                            }
                        }
                        abort_unless($factor > 0, 422, 'Choose the product configured buying unit.');
                        $line['unit_name'] = $unit;
                    }
                    $q = (float) $line['quantity'];
                    $foc = (float) ($line['foc_quantity'] ?? 0);
                    abort_if($q > 1000000 || $foc > 1000000 || abs($q * 1000 - round($q * 1000)) > 0.00001 || abs($foc * 1000 - round($foc * 1000)) > 0.00001, 422, 'Quantities support up to 3 decimals and 1,000,000 units.');
                    $change = ($purchase ? round($q * $factor, 3) + round($foc * $factor, 3) : $q + $foc) * ($purchase ? 1 : -1);
                    $stockDeltas[$product['id']] = ($stockDeltas[$product['id']] ?? 0) + $change;
                    $cost = $purchase ? $line['unit_cost'] : $line['unit_price'];
                    $total += $q * $cost;
                    $effectItems[] = ['product_id' => $product['id'], 'product' => $product['name'], 'unit' => $unit, 'conversion_factor' => $factor, 'quantity' => $q, 'foc_quantity' => $foc, ($purchase ? 'unit_cost' : 'unit_price') => $cost, 'line_total' => (int) round($q * $cost)];
                }unset($line);
                $total = (int) round($total);
                abort_if($total > 1000000000000, 422, 'Transaction total is too large.');
                if (! $purchase) {
                    $total -= min($total, (int) ($data['discount'] ?? 0));
                }abort_if($data['paid_amount'] > $total, 422, 'Paid amount exceeds the transaction total.');
                abort_if(! $purchase && $data['paid_amount'] < $total && empty($data['customer_id']), 422, 'Credit sales require a customer.');
                if ($purchase) {
                    abort_unless(! empty($data['supplier_id']) || trim($data['supplier_name'] ?? '') !== '', 422, 'Provide the supplier.');
                    abort_if($data['paid_amount'] < $total && empty($data['supplier_id']), 422, 'Purchase credit requires a saved supplier.');
                }
                foreach ($stockDeltas as $pid => $delta) {
                    $p = $baseline['products'][$pid] ?? $this->row('products', $pid);
                    $baseline['products'][$pid] = $p;
                    app(EcommerceInventory::class)->assertRemaining((object) $p, (float) $p['stock'] + $delta);
                    $preview[] = ['record_id' => $pid, 'name' => 'Stock: '.$p['name'], 'before' => ['stock' => (float) $p['stock']], 'after' => ['stock' => (float) $p['stock'] + $delta, 'base_unit' => $p['base_unit']]];
                }
                $method = $data['payment_method'];
                if (! $purchase && collect($effectItems)->every(fn ($i) => $i['quantity'] === 0.0) && collect($effectItems)->contains(fn ($i) => $i['foc_quantity'] > 0)) {
                    $method = 'FOC';
                } elseif ((int) $data['paid_amount'] === 0 && $total > 0 && ($purchase || $method === 'Cash')) {
                    $method = 'Credit';
                }
                $effect = array_diff_key($data, ['items' => true]) + ['items' => $effectItems, ($purchase ? 'total_cost' : 'total') => $total, 'credit_amount' => $total - $data['paid_amount']];
                $effect['payment_method'] = $method;
                $effect['payment_type'] = $total > (int) $data['paid_amount'] ? 'credit' : 'cash';
                $route = [$controller, $id ? 'update' : 'store'];
            }
            if ($action === 'delete') {
                foreach ($baseline['items'] as $line) {
                    $pid = $line['product_id'];
                    $baseline['products'][$pid] = $this->row('products', $pid);
                }
            }
            $baseline['orders'] = $this->table('ecommerce_orders')->where('sale_id', $purchase ? -1 : ($id ?? -1))->orderBy('id')->lockForUpdate()->get()->map(fn ($r) => (array) $r)->all();
            if (! $purchase && $action === 'delete' && $baseline['orders']) {
                $requiresGoods = true;
                $effect['linked_orders'] = array_map(fn ($order) => ['order_id' => $order['id'], 'reference' => $order['reference'], 'previous_payment_status' => $order['payment_status'], 'sale_voided' => true, 'resulting_payment_status' => 'refund_pending'], $baseline['orders']);
                $effect['refund_effect'] = 'Creates a refund obligation only; no money is transferred or marked refunded.';
            }
        } elseif ($operation === 'manage_inventory') {
            abort_unless($action === 'adjust' && $id, 422, 'Stock adjustments require action=adjust and record_id.');
            $this->keys($data, ['quantity', 'reason']);
            $data = validator($data, ['quantity' => ['required', 'numeric', 'between:-1000000,1000000', 'not_in:0', 'regex:/^-?\d+(\.\d{1,3})?$/'], 'reason' => 'required|string|min:1|max:2000'])->validate();
            $before = $this->row('products', $id);
            $baseline['record'] = $before;
            app(EcommerceInventory::class)->assertRemaining((object) $before, (float) $before['stock'] + $data['quantity']);
            $effect = ['stock' => (float) $before['stock'] + $data['quantity'], 'quantity_change' => $data['quantity'], 'base_unit' => $before['base_unit'], 'reason' => $data['reason']];
            $route = [ProductController::class, 'adjustStock'];
        } elseif ($operation === 'manage_account_payments') {
            abort_unless(in_array($action, ['create', 'update', 'delete']) && isset($v['party']), 422, 'Choose customer/supplier and create/update/delete.');
            $party = $v['party'];
            $controller = $party === 'customer' ? CustomerController::class : SupplierController::class;
            $table = $party.'_payments';
            if ($action === 'create') {
                abort_unless(isset($v['party_id']) && ! $id, 422, 'New payments require party_id and no record_id.');
                $partyId = $v['party_id'];
            } else {
                abort_unless($id, 422, 'Existing payments require record_id.');
                $before = $this->row($table, $id);
                $baseline['record'] = $before;
                $partyId = $before[$party.'_id'];
            }
            $baseline['party'] = $this->row($party.'s', $partyId);
            abort_unless($baseline['party']['is_active'], 422, 'Choose an active account.');
            if ($action === 'delete') {
                $this->keys($data, []);
                $effect = ['deleted' => true, 'account' => $baseline['party']['name'], 'previous_amount' => $before['amount'], 'direction' => $before['direction']];
                $route = [$controller, 'destroyPayment'];
            } else {
                $this->keys($data, ['amount', 'direction', 'payment_method', 'note']);
                $merged = array_intersect_key(array_replace($before, $data), array_flip(['amount', 'direction', 'payment_method', 'note']));
                $data = app($controller)->validatedPayment(Request::create('/', 'POST', $merged));
                $data['payment_method'] ??= 'Cash';
                validator($data, ['note' => 'sometimes|nullable|string|max:2000', 'amount' => 'required|integer|min:1|max:1000000000000'])->validate();
                $effect = $data + ['account' => $baseline['party']['name'], 'party_id' => $partyId];
                $route = [$controller, $id ? 'updatePayment' : 'storePayment'];
                if ($id) {
                    $data[$party.'_id'] = $partyId;
                }
            }
            $supplier = $party === 'supplier';
            $payments = $this->table($table)->where($party.'_id', $partyId)->orderBy('id')->lockForUpdate()->get()->map(fn ($r) => (array) $r)->all();
            $invoices = $this->table($supplier ? 'purchases' : 'sales')->where($party.'_id', $partyId)->orderBy('id')->lockForUpdate()->get()->map(fn ($r) => (array) $r)->all();
            $baseline['account_payments'] = $payments;
            $baseline['account_invoices'] = $invoices;
            $balance = (float) app(BusinessAccounts::class)->query($supplier)->where('id', $partyId)->first()->balance;
            $settles = $supplier ? 'shop_to_supplier' : 'customer_to_shop';
            $oldImpact = ($before['status'] ?? null) === 'completed' ? (int) $before['amount'] * ($before['direction'] === $settles ? -1 : 1) : 0;
            $newImpact = $action === 'delete' ? 0 : (int) $data['amount'] * ($data['direction'] === $settles ? -1 : 1);
            $before['balance'] = $balance;
            $effect['balance'] = $balance - $oldImpact + $newImpact;
            $effect['balance_meaning'] = $supplier ? 'Positive: shop owes supplier; negative: supplier owes shop.' : 'Positive: customer owes shop; negative: shop owes customer.';
        } else {
            abort_unless(isset($v['type']) && $id && in_array($action, ['archive', 'restore', 'delete']), 422, 'Choose record type, record_id and archive/restore/delete.');
            $type = $v['type'];
            $table = $type === 'expense' ? 'expenses' : $type.'s';
            $before = $this->row($table, $id);
            $baseline['record'] = $before;
            $this->keys($data, []);
            if ($type === 'expense') {
                abort_unless($action === 'delete', 422, 'Expenses use delete.');
                $requiresPin = true;
                $route = [ExpenseController::class, 'destroy'];
            } else {
                abort_unless(in_array($action, ['archive', 'restore']), 422, 'Products and contacts use archive/restore, preserving history.');
                if ($type === 'product') {
                    abort_if($action === 'archive' && app(EcommerceInventory::class)->reserved($id, $before['business_id']) > 0, 409, 'Resolve online reservations before archiving.');
                    if ($action === 'restore' && $before['barcode'] !== '') {
                        abort_if($this->table('products')->where('id', '!=', $id)->where('is_active', true)->where('barcode', $before['barcode'])->exists(), 409, 'An active product uses this barcode.');
                    }
                }$route = [$type === 'product' ? ProductController::class : ($type === 'customer' ? CustomerController::class : SupplierController::class), 'destroy'];
            }
            $effect = $type === 'expense' ? ['deleted' => true] : ['is_active' => $action === 'restore'];
        }
        if (in_array($operation, ['manage_sales', 'manage_purchases'], true)) {
            $before['items'] = array_map(fn ($line) => array_intersect_key($line, array_flip(['product_name', 'unit_name', 'quantity', 'foc_quantity', 'unit_price', 'unit_cost', 'line_total'])), $baseline['items'] ?? []);
            if ($action === 'delete') {
                $effect['items'] = [];
                $deltas = [];
                foreach ($baseline['items'] as $line) {
                    $pid = $line['product_id'];
                    $deltas[$pid] = ($deltas[$pid] ?? 0) + ($operation === 'manage_purchases' ? -1 : 1) * ($operation === 'manage_purchases' ? (float) $line['base_quantity'] + (float) $line['base_foc_quantity'] : (float) $line['quantity'] + (float) $line['foc_quantity']);
                }
                foreach ($deltas as $pid => $delta) {
                    $p = $baseline['products'][$pid];
                    app(EcommerceInventory::class)->assertRemaining((object) $p, (float) $p['stock'] + $delta);
                    $preview[] = ['record_id' => $pid, 'name' => 'Stock: '.$p['name'], 'before' => ['stock' => (float) $p['stock']], 'after' => ['stock' => (float) $p['stock'] + $delta, 'base_unit' => $p['base_unit']]];
                }
            }
        }
        if ($operation === 'manage_purchases') {
            $this->purchaseImpacts($action, $id, $data, $baseline, $preview);
        }
        $requiresPin = $requiresPin && (string) $this->table('settings')->where('key', 'admin_pin_hash')->value('value') !== '';
        $baseline['requires_pin'] = $requiresPin;
        $baseline['reservations'] = $this->table('ecommerce_stock_reservations')->where('status', 'active')->orderBy('id')->lockForUpdate()->get()->map(fn ($r) => (array) $r)->all();
        array_unshift($preview, ['record_id' => $id, 'name' => ucwords(str_replace('_', ' ', $operation)).' — '.$action, 'before' => $before ? array_intersect_key($before, array_flip(['name', 'receipt_no', 'title', 'category', 'expense_date', 'stock', 'balance', 'total', 'total_cost', 'paid_amount', 'credit_amount', 'items', 'amount', 'direction', 'status', 'is_active', 'customer_id', 'supplier_id', 'supplier_name', 'note', 'reference_no', 'purchase_date', 'expected_delivery', 'discount', 'payment_type', 'payment_method'])) : null, 'after' => $effect]);

        return ['payload' => ['input' => $a, 'action' => $action, 'record_id' => $id, 'party' => $v['party'] ?? null, 'party_id' => $v['party_id'] ?? null, 'type' => $v['type'] ?? null, 'data' => $data, 'route' => $route], 'baseline' => $baseline, 'preview' => $preview, 'requires_pin' => $requiresPin, 'requires_goods_verification' => $requiresGoods];
    }

    private function purchaseImpacts(string $action, ?int $id, array $data, array &$baseline, array &$preview): void
    {
        $ids = array_keys($baseline['products'] ?? []);
        if (! $ids) {
            return;
        }
        $history = $this->table('purchase_items')->join('purchases as h', fn ($j) => $j->on('h.id', '=', 'purchase_items.purchase_id')->where('h.business_id', app(TenantContext::class)->requireId())->where('h.status', 'completed'))->whereIn('purchase_items.product_id', $ids)->orderByDesc('h.created_at')->orderByDesc('h.id')->orderBy('purchase_items.id')->lockForUpdate()->get(['purchase_items.*', 'h.created_at as purchase_created_at'])->map(fn ($r) => (array) $r)->all();
        $baseline['cost_history'] = $history;
        $rules = $this->table('price_type_rules')->orderBy('name')->lockForUpdate()->get()->map(fn ($r) => (array) $r)->all();
        $baseline['price_rules'] = $rules;
        $prices = $this->table('product_prices')->whereIn('product_id', $ids)->orderBy('product_id')->orderBy('name')->lockForUpdate()->get()->map(fn ($r) => (array) $r)->all();
        $baseline['prices'] = $prices;
        $new = [];
        foreach ($data['items'] ?? [] as $line) {
            $p = $baseline['products'][$line['product_id']];
            $unit = $line['unit_name'] ?? $p['base_unit'];
            $historical = isset($line['id']) ? collect($baseline['items'] ?? [])->firstWhere('id', $line['id']) : null;
            $factor = $historical && $historical['product_id'] == $p['id'] && strcasecmp($historical['unit_name'], $unit) === 0 ? (float) $historical['conversion_factor'] : (strcasecmp($unit, $p['base_unit']) === 0 ? 1 : (float) $p['purchase_conversion_factor']);
            $received = round((float) $line['quantity'] * $factor, 3) + round((float) ($line['foc_quantity'] ?? 0) * $factor, 3);
            abort_unless($received > 0, 422, 'Received base quantity must be at least 0.001.');
            $effective = (int) round($line['quantity'] * $line['unit_cost']) / $received;
            $new[$p['id']] = ['product_id' => $p['id'], 'purchase_id' => $id, 'effective_unit_cost' => round($effective, 3), 'exact_cost' => (int) round($effective), 'purchase_created_at' => $baseline['record']['created_at'] ?? now()->toDateTimeString(), 'id' => PHP_INT_MAX];
        }
        foreach ($baseline['products'] as $pid => $p) {
            if ($action === 'create') {
                $cost = $new[$pid]['exact_cost'] ?? (int) $p['cost'];
            } else {
                $candidates = array_values(array_filter($history, fn ($h) => (int) $h['product_id'] === (int) $pid && (int) $h['purchase_id'] !== $id));
                if (isset($new[$pid])) {
                    $candidates[] = $new[$pid];
                }usort($candidates, fn ($a, $b) => strcmp($b['purchase_created_at'], $a['purchase_created_at']) ?: ($b['purchase_id'] <=> $a['purchase_id']) ?: ($a['id'] <=> $b['id']));
                $cost = $candidates ? (int) round($candidates[0]['effective_unit_cost']) : (int) $p['base_cost'];
            }
            if ($cost != (int) $p['cost']) {
                $preview[] = ['record_id' => $pid, 'name' => 'Cost: '.$p['name'], 'before' => ['cost' => $p['cost']], 'after' => ['cost' => $cost]];
            }
            foreach ($prices as $price) {
                if ((int) $price['product_id'] !== (int) $pid || $price['is_manual']) {
                    continue;
                }$rule = collect($rules)->firstWhere('name', $price['name']);
                if (! $rule || $rule['pricing_mode'] !== 'automatic') {
                    continue;
                }$next = ProductPricing::automatic($cost, (object) $rule);
                if ($next != (int) $price['price']) {
                    $preview[] = ['record_id' => $pid, 'name' => $p['name'].' - '.$price['name'], 'before' => ['price' => $price['price'], 'is_manual' => false], 'after' => ['price' => $next, 'is_manual' => false]];
                }
            }
        }
    }

    public function execute(string $operation, array $p, int $userId, array $approval = []): array
    {
        if ($operation === 'manage_business_records' && $p['action'] === 'restore') {
            $table = $p['type'].'s';
            $this->table($table)->where('id', $p['record_id'])->update(['is_active' => true, 'updated_at' => now()]);

            return ['record_id' => $p['record_id'], 'restored' => true, 'modules' => [$table]];
        }
        $actor = User::findOrFail($userId);
        $data = $p['data'];
        if (isset($approval['admin_pin'])) {
            $data['admin_pin'] = $approval['admin_pin'];
        }
        $r = Request::create('/api/ai-approved-management', 'POST', $data);
        $r->setUserResolver(fn () => $actor);
        $original = app('request');
        app()->instance('request', $r);
        $r->setUserResolver(fn () => $actor);
        try {
            [$class,$method] = $p['route'];
            $controller = app($class);
            $id = $p['record_id'];
            $result = match ($method) {
                'store' => $controller->store($r),'update' => $controller->update($r, $id),'storePayment' => $controller->storePayment($r, $p['party_id']),'updatePayment' => $controller->updatePayment($r, $id),'destroyPayment' => $controller->destroyPayment($id),'adjustStock' => $controller->adjustStock($r, $id),'destroy' => in_array($class, [ProductController::class, CustomerController::class, SupplierController::class]) ? $controller->destroy($id) : $controller->destroy($r, $id)
            };
            if ($result instanceof JsonResponse) {
                $result = $result->getData(true);
            }

            return ['record_id' => $result['id'] ?? $id, 'action' => $p['action'], 'saved' => true, 'modules' => ['products', 'sales', 'purchases', 'customers', 'suppliers', 'expenses', 'transactions', 'reports', 'shop']];
        } finally {
            app()->instance('request', $original);
        }
    }
}
