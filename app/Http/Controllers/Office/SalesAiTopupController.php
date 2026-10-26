<?php

namespace App\Http\Controllers\Office;

use App\Http\Controllers\Controller;
use App\Services\SalesAi\Topups;
use App\Services\SalesAi\Wallet;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class SalesAiTopupController extends Controller
{
    public function show(int $id): array
    {
        $row = DB::table('sales_ai_topups as t')->join('businesses as b', 'b.id', '=', 't.business_id')
            ->leftJoin('platform_admins as a', 'a.id', '=', 't.reviewed_by_admin_id')
            ->where('t.id', $id)->select('t.*', 'b.name as business_name', 'a.name as reviewed_by_name')->firstOrFail();

        return ['request' => app(Topups::class)->present($row)];
    }

    public function adjustWallet(Request $request, int $businessId): array
    {
        $amount = ['required', 'numeric', 'min:0', 'max:9000000000', 'regex:/^\d{1,10}(?:\.\d{1,6})?$/'];
        $data = $request->validate(['balance' => $amount, 'expected_balance' => $amount, 'request_key' => ['required', 'uuid'], 'reason' => ['required', 'string', 'max:255', 'regex:/\S/u']]);
        return ['wallet' => app(Wallet::class)->adjust($businessId, (int) $request->user('office')->id, $data)];
    }

    public function wallet(Request $request, int $businessId): array
    {
        abort_unless(DB::table('businesses')->where('id', $businessId)->exists(), 404);
        $data = $request->validate(['page' => ['sometimes', 'integer', 'min:1'], 'per_page' => ['sometimes', 'integer', Rule::in([10, 25, 50, 100])]]);
        $query = DB::table('sales_ai_entries')->where('business_id', $businessId);
        $total = (clone $query)->count(); $per = $data['per_page'] ?? 25; $last = max(1, (int) ceil($total / $per)); $page = min($data['page'] ?? 1, $last);
        $rows = $query->orderByDesc('id')->offset(($page - 1) * $per)->limit($per)->get();
        return ['wallet' => app(Wallet::class)->view($businessId), 'total' => $total, 'page' => $page, 'last_page' => $last, 'per_page' => $per,
            'items' => $rows->map(fn ($row) => ['id' => (int) $row->id, 'type' => $row->type, 'amount' => \App\Services\SalesAi\Pricing::amount((int) $row->amount_micros),
                'balance_after' => \App\Services\SalesAi\Pricing::amount((int) $row->balance_after_micros), 'description' => $row->description, 'created_at' => \App\Support\AppTime::utc($row->created_at)])];
    }

    public function index(Request $request): array
    {
        $data = $request->validate(['business_id' => ['sometimes', 'integer', 'min:1'], 'status' => ['nullable', Rule::in(['pending', 'approved', 'rejected', 'cancelled'])], 'q' => ['nullable', 'string', 'max:120'],
            'page' => ['sometimes', 'integer', 'min:1'], 'per_page' => ['sometimes', 'integer', Rule::in([10, 25, 50, 100])]]);
        $query = DB::table('sales_ai_topups as t')->join('businesses as b', 'b.id', '=', 't.business_id')
            ->when(isset($data['business_id']), fn ($q) => $q->where('t.business_id', $data['business_id']))
            ->when(filled($data['status'] ?? null), fn ($q) => $q->where('t.status', $data['status']))
            ->when(filled($data['q'] ?? null), fn ($q) => $q->where(fn ($search) => $search->where('b.name', 'like', '%'.$data['q'].'%')->orWhere('t.transaction_reference', 'like', '%'.$data['q'].'%')));
        $total = (clone $query)->count(); $per = $data['per_page'] ?? 25; $last = max(1, (int) ceil($total / $per)); $page = min($data['page'] ?? 1, $last);
        $rows = $query->select('t.*', 'b.name as business_name')->orderByDesc('t.id')->offset(($page - 1) * $per)->limit($per)->get();
        return ['items' => $rows->map(fn ($row) => app(Topups::class)->present($row)), 'total' => $total, 'page' => $page, 'last_page' => $last, 'per_page' => $per,
            'wallet' => isset($data['business_id']) ? app(Wallet::class)->view($data['business_id']) : null];
    }

    public function review(Request $request, int $id): array
    {
        $data = $request->validate(['decision' => ['required', Rule::in(['approved', 'rejected'])], 'version' => ['required', 'integer', 'min:1'],
            'transaction_reference' => ['required_if:decision,approved', 'nullable', 'string', 'max:120', 'regex:/\S/u'],
            'admin_note' => ['required_if:decision,rejected', 'nullable', 'string', 'max:2000', 'regex:/\S/u']]);
        $row = app(Topups::class)->review($id, $request->user('office'), $data['decision'], $data);
        $row->reviewed_by_name = DB::table('platform_admins')->where('id', $row->reviewed_by_admin_id)->value('name');
        return ['request' => app(Topups::class)->present($row), 'wallet' => app(Wallet::class)->view((int) $row->business_id)];
    }

    public function screenshot(int $id)
    {
        $row = DB::table('sales_ai_topups')->where('id', $id)->firstOrFail();
        abort_unless(Storage::disk('local')->exists($row->screenshot_path), 404, 'Payment proof unavailable.');
        return response()->file(Storage::disk('local')->path($row->screenshot_path), ['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }
}
