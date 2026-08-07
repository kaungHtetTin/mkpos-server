<?php

namespace App\Http\Controllers\Api;

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
        $purchases = DB::table('purchases')->where('status', 'completed');
        foreach ([$sales, $payments, $purchases] as $query) {
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
        $expenseTotal = (int) (clone $expenses)->sum('amount');
        $productCost = (int) round(DB::table('sale_items')->whereIn('sale_id', $saleIds)->selectRaw('COALESCE(SUM(quantity * unit_cost),0) as total')->value('total') ?: 0);
        $foc = DB::table('sale_items')->whereIn('sale_id', $saleIds)->selectRaw('COALESCE(SUM(foc_quantity),0) as quantity, COALESCE(SUM(foc_quantity * unit_cost),0) as cost')->first();
        $purchaseFoc = DB::table('purchase_items')->whereIn('purchase_id', $purchaseIds)->selectRaw('COALESCE(SUM(base_foc_quantity),0) as quantity, COALESCE(SUM(base_foc_quantity * effective_unit_cost),0) as value')->first();
        $debtCollected = (int) (clone $payments)->where('direction', 'customer_to_shop')->sum('amount');
        $shopPayouts = (int) (clone $payments)->where('direction', 'shop_to_customer')->sum('amount');
        $accounts = $this->accounts();

        return ['start' => $start ?: (DB::table('sales')->min(DB::raw('DATE(created_at)')) ?: now()->toDateString()), 'end' => $end ?: now()->toDateString(), 'all_time' => $allTime,
            'sales_count' => (clone $sales)->count(), 'sales_total' => $salesTotal, 'cash_total' => $cashTotal, 'credit_total' => $creditTotal,
            'expense_total' => $expenseTotal, 'profit_estimate' => $salesTotal - $productCost - $expenseTotal,
            'foc_quantity' => (float) ($foc->quantity ?? 0), 'foc_cost' => (int) round($foc->cost ?? 0), 'purchase_foc_quantity' => (float) ($purchaseFoc->quantity ?? 0), 'purchase_foc_value' => (int) round($purchaseFoc->value ?? 0),
            'top_products' => $this->topProducts($saleIds), 'payment_methods' => $this->paymentMethods($saleIds), 'expense_categories' => $this->expenseCategories($expenses),
            'cash_movement' => ['sales_received' => $cashTotal, 'debt_collected' => $debtCollected, 'shop_payouts' => $shopPayouts, 'expenses' => $expenseTotal,
                'expected_cash_drawer' => $cashTotal + $debtCollected - $shopPayouts - $expenseTotal, 'net_movement' => $cashTotal + $debtCollected - $shopPayouts - $expenseTotal],
            'debt_total' => $accounts['receivable_total'], 'customers_with_debt' => $accounts['customers_owing'], 'current_accounts' => $accounts];
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
            foreach ([['Receipts', 'sales_count'], ['Sales Total', 'sales_total'], ['Paid During Sales', 'cash_total'], ['Credit Sales', 'credit_total'], ['Expenses', 'expense_total'], ['Profit Estimate', 'profit_estimate']] as [$label,$key]) {
                fputcsv($out, [$label, $summary[$key]]);
            }
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
}
