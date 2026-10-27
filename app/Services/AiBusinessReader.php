<?php

namespace App\Services;

use App\Ai\Skills\OwnerOnboarding;
use App\Ai\Tools\BusinessActionTool;
use App\Ai\Tools\BusinessManagementTool;
use App\Ai\Tools\BusinessReadTool;
use App\Models\User;
use App\Support\AppTime;
use App\Tenancy\TenantContext;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/** Private reads require an owner; navigation follows page permissions. No model-supplied tenant IDs or SQL. */
class AiBusinessReader
{
    private const MODULES = [
        'get_business_context' => [],
        'get_app_settings' => ['settings'],
        'get_setup_progress' => [],
        'check_business_readiness' => [],
        'get_feature_guide' => [],
        'get_next_setup_step' => [],
        'open_app_screen' => [],

        'get_business_summary' => ['reports'],
        'search_products' => ['products', 'sell', 'purchases'],
        'get_product_details' => ['products', 'sell', 'purchases'],
        'get_inventory_report' => ['products'],
        'search_sales' => ['sales'],
        'search_purchases' => ['purchases', 'transactions'],
        'get_customer_account' => ['customers', 'transactions'],
        'get_supplier_account' => ['suppliers'],
        'get_expense_report' => ['expenses', 'transactions'],
        'get_business_report' => ['reports'],
        'get_shop_orders' => ['shop'],
        'check_product_prices' => ['products'],
        'check_pricing_rules' => ['products'],
        'get_pricing_health_report' => ['products'],
    ];

    public static function fields(string $tool): array
    {
        $page = ['limit', 'offset'];
        $dates = ['start', 'end', 'all_time'];

        return match ($tool) {
            'get_business_context' => [],
            'get_feature_guide' => ['topic'],
            'open_app_screen' => ['screen'],
            'get_business_summary', 'get_business_report' => $dates,
            'search_products' => [...$page, 'query', 'category'],
            'get_product_details' => ['product_id'],
            'get_inventory_report' => [...$page, ...$dates, 'mode', 'product_id'],
            'search_sales' => [...$page, ...$dates, 'query', 'sale_id', 'customer_id', 'status'],
            'search_purchases' => [...$page, ...$dates, 'query', 'purchase_id', 'supplier_id', 'status'],
            'get_customer_account' => [...$page, ...$dates, 'query', 'customer_id', 'sort'],
            'get_supplier_account' => [...$page, ...$dates, 'query', 'supplier_id', 'sort'],
            'get_expense_report' => [...$page, ...$dates, 'query', 'category', 'status'],
            'get_shop_orders' => [...$page, ...$dates, 'query', 'order_id', 'status', 'payment_status'],
            'check_product_prices', 'get_pricing_health_report' => [...$page, 'query', 'category', 'product_id', 'price_type', 'severity', 'issue', 'after_product_id', 'scan_limit'],
            'check_pricing_rules' => [...$page, 'price_type', 'severity', 'issue'],
            default => [],
        };
    }

    public static function statuses(string $tool): array
    {
        return $tool === 'get_shop_orders'
            ? ['pending', 'accepted', 'packing', 'ready_for_pickup', 'dispatched', 'completed', 'cancelled', 'expired', 'return_pending']
            : ['completed', 'voided'];
    }

    public function availableTools(): array
    {
        return array_values(array_filter(array_keys(self::MODULES), fn ($tool) => ! self::MODULES[$tool]
            || array_intersect(self::MODULES[$tool], config('mkpos.enabled_modules', []))));
    }

