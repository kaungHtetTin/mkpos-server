<?php

namespace App\Services;

use App\Models\User;
use App\Support\AppTime;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Owner-scoped diagnostics and calculations. No raw financial/stock repair. */
class AiBusinessAdvisor
{
    public const TOOLS = [
        'check_business_data_integrity' => 'Read bounded business data checks: product units/identifiers, sale and purchase totals, missing linked records and setup gaps. Findings distinguish errors from warnings. Inspect coverage and paginate with offset; never claim a full audit from one page.',
        'explain_business_issue' => 'Recheck an issue_id returned by check_business_data_integrity and explain current evidence, impact and permitted next action. A resolved or unavailable issue is not proof of a new problem.',
        'prepare_issue_fix' => 'Recheck a detected product issue and prepare only its supported field correction for owner review. Supply issue_id and explicit owner-selected values. Does not save or fix financial history, stock, security or staff access.',
        'get_restock_suggestions' => 'Suggest base-unit reorder quantities from completed sales demand including FOC, stock, reservations, threshold, lookback_days, coverage_days and lead_days. Pending incoming purchase orders are not tracked. Unposted drafts do not count as received stock. Read only.',
        'get_slow_moving_products' => 'Read stocked active products with little or no completed sale quantity during lookback_days, their last sale, current cost-based stock value and observation limits. max_sold_quantity is a per-product base-unit threshold, not units summed across products.',
        'get_outstanding_accounts' => 'Read current customer and supplier balances net of completed account payments. Keeps receivables, payables and credits distinct. No invoice aging or overdue claims: due dates are not tracked. Paginated, read only.',
        'prepare_purchase_from_invoice' => 'Use an owned invoice screenshot already visible in this chat. Supply extracted lines with exact matched product_id, source_name, unit_name, quantity, foc_quantity and unit_cost; supplier_id optional, invoice_total required in business currency. Never guess ambiguous matches or quantities. Returns a purchase DRAFT proposal for owner review, not a posted purchase. Unknown lines must be clarified first. Cash payment is not inferred from an invoice.',
        'check_daily_closing' => 'Read completed cash movements by payment method for one business-local date. Cash drawer comparison requires opening_cash and counted_cash together; otherwise return recorded movement only. cash_method defaults exactly Cash. No cash count, closure, bank reconciliation or stock adjustment is written.',
    ];

    public function ready(): bool
    {
        return Schema::hasTable('ai_purchase_drafts');
    }

    public function authorize(User $actor, array $modules = []): void
    {
        app(AiBusinessReader::class)->run($actor, 'get_business_context', []);
        abort_unless($this->ready(), 503, 'Business advisor setup is required.');
        foreach ($modules as $module) {
            abort_unless(in_array($module, config('mkpos.enabled_modules', []), true), 403, 'Required business module is unavailable: '.$module);
        }
    }

    private function table(string $table)
    {
        return DB::table($table)->where($table.'.business_id', app(TenantContext::class)->requireId());
    }

    private function enabled(string $module): bool
    {
        return in_array($module, config('mkpos.enabled_modules', []), true);
    }

