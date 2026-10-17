<?php

namespace App\Http\Controllers\Office;

use App\Http\Controllers\Controller;
use App\Services\ShopReviews;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ProductReviewController extends Controller
{
    protected bool $shopReviews = false;
    private function query()
    {
        return DB::table('shop_reviews as r')->join('businesses as b', 'b.id', '=', 'r.business_id')
            ->join('platform_customers as c', 'c.id', '=', 'r.platform_customer_id')
            ->leftJoin('storefront_settings as sf', 'sf.business_id', '=', 'r.business_id')
            ->leftJoin('products as p', function ($join) { $join->on('p.id', '=', 'r.target_id')->on('p.business_id', '=', 'r.business_id'); })
            ->where('r.target_id', $this->shopReviews ? '=' : '>', 0);
    }

    private function token(object $row): string
    {
        return hash('sha256', json_encode([(int) $row->id, (int) $row->rating, $row->comment, $row->reply, (string) $row->updated_at, (string) $row->replied_at]));
    }

    private function view(object $row): array
    {
        return [
            'id' => (int) $row->id, 'business_id' => (int) $row->business_id, 'product_id' => (int) $row->target_id,
            'customer_id' => (int) $row->platform_customer_id, 'shop_name' => $row->shop_name, 'shop_slug' => $row->shop_slug,
            'product_name' => $this->shopReviews ? null : ($row->product_name ?? 'Deleted product #'.$row->target_id), 'customer_name' => $row->customer_name,
            'customer_email' => $row->customer_email, 'rating' => (int) $row->rating, 'comment' => $row->comment, 'reply' => $row->reply,
            'created_at' => Carbon::parse($row->created_at)->utc()->toISOString(), 'updated_at' => Carbon::parse($row->updated_at)->utc()->toISOString(),
            'replied_at' => $row->replied_at ? Carbon::parse($row->replied_at)->utc()->toISOString() : null, 'version' => $this->token($row),
        ];
    }

    private function columns(): array
    {
        return ['r.*', DB::raw('COALESCE(sf.display_name, b.name) as shop_name'), 'sf.slug as shop_slug', 'p.name as product_name', 'c.name as customer_name', 'c.email as customer_email'];
    }

    public function index(Request $request): array
    {
        $data = $request->validate([
            'q' => ['nullable', 'string', 'max:200'], 'business_id' => ['nullable', 'integer', 'min:1'], 'rating' => ['nullable', 'integer', 'between:1,5'],
            'reply_status' => ['sometimes', Rule::in(['all', 'replied', 'unreplied'])], 'content' => ['sometimes', Rule::in(['all', 'written', 'rating_only'])],
            'from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d'],
            'sort' => ['sometimes', Rule::in(['newest', 'oldest', 'rating_high', 'rating_low'])],
            'page' => ['sometimes', 'integer', 'min:1'], 'per_page' => ['sometimes', 'integer', Rule::in([10, 25, 50, 100])],
        ]);
        if (!empty($data['from']) && !empty($data['to']) && $data['to'] < $data['from']) throw ValidationException::withMessages(['to' => 'End date must be on or after start date.']);
        $query = $this->query();
        if ($search = trim($data['q'] ?? '')) $query->where(function ($filter) use ($search) {
            foreach (['b.name', 'sf.display_name', 'sf.slug', 'p.name', 'c.name', 'c.email', 'r.comment', 'r.reply'] as $column) $filter->orWhere($column, 'like', '%'.$search.'%');
        });
        if (!empty($data['business_id'])) $query->where('r.business_id', $data['business_id']);
        if (!empty($data['rating'])) $query->where('r.rating', $data['rating']);
        if (($data['reply_status'] ?? 'all') === 'replied') $query->whereNotNull('r.reply');
        if (($data['reply_status'] ?? 'all') === 'unreplied') $query->whereNull('r.reply');
        if (($data['content'] ?? 'all') === 'written') $query->whereNotNull('r.comment');
        if (($data['content'] ?? 'all') === 'rating_only') $query->whereNull('r.comment');
        if (!empty($data['from'])) $query->whereDate('r.created_at', '>=', $data['from']);
        if (!empty($data['to'])) $query->whereDate('r.created_at', '<=', $data['to']);
        $total = (clone $query)->count(); $perPage = (int) ($data['per_page'] ?? 25);
        $lastPage = max(1, (int) ceil($total / $perPage)); $page = min((int) ($data['page'] ?? 1), $lastPage);
        $sort = $data['sort'] ?? 'newest';
        if (in_array($sort, ['rating_high', 'rating_low'])) $query->orderBy('r.rating', $sort === 'rating_high' ? 'desc' : 'asc');
        $direction = $sort === 'oldest' ? 'asc' : 'desc';
        $items = $query->select($this->columns())->orderBy('r.created_at', $direction)->orderBy('r.id', $direction)->offset(($page - 1) * $perPage)->limit($perPage)->get()->map(fn ($row) => $this->view($row));
        return ['items' => $items, 'total' => $total, 'page' => $page, 'per_page' => $perPage, 'last_page' => $lastPage];
    }

    public function show(int $review): array
    {
        $row = $this->query()->where('r.id', $review)->select($this->columns())->first();
        abort_unless($row, 404, ($this->shopReviews ? 'Shop review not found.' : 'Product review not found.'));
        return $this->view($row);
    }

    public function options(Request $request): array
    {
        $data = $request->validate([
            'kind' => ['required', Rule::in($this->shopReviews ? ['shops', 'customers'] : ['shops', 'products', 'customers'])], 'q' => ['nullable', 'string', 'max:200'],
            'business_id' => ['required_if:kind,products,customers', 'nullable', 'integer', 'min:1'],
            'product_id' => $this->shopReviews ? ['prohibited'] : ['required_if:kind,customers', 'nullable', 'integer', 'min:1'],
            'selected_id' => ['nullable', 'integer', 'min:1'],
        ]);
        if ($data['kind'] === 'shops') {
            $query = DB::table('businesses as b')->leftJoin('storefront_settings as sf', 'sf.business_id', '=', 'b.id')
                ->select('b.id', DB::raw('COALESCE(sf.display_name, b.name) as label'), 'sf.slug as detail');
            $columns = ['b.name', 'sf.display_name', 'sf.slug']; $id = 'b.id';
        } elseif ($data['kind'] === 'products') {
            $query = DB::table('products as p')->where('p.business_id', $data['business_id'])->select('p.id', 'p.name as label');
            $columns = ['p.name']; $id = 'p.id';
        } else {
            if (!$this->shopReviews) abort_unless(DB::table('products')->where('id', $data['product_id'])->where('business_id', $data['business_id'])->exists(), 422, 'Choose a product belonging to this shop.');
            $query = DB::table('platform_customers as c')->where('c.is_active', true)->whereExists(function ($orders) use ($data) {
                $orders->selectRaw('1')->from('ecommerce_orders as o')->where('o.business_id', $data['business_id'])->whereColumn('o.platform_customer_id', 'c.id')->where('o.status', 'completed');
                if (!$this->shopReviews) $orders->whereExists(function ($items) use ($data) { $items->selectRaw('1')->from('ecommerce_order_items as i')->whereColumn('i.order_id', 'o.id')->where('i.business_id', $data['business_id'])->where('i.product_id', $data['product_id']); });
            })->select('c.id', 'c.name as label', 'c.email as detail');
            $columns = ['c.name', 'c.email']; $id = 'c.id';
        }
        $selected = !empty($data['selected_id']) ? (clone $query)->where($id, $data['selected_id'])->first() : null;
        if ($q = trim($data['q'] ?? '')) $query->where(function ($filter) use ($q, $columns) { foreach ($columns as $column) $filter->orWhere($column, 'like', '%'.$q.'%'); });
        $rows = $query->orderBy('label')->orderBy($id)->limit(26)->get();
        return ['items' => $rows->take(25)->values(), 'has_more' => $rows->count() > 25, 'selected' => $selected];
    }

    private function content(Request $request): array
    {
        $data = $request->validate(['rating' => ['required', 'integer', 'between:1,5'], 'comment' => ['nullable', 'string', 'max:2000'], 'reply' => ['nullable', 'string', 'max:2000']]);
        foreach (['comment', 'reply'] as $key) { $value = trim($data[$key] ?? ''); $data[$key] = $value === '' ? null : $value; }
        return $data;
    }

    public function store(Request $request): array
    {
        $identity = $request->validate(['business_id' => ['required', 'integer', 'exists:businesses,id'], 'product_id' => $this->shopReviews ? ['prohibited'] : ['required', 'integer', 'min:1'], 'customer_id' => ['required', 'integer', 'exists:platform_customers,id']]);
        $data = $this->content($request);
        $target = $this->shopReviews ? 0 : $identity['product_id'];
        $id = DB::transaction(function () use ($identity, $data, $target) {
            $customer = DB::table('platform_customers')->where('id', $identity['customer_id'])->lockForUpdate()->first();
            abort_unless($customer && $customer->is_active, 422, 'Choose an active customer.');
            if (!$this->shopReviews) abort_unless(DB::table('products')->where('id', $target)->where('business_id', $identity['business_id'])->exists(), 422, 'Choose a product belonging to this shop.');
            abort_unless(app(ShopReviews::class)->eligible($identity['business_id'], $identity['customer_id'], $target), 422, $this->shopReviews ? 'This customer needs a completed purchase from this shop.' : 'This customer needs a completed purchase of this product.');
            abort_if(DB::table('shop_reviews')->where('business_id', $identity['business_id'])->where('target_id', $target)->where('platform_customer_id', $identity['customer_id'])->exists(), 409, $this->shopReviews ? 'This customer already reviewed this shop. Edit the existing review.' : 'This customer already reviewed this product. Edit the existing review.');
            return DB::table('shop_reviews')->insertGetId($data + ['business_id' => $identity['business_id'], 'target_id' => $target, 'platform_customer_id' => $identity['customer_id'], 'replied_at' => $data['reply'] !== null ? now() : null, 'created_at' => now(), 'updated_at' => now()]);
        });
        return $this->show($id);
    }

    public function update(Request $request, int $review): array
    {
        $version = $request->validate(['version' => ['required', 'string', 'size:64']])['version'];
        $data = $this->content($request);
        DB::transaction(function () use ($review, $version, $data) {
            $row = DB::table('shop_reviews')->where('id', $review)->where('target_id', $this->shopReviews ? '=' : '>', 0)->lockForUpdate()->first();
            abort_unless($row, 404, ($this->shopReviews ? 'Shop review not found.' : 'Product review not found.'));
            abort_if(!hash_equals($this->token($row), $version), 409, 'This review changed. Refresh before saving.');
            $repliedAt = $data['reply'] === $row->reply ? $row->replied_at : ($data['reply'] !== null ? now() : null);
            DB::table('shop_reviews')->where('id', $review)->update($data + ['replied_at' => $repliedAt, 'updated_at' => now()]);
        });
        return $this->show($review);
    }

    public function destroy(Request $request, int $review): array
    {
        $version = $request->validate(['version' => ['required', 'string', 'size:64']])['version'];
        DB::transaction(function () use ($review, $version) {
            $row = DB::table('shop_reviews')->where('id', $review)->where('target_id', $this->shopReviews ? '=' : '>', 0)->lockForUpdate()->first();
            abort_unless($row, 404, ($this->shopReviews ? 'Shop review not found.' : 'Product review not found.'));
            abort_if(!hash_equals($this->token($row), $version), 409, 'This review changed. Refresh before deleting.');
            DB::table('shop_reviews')->where('id', $review)->delete();
        });
        return ['ok' => true];
    }
}
