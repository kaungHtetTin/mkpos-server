<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ExpenseController extends ApiController
{
    public function index(Request $request)
    {
        return $this->page($this->filtered($request)->orderByDesc('expense_date')->orderByDesc('created_at')->orderByDesc('id'), $request, 25, 100);
    }

    public function summary(Request $request)
    {
        $filtered = $this->filtered($request, 'completed');
        $today = now()->toDateString();
        $month = now()->startOfMonth()->toDateString();
        $top = (clone $filtered)->selectRaw("CASE WHEN TRIM(category)='' THEN 'Uncategorized' ELSE category END as category, COUNT(*) as count, SUM(amount) as total")
            ->groupByRaw("CASE WHEN TRIM(category)='' THEN 'Uncategorized' ELSE category END")->orderByDesc('total')->first();
        $common = $this->filtered($request, 'completed', false);

        return ['today_total' => (int) (clone $common)->whereDate('expense_date', $today)->sum('amount'),
            'month_total' => (int) (clone $common)->whereBetween('expense_date', [$month, $today])->sum('amount'),
            'filter_total' => (int) (clone $filtered)->sum('amount'), 'matching_count' => (clone $filtered)->count(), 'top_category' => $top ? (array) $top : null];
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);
        $id = DB::table('expenses')->insertGetId($data + ['status' => 'completed', 'created_at' => now(), 'updated_at' => now()]);

        return (array) DB::table('expenses')->where('id', $id)->first();
    }

    public function show(int $id)
    {
        $expense = DB::table('expenses')->where('id', $id)->first();
        abort_if(! $expense, 404, 'Expense not found');

        return (array) $expense;
    }

    public function update(Request $request, int $id)
    {
        $expense = DB::table('expenses')->where('id', $id)->first();
        abort_if(! $expense, 404, 'Expense not found');
        abort_if($expense->status === 'voided', 400, 'Voided expense cannot be edited');
        DB::table('expenses')->where('id', $id)->update($this->validated($request) + ['updated_at' => now()]);

        return (array) DB::table('expenses')->where('id', $id)->first();
    }

    public function destroy(Request $request, int $id)
    {
        $this->requireAdminPin($request->input('admin_pin'));
        abort_if(! DB::table('expenses')->where('id', $id)->delete(), 404, 'Expense not found');

        return ['id' => $id, 'deleted' => true];
    }

    private function validated(Request $request): array
    {
        $data = $request->validate(['title' => ['required', 'string', 'max:255'], 'category' => ['nullable', 'string', 'max:255'], 'amount' => ['required', 'integer', 'min:1'],
            'payment_method' => ['nullable', 'string', 'max:255'], 'expense_date' => ['nullable', 'date'], 'note' => ['nullable', 'string']]);
        $data['category'] = $data['category'] ?? '';
        $data['payment_method'] = $data['payment_method'] ?? 'Cash';
        $data['expense_date'] = $data['expense_date'] ?? now()->toDateString();

        return $data;
    }

    private function filtered(Request $request, string $defaultStatus = 'all', bool $dates = true)
    {
        $query = DB::table('expenses');
        $status = $request->query('status', $defaultStatus);
        if ($status !== 'all') {
            $query->where('status', $status);
        }
        if ($search = trim((string) $request->query('q', ''))) {
            $like = "%{$search}%";
            $query->where(fn ($q) => $q->where('title', 'like', $like)->orWhere('category', 'like', $like)->orWhere('payment_method', 'like', $like)->orWhere('note', 'like', $like)->orWhere('id', 'like', $like));
        }
        if ($request->query('category')) {
            $query->where('category', $request->query('category'));
        } if ($request->query('payment_method')) {
            $query->where('payment_method', $request->query('payment_method'));
        }
        if ($dates && $request->query('date_from')) {
            $query->whereDate('expense_date', '>=', $request->query('date_from'));
        } if ($dates && $request->query('date_to')) {
            $query->whereDate('expense_date', '<=', $request->query('date_to'));
        }

        return $query;
    }
}
