<?php

namespace App\Http\Controllers\Ecommerce;

use App\Http\Controllers\Controller;
use App\Services\EcommerceService;
use App\Services\ShopReviews;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Carbon\CarbonImmutable;

class ReviewController extends Controller
{
    public function __construct(private EcommerceService $commerce, private ShopReviews $reviews) {}

    private function target(string $slug, int $id): object
    {
        $store = $this->commerce->store($slug);
        if ($id) abort_unless($this->commerce->catalogQuery($store)->where('p.id', $id)->exists(), 404, 'Product unavailable.');
        return $store;
    }

    public function index(Request $request, string $slug, int $id = 0): array
    {
        $store = $this->target($slug, $id);
        $limit = max(1, min(48, (int) $request->query('limit', 12)));
        $offset = max(0, (int) $request->query('offset', 0));
        $rows = $this->reviews->query($store->business_id, $id)->join('platform_customers as c', 'c.id', '=', 'shop_reviews.platform_customer_id')
            ->orderByDesc('shop_reviews.id')->offset($offset)->limit($limit + 1)->get(['shop_reviews.*', 'c.name as customer_name']);
        return ['items' => $rows->take($limit)->map(fn ($row) => $this->reviews->view($row))->values(),
            'has_more' => $rows->count() > $limit, 'summary' => $this->reviews->summary($store->business_id, $id)];
    }

    public function mine(Request $request, string $slug, int $id = 0): array
    {
        $store = $this->target($slug, $id);
        $customer = $request->attributes->get('platform_customer');
        $row = $this->reviews->query($store->business_id, $id)->where('platform_customer_id', $customer->id)->first();
        if ($row) $row->customer_name = $customer->name;
        return ['review' => $row ? $this->reviews->view($row) : null,
            'can_review' => $this->reviews->eligible($store->business_id, $customer->id, $id)];
    }

    public function save(Request $request, string $slug, int $id = 0): array
    {
        $store = $this->target($slug, $id);
        $customer = $request->attributes->get('platform_customer');
        $data = $request->validate(['rating' => ['required', 'integer', 'between:1,5'], 'comment' => ['nullable', 'string', 'max:2000']]);
        abort_unless($this->reviews->eligible($store->business_id, $customer->id, $id), 403, 'Complete a purchase before reviewing this shop or product.');
        DB::transaction(function () use ($store, $customer, $id, $data) {
            // Serialize submissions from one customer, including their first review.
            DB::table('platform_customers')->where('id', $customer->id)->lockForUpdate()->first();
            $query = $this->reviews->query($store->business_id, $id)->where('platform_customer_id', $customer->id);
            $comment = trim($data['comment'] ?? '');
            $values = ['rating' => $data['rating'], 'comment' => $comment === '' ? null : $comment, 'updated_at' => now()];
            if ($query->exists()) $query->update($values);
            else DB::table('shop_reviews')->insert($values + ['business_id' => $store->business_id, 'target_id' => $id,
                'platform_customer_id' => $customer->id, 'created_at' => now()]);
        });
        return $this->mine($request, $slug, $id) + ['summary' => $this->reviews->summary($store->business_id, $id)];
    }

    public function destroy(Request $request, string $slug, int $id = 0): array
    {
        $store = $this->target($slug, $id);
        $this->reviews->query($store->business_id, $id)->where('platform_customer_id', $request->attributes->get('platform_customer')->id)->delete();
        return ['ok' => true, 'summary' => $this->reviews->summary($store->business_id, $id)];
    }

    public function adminShow(int $id): array
    {
        $rows = $this->adminIndex(new Request(['limit' => 1]), $id)['items'];
        abort_unless($rows->isNotEmpty(), 404, 'Review unavailable.');
        return $rows->first();
    }

