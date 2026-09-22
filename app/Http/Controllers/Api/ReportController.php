<?php

namespace App\Http\Controllers\Api;

use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportController extends ApiController
{
    public function summary(Request $request): array
    {
        $allTime = $request->boolean('all_time');
        $start = $allTime ? null : ($request->query('start') ?: now()->startOfMonth()->toDateString());
        $end = $allTime ? null : ($request->query('end') ?: now()->toDateString());
        $sales = DB::table('sales')->where('status', 'completed');
        $expenses = DB::table('expenses')->where('status', 'completed');
        $payments = DB::table('customer_payments')->where('status', 'completed');
        $supplierPayments = DB::table('supplier_payments')->where('status', 'completed');
        $purchases = DB::table('purchases')->where('status', 'completed');
        foreach ([$sales, $payments, $supplierPayments, $purchases] as $query) {
            if ($start) {
                $query->whereDate('created_at', '>=', $start);
            } if ($end) {
                $query->whereDate('created_at', '<=', $end);
            }
        }
        if ($start) {
            $expenses->whereDate('expense_date', '>=', $start);
        } if ($end) {
            $expenses->whereDate('expense_date', '<=', $end);
        }
        $saleIds = (clone $sales)->pluck('id');
        $purchaseIds = (clone $purchases)->pluck('id');
        $salesTotal = (int) (clone $sales)->sum('total');
        $cashTotal = (int) (clone $sales)->sum('paid_amount');
        $creditTotal = (int) (clone $sales)->sum('credit_amount');
        $purchaseTotal = (int) (clone $purchases)->sum('total_cost');
        $purchasePaidTotal = (int) (clone $purchases)->sum('paid_amount');
        $purchaseCreditTotal = (int) (clone $purchases)->sum('credit_amount');
        $expenseTotal = (int) (clone $expenses)->sum('amount');
        $productCost = (int) round(DB::table('sale_items')->whereIn('sale_id', $saleIds)->selectRaw('COALESCE(SUM(quantity * unit_cost),0) as total')->value('total') ?: 0);
        $foc = DB::table('sale_items')->whereIn('sale_id', $saleIds)->selectRaw('COALESCE(SUM(foc_quantity),0) as quantity, COALESCE(SUM(foc_quantity * unit_cost),0) as cost')->first();
        $purchaseFoc = DB::table('purchase_items')->whereIn('purchase_id', $purchaseIds)->selectRaw('COALESCE(SUM(base_foc_quantity),0) as quantity, COALESCE(SUM(base_foc_quantity * effective_unit_cost),0) as value')->first();
        $debtCollected = (int) (clone $payments)->where('direction', 'customer_to_shop')->sum('amount');
        $shopPayouts = (int) (clone $payments)->where('direction', 'shop_to_customer')->sum('amount');
        $supplierPaid = (int) (clone $supplierPayments)->where('direction', 'shop_to_supplier')->sum('amount');
        $supplierRefunds = (int) (clone $supplierPayments)->where('direction', 'supplier_to_shop')->sum('amount');
        $accounts = $this->accounts();
        $supplierAccounts = $this->supplierAccounts();
        $accounts['customer_payable_total'] = $accounts['payable_total'];
        $accounts['supplier_payable_total'] = $supplierAccounts['payable_total'];
        $accounts['suppliers_owed'] = $supplierAccounts['suppliers_owed'];
        $accounts['supplier_credit_total'] = $supplierAccounts['credit_total'];
        $accounts['payable_total'] += $supplierAccounts['payable_total'];
        $accounts['open_accounts'] += $supplierAccounts['open_accounts'];
        $inventoryValuation = $this->inventoryValuation();
        $salesTrends = $this->salesTrends($end);

        return ['start' => $start ?: (DB::table('sales')->min(DB::raw('DATE(created_at)')) ?: now()->toDateString()), 'end' => $end ?: now()->toDateString(), 'all_time' => $allTime,
            'sales_count' => (clone $sales)->count(), 'sales_total' => $salesTotal, 'cash_total' => $cashTotal, 'credit_total' => $creditTotal,
            'purchase_count' => (clone $purchases)->count(), 'purchase_total' => $purchaseTotal, 'purchase_paid_total' => $purchasePaidTotal, 'purchase_credit_total' => $purchaseCreditTotal,
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

    public function today(Request $request): array
    {
        $day = $request->query('day', now()->toDateString());
        $request->query->set('start', $day);
        $request->query->set('end', $day);

        return $this->summary($request);
    }

    public function csv(Request $request): StreamedResponse
    {
        $summary = $this->summary($request);
        $filename = 'mkpos-report-'.$summary['start'].'-to-'.$summary['end'].'.csv';

        return response()->streamDownload(function () use ($summary) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['MKPOS Report', $summary['start'], $summary['end']]);
            foreach ([['Receipts', 'sales_count'], ['Sales Total', 'sales_total'], ['Paid During Sales', 'cash_total'], ['Credit Sales', 'credit_total'], ['Purchases', 'purchase_total'], ['Paid During Purchases', 'purchase_paid_total'], ['Credit Purchases', 'purchase_credit_total'], ['Expenses', 'expense_total'], ['Profit Estimate', 'profit_estimate']] as [$label,$key]) {
                fputcsv($out, [$label, $summary[$key]]);
            }
            fputcsv($out, []);
            fputcsv($out, ['Current Inventory', $summary['inventory_valuation']['as_of']]);
            fputcsv($out, ['Active Products in Stock', $summary['inventory_valuation']['product_count']]);
            fputcsv($out, ['Stock Units', $summary['inventory_valuation']['stock_units']]);
            fputcsv($out, ['Current Stock Investment', $summary['inventory_valuation']['investment_value']]);
            fputcsv($out, ['Potential Sales Value', $summary['inventory_valuation']['potential_sales_value']]);
            fputcsv($out, []);
            fputcsv($out, ['Top Products']);
            fputcsv($out, ['Product', 'Paid Quantity', 'FOC Quantity', 'Total']);
            foreach ($summary['top_products'] as $row) {
                fputcsv($out, [$row['product_name'], $row['quantity'], $row['foc_quantity'], $row['total']]);
            } fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function topProducts($saleIds): array
    {
        return DB::table('sale_items')->whereIn('sale_id', $saleIds)->select('product_name')->selectRaw('SUM(quantity) as quantity, SUM(foc_quantity) as foc_quantity, SUM(quantity+foc_quantity) as issued_quantity, SUM(line_total) as total')
            ->groupBy('product_name')->orderByDesc('total')->limit(10)->get()->map(fn ($r) => ['product_name' => $r->product_name, 'quantity' => (float) $r->quantity, 'foc_quantity' => (float) $r->foc_quantity, 'issued_quantity' => (float) $r->issued_quantity, 'total' => (int) $r->total])->all();
    }

    private function paymentMethods($saleIds): array
    {
        $configured = collect(explode(',', $this->setting('payment_methods', 'Cash,Wallet Pay,Banking Pay,KPay,Wave Pay,Credit')))->map('trim')->filter();
        $rows = DB::table('sales')->whereIn('id', $saleIds)->select('payment_method')->selectRaw('COUNT(*) as count, SUM(total) as total, SUM(paid_amount) as paid_total, SUM(credit_amount) as credit_total')->groupBy('payment_method')->get()->keyBy('payment_method');

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
        $rows = DB::table('customers')->where('is_active', true)->get()->map(function ($customer) {
        $credit = DB::table('sales')->where('customer_id', $customer->id)->where('status', 'completed')->sum('credit_amount');
        $paid = DB::table('customer_payments')->where('customer_id', $customer->id)->where('status', 'completed')->selectRaw("COALESCE(SUM(CASE WHEN direction='customer_to_shop' THEN amount ELSE -amount END),0) as total")->value('total');

        return (int) $credit - (int) $paid;
        });

        return ['as_of' => now()->toDateString(), 'receivable_total' => (int) $rows->sum(fn ($b) => max($b, 0)), 'payable_total' => (int) $rows->sum(fn ($b) => max(-$b, 0)),
            'customers_owing' => $rows->filter(fn ($b) => $b > 0)->count(), 'open_accounts' => $rows->filter(fn ($b) => $b != 0)->count()];
    }

    private function supplierAccounts(): array
    {
        $rows = DB::table('suppliers')->where('is_active', true)->get()->map(function ($supplier) {
            $credit = (int) DB::table('purchases')->where('supplier_id', $supplier->id)->where('status', 'completed')->sum('credit_amount');
            $settled = (int) DB::table('supplier_payments')->where('supplier_id', $supplier->id)->where('status', 'completed')
                ->selectRaw("COALESCE(SUM(CASE WHEN direction='shop_to_supplier' THEN amount ELSE -amount END),0) as total")->value('total');

            return $credit - $settled;
        });

        return ['payable_total' => (int) $rows->sum(fn ($balance) => max($balance, 0)),
            'credit_total' => (int) $rows->sum(fn ($balance) => max(-$balance, 0)),
            'suppliers_owed' => $rows->filter(fn ($balance) => $balance > 0)->count(),
            'open_accounts' => $rows->filter(fn ($balance) => $balance != 0)->count()];
    }

    private function inventoryValuation(): array
    {
        $row = DB::table('products')->where('is_active', true)->where('stock', '>', 0)
            ->selectRaw('COUNT(*) as product_count')
            ->selectRaw('COALESCE(SUM(stock), 0) as stock_units')
            ->selectRaw('COALESCE(SUM(stock * cost), 0) as investment_value')
            ->selectRaw('COALESCE(SUM(stock * price), 0) as potential_sales_value')
            ->first();
        $investmentValue = (int) round((float) ($row->investment_value ?? 0));
        $potentialSalesValue = (int) round((float) ($row->potential_sales_value ?? 0));

        return [
            'as_of' => now()->toDateString(),
            'product_count' => (int) ($row->product_count ?? 0),
            'stock_units' => (float) ($row->stock_units ?? 0),
            'investment_value' => $investmentValue,
            'potential_sales_value' => $potentialSalesValue,
            'potential_gross_profit' => $potentialSalesValue - $investmentValue,
        ];
    }

    private function salesTrends(?string $anchorDate): array
    {
        $anchor = Carbon::parse($anchorDate ?: now()->toDateString());
        $monthStart = $anchor->copy()->startOfMonth();
        $monthEnd = $anchor->copy()->endOfMonth();
        $yearStart = $anchor->copy()->startOfYear();
        $yearEnd = $anchor->copy()->endOfYear();

        $dailyTotals = DB::table('sales')->where('status', 'completed')
            ->whereBetween('created_at', [$monthStart, $monthEnd])
            ->get(['created_at', 'total'])
            ->groupBy(fn ($sale) => Carbon::parse($sale->created_at)->day)
            ->map(fn ($sales) => (int) $sales->sum('total'));

        $monthlyTotals = DB::table('sales')->where('status', 'completed')
            ->whereBetween('created_at', [$yearStart, $yearEnd])
            ->get(['created_at', 'total'])
            ->groupBy(fn ($sale) => Carbon::parse($sale->created_at)->month)
            ->map(fn ($sales) => (int) $sales->sum('total'));

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
}
