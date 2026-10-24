<?php

namespace App\Http\Controllers\Office;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class BankBookController extends Controller
{
    public function index(Request $request): array
    {
        $data = $request->validate(['q' => ['nullable', 'string', 'max:200'], 'page' => ['sometimes', 'integer', 'min:1'], 'per_page' => ['sometimes', Rule::in([10, 25, 50, 100])]]);
        $query = DB::table('office_bank_books')->orderBy('id');
        if ($q = trim($data['q'] ?? '')) $query->where('name', 'like', '%'.$q.'%');
        $result = ['subscription_account_id' => DB::table('office_book_settings')->where('id', 1)->value('subscription_account_id')];
        if (isset($data['per_page'])) {
            $total = (clone $query)->count(); $perPage = (int) $data['per_page'];
            $lastPage = max(1, (int) ceil($total / $perPage)); $page = min((int) ($data['page'] ?? 1), $lastPage);
            $query->offset(($page - 1) * $perPage)->limit($perPage);
            $result += ['total' => $total, 'page' => $page, 'per_page' => $perPage, 'last_page' => $lastPage];
        }
        return $result + ['items' => $query->get()];
    }

    public function store(Request $request): array
    {
        return $this->save($request);
    }

    public function update(Request $request, int $bank): array
    {
        return $this->save($request, $bank);
    }

    private function save(Request $request, ?int $bank = null): array
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:120', Rule::unique('office_bank_books')->ignore($bank)], 'is_active' => ['required', 'boolean']]);
        DB::transaction(function () use ($data, $bank) {
            $settings = DB::table('office_book_settings')->where('id', 1)->lockForUpdate()->first();
            if ($bank) {
                abort_unless(DB::table('office_bank_books')->where('id', $bank)->exists(), 404);
                abort_if(!$data['is_active'] && $settings->subscription_account_id === $bank, 422, 'Choose another subscription bank book before archiving this book.');
                DB::table('office_bank_books')->where('id', $bank)->update($data + ['updated_at' => now()]);
            } else DB::table('office_bank_books')->insert($data + ['created_at' => now(), 'updated_at' => now()]);
        });
        return $this->index(new Request);
    }

    public function destroy(int $bank): array
    {
        DB::transaction(function () use ($bank) {
            $settings = DB::table('office_book_settings')->where('id', 1)->lockForUpdate()->first();
            abort_unless(DB::table('office_bank_books')->where('id', $bank)->exists(), 404);
            abort_if($settings->subscription_account_id === $bank, 422, 'Choose another subscription bank book first.');
            abort_if(DB::table('subscription_payments')->where('account_id', $bank)->exists() || DB::table('office_book_entries')->where('account_id', $bank)->orWhere('to_account_id', $bank)->exists(), 422, 'This book has financial history. Archive it instead.');
            DB::table('office_bank_books')->where('id', $bank)->delete();
        });
        return ['ok' => true];
    }

    public function subscriptionAccount(Request $request): array
    {
        $data = $request->validate(['subscription_account_id' => ['required', 'integer', Rule::exists('office_bank_books', 'id')->where('is_active', true)]]);
        DB::transaction(function () use ($data) {
            DB::table('office_book_settings')->where('id', 1)->lockForUpdate()->first();
            abort_unless(DB::table('office_bank_books')->where('id', $data['subscription_account_id'])->where('is_active', true)->exists(), 422, 'Choose an active bank book.');
            DB::table('office_book_settings')->where('id', 1)->update($data + ['updated_at' => now()]);
        });
        return $this->index(new Request);
    }
}
