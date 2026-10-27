<?php

namespace App\Http\Controllers\Office;

use App\Http\Controllers\Controller;
use App\Services\AiBalanceBook;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class AiBalanceBookController extends Controller
{
    private function admin(Request $request): object
    {
        $admin = $request->user('office');
        abort_unless($admin?->role === 'super_admin', 403, 'Only a super administrator can access the AI balance book.');

        return $admin;
    }

    private function serialize(object $row): array
    {
        $data = (array) $row;
        $data['amount'] = AiBalanceBook::amount((int) $row->amount_nanos);
        $data['balance_after'] = AiBalanceBook::amount((int) $row->balance_after_nanos);
        $data['pricing'] = $row->pricing ? json_decode($row->pricing, true) : null;
        $data['created_at'] = Carbon::parse($row->created_at, 'UTC')->toIso8601String();
        unset($data['amount_nanos'], $data['balance_after_nanos'], $data['request_key']);

        return $data;
    }

    public function summary(Request $request, AiBalanceBook $book): array
    {
        $this->admin($request);
        $book->ready();
        $account = DB::table('ai_balance_accounts')->where('id', 1)->first();
        abort_unless($account, 503, 'AI balance book setup is required.');

        return array_merge($this->usageSummary(), ['currency' => 'USD', 'balance' => AiBalanceBook::amount((int) $account->balance_nanos),
            'can_use_ai' => $account->balance_nanos > 0, 'updated_at' => $account->updated_at ? Carbon::parse($account->updated_at, 'UTC')->toIso8601String() : null]);
    }

    private function usageSummary(): array
    {
        $usage = DB::table('ai_balance_entries')->where('type', 'usage')
            ->selectRaw('COUNT(*) AS requests, SUM(amount_nanos) AS amount_nanos')->first();
        $requests = (int) $usage->requests;
        $usageNanos = -(int) ($usage->amount_nanos ?? 0);

        return ['total_usage' => AiBalanceBook::amount($usageNanos), 'total_requests' => $requests,
            'average_cost_per_100_requests' => $requests > 0 ? AiBalanceBook::amount((int) round($usageNanos / $requests * 100)) : null];
    }

    public function index(Request $request, AiBalanceBook $book): array
    {
        $this->admin($request);
        $book->ready();
        $data = $request->validate(['q' => 'sometimes|nullable|string|max:200', 'page' => 'sometimes|integer|min:1', 'per_page' => ['sometimes', 'integer', Rule::in([10, 25, 50, 100])]]);

        // A consistent snapshot keeps the balance, totals and ledger in sync.
        return DB::transaction(function () use ($data) {
            $account = DB::table('ai_balance_accounts')->where('id', 1)->lockForUpdate()->first();
            $query = DB::table('ai_balance_entries');
            if (filled($data['q'] ?? null)) {
                $term = '%'.$data['q'].'%';
                $query->where(fn ($q) => $q->where('description', 'like', $term)->orWhere('reference', 'like', $term)->orWhere('model', 'like', $term)->orWhere('run_id', 'like', $term));
            }
            $perPage = (int) ($data['per_page'] ?? 25);
            $total = $query->count();
            $lastPage = max(1, (int) ceil($total / $perPage));
            $page = min((int) ($data['page'] ?? 1), $lastPage);
            $entries = $query->orderByDesc('id')->offset(($page - 1) * $perPage)->limit($perPage)->get();
            // All-time usage stays independent of ledger search and pagination.
            $usage = $this->usageSummary();

            return ['currency' => 'USD', 'balance' => AiBalanceBook::amount((int) ($account->balance_nanos ?? 0)),
                'can_use_ai' => ($account->balance_nanos ?? 0) > 0,
                'total_top_ups' => AiBalanceBook::amount((int) DB::table('ai_balance_entries')->where('type', 'top_up')->sum('amount_nanos')),
                'total_usage' => $usage['total_usage'],
                'total_requests' => $usage['total_requests'],
                'average_cost_per_100_requests' => $usage['average_cost_per_100_requests'],
                'items' => $entries->map(fn ($row) => $this->serialize($row)), 'total' => $total, 'page' => $page, 'last_page' => $lastPage, 'per_page' => $perPage];
        });
    }

    public function topUp(Request $request, AiBalanceBook $book): array
    {
        $admin = $this->admin($request);
        $data = $request->validate(['request_key' => 'required|uuid', 'amount' => ['required', 'string', 'regex:/^\d{1,7}(\.\d{1,6})?$/', 'numeric', 'min:0.000001', 'max:1000000'],
            'payment_provider' => 'nullable|string|max:255', 'description' => 'required|string|max:255', 'reference' => 'nullable|string|max:120']);
        $data['description'] = trim($data['description']);
        abort_if($data['description'] === '', 422, 'Enter a description for this top-up.');
        $row = $book->topUp($admin, $data);

        return ['entry' => $this->serialize($row)];
    }
}