    public function adminIndex(Request $request, ?int $reviewId = null): array
    {
        $businessId = app(\App\Tenancy\TenantContext::class)->requireId();
        $rules = ['type' => ['nullable', 'in:shop,product'], 'q' => ['nullable', 'string', 'max:120'],
            'rating' => ['nullable', 'integer', 'between:1,5'], 'reply_status' => ['nullable', 'in:replied,unreplied'],
            'content' => ['nullable', 'in:written,rating_only'], 'sort' => ['nullable', 'in:newest,oldest,rating_high,rating_low'],
            'date_from' => ['nullable', 'date_format:Y-m-d'], 'date_to' => ['nullable', 'date_format:Y-m-d']];
        if ($request->filled('date_from')) $rules['date_to'][] = 'after_or_equal:date_from';
        $data = $request->validate($rules);
        $query = DB::table('shop_reviews as r')->join('platform_customers as c', 'c.id', '=', 'r.platform_customer_id')
            ->leftJoin('products as p', function ($join) { $join->on('p.id', '=', 'r.target_id')->on('p.business_id', '=', 'r.business_id'); })
            ->where('r.business_id', $businessId);
        if ($reviewId !== null) $query->where('r.id', $reviewId);
        if (($data['type'] ?? '') === 'shop') $query->where('r.target_id', 0);
        if (($data['type'] ?? '') === 'product') $query->where('r.target_id', '>', 0);
        if (! empty($data['rating'])) $query->where('r.rating', $data['rating']);
        if (($data['reply_status'] ?? '') === 'replied') $query->whereNotNull('r.reply');
        if (($data['reply_status'] ?? '') === 'unreplied') $query->whereNull('r.reply');
        if (($data['content'] ?? '') === 'written') $query->whereNotNull('r.comment');
        if (($data['content'] ?? '') === 'rating_only') $query->whereNull('r.comment');
        if (! empty($data['date_from']) || ! empty($data['date_to'])) {
            $timezone = DB::table('businesses')->where('id', $businessId)->value('timezone') ?: 'Asia/Rangoon';
            if (! empty($data['date_from'])) $query->where('r.created_at', '>=', \App\Support\AppTime::day($data['date_from'], $timezone));
            if (! empty($data['date_to'])) $query->where('r.created_at', '<', \App\Support\AppTime::storage(CarbonImmutable::createFromFormat('!Y-m-d', $data['date_to'], $timezone)->addDay()));
        }
        if ($search = trim($data['q'] ?? '')) $query->where(function ($q) use ($search) {
            $q->where('c.name', 'like', '%'.$search.'%')->orWhere('p.name', 'like', '%'.$search.'%')->orWhere('r.comment', 'like', '%'.$search.'%');
        });
        $total = (clone $query)->count();
        $limit = max(1, min(50, (int) $request->query('limit', 20)));
        $sort = $data['sort'] ?? 'newest';
        if ($sort === 'rating_high' || $sort === 'rating_low') $query->orderBy('r.rating', $sort === 'rating_high' ? 'desc' : 'asc');
        $direction = $sort === 'oldest' ? 'asc' : 'desc';
        $rows = $query->orderBy('r.created_at', $direction)->orderBy('r.id', $direction)
            ->offset(max(0, (int) $request->query('offset', 0)))->limit($limit)->get(['r.*', 'c.name as customer_name', 'p.name as product_name', 'p.base_unit as product_unit', 'p.photo_path']);
        return ['items' => $rows->map(fn ($row) => $this->reviews->view($row) + ['product_name' => $row->product_name, 'product_unit' => $row->product_unit,
            'photo_url' => $row->photo_path ? '/shop-admin/reviews/'.$row->id.'/photo' : null])->values(), 'total' => $total];
    }

    public function adminPhoto(int $id)
    {
        $businessId = app(\App\Tenancy\TenantContext::class)->requireId();
        $product = DB::table('shop_reviews as r')->join('products as p', fn ($join) => $join->on('p.id', '=', 'r.target_id')->on('p.business_id', '=', 'r.business_id'))
            ->where('r.business_id', $businessId)->where('r.id', $id)->first(['p.photo_path']);
        abort_unless($product && $product->photo_path && Storage::disk('local')->exists($product->photo_path), 404, 'Product photo not found.');
        return response()->file(Storage::disk('local')->path($product->photo_path), ['Cache-Control' => 'private, no-cache', 'X-Content-Type-Options' => 'nosniff']);
    }

    public function reply(Request $request, int $id): array
    {
        $data = $request->validate(['reply' => ['nullable', 'string', 'max:2000']]);
        $query = DB::table('shop_reviews')->where('business_id', app(\App\Tenancy\TenantContext::class)->requireId())->where('id', $id);
        abort_unless($query->exists(), 404);
        $reply = trim($data['reply'] ?? '');
        $reply = $reply === '' ? null : $reply;
        $query->update(['reply' => $reply, 'replied_at' => $reply ? now() : null]);
        return ['ok' => true, 'reply' => $reply, 'replied_at' => $reply ? now()->toDateTimeString() : null];
    }
}
