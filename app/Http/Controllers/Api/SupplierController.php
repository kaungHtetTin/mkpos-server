<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SupplierController extends ApiController
{
    public function index(Request $request)
    {
        $query = DB::table('suppliers as s')->where('s.is_active', true)->select('s.*')->selectRaw($this->balanceSql().' as balance');
        if ($search = trim((string) $request->query('q', ''))) {
            $like = "%{$search}%";
            $query->where(fn ($q) => $q->where('s.name', 'like', $like)->orWhere('s.phone', 'like', $like)->orWhere('s.contact_person', 'like', $like)->orWhere('s.address', 'like', $like)->orWhere('s.note', 'like', $like));
        }
        $balanceSql = $this->balanceSql();
        match ($request->query('account_status', 'all')) {
            'payable' => $query->whereRaw("({$balanceSql}) > 0"),
            'credit' => $query->whereRaw("({$balanceSql}) < 0"),
            'settled' => $query->whereRaw("({$balanceSql}) = 0"),
            default => null,
        };
        match ($request->query('purchase_activity', 'all')) {
            'with_purchases' => $query->whereExists(fn ($purchase) => $purchase->selectRaw('1')->from('purchases as p')->whereColumn('p.supplier_id', 's.id')->where('p.status', 'completed')),
            'without_purchases' => $query->whereNotExists(fn ($purchase) => $purchase->selectRaw('1')->from('purchases as p')->whereColumn('p.supplier_id', 's.id')->where('p.status', 'completed')),
            default => null,
        };
        $result = $this->page($query->orderBy('s.name'), $request, 500, 500, true);
        if (isset($result['items'])) {
            $result['items'] = $this->stats($result['items']);
            $result['account_summary'] = $this->accountSummary();
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

        $payments = DB::table('supplier_payments')->where('supplier_id', $id);

        return ['supplier' => $supplier, 'summary' => $this->accountTotals($id),
            'purchases' => $query->orderByDesc('created_at')->limit($limit)->offset($offset)->get(),
            'payments' => $payments->orderByDesc('created_at')->orderByDesc('id')->limit(100)->get(),
            'filtered_stats' => ['purchase_count' => $total, 'total_purchase' => $totalCost], 'total' => $total, 'limit' => $limit, 'offset' => $offset];
    }

    public function statement(Request $request, int $id): array
    {
        $supplier = $this->find($id);
        $purchases = DB::table('purchases')->where('supplier_id', $id)->where('credit_amount', '>', 0);
        $payments = DB::table('supplier_payments')->where('supplier_id', $id);
        $this->historyFilters($purchases, $request);
        $this->historyFilters($payments, $request);

        return ['supplier' => $supplier, 'summary' => $this->accountTotals($id),
            'start' => $request->query('start'), 'end' => $request->query('end'),
            'purchases' => $purchases->orderBy('created_at')->get(), 'payments' => $payments->orderBy('created_at')->get()];
    }

    public function storePayment(Request $request, int $id): array
    {
        abort_if(! DB::table('suppliers')->where('id', $id)->where('is_active', true)->exists(), 404, 'Supplier not found');
        $data = $this->validatedPayment($request);
        $paymentId = DB::table('supplier_payments')->insertGetId($data + ['supplier_id' => $id, 'status' => 'completed', 'created_at' => now(), 'updated_at' => now()]);

        return $this->paymentResponse($paymentId);
    }

    public function allPayments(Request $request)
    {
        $query = DB::table('supplier_payments as sp')->join('suppliers as s', 's.id', '=', 'sp.supplier_id')->select('sp.*', 's.name as supplier_name');
        if ($request->filled('supplier_id')) {
            $query->where('sp.supplier_id', (int) $request->query('supplier_id'));
        }
        if (($status = $request->query('status', 'all')) !== 'all') {
            $query->where('sp.status', $status);
        }
        if (($direction = $request->query('direction', 'all')) !== 'all') {
            $query->where('sp.direction', $direction);
        }
        if ($method = trim((string) $request->query('payment_method', ''))) {
            $query->where('sp.payment_method', $method);
        }
        if ($search = trim((string) $request->query('q', ''))) {
            $like = "%{$search}%";
            $query->where(fn ($q) => $q->where('s.name', 'like', $like)->orWhere('sp.note', 'like', $like)->orWhere('sp.payment_method', 'like', $like));
        }
        $this->historyFilters($query, $request, 'sp.created_at', false);

        return $this->page($query->orderByDesc('sp.created_at')->orderByDesc('sp.id'), $request, 25, 100);
    }

    public function showPayment(int $id): array
    {
        return $this->paymentResponse($id);
    }

    public function updatePayment(Request $request, int $id): array
    {
        $data = $this->validatedPayment($request, true);
        abort_if(! DB::table('supplier_payments')->where('id', $id)->update($data + ['updated_at' => now()]), 404, 'Supplier payment not found');

        return $this->paymentResponse($id);
    }

    public function destroyPayment(int $id): array
    {
        $payment = DB::table('supplier_payments')->where('id', $id)->first();
        abort_if(! $payment, 404, 'Supplier payment not found');
        DB::table('supplier_payments')->where('id', $id)->delete();

        return ['id' => $id, 'supplier_id' => $payment->supplier_id, 'deleted' => true];
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

    private function validatedPayment(Request $request, bool $includeSupplier = false): array
    {
        $rules = ['amount' => ['required', 'integer', 'min:1'], 'direction' => ['required', 'in:shop_to_supplier,supplier_to_shop'], 'payment_method' => ['nullable', 'string', 'max:255'], 'note' => ['nullable', 'string']];
        if ($includeSupplier) {
            $rules['supplier_id'] = ['required', 'exists:suppliers,id'];
        }
        $data = $request->validate($rules);
        $data['payment_method'] = $data['payment_method'] ?? 'Cash';
        $data['note'] = $data['note'] ?? '';

        return $data;
    }

    private function stats(array $suppliers): array
    {
        foreach ($suppliers as &$supplier) {
            $query = DB::table('purchases')->where('status', 'completed')->where(fn ($q) => $q->where('supplier_id', $supplier['id'])->orWhere(fn ($legacy) => $legacy->whereNull('supplier_id')->where('supplier_name', $supplier['name'])));
            $supplier['purchase_count'] = (clone $query)->count();
            $supplier['total_purchase'] = (int) (clone $query)->sum('total_cost');
            $supplier['last_purchase'] = $query->max('created_at');
            $totals = $this->accountTotals((int) $supplier['id']);
            $supplier['credit_purchase_total'] = $totals['credit_purchase_total'];
            $supplier['paid_total'] = $totals['paid_total'];
            $supplier['refund_total'] = $totals['refund_total'];
            $supplier['balance'] = $totals['balance'];
        }

        return $suppliers;
    }

    private function find(int $id): array
    {
        $row = DB::table('suppliers as s')->where('s.id', $id)->where('s.is_active', true)->select('s.*')->selectRaw($this->balanceSql().' as balance')->first();
        abort_if(! $row, 404, 'Supplier not found');

        return $this->stats([(array) $row])[0];
    }

    private function balanceSql(): string
    {
        return "CAST(COALESCE((SELECT SUM(p.credit_amount) FROM purchases p WHERE p.supplier_id=s.id AND p.status='completed'),0)-COALESCE((SELECT SUM(CASE WHEN sp.direction='shop_to_supplier' THEN sp.amount ELSE -sp.amount END) FROM supplier_payments sp WHERE sp.supplier_id=s.id AND sp.status='completed'),0) AS SIGNED)";
    }

    private function accountTotals(int $supplierId): array
    {
        $credit = (int) DB::table('purchases')->where('supplier_id', $supplierId)->where('status', 'completed')->sum('credit_amount');
        $paid = (int) DB::table('supplier_payments')->where('supplier_id', $supplierId)->where('status', 'completed')->where('direction', 'shop_to_supplier')->sum('amount');
        $refund = (int) DB::table('supplier_payments')->where('supplier_id', $supplierId)->where('status', 'completed')->where('direction', 'supplier_to_shop')->sum('amount');

        return ['credit_purchase_total' => $credit, 'paid_total' => $paid, 'refund_total' => $refund, 'balance' => $credit - $paid + $refund];
    }

    private function accountSummary(): array
    {
        $rows = DB::table('suppliers as s')->where('s.is_active', true)->selectRaw($this->balanceSql().' as balance')->get();

        return ['total_suppliers' => $rows->count(), 'payable_total' => (int) $rows->sum(fn ($row) => max((int) $row->balance, 0)),
            'credit_total' => (int) $rows->sum(fn ($row) => max(-(int) $row->balance, 0)), 'open_accounts' => $rows->filter(fn ($row) => (int) $row->balance !== 0)->count()];
    }

    private function historyFilters($query, Request $request, string $column = 'created_at', bool $status = true): void
    {
        $start = $request->query('start', $request->query('date_from'));
        $end = $request->query('end', $request->query('date_to'));
        if ($start) {
            $query->whereDate($column, '>=', $start);
        }
        if ($end) {
            $query->whereDate($column, '<=', $end);
        }
        if ($status && ($value = $request->query('status', 'all')) !== 'all') {
            $query->where('status', $value);
        }
    }

    private function paymentResponse(int $id): array
    {
        $row = DB::table('supplier_payments')->where('id', $id)->first();
        abort_if(! $row, 404, 'Supplier payment not found');
        $result = (array) $row;
        $result['balance'] = $this->accountTotals((int) $row->supplier_id)['balance'];

        return $result;
    }
}