    public function run(User $actor, string $tool, array $arguments): array
    {
        if ($tool === 'open_app_screen') {
            $args = $this->validate($tool, $arguments);
            $link = app(AiPageLinks::class)->forActor($actor, $args['screen'] ?? 'settings');
            $business = DB::table('businesses')->where('id', $actor->business_id)->first();

            return ['tool' => $tool, 'currency' => $business->currency ?? 'MMK', 'timezone' => $business->timezone ?? 'Asia/Yangon', 'data' => $link];
        }
        $businessId = app(TenantContext::class)->requireId();
        // Recheck stored identity on every call, including stale agent objects.
        $owner = DB::table('users')->where('id', $actor->id)->where('business_id', $businessId)
            ->where('role', 'owner')->where('is_active', true)->exists();
        $business = DB::table('businesses')->where('id', $businessId)->where('status', 'active')->first();
        abort_unless((int) $actor->business_id === $businessId, 403, 'business_context_mismatch');
        abort_unless($actor->role === 'owner' && $owner, 403, 'business_owner_required_or_access_changed');
        abort_unless($business, 403, 'business_inactive');
        abort_unless(isset(BusinessReadTool::DESCRIPTIONS[$tool]) && in_array($tool, $this->availableTools(), true), 403, 'business_module_unavailable');
        $args = $this->validate($tool, $arguments);
        $timezone = AppTime::businessTimezone();
        $today = now($timezone)->toDateString();
        $args += ['start' => $args['end'] ?? $today, 'end' => $args['start'] ?? $today, 'limit' => 10, 'offset' => 0];
        validator($args, ['end' => 'after_or_equal:start'])->validate();
        if ($args['all_time'] ?? false) {
            $args['start'] = $args['end'] = null;
        }
        // Read access follows existing GET routes: expired subscriptions retain history.
        $data = match ($tool) {
            'get_setup_progress', 'check_business_readiness', 'get_feature_guide', 'get_next_setup_step', 'open_app_screen' => app(AiOwnerOnboarding::class)->run($tool, $args),
            'get_app_settings' => app(AiAppSettings::class)->read(),
            'get_business_context' => ['business' => array_intersect_key((array) $business, array_flip(['id', 'name', 'currency', 'timezone'])), 'local_date' => $today, 'available_tools' => $this->availableTools(),
                'business_reads_are_read_only' => true, 'available_management_tools' => app(AiManagementOperations::class)->ready() ? BusinessManagementTool::NAMES : [], 'available_advisor_tools' => app(AiBusinessAdvisor::class)->ready() ? array_keys(AiBusinessAdvisor::TOOLS) : [], 'available_action_tools' => app(AiBusinessActions::class)->ready() ? [...array_values(array_diff(AiBusinessOperations::OPERATIONS, ['create_purchase_draft'])), ...BusinessActionTool::HELPERS] : [],
                'action_confirmation' => 'Action tools prepare proposals only. The owner must review and confirm the exact changes in the MKPOS app before they are saved.'],
            'get_business_summary', 'get_business_report' => $this->report($args, $tool === 'get_business_summary'),
            'search_products' => $this->products($args),
            'get_product_details' => $this->product($args),
            'get_inventory_report' => $this->inventory($args),
            'search_sales' => $this->transactions($args, false),
            'search_purchases' => $this->transactions($args, true),
            'get_customer_account' => $this->account($args, false),
            'get_supplier_account' => $this->account($args, true),
            'get_expense_report' => $this->expenses($args),
            'get_shop_orders' => $this->orders($args),
            'check_product_prices', 'check_pricing_rules', 'get_pricing_health_report' => app(AiPriceChecker::class)->run($tool, $args),
        };

        return ['tool' => $tool, 'currency' => $business->currency ?? 'MMK', 'timezone' => $timezone,
            'as_of' => now($timezone)->toIso8601String(), 'date_range' => array_intersect(self::fields($tool), ['start', 'end']) && ! ($tool === 'get_inventory_report' && ($args['mode'] ?? 'low_stock') !== 'movements') ? ['start' => $args['start'], 'end' => $args['end'], 'all_time' => (bool) ($args['all_time'] ?? false)] : null,
            'data' => $this->boundText($data)];
    }

