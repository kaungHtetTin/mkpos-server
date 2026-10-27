<?php

namespace App\Http\Controllers\Office;

use App\Http\Controllers\Controller;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class FinancialBookController extends Controller
{
    private const TYPES = ['income', 'expense', 'contribution', 'payout', 'profit_allocation', 'transfer', 'opening_balance'];

    // Integer minor units keep allocations and comparisons exact to two decimals.
    private function minor($value): int
    {
        $negative = str_starts_with((string) $value, '-');
        $parts = explode('.', ltrim((string) $value, '-'));
        $minor = (int) $parts[0] * 100 + (int) str_pad(substr($parts[1] ?? '', 0, 2), 2, '0');
        return $negative ? -$minor : $minor;
    }

    private function amount(int $minor): string
    {
        return ($minor < 0 ? '-' : '').intdiv(abs($minor), 100).'.'.str_pad((string) (abs($minor) % 100), 2, '0', STR_PAD_LEFT);
    }

    private function founders($settings): array
    {
        return DB::table('office_founders')->orderBy('id')->get()->map(fn ($row) => array_merge((array) $row, ['is_active' => (bool) $row->is_active]))->all();
    }

    private function totals(string $currency): array
    {
        $entries = DB::table('office_book_entries')->where('currency', $currency)->whereNull('voided_at');
        $sums = (clone $entries)->selectRaw('type, SUM(amount) as total')->groupBy('type')->pluck('total', 'type');
        $subscription = $this->minor(DB::table('subscription_payments')->where('currency', $currency)->sum('amount'));
        $income = $subscription + $this->minor($sums['income'] ?? 0);
        $expenses = $this->minor($sums['expense'] ?? 0);
        $allocated = array_fill_keys(DB::table('office_founders')->pluck('id')->all(), 0); $paidAllocations = $allocated;
        foreach ((clone $entries)->where('type', 'profit_allocation')->get(['allocation', 'account_id']) as $entry) {
            foreach (json_decode($entry->allocation, true) as $share) {
                $amount = $this->minor($share['amount']);
                $allocated[$share['id']] = ($allocated[$share['id']] ?? 0) + $amount;
                // Older allocations had no bank book and remain unpaid entitlements.
                if ($entry->account_id) $paidAllocations[$share['id']] = ($paidAllocations[$share['id']] ?? 0) + $amount;
            }
        }
        $payouts = (clone $entries)->where('type', 'payout')->selectRaw('founder_id, SUM(amount) as total')->groupBy('founder_id')->pluck('total', 'founder_id');
        $contributions = (clone $entries)->where('type', 'contribution')->selectRaw('founder_id, SUM(amount) as total')->groupBy('founder_id')->pluck('total', 'founder_id');
        return [
            'subscription_income' => $subscription, 'income' => $income, 'expenses' => $expenses, 'profit' => $income - $expenses,
            'allocated' => array_sum($allocated), 'unallocated' => $income - $expenses - array_sum($allocated),
            'opening_adjustments' => $this->minor($sums['opening_balance'] ?? 0),
            'cash' => $this->minor($sums['opening_balance'] ?? 0) + $income + $this->minor($sums['contribution'] ?? 0) - $expenses - $this->minor($sums['payout'] ?? 0) - array_sum($paidAllocations),
            'founders' => array_map(fn ($id) => ['id' => $id, 'allocated' => $allocated[$id], 'paid' => $this->minor($payouts[$id] ?? 0) + $paidAllocations[$id], 'balance' => $allocated[$id] - $this->minor($payouts[$id] ?? 0) - $paidAllocations[$id], 'contributed' => $this->minor($contributions[$id] ?? 0)], array_keys($allocated)),
        ];
    }

    private function bankBalances(string $currency): array
    {
        $balances = array_fill_keys(DB::table('office_bank_books')->pluck('id')->all(), 0);
        foreach (DB::table('subscription_payments')->where('currency', $currency)->selectRaw('COALESCE(account_id, 2) as bank_id, SUM(amount) as total')->groupByRaw('COALESCE(account_id, 2)')->get() as $receipt) $balances[$receipt->bank_id] = ($balances[$receipt->bank_id] ?? 0) + $this->minor($receipt->total);
        $unassigned = 0; $unassignedCount = 0;
        $opening = array_fill_keys(array_keys($balances), 0); $openingCount = $opening;
        $groups = DB::table('office_book_entries')->where('currency', $currency)->whereNull('voided_at')->where(function ($query) { $query->where('type', '!=', 'profit_allocation')->orWhereNotNull('account_id'); })
            ->selectRaw('account_id, to_account_id, type, SUM(amount) as total, COUNT(*) as count')->groupBy('account_id', 'to_account_id', 'type')->get();
        foreach ($groups as $group) {
            $amount = $this->minor($group->total);
            $movement = in_array($group->type, ['income', 'contribution', 'opening_balance']) ? $amount : -$amount;
            if ($group->account_id) $balances[$group->account_id] += $movement;
            else { $unassigned += $movement; $unassignedCount += $group->count; }
            if ($group->type === 'opening_balance') { $opening[$group->account_id] += $amount; $openingCount[$group->account_id] += $group->count; }
            if ($group->type === 'transfer') $balances[$group->to_account_id] += $amount;
        }
        return ['balances' => $balances, 'unassigned' => $unassigned, 'unassigned_count' => $unassignedCount, 'opening' => $opening, 'opening_count' => $openingCount];
    }

    public function index(Request $request): array
    {
        $data = $request->validate([
            'currency' => ['sometimes', 'required', 'string', 'max:20'], 'q' => ['nullable', 'string', 'max:200'],
            'type' => ['sometimes', Rule::in(['all', 'subscription_income', ...self::TYPES])],
            'status' => ['sometimes', Rule::in(['active', 'voided', 'all'])],
            'founder_id' => ['nullable', 'integer', Rule::exists('office_founders', 'id')],
            'account_id' => ['nullable', 'integer', Rule::exists('office_bank_books', 'id')],
            'from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d'],
            'page' => ['sometimes', 'integer', 'min:1'], 'per_page' => ['sometimes', 'integer', Rule::in([10, 25, 50, 100])],
        ]);
        if (!empty($data['from']) && !empty($data['to']) && $data['to'] < $data['from']) throw ValidationException::withMessages(['to' => 'End date must be on or after start date.']);
        $currency = $data['currency'] ?? 'Ks';
        $manual = DB::table('office_book_entries as e')->leftJoin('platform_admins as a', 'a.id', '=', 'e.created_by_admin_id')
            ->leftJoin('platform_admins as v', 'v.id', '=', 'e.voided_by_admin_id')
            ->leftJoin('office_bank_books as ba', 'ba.id', '=', 'e.account_id')->leftJoin('office_bank_books as bt', 'bt.id', '=', 'e.to_account_id')
            ->leftJoin('platform_admins as assigner', 'assigner.id', '=', 'e.bank_assigned_by_admin_id')->where('e.currency', $currency)
            ->selectRaw("e.id, 'manual' as source, e.type, e.entry_date, e.currency, e.amount, e.founder_id, e.description, e.category, e.reference, e.counterparty, e.payment_method, e.allocation, a.name as created_by_name, e.created_at, e.voided_at, e.void_reason, v.name as voided_by_name, e.account_id, e.to_account_id, ba.name as account_name, bt.name as to_account_name, e.bank_assigned_at, assigner.name as bank_assigned_by_name");
        $receipts = DB::table('subscription_payments as p')->leftJoin('businesses as b', 'b.id', '=', 'p.business_id')->leftJoin('platform_admins as a', 'a.id', '=', 'p.created_by_admin_id')->leftJoin('office_bank_books as pb', 'pb.id', '=', 'p.account_id')->where('p.currency', $currency)
            ->selectRaw("p.id, 'subscription' as source, 'subscription_income' as type, DATE(p.paid_at) as entry_date, p.currency, p.amount, NULL as founder_id, CONCAT('Subscription payment #', p.id) as description, 'Subscription' as category, NULL as reference, b.name as counterparty, NULL as payment_method, NULL as allocation, a.name as created_by_name, p.created_at, NULL as voided_at, NULL as void_reason, NULL as voided_by_name, COALESCE(p.account_id, 2) as account_id, NULL as to_account_id, COALESCE(pb.name, 'Min Htet Kyaw') as account_name, NULL as to_account_name, NULL as bank_assigned_at, NULL as bank_assigned_by_name");
        $query = DB::query()->fromSub($manual->unionAll($receipts), 'book');
        if ($q = trim($data['q'] ?? '')) $query->where(function ($filter) use ($q) {
            foreach (['description', 'category', 'reference', 'counterparty', 'created_by_name'] as $column) $filter->orWhere($column, 'like', '%'.$q.'%');
        });
        if (!empty($data['account_id'])) $query->where(function ($filter) use ($data) { $filter->where('account_id', $data['account_id'])->orWhere('to_account_id', $data['account_id']); });
        if (($data['type'] ?? 'all') !== 'all') $query->where('type', $data['type']);
        if (!empty($data['founder_id'])) $query->where(function ($filter) use ($data) { $filter->where('founder_id', $data['founder_id'])->orWhere('type', 'profit_allocation'); });
        if (!empty($data['from'])) $query->where('entry_date', '>=', $data['from']);
        if (!empty($data['to'])) $query->where('entry_date', '<=', $data['to']);
        $status = $data['status'] ?? 'active';
        if ($status === 'active') $query->whereNull('voided_at');
        if ($status === 'voided') $query->whereNotNull('voided_at');
        $total = (clone $query)->count(); $perPage = (int) ($data['per_page'] ?? 25);
        $lastPage = max(1, (int) ceil($total / $perPage)); $page = min((int) ($data['page'] ?? 1), $lastPage);
        $items = $query->orderByDesc('entry_date')->orderByDesc('created_at')->orderByDesc('id')->orderBy('source')->offset(($page - 1) * $perPage)->limit($perPage)->get()->map(function ($item) {
            foreach (['id', 'founder_id', 'account_id', 'to_account_id'] as $field) $item->$field = $item->$field === null ? null : (int) $item->$field;
            $item->amount = $this->amount($this->minor($item->amount));
            $item->allocation = $item->allocation ? json_decode($item->allocation, true) : null;
            foreach (['created_at', 'voided_at', 'bank_assigned_at'] as $field) $item->$field = $item->$field ? Carbon::parse($item->$field)->utc()->toISOString() : null;
            return $item;
        });
        $settings = DB::table('office_book_settings')->where('id', 1)->first();
        $totals = $this->totals($currency);
        $founders = $this->founders($settings);
        foreach ($founders as $index => &$founder) foreach (['allocated', 'paid', 'balance', 'contributed'] as $key) $founder[$key] = $this->amount($totals['founders'][$index][$key]);
        unset($founder);
        $summary = []; foreach ($totals as $key => $value) if ($key !== 'founders') $summary[$key] = $this->amount($value);
        $currencies = DB::table('office_book_entries')->select('currency')->union(DB::table('subscription_payments')->select('currency'))->pluck('currency')->push($currency)->unique()->sort()->values();
        $bankState = $this->bankBalances($currency);
        $bank_books = DB::table('office_bank_books')->orderBy('id')->get()->map(fn ($bank) => ['id' => $bank->id, 'name' => $bank->name, 'is_active' => (bool) $bank->is_active, 'is_subscription_default' => $bank->id === $settings->subscription_account_id, 'balance' => $this->amount($bankState['balances'][$bank->id]), 'opening_adjustments' => $this->amount($bankState['opening'][$bank->id]), 'opening_count' => $bankState['opening_count'][$bank->id]]);
        return compact('items', 'total', 'page', 'currency', 'currencies', 'summary', 'founders', 'bank_books') + ['per_page' => $perPage, 'last_page' => $lastPage, 'settings_version' => $settings->version, 'subscription_account_id' => $settings->subscription_account_id, 'unassigned_bank_balance' => $this->amount($bankState['unassigned']), 'unassigned_bank_count' => $bankState['unassigned_count']];
    }

    public function updateFounders(Request $request): array
    {
        $data = $request->validate([
            'founders' => ['required', 'array', 'min:1', 'max:100'],
            'founders.*.id' => ['nullable', 'integer', 'distinct', Rule::exists('office_founders', 'id')],
            'founders.*.name' => ['required', 'string', 'max:120'],
            'founders.*.share_bps' => ['required', 'integer', 'between:0,10000'], 'version' => ['required', 'integer', 'min:1'],
        ]);
        if (count($data['founders']) === 2 && !array_key_exists('id', $data['founders'][0]) && !array_key_exists('id', $data['founders'][1])) {
            $activeIds = DB::table('office_founders')->where('is_active', true)->orderBy('id')->pluck('id');
            if ($activeIds->count() === 2) foreach ($data['founders'] as $i => &$founder) $founder['id'] = $activeIds[$i];
            unset($founder);
        }
        if (array_sum(array_column($data['founders'], 'share_bps')) !== 10000) throw ValidationException::withMessages(['founders' => 'Founder shares must total 100%.']);
        DB::transaction(function () use ($data) {
            $before = DB::table('office_book_settings')->where('id', 1)->lockForUpdate()->first();
            abort_if($before->version !== $data['version'], 409, 'Founder settings changed. Refresh and try again.');
            $oldFounders = DB::table('office_founders')->orderBy('id')->get();
            DB::table('office_founders')->update(['is_active' => false, 'share_bps' => 0, 'updated_at' => now()]);
            foreach ($data['founders'] as $founder) {
                $values = ['name' => trim($founder['name']), 'share_bps' => $founder['share_bps'], 'is_active' => true, 'updated_at' => now()];
                if (!empty($founder['id'])) DB::table('office_founders')->where('id', $founder['id'])->update($values);
                else DB::table('office_founders')->insert($values + ['created_at' => now()]);
            }
            DB::table('office_book_settings')->where('id', 1)->update(['version' => $before->version + 1, 'updated_at' => now()]);
            DB::table('office_book_settings_audits')->insert(['before' => $oldFounders->toJson(), 'after' => json_encode($data['founders']), 'created_by_admin_id' => Auth::guard('office')->id(), 'created_at' => now()]);
        });
        return ['ok' => true];
    }

    public function store(Request $request): array
    {
        $opening = $request->input('type') === 'opening_balance';
        $data = $request->validate([
            'request_key' => ['required', 'uuid'], 'type' => ['required', Rule::in(self::TYPES)],
            'entry_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'currency' => ['required', 'string', 'max:20'], 'amount' => ['required', 'numeric', $opening ? 'min:-999999999999.99' : 'min:0.01', 'max:999999999999.99', $opening ? 'regex:/^-?\d+(\.\d{1,2})?$/' : 'regex:/^\d+(\.\d{1,2})?$/'],
            'description' => ['required', 'string', 'max:500'], 'category' => ['nullable', 'string', 'max:100'],
            'payment_provider' => ['nullable', 'string', 'max:255'], 'reference' => ['nullable', 'string', 'max:200'], 'counterparty' => ['nullable', 'string', 'max:200'], 'payment_method' => ['nullable', 'string', 'max:100'],
            'founder_id' => ['required_if:type,payout,contribution', 'nullable', 'integer', Rule::exists('office_founders', 'id')],
            'account_id' => ['required', 'integer', Rule::exists('office_bank_books', 'id')->where('is_active', true)],
            'to_account_id' => ['required_if:type,transfer', 'nullable', 'integer', 'different:account_id', Rule::exists('office_bank_books', 'id')->where('is_active', true)],
            'expected_bank_balance' => ['required_if:type,opening_balance', 'nullable', 'numeric', 'regex:/^-?\d+(\.\d{1,2})?$/'],
            'settings_version' => ['required_if:type,profit_allocation', 'nullable', 'integer', 'min:1'],
        ]);
        $hash = hash('sha256', json_encode($data));
        $id = DB::transaction(function () use ($data, $hash) {
            $settings = DB::table('office_book_settings')->where('id', 1)->lockForUpdate()->first();
            $existing = DB::table('office_book_entries')->where('request_key', $data['request_key'])->first();
            if ($existing) {
                abort_if($existing->request_hash !== $hash || $existing->created_by_admin_id !== Auth::guard('office')->id(), 409, 'This request key was already used.');
                return $existing->id;
            }
            foreach (['account_id', 'to_account_id'] as $key) if (!empty($data[$key])) abort_unless(DB::table('office_bank_books')->where('id', $data[$key])->where('is_active', true)->exists(), 422, 'Choose an active bank book.');
            $amount = $this->minor($data['amount']); $totals = $this->totals($data['currency']); $allocation = null;
            if ($data['type'] === 'opening_balance') {
                if ($amount === 0) throw ValidationException::withMessages(['amount' => 'Opening adjustment cannot be zero.']);
                $bankState = $this->bankBalances($data['currency']);
                abort_if($this->minor($data['expected_bank_balance']) !== $bankState['balances'][$data['account_id']], 409, 'Bank balance changed. Refresh before recording an opening adjustment.');
            }
            if ($data['type'] === 'profit_allocation') {
                abort_if($settings->version !== $data['settings_version'], 409, 'Founder shares changed. Refresh before allocating profit.');
                if ($data['entry_date'] !== now()->toDateString()) throw ValidationException::withMessages(['entry_date' => "Profit allocations must use today's date."]);
                if ($amount > $totals['unallocated']) throw ValidationException::withMessages(['amount' => 'Amount exceeds unallocated profit.']);
                $allocation = array_values(array_filter($this->founders($settings), fn ($founder) => $founder['is_active'] && $founder['share_bps'] > 0));
                if (array_sum(array_column($allocation, 'share_bps')) !== 10000) throw ValidationException::withMessages(['founders' => 'Active founder shares must total 100%.']);
                $remaining = $amount;
                foreach ($allocation as $index => &$share) {
                    $part = $index === count($allocation) - 1 ? $remaining : intdiv($amount, 10000) * $share['share_bps'] + intdiv(($amount % 10000) * $share['share_bps'], 10000);
                    $share['amount'] = $this->amount($part); $remaining -= $part;
                }
                unset($share);
            }
            if (in_array($data['type'], ['payout', 'transfer', 'profit_allocation'])) {
                $bankState = $this->bankBalances($data['currency']);
                if ($amount > $bankState['balances'][$data['account_id']]) throw ValidationException::withMessages(['amount' => 'Amount exceeds the source bank book balance.']);
            }
            if (in_array($data['type'], ['payout', 'profit_allocation']) && $amount > $totals['cash']) throw ValidationException::withMessages(['amount' => 'Amount exceeds the recorded cash balance.']);
            if ($data['type'] === 'payout') {
                $balance = collect($totals['founders'])->firstWhere('id', $data['founder_id'])['balance'];
                if ($amount > $balance) throw ValidationException::withMessages(['amount' => "Amount exceeds this founder's unpaid allocated profit."]);
                if ($amount > $totals['cash']) throw ValidationException::withMessages(['amount' => 'Amount exceeds the recorded cash balance.']);
            }
            $provider = \App\Services\BankProviders::canonical($data['payment_provider'] ?? $data['payment_method'] ?? '');
            $identity = app(\App\Services\ShopPaymentReceiptReview::class)->transactionIdentity($provider, (string) ($data['reference'] ?? ''));
            if ($identity['transaction_key']) app(\App\Services\PaymentReceiptClaims::class)->assertAvailable($provider, $data['reference']);
            $entryId = DB::table('office_book_entries')->insertGetId([
                ...\App\Services\BankProviders::columns('office_book_entries', $provider),
                ...(\Illuminate\Support\Facades\Schema::hasColumn('office_book_entries', 'transaction_key') ? ['transaction_key' => $identity['transaction_key']] : []),
                'request_key' => $data['request_key'], 'request_hash' => $hash, 'type' => $data['type'], 'entry_date' => $data['entry_date'],
                'currency' => $data['currency'], 'amount' => $this->amount($amount), 'description' => trim($data['description']),
                'founder_id' => in_array($data['type'], ['payout', 'contribution']) ? $data['founder_id'] : null,
                'account_id' => $data['account_id'],
                'to_account_id' => $data['type'] === 'transfer' ? $data['to_account_id'] : null,
                'category' => $data['category'] ?? null, 'reference' => $data['reference'] ?? null, 'counterparty' => $data['counterparty'] ?? null, 'payment_method' => $data['payment_method'] ?? null,
                'allocation' => $allocation ? json_encode($allocation) : null, 'created_by_admin_id' => Auth::guard('office')->id(), 'created_at' => now(), 'updated_at' => now(),
            ]);
            if ($identity['transaction_key']) app(\App\Services\PaymentReceiptClaims::class)->claim($identity['transaction_key'], 'office_book', $entryId, $provider, $data['reference']);
            return $entryId;
        });
        return ['id' => $id];
    }

    public function void(Request $request, int $entry): array
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']]);
        DB::transaction(function () use ($entry, $data) {
            DB::table('office_book_settings')->where('id', 1)->lockForUpdate()->first();
            $row = DB::table('office_book_entries')->where('id', $entry)->lockForUpdate()->first();
            abort_unless($row, 404, 'Book entry not found.');
            abort_if($row->voided_at, 409, 'This entry is already voided.');
            if ($row->type === 'transfer') {
                $bankState = $this->bankBalances($row->currency);
                if ($bankState['balances'][$row->to_account_id] < $this->minor($row->amount)) throw ValidationException::withMessages(['reason' => 'The receiving bank book no longer has enough balance to reverse this transfer.']);
            }
            if ($row->type === 'profit_allocation' && !$row->account_id) {
                $totals = $this->totals($row->currency);
                foreach (json_decode($row->allocation, true) as $share) {
                    if (collect($totals['founders'])->firstWhere('id', $share['id'])['balance'] < $this->minor($share['amount'])) throw ValidationException::withMessages(['reason' => 'Void the related founder payouts before voiding this allocation.']);
                }
            }
            DB::table('office_book_entries')->where('id', $entry)->update(['voided_at' => now(), 'voided_by_admin_id' => Auth::guard('office')->id(), 'void_reason' => trim($data['reason']), 'updated_at' => now()]);
        });
        return ['ok' => true];
    }
    public function assignBank(Request $request, int $entry): array
    {
        $data = $request->validate(['account_id' => ['required', 'integer', Rule::exists('office_bank_books', 'id')]]);
        DB::transaction(function () use ($data, $entry) {
            DB::table('office_book_settings')->where('id', 1)->lockForUpdate()->first();
            $row = DB::table('office_book_entries')->where('id', $entry)->lockForUpdate()->first();
            abort_unless($row, 404, 'Book entry not found.');
            abort_if($row->voided_at || $row->account_id || in_array($row->type, ['profit_allocation', 'transfer']), 409, 'Only an active, unassigned cash entry can be assigned.');
            DB::table('office_book_entries')->where('id', $entry)->update(['account_id' => $data['account_id'], 'bank_assigned_at' => now(), 'bank_assigned_by_admin_id' => Auth::guard('office')->id(), 'updated_at' => now()]);
        });
        return ['ok' => true];
    }

}