    public function run(User $actor, string $tool, array $input, ?string $conversation = null, ?int $messageId = null): array
    {
        $this->authorize($actor);
        abort_unless(isset(self::TOOLS[$tool]), 422);
        $rules = match ($tool) {
            'check_business_data_integrity' => ['area' => 'sometimes|in:all,products,sales,purchases,accounts,settings', 'offset' => 'sometimes|integer|min:0|max:1000000', 'scan_limit' => 'sometimes|integer|min:1|max:500'],
            'explain_business_issue' => ['issue_id' => 'required|string|max:120'],
            'prepare_issue_fix' => ['issue_id' => 'required|string|max:120', 'values' => 'required|array|min:1'],
            'get_restock_suggestions' => ['lookback_days' => 'sometimes|integer|min:1|max:365', 'coverage_days' => 'sometimes|integer|min:1|max:365', 'lead_days' => 'sometimes|integer|min:0|max:365', ...$this->pageRules()],
            'get_slow_moving_products' => ['lookback_days' => 'sometimes|integer|min:1|max:3650', 'max_sold_quantity' => 'sometimes|numeric|min:0|max:1000000', ...$this->pageRules()],
            'get_outstanding_accounts' => ['party' => 'sometimes|in:all,customer,supplier', ...$this->pageRules()],
            'check_daily_closing' => ['date' => 'sometimes|date_format:Y-m-d', 'cash_method' => 'sometimes|string|max:50', 'opening_cash' => 'required_with:counted_cash|integer|min:0|max:1000000000000', 'counted_cash' => 'required_with:opening_cash|integer|min:0|max:1000000000000'],
            'prepare_purchase_from_invoice' => ['image_message_id' => 'required|integer|min:1', 'supplier_id' => 'sometimes|nullable|integer|min:1', 'supplier_name' => 'sometimes|nullable|string|max:255', 'invoice_reference' => 'sometimes|nullable|string|max:120', 'invoice_total' => 'required|integer|min:0|max:1000000000000', 'currency' => 'required|string|max:10', 'items' => 'required|array|min:1|max:50', 'items.*' => 'array:product_id,source_name,unit_name,quantity,foc_quantity,unit_cost', 'items.*.product_id' => 'required|integer|min:1', 'items.*.source_name' => 'required|string|max:255', 'items.*.unit_name' => 'required|string|max:50', 'items.*.quantity' => 'required|numeric|min:0.001|max:1000000', 'items.*.foc_quantity' => 'sometimes|numeric|min:0|max:1000000', 'items.*.unit_cost' => 'required|integer|min:0|max:1000000000'],
        };
        $allowed = array_filter(array_keys($rules), fn ($key) => ! str_contains($key, '.'));
        abort_if(array_diff(array_keys($input), $allowed), 422, 'Unsupported tool arguments.');
        $args = validator($input, $rules)->validate();
        $data = match ($tool) {
            'check_business_data_integrity' => $this->integrity($args),
            'explain_business_issue' => $this->explain($args['issue_id']),
            'prepare_issue_fix' => $this->fix($actor, $conversation, $messageId, $args),
            'get_restock_suggestions' => $this->inventoryAdvice($args, false),
            'get_slow_moving_products' => $this->inventoryAdvice($args, true),
            'get_outstanding_accounts' => $this->outstanding($args),
            'check_daily_closing' => $this->closing($args),
            'prepare_purchase_from_invoice' => app(AiInvoicePurchases::class)->prepare($actor, $conversation, $messageId, $args),
        };

        return ['tool' => $tool, 'currency' => DB::table('businesses')->where('id', $actor->business_id)->value('currency'), 'as_of' => now(AppTime::businessTimezone())->toIso8601String(), 'timezone' => AppTime::businessTimezone(), 'data' => $data];
    }

    private function pageRules(): array
    {
        return ['limit' => 'sometimes|integer|min:1|max:20', 'offset' => 'sometimes|integer|min:0|max:1000000'];
    }

    private function finding(string $area, int $id, string $code, string $severity, array $evidence, string $impact, array $fields = []): array
    {
        $screen = $area === 'accounts' ? ($this->enabled('transactions') ? 'transactions' : (str_contains($code, 'supplier') ? 'suppliers' : 'customers')) : $area;

        return ['issue_id' => "$area:$id:$code", 'area' => $area, 'record_id' => $id, 'code' => $code, 'severity' => $severity, 'evidence' => $evidence, 'impact' => $impact, 'supported_fix_fields' => $fields,
            'next_step' => $fields ? 'Ask the owner for correct values; prepare a reviewed correction.' : 'Review the source record. Financial and stock history must use the normal app workflow.',
            'screen' => app(AiPageLinks::class)->screen($screen)];
    }

