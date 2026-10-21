<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SaleController extends ApiController
{
    public function index(Request $request)
    {
        $query = DB::table('sales')->leftJoin('customers', 'customers.id', '=', 'sales.customer_id')->select('sales.*', 'customers.name as customer_name')
            ->selectRaw('(SELECT COUNT(*) FROM sale_items WHERE sale_items.sale_id = sales.id) as item_count');
        \App\Support\AppTime::filterDays($query, $request->query('start'), $request->query('end'), 'sales.created_at');
        if (($status = $request->query('status', 'all')) !== 'all') {
            $query->where('sales.status', $status);
        }
        $saleChannel = $request->validate(['sale_channel' => ['sometimes', 'in:all,online,pos']])['sale_channel'] ?? 'all';
        if ($saleChannel === 'online') {
            $query->where('sales.source', 'ecommerce');
        } elseif ($saleChannel === 'pos') {
            $query->where(fn ($q) => $q->whereNull('sales.source')->orWhere('sales.source', '!=', 'ecommerce'));
        }
        if (($type = $request->query('payment_type', 'all')) !== 'all') {
            $query->where('sales.payment_type', $type);
        }
        if ($request->query('payment_method')) {
            $query->where('sales.payment_method', 'like', '%'.$request->query('payment_method').'%');
        }
        if ($request->query('customer')) {
            $query->where('customers.name', 'like', '%'.$request->query('customer').'%');
        }
        if ($search = trim((string) $request->query('q', ''))) {
            $like = "%{$search}%";
            $query->where(fn ($q) => $q->where('sales.receipt_no', 'like', $like)->orWhere('customers.name', 'like', $like)->orWhere('sales.payment_method', 'like', $like)->orWhere('sales.payment_type', 'like', $like)->orWhere('sales.id', 'like', $like));
        }

        return $this->page($query->orderByDesc('sales.created_at')->orderByDesc('sales.id'), $request, 25, 100);
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);

        return DB::transaction(fn () => $this->save(null, $data));
    }

    public function offlineSync(Request $request): array
    {
        $data = $this->validated($request);
        $offline = $request->validate([
            'offline_sale_uuid' => ['required', 'uuid'],
            'offline_created_at' => ['required', 'date'],
        ]);
        $offline['offline_created_at'] = Carbon::parse($offline['offline_created_at'])->utc()->toDateTimeString();

        return DB::transaction(function () use ($data, $offline) {
            $existingId = DB::table('sales')->where('offline_sale_uuid', $offline['offline_sale_uuid'])->value('id');
            if ($existingId) {
                return $this->show((int) $existingId) + ['already_synced' => true];
            }

            return $this->save(null, $data, null, $offline);
        });
    }

    public function update(Request $request, int $id)
    {
        $data = $this->validated($request);
        $this->requireAdminPin($data['admin_pin'] ?? '');

        return DB::transaction(function () use ($data, $id) {
            $sale = DB::table('sales')->where('id', $id)->lockForUpdate()->first();
            abort_if(! $sale, 404, 'Sale not found');
            abort_if($sale->status === 'voided', 400, 'Voided sale cannot be edited');
            abort_if($sale->source === 'ecommerce', 409, 'Online order sales cannot be edited. Use the controlled void workflow.');
            $existingItems = DB::table('sale_items')->where('sale_id', $id)->get();
            $productIdMap = $this->saleEditProductIdMap($existingItems, $data['items']);
            foreach ($data['items'] as &$item) {
                $item['product_id'] = $productIdMap[(int) $item['product_id']] ?? (int) $item['product_id'];
            }
            unset($item);
            $editableArchivedProductIds = $existingItems->pluck('product_id')->filter()->map(fn ($productId) => (int) $productId)->unique()->values()->all();
            foreach ($existingItems as $item) {
                $productId = $productIdMap[(int) $item->product_id] ?? (int) $item->product_id;
                $productName = DB::table('products')->where('id', $productId)->value('name') ?: $item->product_name;
                DB::table('products')->where('id', $productId)->increment('stock', $item->quantity + $item->foc_quantity, ['updated_at' => now()]);
                $this->insertMovement($productId, $productName, 'sale_edit_restore', (float) ($item->quantity + $item->foc_quantity), 'sale', $id, $sale->receipt_no);
            }
            DB::table('sale_items')->where('sale_id', $id)->delete();

            return $this->save($id, $data, $sale->receipt_no, null, $editableArchivedProductIds);
        });
    }

    /**
     * Resolve archived products to one unambiguous active replacement before a
     * historical sale is recalculated. This keeps edits attached to the stock
     * record shown in inventory after a product was archived and recreated.
     */
    private function saleEditProductIdMap($existingItems, array $submittedItems): array
    {
        $productIds = $existingItems->pluck('product_id')
            ->merge(collect($submittedItems)->pluck('product_id'))
            ->filter()->map(fn ($productId) => (int) $productId)->unique()->values();
        $products = DB::table('products')->whereIn('id', $productIds)->get()->keyBy('id');
        $map = [];

        foreach ($productIds as $productId) {
            $product = $products[$productId] ?? null;
            if (! $product || $product->is_active) {
                $map[$productId] = $productId;

                continue;
            }

            $replacement = $this->activeReplacementForArchivedProduct($product);
            $map[$productId] = $replacement?->id ?? $productId;
        }

        return $map;
    }

    private function activeReplacementForArchivedProduct(object $product): ?object
    {
        $uniqueCandidate = function ($query): ?object {
            $candidates = $query->limit(2)->lockForUpdate()->get();

            return $candidates->count() === 1 ? $candidates->first() : null;
        };

        $barcode = trim((string) $product->barcode);
        if ($barcode !== '') {
            $replacement = $uniqueCandidate(DB::table('products')->where('is_active', true)->where('barcode', $barcode));
            if ($replacement) {
                return $replacement;
            }
        }

        $sku = trim((string) $product->sku);
        if ($sku !== '') {
            $replacement = $uniqueCandidate(DB::table('products')->where('is_active', true)->where('sku', $sku));
            if ($replacement) {
                return $replacement;
            }
        }

        $query = DB::table('products')
            ->where('is_active', true)
            ->where('name', $product->name)
            ->where('category', $product->category)
            ->where('base_unit', $product->base_unit)
            ->where('purchase_conversion_factor', $product->purchase_conversion_factor)
            ->where('price', $product->price)
            ->where('cost', $product->cost);
        if ($product->purchase_unit === null) {
            $query->whereNull('purchase_unit');
        } else {
            $query->where('purchase_unit', $product->purchase_unit);
        }

        return $uniqueCandidate($query);
    }

    public function show(int $id): array
    {
        $sale = DB::table('sales')->leftJoin('customers', 'customers.id', '=', 'sales.customer_id')->where('sales.id', $id)->select('sales.*', 'customers.name as customer_name', 'customers.phone as customer_phone')->first();
        abort_if(! $sale, 404, 'Sale not found');
        $result = (array) $sale;
        $result['items'] = DB::table('sale_items as si')
            ->leftJoin('products as p', 'p.id', '=', 'si.product_id')
            ->where('si.sale_id', $id)
            ->orderBy('si.id')
            ->select('si.*', 'p.sku', 'p.photo_path', 'p.updated_at as product_updated_at', DB::raw("COALESCE(p.base_unit, 'Unit') as unit_name"))
            ->get()->map(function ($row) {
            $item = (array) $row;
            $item['quantity'] = (float) $item['quantity'];
            $item['foc_quantity'] = (float) $item['foc_quantity'];
            $item['line_type'] = 'product';
            $item['photo_url'] = $item['photo_path'] ? '/products/'.$item['product_id'].'/photo?v='.strtotime((string) $item['product_updated_at']) : null;
            unset($item['photo_path'], $item['product_updated_at']);

            return $item;
        })->all();

        return $result;
    }

    public function lastReceipt(): array
    {
        $id = DB::table('sales')->orderByDesc('created_at')->orderByDesc('id')->value('id');
        abort_if(! $id, 404, 'No receipt found');

        return $this->receipt((int) $id);
    }

    public function receipt(int $id): array
    {
        $sale = $this->show($id);
        $settings = \App\Support\BusinessLogo::addToSettings(array_merge([
            'shop_name' => 'MKPOS Shop', 'shop_title' => '', 'shop_address' => '', 'shop_phone' => '', 'currency' => 'Ks',
            'receipt_footer' => '', 'receipt_show_customer' => '1', 'receipt_show_payment_method' => '1', 'receipt_show_price_type' => '1',
            'receipt_paper_size' => '80mm', 'receipt_header_alignment' => 'center', 'receipt_margin_left' => '3', 'receipt_margin_right' => '3',
            'receipt_header_font_size' => '11', 'receipt_body_font_size' => '8.2', 'receipt_line_height' => '1.45',
        ], DB::table('settings')->pluck('value', 'key')->all()));
        $printSale = $sale;
        $printSale['created_at'] = \App\Support\AppTime::storage($sale['created_at'])->setTimezone(\App\Support\AppTime::businessTimezone())->toDateTimeString();
        $receipt = \App\Support\ReceiptRenderer::render($printSale, $settings);
        $receipt['sale'] = $sale;
        return $receipt;
    }

    public function print(int $id): array
    {
        return $this->receipt($id) + ['ok' => false, 'message' => 'Laravel web mode uses the browser print dialog.'];
    }

    public function destroy(Request $request, int $id)
    {
        $this->requireAdminPin($request->input('admin_pin'));

        return DB::transaction(function () use ($id) {
            $sale = DB::table('sales')->where('id', $id)->lockForUpdate()->first();
            abort_if(! $sale, 404, 'Sale not found');
            foreach (DB::table('sale_items')->where('sale_id', $id)->get() as $item) {
                DB::table('products')->where('id', $item->product_id)->increment('stock', $item->quantity + $item->foc_quantity, ['updated_at' => now()]);
                $this->insertMovement($item->product_id, $item->product_name, 'sale_delete', (float) ($item->quantity + $item->foc_quantity), 'sale', $id, $sale->receipt_no);
            }
            DB::table('ecommerce_orders')->where('business_id', app(\App\Tenancy\TenantContext::class)->requireId())->where('sale_id', $id)
                ->update(['sale_voided' => true, 'payment_status' => 'refund_pending', 'updated_at' => now()]);
            $onlineOrder = DB::table('ecommerce_orders')->where('business_id', app(\App\Tenancy\TenantContext::class)->requireId())->where('sale_id', $id)->first();
            if ($onlineOrder) app(\App\Services\EcommerceService::class)->event($onlineOrder->business_id, $onlineOrder->id, 'sale_voided', request()->user('web')->id);
            DB::table('sale_items')->where('sale_id', $id)->delete();
            DB::table('sales')->where('id', $id)->delete();

            return ['id' => $id, 'receipt_no' => $sale->receipt_no, 'deleted' => true];
        });
    }

    private function validated(Request $request): array
    {
        $data = $request->validate(['items' => ['required', 'array', 'min:1'], 'items.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'items.*.product_name' => ['nullable', 'string', 'max:255'], 'items.*.price_type' => ['nullable', 'string', 'max:50'], 'items.*.quantity' => ['required', 'numeric', 'min:0'], 'items.*.foc_quantity' => ['nullable', 'numeric', 'min:0'],
            'items.*.unit_price' => ['required', 'integer', 'min:0'], 'discount' => ['nullable', 'integer', 'min:0'], 'payment_type' => ['nullable', 'in:cash,credit'],
            'payment_method' => ['nullable', 'string'], 'paid_amount' => ['nullable', 'integer', 'min:0'], 'customer_id' => ['nullable', 'integer', 'exists:customers,id'], 'admin_pin' => ['nullable', 'string']]);

        foreach ($data['items'] as $index => $item) {
            if ((float) $item['quantity'] + (float) ($item['foc_quantity'] ?? 0) <= 0) {
                throw ValidationException::withMessages(["items.{$index}.quantity" => ['Paid quantity or FOC quantity must be greater than zero.']]);
            }
        }

        return $data;
    }

    private function save(?int $id, array $data, ?string $receiptNo = null, ?array $offline = null, array $editableArchivedProductIds = []): array
    {
        $id = app(\App\Services\SalePostingService::class)->save($id, $data, $receiptNo, $offline, $editableArchivedProductIds);
        return $this->show($id);
    }
}