    private function validate(string $tool, array $args): array
    {
        $rules = ['topic' => ['sometimes', Rule::in(array_keys(OwnerOnboarding::GUIDES))], 'screen' => ['sometimes', Rule::in(array_keys(AiPageLinks::pages()))], 'query' => 'sometimes|string|max:120', 'category' => 'sometimes|string|max:120',
            'price_type' => 'sometimes|string|max:50', 'severity' => ['sometimes', Rule::in(['all', 'error', 'warning'])],
            'issue' => ['sometimes', Rule::in(['all', ...AiPriceChecker::ISSUES])], 'after_product_id' => 'sometimes|integer|min:0', 'scan_limit' => 'sometimes|integer|min:1|max:10000',
            'start' => 'sometimes|date_format:Y-m-d', 'end' => 'sometimes|date_format:Y-m-d', 'all_time' => 'sometimes|boolean',
            'limit' => 'sometimes|integer|min:1|max:20', 'offset' => 'sometimes|integer|min:0|max:10000',
            'sort' => ['sometimes', Rule::in(['name', 'balance_desc'])],
            'mode' => ['sometimes', Rule::in(['low_stock', 'out_of_stock', 'all', 'movements'])],
            'status' => ['sometimes', Rule::in(self::statuses($tool))],
            'payment_status' => ['sometimes', Rule::in(['unpaid', 'proof_submitted', 'paid', 'refund_pending', 'refunded'])]];
        foreach (['product_id', 'sale_id', 'purchase_id', 'customer_id', 'supplier_id', 'order_id'] as $key) {
            $rules[$key] = ($tool === 'get_product_details' && $key === 'product_id' ? 'required' : 'sometimes').'|integer|min:1';
        }
        $allowed = self::fields($tool);
        if ($args['all_time'] ?? false) {
            $rules['start'] = $rules['end'] = 'prohibited';
        }
        foreach (array_diff(array_keys($args), $allowed) as $key) {
            $rules[$key] = 'prohibited';
        }

        return validator($args, array_intersect_key($rules, array_flip([...$allowed, ...array_keys($args)])))->validate();
    }

    private function table(string $table): Builder
    {
        return DB::table($table)->where($table.'.business_id', app(TenantContext::class)->requireId());
    }

    private function page(Builder $query, array $args, array $columns, string $apiPath): array
    {
        $total = (clone $query)->count();
        $items = $query->limit($args['limit'])->offset($args['offset'])->get($columns)->map(fn ($row) => (array) $row)->all();

        return ['items' => $items, 'total' => $total, 'limit' => $args['limit'], 'offset' => $args['offset'],
            'has_more' => $total > $args['offset'] + count($items),
            'next_offset' => $total > $args['offset'] + count($items) ? $args['offset'] + count($items) : null,
            'source' => ['api_path' => $apiPath]];
    }

    private function dates(Builder $query, array $args, string $column = 'created_at'): void
    {
        AppTime::filterDays($query, $args['start'], $args['end'], $column);
    }

    private function search(Builder $query, array $args, array $columns): void
    {
        if (($text = trim($args['query'] ?? '')) !== '') {
            $query->where(function ($q) use ($columns, $text) {
                foreach ($columns as $column) {
                    $q->orWhere($column, 'like', '%'.$text.'%');
                }
            });
        }
    }

    private function products(array $args): array
    {
        $q = $this->table('products')->where('is_active', true);
        $this->search($q, $args, ['name', 'sku', 'barcode', 'category']);
        if (isset($args['category'])) {
            $q->where('category', $args['category']);
        }

        return $this->page($q->orderBy('name')->orderBy('id'), $args,
            ['id', 'name', 'sku', 'barcode', 'category', 'base_unit', 'price', 'cost', 'stock', 'low_stock_threshold'], '/api/products');
    }

    private function product(array $args): array
    {
        $row = $this->table('products')->where('is_active', true)->where('id', $args['product_id'])->first([
            'id', 'name', 'sku', 'barcode', 'category', 'base_unit', 'purchase_unit', 'purchase_conversion_factor', 'price', 'cost', 'stock', 'low_stock_threshold', 'is_active',
        ]);
        abort_unless($row, 404);

        return ['product' => (array) $row,
            'prices' => $this->page($this->table('product_prices')->where('product_id', $row->id)->orderBy('id'), ['limit' => 20, 'offset' => 0], ['name', 'price', 'is_manual'], '/api/products/'.$row->id),
            'source' => ['api_path' => '/api/products/'.$row->id]];
    }

