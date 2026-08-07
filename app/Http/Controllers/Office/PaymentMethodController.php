<?php

namespace App\Http\Controllers\Office;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PaymentMethodController extends Controller
{
    public function index(): array
    {
        return [
            'items' => DB::table('payment_methods')
                ->orderBy('sort_order')
                ->orderBy('bank')
                ->orderBy('id')
                ->get()
                ->all(),
        ];
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);
        $id = DB::table('payment_methods')->insertGetId(array_merge($data, [
            'created_at' => now(),
            'updated_at' => now(),
        ]));

        return response()->json(DB::table('payment_methods')->find($id), 201);
    }

    public function update(Request $request, int $id): array
    {
        abort_if(! DB::table('payment_methods')->where('id', $id)->exists(), 404, 'Payment method not found');
        DB::table('payment_methods')->where('id', $id)->update(array_merge($this->validated($request), [
            'updated_at' => now(),
        ]));

        return (array) DB::table('payment_methods')->find($id);
    }

    public function destroy(int $id): array
    {
        abort_if(! DB::table('payment_methods')->where('id', $id)->delete(), 404, 'Payment method not found');

        return ['ok' => true];
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'bank' => ['required', 'string', 'max:255'],
            'account_name' => ['required', 'string', 'max:255'],
            'account_no' => ['required', 'string', 'max:255'],
            'is_active' => ['required', 'boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:65535'],
        ]) + ['sort_order' => 0];
    }
}