    private function integrity(array $a, ?int $recordId = null): array
    {
        $area = $a['area'] ?? 'all';
        $offset = $a['offset'] ?? 0;
        $limit = $a['scan_limit'] ?? 100;
        $findings = [];
        $coverage = [];
        $skipped = [];
        foreach (['products', 'sales', 'purchases'] as $table) {
            if ($area !== 'all' && $area !== $table) {
                continue;
            }
            if (! $this->enabled($table)) {
                $skipped[] = $table;

                continue;
            }
            $q = $this->table($table);
            if ($table === 'products') {
                $q->where('is_active', true);
            } else {
                $q->where('status', 'completed');
            }
            if ($recordId !== null) {
                $q->where('id', $recordId);
            }
            $total = (clone $q)->count();
            $rows = $q->orderBy('id')->offset($offset)->limit($limit)->get();
            $coverage[$table] = ['scanned' => $rows->count(), 'total' => $total, 'offset' => $offset, 'has_more' => $offset + $rows->count() < $total, 'next_offset' => $offset + $rows->count() < $total ? $offset + $rows->count() : null];
            foreach ($rows as $row) {
                $add = function ($code, $severity, $evidence, $impact, $fields = []) use (&$findings, $table, $row) {
                    $findings[] = $this->finding($table, $row->id, $code, $severity, $evidence, $impact, $fields);
                };
                if ($table === 'products') {
                    if (trim($row->base_unit) === '') {
                        $add('missing_base_unit', 'error', ['name' => $row->name, 'base_unit' => $row->base_unit], 'Quantities have no defined base unit.', ['base_unit']);
                    }
                    if ((float) $row->purchase_conversion_factor <= 0 || (! $row->purchase_unit && (float) $row->purchase_conversion_factor !== 1.0) || ($row->purchase_unit === $row->base_unit && (float) $row->purchase_conversion_factor !== 1.0)) {
                        $add('invalid_conversion', 'error', ['name' => $row->name, 'base_unit' => $row->base_unit, 'purchase_unit' => $row->purchase_unit, 'purchase_conversion_factor' => $row->purchase_conversion_factor], 'Buying-unit quantities cannot be interpreted consistently.', ['purchase_unit', 'purchase_conversion_factor']);
                    }
                    if ((float) $row->stock < 0) {
                        $add('negative_stock', 'warning', ['name' => $row->name, 'stock' => $row->stock, 'base_unit' => $row->base_unit], 'Recorded issues exceed available stock. Check movements and actual stock; do not set stock automatically.');
                    }
                    foreach (['sku', 'barcode'] as $field) {
                        if (trim($row->$field) !== '' && $this->table('products')->where('is_active', true)->where($field, $row->$field)->where('id', '!=', $row->id)->exists()) {
                            $add('duplicate_'.$field, 'warning', ['name' => $row->name, $field => $row->$field], 'More than one active product has this identifier; verify whether intentional.', [$field]);
                        }
                    }
                    if ((float) $row->low_stock_threshold < 0) {
                        $add('invalid_threshold', 'error', ['name' => $row->name, 'low_stock_threshold' => $row->low_stock_threshold], 'Low-stock alerts use an invalid threshold.', ['low_stock_threshold']);
                    }
                } else {
                    $purchase = $table === 'purchases';
                    $items = $purchase ? 'purchase_items' : 'sale_items';
                    $key = $purchase ? 'purchase_id' : 'sale_id';
                    $amount = $purchase ? 'total_cost' : 'total';
                    $lines = $this->table($items)->where($key, $row->id);
                    $ecommerce = ! $purchase && ($row->source ?? '') === 'ecommerce';
                    $computedSubtotal = $purchase ? (int) round((clone $lines)->selectRaw('COALESCE(SUM(quantity * unit_cost),0) AS value')->value('value')) : ($ecommerce ? (int) (clone $lines)->sum('line_total') : (int) round((clone $lines)->selectRaw('COALESCE(SUM(quantity * unit_price),0) AS value')->value('value')));
                    $computed = $purchase ? $computedSubtotal : max(0, $computedSubtotal - (int) $row->discount) + (int) ($row->delivery_fee ?? 0);
                    if (! $purchase && $computedSubtotal !== (int) $row->subtotal) {
                        $add('subtotal_mismatch', 'error', ['recorded_subtotal' => $row->subtotal, 'computed_subtotal' => $computedSubtotal], 'The recorded subtotal disagrees with the posting calculation.');
                    }
                    $unitPrice = $purchase ? 'unit_cost' : 'unit_price';
                    if (! $ecommerce && ($badLines = (clone $lines)->whereRaw('line_total <> ROUND(quantity * '.$unitPrice.')')->count())) {
                        $add('line_total_mismatch', 'error', ['invalid_line_count' => $badLines], 'Line totals disagree with paid quantity multiplied by unit price or cost. FOC is not billed.');
                    }
                    if ($purchase && ($badUnits = (clone $lines)->whereRaw('conversion_factor <= 0 OR ABS(base_quantity - ROUND(quantity * conversion_factor,3)) > 0.001 OR ABS(base_foc_quantity - ROUND(foc_quantity * conversion_factor,3)) > 0.001')->count())) {
                        $add('line_unit_mismatch', 'error', ['invalid_line_count' => $badUnits], 'Recorded received base-unit quantities disagree with the purchase unit conversion.');
                    }
                    if ($computed !== (int) $row->$amount || (int) $row->paid_amount + (int) $row->credit_amount !== (int) $row->$amount) {
                        $add('total_mismatch', 'error', ['recorded_total' => $row->$amount, 'computed_total' => $computed, 'paid_amount' => $row->paid_amount, 'original_credit_amount' => $row->credit_amount], 'Header, line totals or original payment split disagree. Historical credit is not current account debt.');
                    }
                    if (! (clone $lines)->exists()) {
                        $add('missing_items', 'error', ['recorded_total' => $row->$amount], 'Completed transaction has no owned line items.');
                    }
                    $missing = (clone $lines)->whereNotExists(fn ($p) => $p->selectRaw('1')->from('products')->whereColumn('products.id', $items.'.product_id')->where('products.business_id', app(TenantContext::class)->requireId()))->count();
                    if ($missing) {
                        $add('missing_product', 'error', ['invalid_line_count' => $missing], 'Line items reference an unavailable product in this business. Historical names do not prove a current link.');
                    }
                    $party = $purchase ? 'supplier' : 'customer';
                    $partyId = $row->{$party.'_id'};
                    if ($partyId && ! $this->table($party.'s')->where('id', $partyId)->exists()) {
                        $add('missing_party', 'error', ['linked_record_id' => $partyId], 'The linked account does not belong to an available record in this business.');
                    }
                }
            }
        }
        foreach (['sales' => ['sale_items', 'sale_id'], 'purchases' => ['purchase_items', 'purchase_id']] as $parent => [$linesTable,$parentKey]) {
            if (($area !== 'all' && $area !== $parent) || ! $this->enabled($parent)) {
                continue;
            }
            $orphans = $this->table($linesTable)->whereNotExists(fn ($q) => $q->selectRaw('1')->from($parent)->whereColumn($parent.'.id', $linesTable.'.'.$parentKey)->where($parent.'.business_id', app(TenantContext::class)->requireId()));
            if ($recordId !== null) {
                $orphans->where('id', $recordId);
            }
            $total = (clone $orphans)->count();
            $rows = $orphans->orderBy('id')->offset($offset)->limit($limit)->get(['id', $parentKey]);
            $coverage[$linesTable.'_orphans'] = ['invalid_records' => $total, 'scanned' => $rows->count(), 'has_more' => $total > $offset + $rows->count(), 'next_offset' => $total > $offset + $rows->count() ? $offset + $rows->count() : null];
            foreach ($rows as $line) {
                $findings[] = $this->finding($parent, $line->id, 'orphan_line', 'error', ['source_table' => $linesTable, 'linked_record_id' => $line->$parentKey], 'A line has no parent transaction in this business. Review data integrity through support.');
            }
        }
        if (($area === 'all' || $area === 'accounts')) {
            foreach (['customer', 'supplier'] as $party) {
                if (! $this->enabled($party.'s')) {
                    $skipped[] = $party.'_accounts';

                    continue;
                }
                $table = $party.'_payments';
                $q = $this->table($table)->where('status', 'completed');
                if ($recordId !== null) {
                    $q->where('id', $recordId);
                }
                $q->whereNotExists(fn ($p) => $p->selectRaw('1')->from($party.'s')->whereColumn($party.'s.id', $table.'.'.$party.'_id')->where($party.'s.business_id', app(TenantContext::class)->requireId()));
                $total = (clone $q)->count();
                $rows = $q->orderBy('id')->offset($offset)->limit($limit)->get(['id', 'amount']);
                $coverage[$table] = ['invalid_records' => $total, 'scanned' => $rows->count(), 'has_more' => $total > $offset + $rows->count(), 'next_offset' => $total > $offset + $rows->count() ? $offset + $rows->count() : null];
                foreach ($rows as $row) {
                    $findings[] = $this->finding('accounts', $row->id, 'missing_'.$party, 'error', ['amount' => $row->amount, 'source_table' => $table], 'Completed payment has no valid owned account link.');
                }
            }
        }
        if (($area === 'all' || $area === 'settings') && $this->enabled('settings')) {
            $gaps = app(AiOwnerOnboarding::class)->run('check_business_readiness', [])['findings'];
            foreach ($gaps as $gap) {
                $findings[] = ['issue_id' => 'settings:0:'.$gap['id'], 'area' => 'settings', 'record_id' => 0, 'code' => $gap['id'], 'severity' => $gap['required'] ? 'error' : 'warning', 'evidence' => ['label' => $gap['label'], 'detail' => $gap['evidence']], 'impact' => $gap['required'] ? 'A required setup step is missing.' : 'Review suggestion; defaults or an empty business may be intentional.', 'supported_fix_fields' => [], 'next_step' => 'Use the setup guide and the linked page.', 'screen' => $gap['action']];
            }
        }

        return ['findings' => $findings, 'coverage' => $coverage, 'skipped_modules' => $skipped, 'complete' => ! collect($coverage)->contains(fn ($c) => $c['has_more']) && ! $skipped, 'writes_performed' => false, 'scope' => 'Configured deterministic checks on owned records only; not an accounting audit. Price-rule checks remain available through get_pricing_health_report.'];
    }

