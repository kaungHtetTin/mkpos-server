<?php

namespace App\Services;

use App\Support\AppTime;
use App\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ProductSalesReport
{
    public function index(Request $request): array
    {
        $sorts = ['amount_desc' => ['total', 'desc'], 'sales_desc' => ['sale_count', 'desc'], 'quantity_desc' => ['quantity', 'desc'], 'name_asc' => ['product_name', 'asc']];
        $data = $request->validate([
            'start' => ['nullable', 'date_format:Y-m-d'], 'end' => ['nullable', 'date_format:Y-m-d'],
            'all_time' => ['sometimes', function ($attribute, $value, $fail) {
                if (! in_array($value, [true, false, 0, 1, '0', '1', 'true', 'false'], true)) $fail('Choose a valid date range.');
            }], 'q' => ['nullable', 'string', 'max:150'],
            'category' => ['nullable', 'string', 'max:255'], 'sort' => ['sometimes', Rule::in(array_keys($sorts))],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:100'], 'offset' => ['sometimes', 'integer', 'min:0', 'max:1000000'],
        ]);
        $business = app(TenantContext::class)->requireId();
        $today = now(AppTime::businessTimezone());
        $allTime = $request->boolean('all_time');
        $start = $allTime ? null : ($data['start'] ?? $today->toDateString());
        $end = $allTime ? null : ($data['end'] ?? $today->toDateString());
        $sales = DB::table('sales')->where('business_id', $business)->where('status', 'completed');
        AppTime::filterDays($sales, $start, $end, 'sales.created_at');
        $lines = DB::table('sale_items as items')
            ->joinSub($sales->select('id'), 'eligible_sales', 'eligible_sales.id', '=', 'items.sale_id')
            ->leftJoin('products as p', fn ($join) => $join->on('p.id', '=', 'items.product_id')->where('p.business_id', $business))
            ->where('items.business_id', $business);
        $categories = (clone $lines)->whereNotNull('p.category')->where('p.category', '<>', '')->distinct()->orderBy('p.category')->pluck('p.category')->all();
        if ($q = trim($data['q'] ?? '')) {
            $lines->where(fn ($query) => $query->whereRaw('COALESCE(p.name, items.product_name) like ?', ['%'.$q.'%'])->orWhere('p.sku', 'like', '%'.$q.'%')->orWhere('p.barcode', 'like', '%'.$q.'%'));
        }
        if ($category = $data['category'] ?? '') {
            if ($category === '__uncategorized__') $lines->where(fn ($query) => $query->whereNull('p.category')->orWhere('p.category', ''));
            else $lines->where('p.category', $category);
        }
        $grouped = $lines->select('items.product_id')
            ->selectRaw("MAX(COALESCE(p.name, items.product_name)) as product_name, MAX(COALESCE(p.sku, '')) as sku, MAX(COALESCE(p.category, '')) as category")
            ->selectRaw('COUNT(DISTINCT items.sale_id) as sale_count, SUM(items.quantity) as quantity, SUM(items.foc_quantity) as foc_quantity, SUM(items.line_total) as total')
            ->groupBy('items.product_id');
        $summary = DB::query()->fromSub(clone $grouped, 'product_sales')
            ->selectRaw('COUNT(*) as product_count, COALESCE(SUM(quantity),0) as quantity, COALESCE(SUM(foc_quantity),0) as foc_quantity, COALESCE(SUM(total),0) as total')->first();
        [$column, $direction] = $sorts[$data['sort'] ?? 'amount_desc'];
        $limit = (int) ($data['limit'] ?? 25);
        $offset = (int) ($data['offset'] ?? 0);
        $items = $grouped->orderBy($column, $direction)->orderBy('items.product_id')->offset($offset)->limit($limit)->get()->map(fn ($row) => [
            'id' => (int) $row->product_id, 'product_id' => (int) $row->product_id, 'product_name' => $row->product_name,
            'sku' => $row->sku, 'category' => $row->category, 'sale_count' => (int) $row->sale_count,
            'quantity' => (float) $row->quantity, 'foc_quantity' => (float) $row->foc_quantity, 'total' => (int) $row->total,
        ])->all();
        return ['items' => $items, 'total' => (int) $summary->product_count, 'limit' => $limit, 'offset' => $offset,
            'has_more' => $offset + count($items) < $summary->product_count,
            'start' => $start, 'end' => $end, 'all_time' => $allTime, 'categories' => $categories,
            'summary' => ['product_count' => (int) $summary->product_count, 'quantity' => (float) $summary->quantity, 'foc_quantity' => (float) $summary->foc_quantity, 'total' => (int) $summary->total]];
    }
}
