<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SalePostingService
{
    public function save(?int $id, array $data, ?string $receiptNo = null, ?array $offline = null, array $editableArchivedProductIds = []): int
    {
        $products = DB::table('products')
            ->whereIn('id', collect($data['items'])->pluck('product_id'))
            ->where(function ($query) use ($editableArchivedProductIds) {
                $query->where('is_active', true);
                if ($editableArchivedProductIds !== []) {
                    $query->orWhereIn('id', $editableArchivedProductIds);
                }
            })
            ->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        abort_if($products->count() !== collect($data['items'])->pluck('product_id')->unique()->count(), 404, 'One or more products were not found');
        if ($offline) {
            $this->validateOfflineItems($data['items'], $products);
        }
        $ecommerce = ! empty($data['ecommerce']);
        $subtotal = $ecommerce ? array_sum(array_map(fn ($i) => (int) $i['snapshot']['line_total'], $data['items'])) : (int) round(collect($data['items'])->sum(fn ($item) => $item['quantity'] * $item['unit_price']));
        $discount = min((int) ($data['discount'] ?? 0), $subtotal);
        $deliveryFee = $ecommerce ? (int) $data['delivery_fee'] : 0;
        $total = $subtotal - $discount + $deliveryFee;
        $paid = min(max((int) ($data['paid_amount'] ?? 0), 0), $total);
        $credit = max($total - $paid, 0);
        $focOnly = collect($data['items'])->every(fn ($item) => (float) $item['quantity'] === 0.0)
            && collect($data['items'])->contains(fn ($item) => (float) ($item['foc_quantity'] ?? 0) > 0.0);
        if ($offline && ($credit > 0 || ! empty($data['customer_id']) || ($data['payment_type'] ?? 'cash') !== 'cash' || strcasecmp((string) ($data['payment_method'] ?? ''), 'Credit') === 0)) {
            throw ValidationException::withMessages(['payment_type' => ['Offline sales must be fully paid and cannot use customer credit.']]);
        }
        abort_if($credit > 0 && empty($data['customer_id']), 400, 'Unpaid amount requires a customer');
        $paymentMethod = $focOnly ? 'FOC' : ($data['payment_method'] ?? 'Cash');
        if ($credit > 0 && $paid === 0 && $paymentMethod === 'Cash') {
            $paymentMethod = 'Credit';
        }
        $receiptNo = $receiptNo ?: $this->nextReceipt();
        $values = ['customer_id' => $data['customer_id'] ?? null, 'payment_type' => $credit > 0 ? 'credit' : 'cash', 'payment_method' => $paymentMethod,
            'delivery_fee' => $deliveryFee, 'subtotal' => $subtotal, 'discount' => $discount, 'total' => $total, 'paid_amount' => $paid, 'credit_amount' => $credit, 'status' => 'completed'];
        if ($id) {
            DB::table('sales')->where('id', $id)->update($values);
        } else {
            $id = DB::table('sales')->insertGetId($values + [
                'receipt_no' => $receiptNo,
                'source' => $ecommerce ? 'ecommerce' : ($offline ? 'offline' : 'online'),
                'offline_sale_uuid' => $offline['offline_sale_uuid'] ?? null,
                'offline_created_at' => $offline['offline_created_at'] ?? null,
                'created_at' => $offline ? \App\Support\AppTime::storage($offline['offline_created_at'], 'UTC')->toDateTimeString() : now(),
            ]);
        }
        foreach ($data['items'] as $item) {
            $product = $products[$item['product_id']];
            $foc = (float) ($item['foc_quantity'] ?? 0);
            $quantity = (float) $item['quantity'];
            $line = $ecommerce ? (int) $item['snapshot']['line_total'] : (int) round($quantity * $item['unit_price']);
            app(EcommerceInventory::class)->assertRemaining($product, (float) $product->stock - $quantity - $foc);
            $product->stock -= $quantity + $foc;
            DB::table('sale_items')->insert(['sale_id' => $id, 'product_id' => $product->id, 'product_name' => $ecommerce ? $item['snapshot']['name'] : $product->name, 'price_type' => $item['price_type'] ?? 'Retail',
                'quantity' => $quantity, 'foc_quantity' => $foc, 'unit_price' => $ecommerce ? (int) round($line / $quantity) : $item['unit_price'], 'unit_cost' => $ecommerce ? $item['snapshot']['unit_cost'] : $product->cost, 'line_total' => $line,
                'ecommerce_unit_name' => $ecommerce ? $item['snapshot']['unit_name'] : null,
                'ecommerce_quantity' => $ecommerce ? $item['snapshot']['quantity'] : null,
                'ecommerce_unit_price' => $ecommerce ? $item['snapshot']['unit_price'] : null]);
            DB::table('products')->where('id', $product->id)->decrement('stock', $quantity + $foc, ['updated_at' => now()]);
            $this->insertMovement($product->id, $product->name, 'sale', -($quantity + $foc), 'sale', $id, $receiptNo);
        }

        return $id;
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
    protected function insertMovement(int $productId, string $productName, string $type, float $quantity, string $referenceType = '', ?int $referenceId = null, string $note = ''): void
    {
        DB::table('stock_movements')->insert([
            'product_id' => $productId, 'product_name' => $productName, 'movement_type' => $type,
            'quantity_change' => $quantity, 'reference_type' => $referenceType,
            'reference_id' => $referenceId, 'note' => $note, 'created_at' => now(),
        ]);
    }
}
