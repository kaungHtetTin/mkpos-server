<?php

namespace App\Http\Controllers\Ecommerce;

use App\Http\Controllers\Controller;
use App\Services\EcommerceService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class ShopReportController extends Controller
{
    public const REASONS = ['misleading_information', 'payment_issue', 'order_issue', 'unsafe_products', 'abusive_behavior', 'other'];
    private function customer(Request $request): int { return (int) $request->attributes->get('platform_customer')->id; }
    private function customerView(object $row): array
    {
        return collect((array) $row)->only(['id', 'reason', 'details', 'order_reference', 'status', 'office_response', 'created_at', 'updated_at'])->all();
    }
    public function mine(Request $request, string $slug): array
    {
        $store = DB::table('storefront_settings')->where('slug', $slug)->first();
        abort_unless($store, 404);
        $query = DB::table('shop_reports')->where('business_id', $store->business_id)->where('customer_id', $this->customer($request));
        $canReport = true;
        try { app(EcommerceService::class)->store($slug); }
        catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) { if ($e->getStatusCode() !== 404) throw $e; $canReport = false; }
        abort_unless($canReport || (clone $query)->exists(), 404);
        $limit = max(1, min(24, (int) $request->query('limit', 12)));
        $rows = $query->orderByDesc('id')->offset(max(0, (int) $request->query('offset', 0)))->limit($limit + 1)->get();
        return ['shop' => ['slug' => $slug, 'name' => $store->display_name, 'can_report' => $canReport],
            'items' => $rows->take($limit)->map(fn ($r) => $this->customerView($r))->values(), 'has_more' => $rows->count() > $limit];
    }
    public function submit(Request $request, string $slug): array
    {
        $data = $request->validate(['reason' => ['required', 'in:'.implode(',', self::REASONS)], 'details' => ['required', 'string', 'min:10', 'max:2000'],
            'order_reference' => ['nullable', 'string', 'max:40'], 'submission_key' => ['required', 'uuid']]);
        abort_if(mb_strlen(trim($data['details'])) < 10, 422, 'Please explain the issue in at least 10 characters.');
        $customer = $this->customer($request);
        // Resolve previous submissions even after a shop becomes unavailable.
        $store = DB::table('storefront_settings')->where('slug', $slug)->first(); abort_unless($store, 404);
        return DB::transaction(function () use ($customer, $data, $slug, $store) {
            DB::table('platform_customers')->where('id', $customer)->lockForUpdate()->first();
            $previous = DB::table('shop_reports')->where('customer_id', $customer)->where('submission_key', $data['submission_key'])->first();
            if ($previous) { abort_unless((int) $previous->business_id === (int) $store->business_id, 409); return ['report' => $this->customerView($previous), 'created' => false]; }
            app(EcommerceService::class)->store($slug);
            $open = DB::table('shop_reports')->where('open_key', $customer.':'.$store->business_id)->first();
            if ($open) return ['report' => $this->customerView($open), 'created' => false];
            $reference = trim($data['order_reference'] ?? '');
            if ($reference !== '') abort_unless(DB::table('ecommerce_orders')->where('business_id', $store->business_id)->where('platform_customer_id', $customer)->where('reference', $reference)->exists(), 422, 'Use an order reference from your own orders for this shop.');
            $id = DB::table('shop_reports')->insertGetId(['business_id' => $store->business_id, 'customer_id' => $customer, 'reason' => $data['reason'],
                'details' => trim($data['details']), 'order_reference' => $reference ?: null, 'open_key' => $customer.':'.$store->business_id,
                'submission_key' => $data['submission_key'], 'created_at' => now(), 'updated_at' => now()]);
            app(\App\Services\RealtimeActivity::class)->record('office', (int) $store->business_id, 'shop.reported', [
                'title' => 'New shop report', 'message' => $store->display_name, 'target' => 'shop-report', 'entity_id' => (int) $id,
            ], 'shop-report:'.$id);
            return ['report' => $this->customerView(DB::table('shop_reports')->find($id)), 'created' => true];
        });
    }
    private function officeQuery()
    {
        return DB::table('shop_reports as r')->join('storefront_settings as s', 's.business_id', '=', 'r.business_id')
            ->join('platform_customers as c', 'c.id', '=', 'r.customer_id')->select('r.*', 's.slug as shop_slug', 's.display_name as shop_name', 'c.name as customer_name', 'c.email as customer_email');
    }
    public function index(Request $request): array
    {
        $data = $request->validate(['q' => ['nullable', 'string', 'max:120'], 'status' => ['sometimes', 'in:all,open,in_review,resolved,dismissed'],
            'reason' => ['sometimes', 'in:all,'.implode(',', self::REASONS)], 'page' => ['sometimes', 'integer', 'min:1'], 'per_page' => ['sometimes', 'integer', 'in:10,25,50,100']]);
        $query = $this->officeQuery();
        if (($data['status'] ?? 'open') !== 'all') $query->where('r.status', $data['status'] ?? 'open');
        if (($data['reason'] ?? 'all') !== 'all') $query->where('r.reason', $data['reason']);
        if ($search = trim($data['q'] ?? '')) {
            $pattern = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $search).'%';
            $query->where(function ($q) use ($pattern) { foreach (['s.display_name', 's.slug', 'c.name', 'r.order_reference', 'r.details'] as $field) $q->orWhereRaw("$field LIKE ? ESCAPE '!'", [$pattern]); });
        }
        $total = (clone $query)->count(); $perPage = (int) ($data['per_page'] ?? 25); $last = max(1, (int) ceil($total / $perPage)); $page = min((int) ($data['page'] ?? 1), $last);
        $rows = $query->orderByDesc('r.id')->offset(($page - 1) * $perPage)->limit($perPage)->get()->map(fn ($r) => collect((array) $r)->except(['open_key', 'submission_key'])->all());
        return ['items' => $rows, 'total' => $total, 'page' => $page, 'per_page' => $perPage, 'last_page' => $last];
    }
    public function show(int $id): array
    {
        $row = $this->officeQuery()->where('r.id', $id)->first(); abort_unless($row, 404);
        return collect((array) $row)->except(['open_key', 'submission_key'])->all() + ['updates' => DB::table('shop_report_updates')->where('report_id', $id)->orderByDesc('id')->get()];
    }
    public function review(Request $request, int $id): array
    {
        $data = $request->validate(['status' => ['required', 'in:open,in_review,resolved,dismissed'], 'version' => ['required', 'integer', 'min:1'],
            'internal_note' => ['required', 'string', 'min:5', 'max:2000'], 'office_response' => ['nullable', 'string', 'max:2000']]);
        abort_if(mb_strlen(trim($data['internal_note'])) < 5, 422, 'Please enter a review note.');
        abort_if(in_array($data['status'], ['resolved', 'dismissed']) && mb_strlen(trim($data['office_response'] ?? '')) < 5, 422, 'Please provide a response to the customer before closing the report.');
        DB::transaction(function () use ($id, $data) {
            $row = DB::table('shop_reports')->find($id); abort_unless($row, 404);
            DB::table('platform_customers')->where('id', $row->customer_id)->lockForUpdate()->first();
            $row = DB::table('shop_reports')->where('id', $id)->lockForUpdate()->first();
            abort_unless($row && (int) $row->version === (int) $data['version'], 409, 'This report changed. Reload it before saving your review.');
            $key = $row->customer_id.':'.$row->business_id;
            $active = in_array($data['status'], ['open', 'in_review']);
            abort_if($active && DB::table('shop_reports')->where('open_key', $key)->where('id', '<>', $id)->exists(), 409, 'This customer already has another open report for this shop.');
            $response = trim($data['office_response'] ?? '') ?: null;
            DB::table('shop_reports')->where('id', $id)->update(['status' => $data['status'], 'office_response' => $response, 'open_key' => $active ? $key : null, 'version' => $row->version + 1, 'updated_at' => now()]);
            DB::table('shop_report_updates')->insert(['report_id' => $id, 'admin_id' => Auth::guard('office')->id(), 'status' => $data['status'],
                'internal_note' => trim($data['internal_note']), 'office_response' => $response, 'created_at' => now()]);
        });
        return $this->show($id);
    }
}