    private function explain(string $id): array
    {
        abort_unless(preg_match('/^(products|sales|purchases|accounts|settings):(\d+):([a-z_]+)$/', $id, $m), 422, 'Use a returned issue ID.');
        $result = $this->integrity(['area' => $m[1], 'scan_limit' => 1], (int) $m[2]);
        $finding = collect($result['findings'])->firstWhere('issue_id', $id);

        return ['status' => $finding ? 'detected' : 'resolved_or_unavailable', 'finding' => $finding, 'writes_performed' => false];
    }

    private function fix(User $actor, ?string $conversation, ?int $messageId, array $a): array
    {
        $issue = $this->explain($a['issue_id'])['finding'];
        abort_unless($issue && $issue['supported_fix_fields'], 422, 'This issue requires the normal app correction workflow; no automatic repair is supported.');
        abort_if(array_diff(array_keys($a['values']), $issue['supported_fix_fields']), 422, 'Only the fields identified by this issue may be proposed.');
        abort_unless($conversation && $messageId, 422, 'An authenticated conversation is required.');
        $action = app(AiBusinessActions::class)->prepare($actor, $conversation, $messageId, 'update_product', ['record_id' => $issue['record_id'], ...$a['values']]);

        return ['issue' => $issue, 'action' => $action, 'saved' => false, 'owner_approval_required' => true];
    }

