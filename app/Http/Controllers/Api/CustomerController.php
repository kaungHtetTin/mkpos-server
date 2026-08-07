<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CustomerController extends ApiController
{
    public function index(Request $request)
    {
        $query = DB::table('customers as c')->where('c.is_active', true)
            ->select('c.*')->selectRaw($this->balanceSql().' as balance');
        if ($search = trim((string) $request->query('q', ''))) {
            $like = "%{$search}%";
            $query->where(fn ($q) => $q->where('c.name', 'like', $like)->orWhere('c.phone', 'like', $like)->orWhere('c.address', 'like', $like)->orWhere('c.note', 'like', $like));
        }
        $result = $this->page($query->orderBy('c.name'), $request, 200, 500);
        if (isset($result['items'])) {
            $result['account_summary'] = $this->accountSummary();

            return $result;
        }

        return $result;
    }

    public function store(Request $request)
    {
        $data = $this->validatedCustomer($request);
        $id = DB::table('customers')->insertGetId($data + ['is_active' => true, 'created_at' => now(), 'updated_at' => now()]);

        return (array) DB::table('customers')->where('id', $id)->select('*')->selectRaw('0 as balance')->first();
    }

    public function update(Request $request, int $id)
    {
        $data = $this->validatedCustomer($request);
        abort_if(! DB::table('customers')->where('id', $id)->where('is_active', true)->update($data + ['updated_at' => now()]), 404, 'Customer not found');

        return $this->customer($id);
    }

    public function destroy(int $id)
    {
        abort_if(! DB::table('customers')->where('id', $id)->where('is_active', true)->update(['is_active' => false, 'updated_at' => now()]), 404, 'Customer not found');

        return ['ok' => true];
    }

    public function detail(int $id)
    {
        $customer = $this->customer($id);
        $credit = (int) DB::table('sales')->where('customer_id', $id)->where('status', 'completed')->sum('credit_amount');
        $paid = (int) DB::table('customer_payments')->where('customer_id', $id)->where('status', 'completed')->where('direction', 'customer_to_shop')->sum('amount');
        $payout = (int) DB::table('customer_payments')->where('customer_id', $id)->where('status', 'completed')->where('direction', 'shop_to_customer')->sum('amount');

        return ['customer' => $customer, 'summary' => ['credit_total' => $credit, 'paid_total' => $paid, 'payout_total' => $payout, 'balance' => $credit - $paid + $payout]];
    }

    public function sales(Request $request, int $id)
    {
        abort_if(! DB::table('customers')->where('id', $id)->where('is_active', true)->exists(), 404, 'Customer not found');
        $query = DB::table('sales')->where('customer_id', $id)->where('credit_amount', '>', 0);
        $this->historyFilters($query, $request);

        return $this->page($query->orderByDesc('created_at')->orderByDesc('id'), $request, 25, 100);
    }

    public function payments(Request $request, int $id)
    {
        abort_if(! DB::table('customers')->where('id', $id)->where('is_active', true)->exists(), 404, 'Customer not found');
        $query = DB::table('customer_payments')->where('customer_id', $id);
        $this->historyFilters($query, $request);

        return $this->page($query->orderByDesc('created_at')->orderByDesc('id'), $request, 25, 100);
    }

    public function statement(Request $request, int $id)
    {
        $detail = $this->detail($id);
        $salesQuery = DB::table('sales')->where('customer_id', $id)->where('credit_amount', '>', 0);
        $paymentsQuery = DB::table('customer_payments')->where('customer_id', $id);
        $this->historyFilters($salesQuery, $request);
        $this->historyFilters($paymentsQuery, $request);

        return $detail + ['start' => $request->query('start'), 'end' => $request->query('end'),
            'sales' => $salesQuery->orderBy('created_at')->get(), 'payments' => $paymentsQuery->orderBy('created_at')->get()];
    }

    public function storePayment(Request $request, int $id)
    {
        abort_if(! DB::table('customers')->where('id', $id)->where('is_active', true)->exists(), 404, 'Customer not found');
        $data = $this->validatedPayment($request);
        $paymentId = DB::table('customer_payments')->insertGetId($data + ['customer_id' => $id, 'status' => 'completed', 'created_at' => now(), 'updated_at' => now()]);

        return $this->paymentResponse($paymentId);
    }

    public function allPayments(Request $request)
    {
        $query = DB::table('customer_payments as cp')->join('customers as c', 'c.id', '=', 'cp.customer_id')->select('cp.*', 'c.name as customer_name');
        if (($status = $request->query('status', 'all')) !== 'all') {
            $query->where('cp.status', $status);
        }
        if ($search = trim((string) $request->query('q', ''))) {
            $like = "%{$search}%";
            $query->where(fn ($q) => $q->where('c.name', 'like', $like)->orWhere('cp.note', 'like', $like)->orWhere('cp.payment_method', 'like', $like));
        }
        if ($request->query('start', $request->query('date_from'))) {
            $query->whereDate('cp.created_at', '>=', $request->query('start', $request->query('date_from')));
        }
        if ($request->query('end', $request->query('date_to'))) {
            $query->whereDate('cp.created_at', '<=', $request->query('end', $request->query('date_to')));
        }

        return $this->page($query->orderByDesc('cp.created_at')->orderByDesc('cp.id'), $request, 25, 100);
    }

    public function showPayment(int $id)
    {
        return $this->paymentResponse($id);
    }

    public function updatePayment(Request $request, int $id)
    {
        $data = $this->validatedPayment($request, true);
        abort_if(! DB::table('customer_payments')->where('id', $id)->update($data + ['updated_at' => now()]), 404, 'Payment not found');

        return $this->paymentResponse($id);
    }

    public function destroyPayment(int $id)
    {
        $payment = DB::table('customer_payments')->where('id', $id)->first();
        abort_if(! $payment, 404, 'Payment not found');
        DB::table('customer_payments')->where('id', $id)->delete();

        return ['id' => $id, 'customer_id' => $payment->customer_id, 'deleted' => true];
    }

    private function validatedCustomer(Request $request): array
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:255'], 'phone' => ['nullable', 'string', 'max:255'], 'address' => ['nullable', 'string'], 'note' => ['nullable', 'string']]);
        foreach (['phone', 'address', 'note'] as $field) {
            $data[$field] = $data[$field] ?? '';
        }

        return $data;
    }

    private function validatedPayment(Request $request, bool $includeCustomer = false): array
    {
        $rules = ['amount' => ['required', 'integer', 'min:1'], 'direction' => ['required', 'in:customer_to_shop,shop_to_customer'], 'payment_method' => ['nullable', 'string', 'max:255'], 'note' => ['nullable', 'string']];
        if ($includeCustomer) {
            $rules['customer_id'] = ['required', 'exists:customers,id'];
        }
        $data = $request->validate($rules);
        $data['payment_method'] = $data['payment_method'] ?? 'Cash';
        $data['note'] = $data['note'] ?? '';

        return $data;
    }

    private function balanceSql(): string
    {
        return "CAST(COALESCE((SELECT SUM(s.credit_amount) FROM sales s WHERE s.customer_id=c.id AND s.status='completed'),0)-COALESCE((SELECT SUM(CASE WHEN cp.direction='customer_to_shop' THEN cp.amount ELSE -cp.amount END) FROM customer_payments cp WHERE cp.customer_id=c.id AND cp.status='completed'),0) AS SIGNED)";
    }

    private function customer(int $id): array
    {
        $row = DB::table('customers as c')->where('c.id', $id)->where('c.is_active', true)->select('c.*')->selectRaw($this->balanceSql().' as balance')->first();
        abort_if(! $row, 404, 'Customer not found');

        return (array) $row;
    }

    private function accountSummary(): array
    {
        $rows = DB::table('customers as c')->where('c.is_active', true)->selectRaw($this->balanceSql().' as balance')->get();

        return ['total_customers' => $rows->count(), 'receivable_total' => (int) $rows->sum(fn ($r) => max((int) $r->balance, 0)),
            'payable_total' => (int) $rows->sum(fn ($r) => max(-(int) $r->balance, 0)), 'open_accounts' => $rows->filter(fn ($r) => (int) $r->balance !== 0)->count()];
    }

    private function historyFilters($query, Request $request, string $column = 'created_at'): void
    {
        $start = $request->query('start', $request->query('date_from'));
        $end = $request->query('end', $request->query('date_to'));
        if ($start) {
            $query->whereDate($column, '>=', $start);
        } if ($end) {
            $query->whereDate($column, '<=', $end);
        }
        if (($status = $request->query('status', 'all')) !== 'all') {
            $query->where('status', $status);
        }
    }

    private function paymentResponse(int $id): array
    {
        $row = DB::table('customer_payments')->where('id', $id)->first();
        abort_if(! $row, 404, 'Payment not found');
        $result = (array) $row;
        $result['balance'] = $this->customer((int) $row->customer_id)['balance'];

        return $result;
    }
}
