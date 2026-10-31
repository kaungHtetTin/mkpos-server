<?php

namespace App\Services;

use App\Support\AppTime;
use App\Tenancy\TenantContext;
use Carbon\Carbon;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Shared report calculations for the Reports screen and owner AI tools. */
class BusinessReport
{
    public function summary(Request $request, bool $includeTrends = true): array
    {
        $allTime = $request->boolean('all_time');
        $timezone = AppTime::businessTimezone();
        $today = now($timezone);
        $start = $allTime ? null : ($request->query('start') ?: $today->copy()->startOfMonth()->toDateString());
        $end = $allTime ? null : ($request->query('end') ?: $today->toDateString());
        $sales = $this->table('sales')->where('status', 'completed');
        $expenses = $this->table('expenses')->where('status', 'completed');
        $payments = $this->table('customer_payments')->where('status', 'completed');
        $supplierPayments = $this->table('supplier_payments')->where('status', 'completed');
        $purchases = $this->table('purchases')->where('status', 'completed');
        foreach ([$sales, $payments, $supplierPayments, $purchases] as $query) {
            AppTime::filterDays($query, $start, $end);
        }
        if ($start) {
            $expenses->whereDate('expense_date', '>=', $start);
        } if ($end) {
            $expenses->whereDate('expense_date', '<=', $end);
        }
        $saleIds = (clone $sales)->select('sales.id');
        $purchaseIds = (clone $purchases)->select('purchases.id');
        // One aggregate per source instead of rescanning for each metric.
        $saleTotals = (clone $sales)->selectRaw('COUNT(*) as count, COALESCE(SUM(total),0) as total, COALESCE(SUM(paid_amount),0) as paid, COALESCE(SUM(credit_amount),0) as credit')->first();
        $purchaseTotals = (clone $purchases)->selectRaw('COUNT(*) as count, COALESCE(SUM(total_cost),0) as total, COALESCE(SUM(paid_amount),0) as paid, COALESCE(SUM(credit_amount),0) as credit')->first();
        $salesTotal = (int) $saleTotals->total;
        $cashTotal = (int) $saleTotals->paid;
        $creditTotal = (int) $saleTotals->credit;
        $purchaseTotal = (int) $purchaseTotals->total;
        $purchasePaidTotal = (int) $purchaseTotals->paid;
        $purchaseCreditTotal = (int) $purchaseTotals->credit;
        $expenseTotal = (int) (clone $expenses)->sum('amount');
        $foc = $this->table('sale_items')->whereIn('sale_id', $saleIds)->selectRaw('COALESCE(SUM(quantity * unit_cost),0) as product_cost, COALESCE(SUM(foc_quantity),0) as quantity, COALESCE(SUM(foc_quantity * unit_cost),0) as cost')->first();
        $productCost = (int) round($foc->product_cost);
        $purchaseFoc = $this->table('purchase_items')->whereIn('purchase_id', $purchaseIds)->selectRaw('COALESCE(SUM(base_foc_quantity),0) as quantity, COALESCE(SUM(base_foc_quantity * effective_unit_cost),0) as value')->first();
        $customerFlow = (clone $payments)->selectRaw("COALESCE(SUM(CASE WHEN direction='customer_to_shop' THEN amount ELSE 0 END),0) as received, COALESCE(SUM(CASE WHEN direction='shop_to_customer' THEN amount ELSE 0 END),0) as paid")->first();
        $supplierFlow = (clone $supplierPayments)->selectRaw("COALESCE(SUM(CASE WHEN direction='shop_to_supplier' THEN amount ELSE 0 END),0) as paid, COALESCE(SUM(CASE WHEN direction='supplier_to_shop' THEN amount ELSE 0 END),0) as received")->first();
        $debtCollected = (int) $customerFlow->received;
        $shopPayouts = (int) $customerFlow->paid;
        $supplierPaid = (int) $supplierFlow->paid;
        $supplierRefunds = (int) $supplierFlow->received;
        $accounts = $this->accounts();
        $supplierAccounts = $this->supplierAccounts();
        $accounts['customer_payable_total'] = $accounts['payable_total'];
        $accounts['supplier_payable_total'] = $supplierAccounts['payable_total'];
        $accounts['suppliers_owed'] = $supplierAccounts['suppliers_owed'];
        $accounts['supplier_credit_total'] = $supplierAccounts['credit_total'];
        $accounts['payable_total'] += $supplierAccounts['payable_total'];
        $accounts['open_accounts'] += $supplierAccounts['open_accounts'];
        $inventoryValuation = $this->inventoryValuation();
        $salesTrends = $includeTrends ? $this->salesTrends($end) : [];

        return ['start' => $start ?: ($this->table('sales')->min(DB::raw('DATE(created_at)')) ?: now()->toDateString()), 'end' => $end ?: now()->toDateString(), 'all_time' => $allTime,
            'sales_count' => (int) $saleTotals->count, 'sales_total' => $salesTotal, 'cash_total' => $cashTotal, 'credit_total' => $creditTotal,
            'purchase_count' => (int) $purchaseTotals->count, 'purchase_total' => $purchaseTotal, 'purchase_paid_total' => $purchasePaidTotal, 'purchase_credit_total' => $purchaseCreditTotal,
            'expense_total' => $expenseTotal, 'profit_estimate' => $salesTotal - $productCost - $expenseTotal,
            'foc_quantity' => (float) ($foc->quantity ?? 0), 'foc_cost' => (int) round($foc->cost ?? 0), 'purchase_foc_quantity' => (float) ($purchaseFoc->quantity ?? 0), 'purchase_foc_value' => (int) round($purchaseFoc->value ?? 0),
            'top_products' => $this->topProducts($saleIds), 'payment_methods' => $this->paymentMethods($saleIds), 'expense_categories' => $this->expenseCategories($expenses),
            'cash_movement' => ['sales_received' => $cashTotal, 'debt_collected' => $debtCollected, 'shop_payouts' => $shopPayouts,
                'purchase_payments' => $purchasePaidTotal, 'supplier_payments' => $supplierPaid, 'supplier_refunds' => $supplierRefunds, 'expenses' => $expenseTotal,
                'expected_cash_drawer' => $cashTotal + $debtCollected + $supplierRefunds - $shopPayouts - $purchasePaidTotal - $supplierPaid - $expenseTotal,
                'net_movement' => $cashTotal + $debtCollected + $supplierRefunds - $shopPayouts - $purchasePaidTotal - $supplierPaid - $expenseTotal],
            'debt_total' => $accounts['receivable_total'], 'customers_with_debt' => $accounts['customers_owing'],
            'current_accounts' => $accounts, 'inventory_valuation' => $inventoryValuation, 'sales_trends' => $salesTrends];
    }

