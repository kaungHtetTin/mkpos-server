<?php

namespace App\Http\Controllers\Ecommerce;

use App\Http\Controllers\Controller;
use App\Services\SalesAi\Pricing;
use App\Services\SalesAi\Settings;
use App\Services\SalesAi\Topups;
use App\Services\SalesAi\Wallet;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class SalesAiController extends Controller
{
    private function owner(Request $request): object
    {
        $owner = $request->user();
        abort_unless($owner && $owner->role === 'owner', 403, 'Only the business owner can manage Sales AI.');
        return $owner;
    }

    public function show(Request $request): array
    {
        $owner = $this->owner($request);
        return ['settings' => app(Settings::class)->get($owner->business_id), 'tools' => collect(Settings::TOOLS)->map(fn ($description, $name) => ['name' => $name, 'description' => $description])->values(),
            'wallet' => app(Wallet::class)->view($owner->business_id), 'pricing' => ['usd_to_mmk_rate' => app(\App\Services\AiAssistantSettings::class)->get()['usd_to_mmk_rate'], 'markup_percent' => app(\App\Services\AiAssistantSettings::class)->get()['sales_ai_markup_percent']]];
    }

    public function update(Request $request): array
    {
        $owner = $this->owner($request);
        $data = $request->validate(Settings::rules());
        app(Settings::class)->update($owner->business_id, $data);
        return $this->show($request);
    }

    public function wallet(Request $request): array
    {
        $owner = $this->owner($request);
        $data = $request->validate(['limit' => ['sometimes', 'integer', 'min:1', 'max:100'], 'offset' => ['sometimes', 'integer', 'min:0'], 'with_total' => ['sometimes', 'boolean'], 'include_usage' => ['sometimes', 'boolean']]);
        $limit = $data['limit'] ?? 25;
        $query = DB::table('sales_ai_entries')->where('business_id', $owner->business_id);
        $rows = (clone $query)->orderByDesc('id')->offset($data['offset'] ?? 0)->limit($limit + 1)->get();
        return ['wallet' => app(Wallet::class)->view($owner->business_id), 'items' => $rows->take($limit)->map(fn ($row) => ['id' => (int) $row->id, 'type' => $row->type,
            'amount' => Pricing::amount((int) $row->amount_micros), 'balance_after' => Pricing::amount((int) $row->balance_after_micros), 'description' => $row->description,
            'created_at' => \App\Support\AppTime::utc($row->created_at), 'pricing' => $row->pricing ? json_decode($row->pricing, true) : null]), 'has_more' => $rows->count() > $limit]
            + ($request->boolean('with_total') ? ['total' => (clone $query)->count(), 'limit' => (int) $limit, 'offset' => (int) ($data['offset'] ?? 0)] : [])
            + ($request->boolean('include_usage') ? ['usage' => app(\App\Services\SalesAi\WalletUsage::class)->report((int) $owner->business_id, DB::table('businesses')->where('id', $owner->business_id)->value('timezone') ?: 'Asia/Yangon')] : []);
    }

    public function topups(Request $request): array
    {
        $owner = $this->owner($request);
        $data = $request->validate(['limit' => ['sometimes', 'integer', 'min:1', 'max:100'], 'offset' => ['sometimes', 'integer', 'min:0']]);
        $limit = $data['limit'] ?? 25;
        $rows = DB::table('sales_ai_topups')->where('business_id', $owner->business_id)->orderByDesc('id')->offset($data['offset'] ?? 0)->limit($limit + 1)->get();
        return ['items' => $rows->take($limit)->map(fn ($row) => app(Topups::class)->present($row)), 'has_more' => $rows->count() > $limit,
            'payment_methods' => DB::table('payment_methods')->where('is_active', true)->get(['id', 'bank', 'account_no', 'account_name'])];
    }

    public function submit(Request $request)
    {
        $owner = $this->owner($request);
        $data = $request->validate(['request_key' => ['required', 'uuid'], 'amount' => ['required', 'integer', 'min:1', 'max:100000000'], 'payment_method_id' => ['required', 'integer'],
            'transaction_reference' => ['nullable', 'string', 'max:120'], 'message' => ['nullable', 'string', 'max:1000'],
            'payment_screenshot' => ['required', 'image', 'mimes:jpeg,jpg,png,webp', 'max:5120', 'dimensions:max_width=4096,max_height=4096']]);
        $file = $request->file('payment_screenshot');
        $hash = hash_file('sha256', $file->getRealPath());
        $path = $file->store('sales-ai-payment-proofs/'.$owner->business_id, 'local');
        try {
            $row = app(Topups::class)->submit($owner, $data, $path, $hash);
            if ($row->screenshot_path !== $path) Storage::disk('local')->delete($path);
            return response()->json(['request' => app(Topups::class)->present($row)], 201);
        } catch (\Throwable $e) {
            Storage::disk('local')->delete($path);
            throw $e;
        }
    }

    public function detail(Request $request, int $id): array
    {
        return ['request' => app(Topups::class)->present($this->row($request, $id))];
    }

    private function row(Request $request, int $id): object
    {
        $owner = $this->owner($request);
        return DB::table('sales_ai_topups')->where('id', $id)->where('business_id', $owner->business_id)->firstOrFail();
    }

    public function cancel(Request $request, int $id): array
    {
        $row = $this->row($request, $id);
        $data = $request->validate(['version' => ['required', 'integer', 'min:1']]);
        app(Topups::class)->cancel((int) $row->business_id, (int) $row->id, (int) $data['version']);
        return $this->detail($request, $id);
    }

    public function screenshot(Request $request, int $id)
    {
        $row = $this->row($request, $id);
        abort_unless(Storage::disk('local')->exists($row->screenshot_path), 404, 'Payment proof unavailable.');
        return response()->file(Storage::disk('local')->path($row->screenshot_path), ['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }
}