    private function inventory(array $args): array
    {
        $mode = $args['mode'] ?? 'low_stock';
        if ($mode === 'movements') {
            $q = $this->table('stock_movements');
            $q->whereIn('product_id', $this->table('products')->where('is_active', true)->select('id'));
            if (isset($args['product_id'])) {
                abort_unless($this->table('products')->where('is_active', true)->where('id', $args['product_id'])->exists(), 404);
                $q->where('product_id', $args['product_id']);
            }
            $this->dates($q, $args);

            return $this->page($q->orderByDesc('created_at')->orderByDesc('id'), $args,
                ['id', 'product_id', 'product_name', 'movement_type', 'quantity_change', 'reference_type', 'reference_id', 'created_at'], '/api/products');
        }
        $q = $this->table('products')->where('is_active', true);
        if (isset($args['product_id'])) {
            $q->where('id', $args['product_id']);
        }
        if ($mode === 'low_stock') {
            $q->where(fn ($q) => $q->where('stock', '<=', 0)->orWhere(fn ($q) => $q->where('low_stock_threshold', '>', 0)->whereColumn('stock', '<=', 'low_stock_threshold')));
        }
        if ($mode === 'out_of_stock') {
            $q->where('stock', '<=', 0);
        }

        return ['basis' => 'Current recorded stock; not historical or net of online reservations. Quantities use each product base unit.']
            + $this->page($q->orderBy('stock')->orderBy('id'), $args, ['id', 'name', 'base_unit', 'stock', 'low_stock_threshold', 'cost', 'price'], '/api/products');
    }

    private function transactions(array $args, bool $purchase): array
    {
        $table = $purchase ? 'purchases' : 'sales';
        $id = $purchase ? 'purchase_id' : 'sale_id';
        $party = $purchase ? 'supplier_id' : 'customer_id';
        $amount = $purchase ? 'total_cost' : 'total';
        $q = $this->table($table)->where('status', $args['status'] ?? 'completed');
        foreach ([$id => 'id', $party => $party] as $key => $column) {
            if (isset($args[$key])) {
                $q->where($column, $args[$key]);
            }
        }
        $this->search($q, $args, $purchase ? ['supplier_name', 'id'] : ['receipt_no', 'id']);
        $this->dates($q, $args);
        $summary = ['count' => (clone $q)->count(), 'total' => (int) (clone $q)->sum($amount), 'paid_at_posting' => (int) (clone $q)->sum('paid_amount'), 'original_credit' => (int) (clone $q)->sum('credit_amount')];
        $data = $this->page($q->orderByDesc('created_at')->orderByDesc('id'), isset($args[$id]) ? array_replace($args, ['offset' => 0, 'limit' => 1]) : $args,
            ['id', ...($purchase ? ['supplier_id', 'supplier_name'] : ['customer_id', 'receipt_no']), $amount, 'paid_amount', 'credit_amount', 'status', 'created_at'], '/api/'.$table);
        if (isset($args[$id]) && $data['items']) {
            $data['line_items'] = $this->page($this->table($purchase ? 'purchase_items' : 'sale_items')->where($id, $args[$id])->orderBy('id'), $args,
                ['id', 'product_id', 'product_name', 'quantity', 'foc_quantity', $purchase ? 'unit_cost' : 'unit_price', 'line_total'], '/api/'.$table.'/'.$args[$id]);
        }

        return ['summary' => $summary, 'date_basis' => 'created_at in business timezone', 'credit_basis' => 'Original invoice credit; use account tools for current outstanding balance.'] + $data;
    }

    private function account(array $args, bool $supplier): array
    {
        $parties = $supplier ? 'suppliers' : 'customers';
        $transactions = $supplier ? 'purchases' : 'sales';
        $payments = $supplier ? 'supplier_payments' : 'customer_payments';
        $key = $supplier ? 'supplier_id' : 'customer_id';
        $q = app(BusinessAccounts::class)->query($supplier);
        $this->search($q, $args, ['name', 'phone']);
        if (isset($args[$key])) {
            $q->where('id', $args[$key]);
        }
        if (($args['sort'] ?? 'name') === 'balance_desc') {
            $q->orderByDesc('balance');
        }
        $data = $this->page($q->orderBy('name')->orderBy('id'), isset($args[$key]) ? array_replace($args, ['offset' => 0, 'limit' => 1]) : $args, ['*'], '/api/'.$parties);
        $data['balance_basis'] = $supplier ? 'Current all-time balance. Positive: business owes supplier; negative: supplier credit.' : 'Current all-time balance. Positive: customer owes business; negative: business owes customer.';
        if (isset($args[$key])) {
            abort_unless($this->table($parties)->where('id', $args[$key])->where('is_active', true)->exists(), 404);
            $history = $this->table($transactions)->where($key, $args[$key]);
            $paymentHistory = $this->table($payments)->where($key, $args[$key]);
            $this->dates($history, $args);
            $this->dates($paymentHistory, $args);
            $data['transactions'] = $this->page($history->orderByDesc('created_at')->orderByDesc('id'), $args, ['id', $supplier ? 'total_cost' : 'total', 'paid_amount', 'credit_amount', 'status', 'created_at'], '/api/'.$parties.'/'.$args[$key].'/statement');
            $data['payments'] = $this->page($paymentHistory->orderByDesc('created_at')->orderByDesc('id'), $args, ['id', 'amount', 'direction', 'payment_method', 'status', 'created_at'], '/api/'.$parties.'/'.$args[$key].'/statement');
        }

        return $data;
    }