    private function inventoryAdvice(array $a, bool $slow): array
    {
        abort_unless($this->enabled('products') && $this->enabled('sales'), 403, 'Products and sales modules are required.');
        $days = $a['lookback_days'] ?? ($slow ? 90 : 30);
        $coverage = $a['coverage_days'] ?? 14;
        $lead = $a['lead_days'] ?? 0;
        $from = now(AppTime::businessTimezone())->startOfDay()->subDays($days - 1)->toDateString();
        $to = now(AppTime::businessTimezone())->toDateString();
        $sales = $this->table('sales')->where('status', 'completed');
        AppTime::filterDays($sales, $from, $to);
        $demand = $this->table('sale_items')->whereIn('sale_id', $sales->select('id'))->select('product_id')->selectRaw('SUM(quantity + foc_quantity) AS issued, SUM(quantity) AS sold')->groupBy('product_id');
        $last = $this->table('sale_items')->join('sales as s', fn ($j) => $j->on('s.id', '=', 'sale_items.sale_id')->where('s.business_id', app(TenantContext::class)->requireId())->where('s.status', 'completed'))->where('s.created_at', '<=', now()->toDateTimeString())->select('product_id')->selectRaw('MAX(s.created_at) AS last_sold_at')->groupBy('product_id');
        $q = $this->table('products')->where('is_active', true)->leftJoinSub($demand, 'demand', 'demand.product_id', '=', 'products.id')->leftJoinSub($last, 'last_sale', 'last_sale.product_id', '=', 'products.id');
        $reserved = '0';
        if (Schema::hasTable('ecommerce_stock_reservations')) {
            $r = $this->table('ecommerce_stock_reservations')->where('status', 'active')->select('product_id')->selectRaw('SUM(quantity) AS reserved')->groupBy('product_id');
            $q->leftJoinSub($r, 'reservations', 'reservations.product_id', '=', 'products.id');
            $reserved = 'COALESCE(reservations.reserved,0)';
        }
        if ($slow) {
            $q->where('stock', '>', 0)->whereRaw('COALESCE(demand.sold,0) <= ?', [$a['max_sold_quantity'] ?? 0]);
        } else {
            $q->where(fn ($w) => $w->whereRaw("stock - $reserved < low_stock_threshold")->orWhereRaw("stock - $reserved < COALESCE(demand.issued,0) * 1.0 / ? * ?", [$days, $coverage + $lead]));
        }
        $total = (clone $q)->count();
        $offset = $a['offset'] ?? 0;
        $limit = $a['limit'] ?? 10;
        $rows = $q->orderBy('products.id')->offset($offset)->limit($limit)->select('products.id', 'products.name', 'products.base_unit', 'products.stock', 'products.cost', 'products.low_stock_threshold', 'last_sale.last_sold_at')->selectRaw("COALESCE(demand.issued,0) AS issued, COALESCE(demand.sold,0) AS sold, $reserved AS reserved")->get();
        $items = $rows->map(function ($p) use ($days, $coverage, $lead, $slow) {
            $available = (float) $p->stock - (float) $p->reserved;
            $daily = (float) $p->issued / $days;
            $target = max(0, (float) $p->low_stock_threshold, $daily * ($coverage + $lead));

            return ['product_id' => $p->id, 'name' => $p->name, 'base_unit' => $p->base_unit, 'recorded_stock' => (float) $p->stock, 'reserved' => (float) $p->reserved, 'available_stock' => $available, 'sold_quantity' => (float) $p->sold, 'issued_including_foc' => (float) $p->issued, 'last_sale_at' => $p->last_sold_at, 'stock_value_at_current_cost' => (float) $p->stock * (float) $p->cost,
                ...($slow ? [] : ['average_daily_issued' => $daily, 'target_stock' => ceil($target * 1000) / 1000, 'suggested_quantity' => max(0, ceil(($target - $available) * 1000) / 1000), 'low_stock_threshold' => (float) $p->low_stock_threshold])];
        })->all();

        return ['items' => $items, 'total' => $total, 'offset' => $offset, 'limit' => $limit, 'has_more' => $offset + count($items) < $total, 'next_offset' => $offset + count($items) < $total ? $offset + count($items) : null, 'date_range' => ['start' => $from, 'end' => $to], 'lookback_days' => $days, 'coverage_days' => $coverage, 'lead_days' => $lead,
            'basis' => $slow ? 'Stocked active products with limited paid-unit sales during the period. Last sale includes completed sale lines. Current stock value is an estimate at current cost.' : 'Target = max(low-stock threshold, average daily issued base units ? (coverage days + lead days)); suggestion = max(target ? (stock ? reservations), 0), rounded up to 0.001 base unit.',
            'limitations' => ['Sparse history, new products, stockouts and seasonality can distort suggestions.', 'Incoming purchase orders and due dates are not tracked; unposted purchase drafts are not incoming or received stock.', 'Different product base units are not added together.'], 'writes_performed' => false];
    }

