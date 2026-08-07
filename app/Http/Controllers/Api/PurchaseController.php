<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PurchaseController extends ApiController
{
    public function index(Request $request)
    {
        $query = DB::table('purchases');
        if (($status = $request->query('status', 'all')) !== 'all') {
            $query->where('status', $status);
        }
        if ($request->query('supplier')) {
            $query->where('supplier_name', 'like', '%'.$request->query('supplier').'%');
        }
        if ($search = trim((string) $request->query('q', ''))) {
            $like = "%{$search}%";
            $query->where(function ($q) use ($like) {
                $q->where('supplier_name', 'like', $like)->orWhere('note', 'like', $like)->orWhere('id', 'like', $like)->orWhereExists(fn ($items) => $items->selectRaw('1')->from('purchase_items')->whereColumn('purchase_items.purchase_id', 'purchases.id')->where('purchase_items.product_name', 'like', $like));
            });
        }
        if ($request->query('date_from')) {
            $query->whereDate('created_at', '>=', $request->query('date_from'));
        } if ($request->query('date_to')) {
            $query->whereDate('created_at', '<=', $request->query('date_to'));
        }

        return $this->page($query->orderByDesc('created_at')->orderByDesc('id'), $request, 25, 100);
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);

        return DB::transaction(fn () => $this->save(null, $data));
    }

    public function show(int $id): array
    {
        $row = DB::table('purchases')->where('id', $id)->first();
        abort_if(! $row, 404, 'Purchase not found');
        $purchase = (array) $row;
        $purchase['items'] = DB::table('purchase_items')->where('purchase_id', $id)->orderBy('id')->get()->map(function ($row) {
            $item = (array) $row;
            foreach (['quantity', 'foc_quantity', 'conversion_factor', 'base_quantity', 'base_foc_quantity', 'effective_unit_cost'] as $field) {
                $item[$field] = (float) $item[$field];
            }

            return $item;
        })->all();

        return $purchase;
    }

    public function update(Request $request, int $id)
    {
        $data = $this->validated($request);

        return DB::transaction(function () use ($data, $id) {
            $purchase = DB::table('purchases')->where('id', $id)->lockForUpdate()->first();
            abort_if(! $purchase, 404, 'Purchase not found');
            abort_if($purchase->status === 'voided', 400, 'Voided purchase cannot be edited');
            $affected = [];
            $existingItems = DB::table('purchase_items')->where('purchase_id', $id)->get();
            foreach ($existingItems as $item) {
                $affected[] = $item->product_id;
                $received = (float) $item->base_quantity + (float) $item->base_foc_quantity;
                DB::table('products')->where('id', $item->product_id)->decrement('stock', $received, ['updated_at' => now()]);
                $this->insertMovement($item->product_id, $item->product_name, 'purchase_edit_reverse', -$received, 'purchase', $id, $purchase->supplier_name);
            }
            DB::table('purchase_items')->where('purchase_id', $id)->delete();
            $saved = $this->save($id, $data, $existingItems->keyBy('id'));
            $this->refreshCosts(array_unique(array_merge($affected, array_column($data['items'], 'product_id'))));

            return $saved;
        });
    }

    public function destroy(Request $request, int $id)
    {
        $this->requireAdminPin($request->input('admin_pin'));

        return DB::transaction(function () use ($id) {
            $purchase = DB::table('purchases')->where('id', $id)->lockForUpdate()->first();
            abort_if(! $purchase, 404, 'Purchase not found');
            $affected = [];
            foreach (DB::table('purchase_items')->where('purchase_id', $id)->get() as $item) {
                $affected[] = $item->product_id;
                $received = (float) $item->base_quantity + (float) $item->base_foc_quantity;
                DB::table('products')->where('id', $item->product_id)->decrement('stock', $received, ['updated_at' => now()]);
                $this->insertMovement($item->product_id, $item->product_name, 'purchase_delete', -$received, 'purchase', $id, $purchase->supplier_name);
            }
            DB::table('purchase_items')->where('purchase_id', $id)->delete();
            DB::table('purchases')->where('id', $id)->delete();
            $this->refreshCosts(array_unique($affected));

            return ['id' => $id, 'deleted' => true];
        });
    }

    private function validated(Request $request): array
    {
        return $request->validate(['supplier_id' => ['nullable', 'integer', 'exists:suppliers,id'], 'supplier_name' => ['nullable', 'string', 'max:255'], 'note' => ['nullable', 'string'],
            'items' => ['required', 'array', 'min:1'], 'items.*.id' => ['nullable', 'integer'], 'items.*.product_id' => ['required', 'integer', 'exists:products,id'], 'items.*.unit_name' => ['nullable', 'string', 'max:50'], 'items.*.quantity' => ['required', 'numeric', 'gt:0'],
            'items.*.foc_quantity' => ['nullable', 'numeric', 'min:0'], 'items.*.unit_cost' => ['required', 'integer', 'min:0']]);
    }

    private function save(?int $id, array $data, $historicalItems = null): array
    {
        $products = DB::table('products')->whereIn('id', collect($data['items'])->pluck('product_id'))->where('is_active', true)->lockForUpdate()->get()->keyBy('id');
        abort_if($products->count() !== collect($data['items'])->pluck('product_id')->unique()->count(), 404, 'One or more products were not found');
        $supplierName = trim($data['supplier_name'] ?? '');
        if (! empty($data['supplier_id'])) {
            $supplier = DB::table('suppliers')->where('id', $data['supplier_id'])->where('is_active', true)->first();
            abort_if(! $supplier, 404, 'Supplier not found');
            $supplierName = $supplier->name;
        }
        $total = (int) round(collect($data['items'])->sum(fn ($item) => $item['quantity'] * $item['unit_cost']));
        $values = ['supplier_id' => $data['supplier_id'] ?? null, 'supplier_name' => $supplierName, 'note' => $data['note'] ?? '', 'total_cost' => $total, 'status' => 'completed', 'updated_at' => now()];
        if ($id) {
            DB::table('purchases')->where('id', $id)->update($values);
        } else {
            $id = DB::table('purchases')->insertGetId($values + ['created_at' => now()]);
        }
        foreach ($data['items'] as $item) {
            $product = $products[$item['product_id']];
            $historical = ! empty($item['id']) ? $historicalItems?->get((int) $item['id']) : null;
            $unit = $historical && (int) $historical->product_id === (int) $product->id
                && strcasecmp((string) $historical->unit_name, (string) ($item['unit_name'] ?? '')) === 0
                ? ['name' => $historical->unit_name, 'factor' => (float) $historical->conversion_factor]
                : $this->resolveUnit($product, $item['unit_name'] ?? null);
            $factor = $unit['factor'];
            $quantity = (float) $item['quantity'];
            $foc = (float) ($item['foc_quantity'] ?? 0);
            $line = (int) round($quantity * $item['unit_cost']);
            $baseQuantity = round($quantity * $factor, 3);
            $baseFocQuantity = round($foc * $factor, 3);
            $received = $baseQuantity + $baseFocQuantity;
            DB::table('purchase_items')->insert(['purchase_id' => $id, 'product_id' => $product->id, 'product_name' => $product->name,
                'unit_name' => $unit['name'], 'conversion_factor' => $factor, 'quantity' => $quantity, 'foc_quantity' => $foc,
                'base_quantity' => $baseQuantity, 'base_foc_quantity' => $baseFocQuantity, 'unit_cost' => $item['unit_cost'],
                'effective_unit_cost' => $received ? $line / $received : 0, 'line_total' => $line]);
            DB::table('products')->where('id', $product->id)->increment('stock', $received, ['cost' => (int) round($line / $received), 'updated_at' => now()]);
            $this->insertMovement($product->id, $product->name, 'purchase', $received, 'purchase', $id, $supplierName.' - '.$quantity.' '.$unit['name']);
        }
        $purchase = $this->show($id);
        $purchase['price_changes'] = $this->recalculatePrices(array_column($data['items'], 'product_id'));

        return $purchase;
    }

    private function refreshCosts(array $productIds): void
    {
        foreach ($productIds as $productId) {
            $latest = DB::table('purchase_items as pi')->join('purchases as p', 'p.id', '=', 'pi.purchase_id')->where('pi.product_id', $productId)->where('p.status', 'completed')->orderByDesc('p.created_at')->orderByDesc('p.id')->value('pi.effective_unit_cost');
            DB::table('products')->where('id', $productId)->update(['cost' => $latest === null ? DB::raw('base_cost') : (int) round($latest), 'updated_at' => now()]);
        }
        $this->recalculatePrices($productIds);
    }

    private function resolveUnit(object $product, ?string $requestedName): array
    {
        $baseUnit = trim((string) ($product->base_unit ?? 'Unit')) ?: 'Unit';
        $requested = trim((string) $requestedName) ?: $baseUnit;
        if (strcasecmp($requested, $baseUnit) === 0) {
            return ['name' => $baseUnit, 'factor' => 1.0];
        }

        $purchaseUnit = trim((string) ($product->purchase_unit ?? ''));
        if ($purchaseUnit !== '' && strcasecmp($requested, $purchaseUnit) === 0) {
            return ['name' => $purchaseUnit, 'factor' => (float) $product->purchase_conversion_factor];
        }

        abort(422, "{$requested} is not configured as a purchase unit for {$product->name}.");
    }

    private function recalculatePrices(array $productIds): array
    {
        $changes = [];
        $rows = DB::table('product_prices as pp')->join('price_type_rules as rules', function ($join) {
            $join->on('rules.name', '=', 'pp.name')->on('rules.business_id', '=', 'pp.business_id');
        })->join('products as p', 'p.id', '=', 'pp.product_id')
            ->whereIn('pp.product_id', array_unique($productIds))->where('pp.is_manual', false)->where('rules.pricing_mode', 'automatic')
            ->select('pp.id', 'pp.product_id', 'pp.name', 'pp.price', 'p.name as product_name', 'p.cost', 'rules.markup_percent', 'rules.rounding', 'rules.minimum_profit')->get();
        foreach ($rows as $row) {
            $price = max(ceil($row->cost * (1 + $row->markup_percent / 100)), $row->cost + $row->minimum_profit);
            $price = (int) (ceil($price / max($row->rounding, 1)) * max($row->rounding, 1));
            if ($price !== (int) $row->price) {
                $changes[] = ['product_id' => $row->product_id, 'product_name' => $row->product_name, 'price_type' => $row->name, 'old_price' => (int) $row->price, 'new_price' => $price];
            }
            DB::table('product_prices')->where('id', $row->id)->update(['price' => $price]);
            if (strcasecmp($row->name, 'Retail') === 0) {
                DB::table('products')->where('id', $row->product_id)->update(['price' => $price, 'updated_at' => now()]);
            }
        }

        return $changes;
    }
}