    private function expenses(array $args): array
    {
        $q = $this->table('expenses')->where('status', $args['status'] ?? 'completed');
        if ($args['start']) {
            $q->whereDate('expense_date', '>=', $args['start']);
        }
        if ($args['end']) {
            $q->whereDate('expense_date', '<=', $args['end']);
        }
        $this->search($q, $args, ['title', 'category']);
        if (isset($args['category'])) {
            $q->where('category', $args['category']);
        }
        $total = (int) (clone $q)->sum('amount');
        $categories = (clone $q)->select('category')->selectRaw('SUM(amount) as total')->groupBy('category')->orderByDesc('total')->limit(20)->get()->all();

        return ['total_amount' => $total, 'top_categories' => $categories, 'date_basis' => 'expense_date']
            + $this->page($q->orderByDesc('expense_date')->orderByDesc('id'), $args, ['id', 'title', 'category', 'amount', 'payment_method', 'expense_date', 'status'], '/api/expenses');
    }

    private function orders(array $args): array
    {
        $q = $this->table('ecommerce_orders');
        foreach (['status', 'payment_status'] as $key) {
            if (isset($args[$key])) {
                $q->where($key, $args[$key]);
            }
        }
        if (isset($args['order_id'])) {
            $q->where('id', $args['order_id']);
        }
        $this->search($q, $args, ['reference', 'customer_name']);
        $this->dates($q, $args);
        $data = $this->page($q->orderByDesc('created_at')->orderByDesc('id'), isset($args['order_id']) ? array_replace($args, ['offset' => 0, 'limit' => 1]) : $args,
            ['id', 'reference', 'customer_name', 'status', 'payment_status', 'fulfillment', 'currency', 'total', 'created_at'], '/api/shop-admin/orders');
        if (isset($args['order_id']) && $data['items']) {
            $data['line_items'] = $this->page($this->table('ecommerce_order_items')->where('order_id', $args['order_id'])->orderBy('id'), $args,
                ['id', 'product_id', 'name', 'unit_name', 'quantity', 'unit_price', 'line_total'], '/api/shop-admin/orders/'.$args['order_id']);
        }

        return $data;
    }

    private function report(array $args, bool $overview): array
    {
        $report = app(BusinessReport::class)->summary(Request::create('/', 'GET', ['start' => $args['start'], 'end' => $args['end'], 'all_time' => (bool) ($args['all_time'] ?? false)]), false);
        // Fixed chart arrays are unnecessary for a language model; category lists are bounded.
        unset($report['sales_trends']);
        $report['expense_categories'] = array_slice($report['expense_categories'], 0, 20);
        $report['payment_methods'] = array_slice($report['payment_methods'], 0, 20);
        if ($overview) {
            $report['low_stock_count'] = $this->table('products')->where('is_active', true)->where(fn ($q) => $q->where('stock', '<=', 0)->orWhere(fn ($q) => $q->where('low_stock_threshold', '>', 0)->whereColumn('stock', '<=', 'low_stock_threshold')))->count();
            $report['pending_shop_orders'] = in_array('get_shop_orders', $this->availableTools(), true) ? $this->table('ecommerce_orders')->where('status', 'pending')->count() : null;
        }

        return ['report' => $report, 'notes' => 'Profit is an estimate. Current accounts and inventory are as of now, independent of the date range. Stock units may represent different base units.',
            'source' => ['api_path' => '/api/reports/summary?'.(($args['all_time'] ?? false) ? 'all_time=true' : 'start='.$args['start'].'&end='.$args['end'])]];
    }

    private function boundText(mixed $value): mixed
    {
        if (is_object($value)) {
            $value = (array) $value;
        }
        if (is_array($value)) {
            return array_map(fn ($item) => $this->boundText($item), $value);
        }

        return is_string($value) && mb_strlen($value) > 500 ? mb_substr($value, 0, 500).' [truncated]' : $value;
    }
}