    private function outstanding(array $a): array
    {
        $parties = ($a['party'] ?? 'all') === 'all' ? ['customer', 'supplier'] : [$a['party']];
        $queries = [];
        $skipped = [];
        foreach ($parties as $party) {
            if (! $this->enabled($party.'s')) {
                if (($a['party'] ?? 'all') !== 'all') {
                    abort(403, 'Account module is unavailable.');
                }$skipped[] = $party;

                continue;
            }
            $q = app(BusinessAccounts::class)->query($party === 'supplier');
            $queries[] = DB::query()->fromSub($q, 'a')->where('balance', '!=', 0)->select('id', 'name', 'phone', 'balance')->selectRaw('? AS party', [$party]);
        }
        abort_unless($queries, 403, 'No account modules are available.');
        $union = array_shift($queries);
        foreach ($queries as $q) {
            $union->unionAll($q);
        }
        $base = DB::query()->fromSub($union, 'accounts');
        $total = (clone $base)->count();
        $offset = $a['offset'] ?? 0;
        $limit = $a['limit'] ?? 10;
        $totals = (clone $base)->selectRaw('party, SUM(CASE WHEN balance>0 THEN balance ELSE 0 END) AS positive_balance, SUM(CASE WHEN balance<0 THEN -balance ELSE 0 END) AS credit_balance')->groupBy('party')->get()->map(fn ($r) => (array) $r)->all();
        $items = $base->orderByRaw('ABS(balance) DESC')->orderBy('party')->orderBy('id')->offset($offset)->limit($limit)->get()->map(fn ($r) => ['party' => $r->party, 'record_id' => $r->id, 'name' => $r->name, 'phone' => $r->phone, 'amount' => abs((float) $r->balance), 'direction' => $r->party === 'customer' ? ($r->balance > 0 ? 'customer_owes_business' : 'business_owes_customer') : ($r->balance > 0 ? 'business_owes_supplier' : 'supplier_owes_business'), 'overdue' => null, 'screen' => app(AiPageLinks::class)->screen($r->party === 'customer' ? 'customers' : 'suppliers')])->all();

        return ['items' => $items, 'total' => $total, 'totals_by_party' => $totals, 'offset' => $offset, 'limit' => $limit, 'has_more' => $offset + count($items) < $total, 'next_offset' => $offset + count($items) < $total ? $offset + count($items) : null, 'skipped_modules' => $skipped, 'basis' => 'Current active accounts: original completed transaction credit less net completed account payments. Not individual invoice aging. Due dates are not tracked; outstanding does not mean overdue.', 'writes_performed' => false];
    }

