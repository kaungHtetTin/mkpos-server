<?php

namespace App\Http\Controllers\Office;

use App\Http\Controllers\Controller;
use App\Services\MarketplaceTaxonomy;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class MarketplaceProductController extends Controller
{
    private function query()
    {
        return DB::table('products as p')->join('businesses as b', 'b.id', '=', 'p.business_id')
            ->leftJoin('storefront_settings as sf', 'sf.business_id', '=', 'p.business_id')
            ->leftJoin('ecommerce_product_settings as ep', fn ($join) => $join->on('ep.product_id', '=', 'p.id')->on('ep.business_id', '=', 'p.business_id'))
            ->leftJoin('marketplace_product_categories as mc', fn ($join) => $join->on('mc.product_id', '=', 'p.id')->on('mc.business_id', '=', 'p.business_id'));
    }

    private function columns(): array
    {
        return ['p.id', 'p.business_id', 'p.name', 'p.sku', 'p.barcode', 'p.category as business_category', 'p.price', 'p.stock', 'p.is_active',
            'b.currency', 'b.status as business_status', DB::raw('COALESCE(sf.display_name, b.name) as shop_name'), 'sf.slug as shop_slug',
            'ep.published', 'mc.category_id', DB::raw('COALESCE(mc.version, 0) as link_version')];
    }

    private function present(object $row, $categories): array
    {
        $result = (array) $row;
        foreach (['id', 'business_id', 'price', 'link_version'] as $key) $result[$key] = (int) $result[$key];
        $result['is_active'] = (bool) $row->is_active; $result['published'] = (bool) $row->published;
        $result['marketplace_category'] = $row->category_id ? $categories->get($row->category_id) : null;
        $result['link_status'] = !$row->category_id ? 'unlinked' : ($result['marketplace_category']['effective_active'] ? 'linked' : 'needs_review');
        unset($result['category_id']);
        return $result;
    }

    public function index(Request $request): array
    {
        $data = $request->validate(['q' => ['nullable', 'string', 'max:200'], 'business_id' => ['nullable', 'integer', 'min:1'],
            'business_category' => ['nullable', 'string', 'max:255'], 'category_id' => ['nullable', 'integer', 'min:1'],
            'link_status' => ['nullable', Rule::in(['all', 'unlinked', 'linked', 'needs_review'])],
            'publication' => ['nullable', Rule::in(['all', 'published', 'unpublished'])], 'status' => ['nullable', Rule::in(['all', 'active', 'inactive'])],
            'page' => ['nullable', 'integer', 'min:1'], 'per_page' => ['nullable', 'integer', Rule::in([10, 25, 50, 100])]]);
        $categories = app(MarketplaceTaxonomy::class)->categories();
        $active = $categories->filter(fn ($row) => $row['effective_active'])->keys()->all();
        $query = $this->query();
        if ($q = trim($data['q'] ?? '')) $query->where(function ($builder) use ($q) {
            foreach (['p.name', 'p.sku', 'p.barcode', 'p.category', 'b.name', 'sf.display_name', 'sf.slug'] as $column) $builder->orWhere($column, 'like', '%'.$q.'%');
        });
        if (!empty($data['business_id'])) $query->where('p.business_id', $data['business_id']);
        if (($data['business_category'] ?? '') !== '') $query->where('p.category', $data['business_category']);
        if (!empty($data['category_id'])) {
            // Filtering a parent includes all its descendants.
            $ids = [(int) $data['category_id']]; $visited = [];
            for ($i = 0; $i < count($ids); $i++) {
                $id = $ids[$i]; if (isset($visited[$id])) continue; $visited[$id] = true;
                foreach ($categories as $category) if ($category['parent_id'] === $id && !isset($visited[$category['id']])) $ids[] = $category['id'];
            }
            $query->whereIn('mc.category_id', array_keys($visited));
        }
        if (in_array($data['status'] ?? 'all', ['active', 'inactive'])) $query->where('p.is_active', $data['status'] === 'active');
        if (($data['publication'] ?? 'all') === 'published') $query->where('ep.published', true);
        if (($data['publication'] ?? 'all') === 'unpublished') $query->where(fn ($builder) => $builder->whereNull('ep.published')->orWhere('ep.published', false));
        $summary = ['total' => (clone $query)->count(), 'unlinked' => (clone $query)->whereNull('mc.category_id')->count(),
            'linked' => (clone $query)->whereIn('mc.category_id', $active)->count(),
            'needs_review' => (clone $query)->whereNotNull('mc.category_id')->whereNotIn('mc.category_id', $active)->count()];
        switch ($data['link_status'] ?? 'all') {
            case 'unlinked': $query->whereNull('mc.category_id'); break;
            case 'linked': $query->whereIn('mc.category_id', $active); break;
            case 'needs_review': $query->whereNotNull('mc.category_id')->whereNotIn('mc.category_id', $active); break;
        }
        $total = (clone $query)->count(); $perPage = (int) ($data['per_page'] ?? 25);
        $lastPage = max(1, (int) ceil($total / $perPage)); $page = min((int) ($data['page'] ?? 1), $lastPage);
        return ['items' => $query->select($this->columns())->orderBy('p.name')->orderBy('p.id')->offset(($page - 1) * $perPage)->limit($perPage)->get()->map(fn ($row) => $this->present($row, $categories)),
            'total' => $total, 'page' => $page, 'per_page' => $perPage, 'last_page' => $lastPage, 'summary' => $summary];
    }

    public function show(int $id): array
    {
        $row = $this->query()->where('p.id', $id)->select($this->columns())->first();
        abort_unless($row, 404, 'Product not found.');
        $history = DB::table('marketplace_product_category_audits as a')->leftJoin('platform_admins as admin', 'admin.id', '=', 'a.admin_id')->leftJoin('users as merchant', 'merchant.id', '=', 'a.user_id')
            ->where('a.product_id', $id)->where('a.business_id', $row->business_id)->orderByDesc('a.id')->limit(20)
            ->get(['a.id', 'a.before', 'a.after', 'a.created_at', 'admin.name as admin_name', 'merchant.name as user_name'])->map(function ($audit) {
                $audit->before = json_decode($audit->before ?? 'null', true); $audit->after = json_decode($audit->after ?? 'null', true);
                $audit->created_at = \Carbon\Carbon::parse($audit->created_at)->utc()->toISOString(); return $audit;
            });
        return $this->present($row, app(MarketplaceTaxonomy::class)->categories()) + ['history' => $history];
    }

    public function shops(Request $request): array
    {
        $data = $request->validate(['q' => ['nullable', 'string', 'max:200'], 'page' => ['nullable', 'integer', 'min:1']]);
        $query = DB::table('businesses as b')->leftJoin('storefront_settings as sf', 'sf.business_id', '=', 'b.id');
        if ($q = trim($data['q'] ?? '')) $query->where(fn ($builder) => $builder->where('b.name', 'like', '%'.$q.'%')->orWhere('sf.display_name', 'like', '%'.$q.'%')->orWhere('sf.slug', 'like', '%'.$q.'%'));
        $page = (int) ($data['page'] ?? 1);
        $items = $query->select('b.id', DB::raw('COALESCE(sf.display_name, b.name) as path'))->orderBy('path')->orderBy('b.id')->offset(($page - 1) * 50)->limit(51)->get();
        return ['items' => $items->take(50)->values(), 'has_more' => $items->count() > 50];
    }

    public function link(Request $request): array
    {
        $data = $request->validate(['category_id' => ['present', 'nullable', 'integer', 'min:1'],
            'products' => ['required', 'array', 'min:1', 'max:100'], 'products.*.id' => ['required', 'integer', 'min:1', 'distinct'],
            'products.*.version' => ['required', 'integer', 'min:0']]);
        return DB::transaction(function () use ($request, $data) {
            DB::table('marketplace_category_locks')->where('id', 1)->lockForUpdate()->first();
            $categories = app(MarketplaceTaxonomy::class)->categories();
            $category = $data['category_id'] ? $categories->get($data['category_id']) : null;
            abort_if($data['category_id'] && (!$category || !$category['effective_active']), 422, 'Choose an active marketplace category with active parent categories.');
            $ids = array_column($data['products'], 'id');
            $products = DB::table('products')->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            abort_unless($products->count() === count($ids), 409, 'A selected product was deleted. Close the form and refresh the list.');
            $links = DB::table('marketplace_product_categories')->whereIn('product_id', $ids)->get()->keyBy('product_id');
            foreach ($data['products'] as $item) {
                $existing = $links->get($item['id']);
                abort_unless((int) ($existing->version ?? 0) === (int) $item['version'], 409, 'A selected product link changed. Close the form and refresh the list before trying again.');
            }
            $changed = 0;
            foreach ($products as $product) {
                $existing = $links->get($product->id);
                if (($existing?->category_id ? (int) $existing->category_id : null) === ($category['id'] ?? null)) continue;
                $before = $existing?->category_id ? $categories->get($existing->category_id) : null;
                $values = ['business_id' => $product->business_id, 'category_id' => $category['id'] ?? null,
                    'version' => (int) ($existing->version ?? 0) + 1, 'updated_by_admin_id' => $request->user('office')->id, 'updated_by_user_id' => null, 'updated_at' => now()];
                if ($existing) DB::table('marketplace_product_categories')->where('product_id', $product->id)->update($values);
                else DB::table('marketplace_product_categories')->insert($values + ['product_id' => $product->id, 'created_at' => now()]);
                DB::table('marketplace_product_category_audits')->insert(['product_id' => $product->id, 'business_id' => $product->business_id,
                    'admin_id' => $request->user('office')->id, 'before' => $before ? json_encode($before) : null,
                    'after' => $category ? json_encode($category) : null, 'created_at' => now()]);
                $changed++;
            }
            return ['changed' => $changed, 'items' => $this->query()->whereIn('p.id', $ids)->select($this->columns())->get()->map(fn ($row) => $this->present($row, $categories))];
        }, 3);
    }
}
