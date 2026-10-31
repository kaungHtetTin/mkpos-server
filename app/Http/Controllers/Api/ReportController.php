<?php

namespace App\Http\Controllers\Api;

use App\Services\BusinessReport;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportController extends ApiController
{
    public function products(Request $request): array
    {
        return app(\App\Services\ProductSalesReport::class)->index($request);
    }

    public function summary(Request $request): array
    {
        return app(BusinessReport::class)->summary($request);
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
}
