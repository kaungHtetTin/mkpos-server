<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SupplierController extends ApiController
{
    public function index(Request $request)
    {
        $query = DB::table('suppliers')->where('is_active', true);
        if ($search = trim((string) $request->query('q', ''))) {
            $like = "%{$search}%";
            $query->where(fn ($q) => $q->where('name', 'like', $like)->orWhere('phone', 'like', $like)->orWhere('contact_person', 'like', $like)->orWhere('address', 'like', $like)->orWhere('note', 'like', $like));
        }
        $result = $this->page($query->orderBy('name'), $request, 500, 500, true);
        if (isset($result['items'])) {
            $result['items'] = $this->stats($result['items']);
        } else {
            $result = $this->stats($result);
        }

        return $result;
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);
        $id = DB::table('suppliers')->insertGetId($data + ['is_active' => true, 'created_at' => now(), 'updated_at' => now()]);

        return $this->find($id);
    }

    public function show(Request $request, int $id)
    {
        $supplier = $this->find($id);
        $query = DB::table('purchases')->where('status', 'completed')->where(fn ($q) => $q->where('supplier_id', $id)->orWhere(fn ($legacy) => $legacy->whereNull('supplier_id')->where('supplier_name', $supplier['name'])));
        if ($search = trim((string) $request->query('purchase_q', ''))) {
            $query->where(fn ($q) => $q->where('note', 'like', "%{$search}%")->orWhere('id', 'like', "%{$search}%"));
        }
        if ($request->query('date_from')) {
            $query->whereDate('created_at', '>=', $request->query('date_from'));
        }
        if ($request->query('date_to')) {
            $query->whereDate('created_at', '<=', $request->query('date_to'));
        }
        $total = (clone $query)->count();
        $totalCost = (int) (clone $query)->sum('total_cost');
        $limit = max(1, min((int) $request->query('limit', 25), 100));
        $offset = max((int) $request->query('offset', 0), 0);

        return ['supplier' => $supplier, 'purchases' => $query->orderByDesc('created_at')->limit($limit)->offset($offset)->get(),
            'filtered_stats' => ['purchase_count' => $total, 'total_purchase' => $totalCost], 'total' => $total, 'limit' => $limit, 'offset' => $offset];
    }

    public function update(Request $request, int $id)
    {
        abort_if(! DB::table('suppliers')->where('id', $id)->where('is_active', true)->update($this->validated($request) + ['updated_at' => now()]), 404, 'Supplier not found');

        return $this->find($id);
    }

    public function destroy(int $id)
    {
        abort_if(! DB::table('suppliers')->where('id', $id)->where('is_active', true)->update(['is_active' => false, 'updated_at' => now()]), 404, 'Supplier not found');

        return ['ok' => true];
    }

    private function validated(Request $request): array
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:255'], 'phone' => ['nullable', 'string', 'max:255'], 'address' => ['nullable', 'string'], 'contact_person' => ['nullable', 'string', 'max:255'], 'note' => ['nullable', 'string']]);
        foreach (['phone', 'address', 'contact_person', 'note'] as $field) {
            $data[$field] = $data[$field] ?? '';
        }

        return $data;
    }

    private function stats(array $suppliers): array
    {
        foreach ($suppliers as &$supplier) {
            $query = DB::table('purchases')->where('status', 'completed')->where(fn ($q) => $q->where('supplier_id', $supplier['id'])->orWhere(fn ($legacy) => $legacy->whereNull('supplier_id')->where('supplier_name', $supplier['name'])));
            $supplier['purchase_count'] = (clone $query)->count();
            $supplier['total_purchase'] = (int) (clone $query)->sum('total_cost');
            $supplier['last_purchase'] = $query->max('created_at');
        }

        return $suppliers;
    }

    private function find(int $id): array
    {
        $row = DB::table('suppliers')->where('id', $id)->where('is_active', true)->first();
        abort_if(! $row, 404, 'Supplier not found');

        return $this->stats([(array) $row])[0];
    }
}
