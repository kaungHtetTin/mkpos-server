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
        $query = DB::table('sales')->leftJoin('customers', 'customers.id', '=', 'sales.customer_id')->select('sales.*', 'customers.name as customer_name');
        if ($request->query('start')) {
            $query->whereDate('sales.created_at', '>=', $request->query('start'));
        }
        if ($request->query('end')) {
            $query->whereDate('sales.created_at', '<=', $request->query('end'));
        }
        if (($status = $request->query('status', 'all')) !== 'all') {
            $query->where('sales.status', $status);
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
            foreach (DB::table('sale_items')->where('sale_id', $id)->get() as $item) {
                DB::table('products')->where('id', $item->product_id)->increment('stock', $item->quantity + $item->foc_quantity, ['updated_at' => now()]);
                $this->insertMovement($item->product_id, $item->product_name, 'sale_edit_restore', (float) ($item->quantity + $item->foc_quantity), 'sale', $id, $sale->receipt_no);
            }
            DB::table('sale_items')->where('sale_id', $id)->delete();

            return $this->save($id, $data, $sale->receipt_no);
        });
    }

    public function show(int $id): array
    {
        $sale = DB::table('sales')->leftJoin('customers', 'customers.id', '=', 'sales.customer_id')->where('sales.id', $id)->select('sales.*', 'customers.name as customer_name')->first();
        abort_if(! $sale, 404, 'Sale not found');
        $result = (array) $sale;
        $result['items'] = DB::table('sale_items')->where('sale_id', $id)->orderBy('id')->get()->map(function ($row) {
            $item = (array) $row;
            $item['quantity'] = (float) $item['quantity'];
            $item['foc_quantity'] = (float) $item['foc_quantity'];
            $item['line_type'] = 'product';

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
        $settings = array_merge(['shop_name' => 'MKPOS Shop', 'currency' => 'Ks', 'receipt_paper_size' => '80mm'], DB::table('settings')->pluck('value', 'key')->all());
        $lines = [$settings['shop_name'], 'Receipt: '.$sale['receipt_no']];
        $rows = '';
        foreach ($sale['items'] as $item) {
            $lines[] = $item['product_name'].' x '.$item['quantity'].'  '.$item['line_total'];
            $rows .= '<tr><td>'.htmlspecialchars($item['product_name']).' x '.$item['quantity'].'</td><td style="text-align:right">'.number_format($item['line_total']).'</td></tr>';
        }
        $lines[] = 'Total: '.number_format($sale['total']).' '.$settings['currency'];
        $html = '<div class="receipt"><h2>'.htmlspecialchars($settings['shop_name']).'</h2><p>'.htmlspecialchars($sale['receipt_no']).'</p><table style="width:100%">'.$rows.'</table><hr><strong>Total: '.number_format($sale['total']).' '.htmlspecialchars($settings['currency']).'</strong></div>';

        return ['sale' => $sale, 'text' => implode("\n", $lines), 'html' => $html, 'paper_size' => $settings['receipt_paper_size'], 'layout' => ['paper_size' => $settings['receipt_paper_size']]];
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
            DB::table('sale_items')->where('sale_id', $id)->delete();
            DB::table('sales')->where('id', $id)->delete();

            return ['id' => $id, 'receipt_no' => $sale->receipt_no, 'deleted' => true];
        });
    }

    private function validated(Request $request): array
    {
        return $request->validate(['items' => ['required', 'array', 'min:1'], 'items.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'items.*.product_name' => ['nullable', 'string', 'max:255'], 'items.*.price_type' => ['nullable', 'string', 'max:50'], 'items.*.quantity' => ['required', 'numeric', 'gt:0'], 'items.*.foc_quantity' => ['nullable', 'numeric', 'min:0'],
            'items.*.unit_price' => ['required', 'integer', 'min:0'], 'discount' => ['nullable', 'integer', 'min:0'], 'payment_type' => ['nullable', 'in:cash,credit'],
            'payment_method' => ['nullable', 'string'], 'paid_amount' => ['nullable', 'integer', 'min:0'], 'customer_id' => ['nullable', 'integer', 'exists:customers,id'], 'admin_pin' => ['nullable', 'string']]);
    }

    private function save(?int $id, array $data, ?string $receiptNo = null, ?array $offline = null): array
    {
        $products = DB::table('products')->whereIn('id', collect($data['items'])->pluck('product_id'))->where('is_active', true)->lockForUpdate()->get()->keyBy('id');
        abort_if($products->count() !== collect($data['items'])->pluck('product_id')->unique()->count(), 404, 'One or more products were not found');
        if ($offline) {
            $this->validateOfflineItems($data['items'], $products);
        }
        $subtotal = (int) round(collect($data['items'])->sum(fn ($item) => $item['quantity'] * $item['unit_price']));
        $discount = min((int) ($data['discount'] ?? 0), $subtotal);
        $total = $subtotal - $discount;
        $paid = min(max((int) ($data['paid_amount'] ?? 0), 0), $total);
        $credit = max($total - $paid, 0);
        if ($offline && ($credit > 0 || ! empty($data['customer_id']) || ($data['payment_type'] ?? 'cash') !== 'cash' || strcasecmp((string) ($data['payment_method'] ?? ''), 'Credit') === 0)) {
            throw ValidationException::withMessages(['payment_type' => ['Offline sales must be fully paid and cannot use customer credit.']]);
        }
        abort_if($credit > 0 && empty($data['customer_id']), 400, 'Unpaid amount requires a customer');
        $paymentMethod = $data['payment_method'] ?? 'Cash';
        if ($credit > 0 && $paid === 0 && $paymentMethod === 'Cash') {
            $paymentMethod = 'Credit';
        }
        $receiptNo = $receiptNo ?: $this->nextReceipt();
        $values = ['customer_id' => $data['customer_id'] ?? null, 'payment_type' => $credit > 0 ? 'credit' : 'cash', 'payment_method' => $paymentMethod,
            'subtotal' => $subtotal, 'discount' => $discount, 'total' => $total, 'paid_amount' => $paid, 'credit_amount' => $credit, 'status' => 'completed'];
        if ($id) {
            DB::table('sales')->where('id', $id)->update($values);
        } else {
            $id = DB::table('sales')->insertGetId($values + [
                'receipt_no' => $receiptNo,
                'source' => $offline ? 'offline' : 'online',
                'offline_sale_uuid' => $offline['offline_sale_uuid'] ?? null,
                'offline_created_at' => $offline['offline_created_at'] ?? null,
                'created_at' => $offline['offline_created_at'] ?? now(),
            ]);
        }
        foreach ($data['items'] as $item) {
            $product = $products[$item['product_id']];
            $foc = (float) ($item['foc_quantity'] ?? 0);
            $quantity = (float) $item['quantity'];
            $line = (int) round($quantity * $item['unit_price']);
            DB::table('sale_items')->insert(['sale_id' => $id, 'product_id' => $product->id, 'product_name' => $product->name, 'price_type' => $item['price_type'] ?? 'Retail',
                'quantity' => $quantity, 'foc_quantity' => $foc, 'unit_price' => $item['unit_price'], 'unit_cost' => $product->cost, 'line_total' => $line]);
            DB::table('products')->where('id', $product->id)->decrement('stock', $quantity + $foc, ['updated_at' => now()]);
            $this->insertMovement($product->id, $product->name, 'sale', -($quantity + $foc), 'sale', $id, $receiptNo);
        }

        return $this->show($id);
    }

    private function validateOfflineItems(array $items, $products): void
    {
        $requiredStock = [];
        foreach ($items as $index => $item) {
            $product = $products[$item['product_id']];
            if (trim((string) ($item['product_name'] ?? '')) !== $product->name) {
                throw ValidationException::withMessages(["items.{$index}.product_name" => ["{$product->name} has changed since it was downloaded."]]);
            }

            $priceType = trim((string) ($item['price_type'] ?? 'Retail')) ?: 'Retail';
            $currentPrice = DB::table('product_prices')->where('product_id', $product->id)->where('name', $priceType)->value('price');
            if ($currentPrice === null && strcasecmp($priceType, 'Retail') === 0) {
                $currentPrice = $product->price;
            }
            if ($currentPrice === null || (int) $currentPrice !== (int) $item['unit_price']) {
                throw ValidationException::withMessages(["items.{$index}.unit_price" => ["The {$priceType} price for {$product->name} has changed."]]);
            }

            $requiredStock[$product->id] = ($requiredStock[$product->id] ?? 0)
                + (float) $item['quantity'] + (float) ($item['foc_quantity'] ?? 0);
        }

        foreach ($requiredStock as $productId => $quantity) {
            $product = $products[$productId];
            if ((float) $product->stock + 0.0005 < $quantity) {
                throw ValidationException::withMessages(['stock' => ["Not enough stock for {$product->name}. Required {$quantity}, available {$product->stock}."]]);
            }
        }
    }

    private function nextReceipt(): string
    {
        $prefix = 'R'.now()->format('ymd');
        $sequence = DB::table('sales')->where('receipt_no', 'like', $prefix.'-%')->count() + 1;
        do {
            $receipt = sprintf('%s-%04d', $prefix, $sequence++);
        } while (DB::table('sales')->where('receipt_no', $receipt)->exists());

        return $receipt;
    }
}