    private function closing(array $a): array
    {
        abort_unless($this->enabled('reports'), 403, 'Reports module is required.');
        $date = $a['date'] ?? now(AppTime::businessTimezone())->toDateString();
        abort_if($date > now(AppTime::businessTimezone())->toDateString(), 422, 'Choose today or a past closing date.');
        $methods = [];
        $excluded = [];
        $add = function ($table, $module, $field, $column, $direction = null) use ($date, &$methods, &$excluded) {
            if (! $this->enabled($module)) {
                $excluded[] = $table;

                return;
            }
            $q = $this->table($table)->where('status', 'completed');
            if ($table === 'expenses') {
                $q->whereDate('expense_date', $date);
            } else {
                AppTime::filterDays($q, $date, $date);
            }
            $rows = $q->select('payment_method')->selectRaw($direction ? "SUM(CASE WHEN direction=? THEN $column ELSE -$column END) AS movement" : "SUM($column) AS movement", $direction ? [$direction] : [])->groupBy('payment_method')->get();
            foreach ($rows as $r) {
                $name = $r->payment_method;
                $methods[$name] ??= ['payment_method' => $name, 'sales_received' => 0, 'customer_payments_net' => 0, 'purchases_paid' => 0, 'supplier_payments_net' => 0, 'expenses' => 0];
                $methods[$name][$field] += (float) $r->movement;
            }
        };
        $add('sales', 'sales', 'sales_received', 'paid_amount');
        $add('customer_payments', 'customers', 'customer_payments_net', 'amount', 'customer_to_shop');
        $add('purchases', 'purchases', 'purchases_paid', 'paid_amount');
        $add('supplier_payments', 'suppliers', 'supplier_payments_net', 'amount', 'shop_to_supplier');
        $add('expenses', 'expenses', 'expenses', 'amount');
        foreach ($methods as &$m) {
            $m['net_movement'] = $m['sales_received'] + $m['customer_payments_net'] - $m['purchases_paid'] - $m['supplier_payments_net'] - $m['expenses'];
        }unset($m);
        $method = $a['cash_method'] ?? 'Cash';
        $configured = explode(',', $this->table('settings')->where('key', 'payment_methods')->value('value') ?: 'Cash,Wallet Pay,Banking Pay,KPay,Wave Pay,Credit');
        abort_unless(in_array($method,array_map('trim',$configured),true) || isset($methods[$method]),422,'Choose an exact configured or recorded payment-method name.');
        $movement = $methods[$method]['net_movement'] ?? 0;
        $expected = isset($a['opening_cash']) && ! $excluded ? $a['opening_cash'] + $movement : null;

        return ['date' => $date, 'methods' => array_values($methods), 'cash_method' => $method, 'recorded_cash_movement' => $movement, 'opening_cash' => $a['opening_cash'] ?? null, 'counted_cash' => $a['counted_cash'] ?? null, 'expected_cash' => $expected, 'difference' => $expected !== null ? $a['counted_cash'] - $expected : null,
            'status' => $excluded ? 'incomplete' : (! isset($a['counted_cash']) ? 'count_required' : (abs($expected - $a['counted_cash']) < 0.5 ? 'matches' : 'difference')), 'excluded_modules' => $excluded,
            'basis' => 'Recorded completed transactions only, separated by exact payment-method name. Sales/purchases/account payments use posting timestamps in business timezone; expenses use expense_date. Opening/count supplied by owner, not independently verified. Excludes unrecorded movements and is not bank reconciliation.', 'writes_performed' => false];
    }
}