    private function topProducts($saleIds): array
    {
        return $this->table('sale_items')->whereIn('sale_id', $saleIds)->select('product_name')->selectRaw('SUM(quantity) as quantity, SUM(foc_quantity) as foc_quantity, SUM(quantity+foc_quantity) as issued_quantity, SUM(line_total) as total')
            ->groupBy('product_name')->orderByDesc('total')->limit(10)->get()->map(fn ($r) => ['product_name' => $r->product_name, 'quantity' => (float) $r->quantity, 'foc_quantity' => (float) $r->foc_quantity, 'issued_quantity' => (float) $r->issued_quantity, 'total' => (int) $r->total])->all();
    }

    private function paymentMethods($saleIds): array
    {
        $configured = collect(explode(',', $this->setting('payment_methods', 'Cash,Wallet Pay,Banking Pay,KPay,Wave Pay,Credit')))->map('trim')->filter();
        $rows = $this->table('sales')->whereIn('id', $saleIds)->select('payment_method')->selectRaw('COUNT(*) as count, SUM(total) as total, SUM(paid_amount) as paid_total, SUM(credit_amount) as credit_total')->groupBy('payment_method')->get()->keyBy('payment_method');

        return $configured->merge($rows->keys())->unique()->map(function ($name) use ($rows) {
            $r = $rows->get($name);

            return ['payment_method' => $name, 'count' => (int) ($r->count ?? 0), 'total' => (int) ($r->total ?? 0), 'paid_total' => (int) ($r->paid_total ?? 0), 'credit_total' => (int) ($r->credit_total ?? 0)];
        })->values()->all();
    }

    private function expenseCategories($query): array
    {
        return (clone $query)->selectRaw("CASE WHEN TRIM(category)='' THEN 'Uncategorized' ELSE category END as category, COUNT(*) as count, SUM(amount) as total")
            ->groupByRaw("CASE WHEN TRIM(category)='' THEN 'Uncategorized' ELSE category END")->orderByDesc('total')->get()->map(fn ($r) => ['category' => $r->category, 'count' => (int) $r->count, 'total' => (int) $r->total])->all();
    }

    private function accounts(): array
    {
        $totals = app(BusinessAccounts::class)->totals(false);

        return ['as_of' => now(AppTime::businessTimezone())->toDateString(), 'receivable_total' => (int) $totals['positive_total'], 'payable_total' => (int) $totals['negative_total'],
            'customers_owing' => (int) $totals['owing_count'], 'open_accounts' => (int) $totals['open_count']];
    }

    private function supplierAccounts(): array
    {
        $totals = app(BusinessAccounts::class)->totals(true);

        return ['payable_total' => (int) $totals['positive_total'],
            'credit_total' => (int) $totals['negative_total'],
            'suppliers_owed' => (int) $totals['owing_count'],
            'open_accounts' => (int) $totals['open_count']];
    }

    private function inventoryValuation(): array
    {
        $row = $this->table('products')->where('is_active', true)->where('stock', '>', 0)
            ->selectRaw('COUNT(*) as product_count')
            ->selectRaw('COALESCE(SUM(stock), 0) as stock_units')
            ->selectRaw('COALESCE(SUM(stock * cost), 0) as investment_value')
            ->selectRaw('COALESCE(SUM(stock * price), 0) as potential_sales_value')
            ->first();
        $investmentValue = (int) round((float) ($row->investment_value ?? 0));
        $potentialSalesValue = (int) round((float) ($row->potential_sales_value ?? 0));
        $businessId = app(TenantContext::class)->requireId();
        $reservations = $this->table('ecommerce_stock_reservations as r')->join('ecommerce_orders as o', 'o.id', '=', 'r.order_id')
            ->where('r.business_id', $businessId)->where('o.business_id', $businessId)->where('r.status', 'active');
        $reserved = (float) (clone $reservations)->sum('r.quantity');
        $dispatched = (float) (clone $reservations)->whereIn('o.status', ['dispatched', 'return_pending'])->sum('r.quantity');

        return [
            'as_of' => now(AppTime::businessTimezone())->toDateString(),
            'product_count' => (int) ($row->product_count ?? 0),
            'stock_units' => (float) ($row->stock_units ?? 0),
            'reserved_units' => $reserved,
            'dispatched_units' => $dispatched,
            'available_units' => (float) ($row->stock_units ?? 0) - $reserved,
            'investment_value' => $investmentValue,
            'potential_sales_value' => $potentialSalesValue,
            'potential_gross_profit' => $potentialSalesValue - $investmentValue,
        ];
    }

    private function salesTrends(?string $anchorDate): array
    {
        $timezone = AppTime::businessTimezone();
        $anchor = Carbon::parse($anchorDate ?: now($timezone)->toDateString(), $timezone);
        $monthStart = $anchor->copy()->startOfMonth();
        $yearStart = $anchor->copy()->startOfYear();

        $dailyTotals = $this->periodTotals($monthStart, $anchor->daysInMonth, 'day');
        $monthlyTotals = $this->periodTotals($yearStart, 12, 'month');

        return [
            'month' => [
                'label' => $anchor->format('F Y'),
                'items' => collect(range(1, $anchor->daysInMonth))->map(fn ($day) => [
                    'label' => (string) $day,
                    'total' => (int) ($dailyTotals->get($day) ?? 0),
                ])->all(),
            ],
            'year' => [
                'label' => (string) $anchor->year,
                'items' => collect(range(1, 12))->map(fn ($month) => [
                    'label' => Carbon::create(null, $month, 1)->format('M'),
                    'total' => (int) ($monthlyTotals->get($month) ?? 0),
                ])->all(),
            ],
        ];
    }

    /** Boundaries are computed in PHP so SQL needs no installed timezone tables.
     * Half-open local periods preserve DST and midnight boundaries exactly.
     * Return one aggregate row rather than every sale in the year.
     */
    private function periodTotals(Carbon $start, int $count, string $unit): \Illuminate\Support\Collection
    {
        $query = $this->table('sales')->where('status', 'completed');
        $cursor = $start->copy();
        for ($index = 1; $index <= $count; $index++) {
            $next = $unit === 'day' ? $cursor->copy()->addDay() : $cursor->copy()->addMonth();
            $query->selectRaw("COALESCE(SUM(CASE WHEN created_at >= ? AND created_at < ? THEN total ELSE 0 END),0) as period_{$index}", [AppTime::storage($cursor), AppTime::storage($next)]);
            $cursor = $next;
        }
        $row = $query->where('created_at', '>=', AppTime::storage($start))
            ->where('created_at', '<', AppTime::storage($cursor))->first();

        return collect(range(1, $count))->mapWithKeys(fn ($index) => [$index => (int) $row->{'period_'.$index}]);
    }

    private function table(string $table): Builder
    {
        $qualifier = str_contains($table, ' as ') ? explode(' as ', $table)[1] : $table;

        return DB::table($table)->where($qualifier.'.business_id', app(TenantContext::class)->requireId());
    }

    private function setting(string $key, string $default = ''): string
    {
        return (string) ($this->table('settings')->where('key', $key)->value('value') ?? $default);
    }
}
