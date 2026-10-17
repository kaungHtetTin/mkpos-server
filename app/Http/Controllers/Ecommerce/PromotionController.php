<?php

namespace App\Http\Controllers\Ecommerce;

use App\Http\Controllers\Controller;
use App\Tenancy\TenantContext;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PromotionController extends Controller
{
    public function __construct(private TenantContext $tenant) {}

    public function show(int $id): array
    {
        $result = $this->index(new Request(['limit' => 1]), $id);
        $item = $result['items']->first();
        abort_unless($item, 404, 'Promotion not found.');
        return ['promotion' => $item, 'timezone' => $result['timezone']];
    }

    public function index(Request $request, ?int $promotionId = null): array
    {
        $rules = ['type' => ['nullable', 'in:flash_sale,coupon'], 'q' => ['nullable', 'string', 'max:120'],
            'status' => ['nullable', 'in:all,active,scheduled,expired,paused,exhausted'], 'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
            'offset' => ['nullable', 'integer', 'min:0'], 'sort' => ['nullable', 'in:newest,oldest,name'],
            'date_from' => ['nullable', 'date_format:Y-m-d'], 'date_to' => ['nullable', 'date_format:Y-m-d']];
        if ($request->filled('date_from')) $rules['date_to'][] = 'after_or_equal:date_from';
        $data = $request->validate($rules);
        $businessId = $this->tenant->requireId();
        $timezone = DB::table('businesses')->where('id', $businessId)->value('timezone') ?: 'Asia/Rangoon';
        $query = DB::table('shop_promotions as promotion')->leftJoin('products as product', function ($join) {
            $join->on('product.id', '=', 'promotion.product_id')->on('product.business_id', '=', 'promotion.business_id');
        })->where('promotion.business_id', $businessId)->whereNull('promotion.deleted_at');
        if ($promotionId !== null) $query->where('promotion.id', $promotionId);
        if ($data['type'] ?? '') $query->where('promotion.type', $data['type']);
        if (!empty($data['date_from'])) {
            $start = Carbon::createFromFormat('!Y-m-d', $data['date_from'], $timezone)->utc();
            $query->where(fn ($q) => $q->whereNull('promotion.ends_at')->orWhere('promotion.ends_at', '>', $start));
        }
        if (!empty($data['date_to'])) {
            $end = Carbon::createFromFormat('!Y-m-d', $data['date_to'], $timezone)->addDay()->utc();
            $query->where(fn ($q) => $q->whereNull('promotion.starts_at')->orWhere('promotion.starts_at', '<', $end));
        }
        if ($q = trim($data['q'] ?? '')) $query->where(fn ($query) => $query->where('promotion.name', 'like', '%'.$q.'%')->orWhere('promotion.code', 'like', '%'.$q.'%')->orWhere('product.name', 'like', '%'.$q.'%'));
        $exhausted = "((promotion.type = 'coupon' AND promotion.usage_limit IS NOT NULL AND promotion.used_count >= promotion.usage_limit) OR (promotion.type = 'flash_sale' AND promotion.quantity_limit IS NOT NULL AND promotion.used_quantity >= promotion.quantity_limit))";
        $statusSql = "CASE WHEN promotion.enabled = 0 THEN 'paused' WHEN promotion.ends_at IS NOT NULL AND promotion.ends_at <= ? THEN 'expired' WHEN $exhausted THEN 'exhausted' WHEN promotion.starts_at IS NOT NULL AND promotion.starts_at > ? THEN 'scheduled' ELSE 'active' END";
        if (($data['status'] ?? 'all') !== 'all') $query->whereRaw("($statusSql) = ?", [now()->utc(), now()->utc(), $data['status']]);
        $total = (clone $query)->count();
        if (($data['sort'] ?? 'newest') === 'name') $query->orderBy('promotion.name');
        $rows = $query->select('promotion.*', 'product.name as product_name')->selectRaw("$statusSql as status", [now()->utc(), now()->utc()])
            ->orderBy('promotion.id', ($data['sort'] ?? 'newest') === 'oldest' ? 'asc' : 'desc')->offset($data['offset'] ?? 0)->limit($data['limit'] ?? 25)->get();
        foreach ($rows as $row) {
            foreach (['starts_at', 'ends_at'] as $field) $row->$field = $row->$field ? Carbon::parse($row->$field, 'UTC')->toIso8601String() : null;
            $row->enabled = (bool) $row->enabled;
        }
        return ['items' => $rows, 'total' => $total, 'timezone' => $timezone];
    }

    public function store(Request $request): array { return $this->save($request); }
    public function update(Request $request, int $id): array { return $this->save($request, $id); }

    private function save(Request $request, ?int $id = null): array
    {
        $businessId = $this->tenant->requireId();
        $data = $request->validate([
            'type' => ['required', 'in:flash_sale,coupon'], 'name' => ['required', 'string', 'max:255'],
            'code' => ['nullable', 'string', 'max:40', 'regex:/^[A-Za-z0-9_-]+$/'],
            'product_id' => ['nullable', 'integer', 'min:1'], 'discount_type' => ['required', 'in:percentage,fixed'],
            'product_ids' => [$id ? 'prohibited' : 'nullable', 'array', 'min:1', 'max:100'],
            'product_ids.*' => ['required', 'integer', 'min:1', 'distinct'],
            'items' => [$id ? 'prohibited' : 'nullable', 'array', 'min:1', 'max:100'],
            'items.*' => ['required', 'array:product_id,discount_type,discount_value,quantity_limit'],
            'items.*.product_id' => ['required', 'integer', 'min:1', 'distinct'],
            'items.*.discount_type' => ['required', 'in:percentage,fixed'],
            'items.*.discount_value' => ['required', 'integer', 'min:1', 'max:1000000000'],
            'items.*.quantity_limit' => ['nullable', 'numeric', 'min:0.001', 'max:1000000', 'regex:/^\d+(\.\d{1,3})?$/'],
            'discount_value' => ['required', 'integer', 'min:1', 'max:1000000000'], 'minimum_subtotal' => ['nullable', 'integer', 'min:0', 'max:1000000000'],
            'usage_limit' => ['nullable', 'integer', 'min:1', 'max:1000000'],
            'quantity_limit' => ['nullable', 'numeric', 'min:0.001', 'max:1000000', 'regex:/^\d+(\.\d{1,3})?$/'],
            'starts_at' => ['nullable', 'date_format:Y-m-d\TH:i'], 'ends_at' => ['nullable', 'date_format:Y-m-d\TH:i'],
            'enabled' => ['required', 'boolean'],
        ]);
        $data['name'] = trim($data['name']);
        $data['minimum_subtotal'] = (int) ($data['minimum_subtotal'] ?? 0);
        if (!$data['name']) throw ValidationException::withMessages(['name' => 'Enter a promotion name.']);
        if ($data['discount_type'] === 'percentage' && $data['discount_value'] > 99) throw ValidationException::withMessages(['discount_value' => 'Percentage discounts must be between 1 and 99.']);
        $timezone = DB::table('businesses')->where('id', $businessId)->value('timezone') ?: 'Asia/Rangoon';
        foreach (['starts_at', 'ends_at'] as $field) $data[$field] = empty($data[$field]) ? null : Carbon::createFromFormat('Y-m-d\TH:i', $data[$field], $timezone)->second(0)->utc()->format('Y-m-d H:i:s');
        if ($data['starts_at'] && $data['ends_at'] && $data['ends_at'] <= $data['starts_at']) throw ValidationException::withMessages(['ends_at' => 'End time must be later than start time.']);
        if ($data['type'] === 'coupon' && !empty($data['product_ids'])) throw ValidationException::withMessages(['product_ids' => 'Only flash sales can select multiple products.']);
        if (!empty($data['items']) && ($data['type'] !== 'flash_sale' || !empty($data['product_ids']))) throw ValidationException::withMessages(['items' => 'Use product rows only for flash sales, without product_ids.']);
        $items = $data['items'] ?? array_map(fn ($productId) => ['product_id' => $productId], $data['product_ids'] ?? [$data['product_id'] ?? null]);
        foreach ($items as $index => $item) {
            if (($item['discount_type'] ?? $data['discount_type']) === 'percentage' && ($item['discount_value'] ?? $data['discount_value']) > 99) {
                throw ValidationException::withMessages(["items.$index.discount_value" => 'Percentage discounts must be between 1 and 99.']);
            }
        }
        unset($data['product_ids'], $data['items']);
        return DB::transaction(function () use ($id, $businessId, $data, $items) {
            abort_unless(DB::table('storefront_settings')->where('business_id', $businessId)->lockForUpdate()->first(), 404, 'Shop settings not found.');
            $ids = [];
            foreach ($items as $item) {
                $ids[] = $this->persist($id, $businessId, array_replace($data, $item));
            }
            return ['id' => $ids[0], 'ids' => $ids, 'ok' => true];
        });
    }

    private function persist(?int $id, int $businessId, array $data): int
    {
        $existing = $id ? DB::table('shop_promotions')->where('business_id', $businessId)->where('id', $id)->whereNull('deleted_at')->first() : null;
        abort_if($id && !$existing, 404, 'Promotion not found.');
        if ($existing && $existing->type !== $data['type']) throw ValidationException::withMessages(['type' => 'The promotion type cannot be changed.']);
        if ($data['type'] === 'coupon') {
            $data['code'] = strtoupper(trim($data['code'] ?? ''));
            if (!$data['code']) throw ValidationException::withMessages(['code' => 'Enter a coupon code.']);
            if (DB::table('shop_promotions')->where('business_id', $businessId)->where('code', $data['code'])->when($id, fn ($q) => $q->where('id', '<>', $id))->exists()) throw ValidationException::withMessages(['code' => 'This coupon code has already been used.']);
            $data['product_id'] = null; $data['unit'] = null; $data['quantity_limit'] = null;
            if ($existing && ($data['usage_limit'] ?? null) !== null && $data['usage_limit'] < $existing->used_count) throw ValidationException::withMessages(['usage_limit' => 'The limit cannot be lower than the number already used.']);
        } else {
            $product = DB::table('products as product')->join('ecommerce_product_settings as publication', function ($join) {
                $join->on('publication.product_id', '=', 'product.id')->on('publication.business_id', '=', 'product.business_id');
            })->where('product.business_id', $businessId)->where('product.id', $data['product_id'] ?? 0)->where('product.is_active', true)->first(['product.id', 'product.name', 'publication.unit']);
            if (!$product) throw ValidationException::withMessages(['product_id' => 'Choose a product from this shop’s publication catalog.']);
            $data['unit'] = $product->unit;
            if ($data['enabled']) {
                $conflict = DB::table('shop_promotions')->where('business_id', $businessId)
                    ->where('type', 'flash_sale')->where('product_id', $product->id)
                    ->whereNull('deleted_at')->where('enabled', true)
                    ->when($id, fn ($q) => $q->where('id', '<>', $id))
                    ->when($data['ends_at'], fn ($q, $end) => $q->where(fn ($q) => $q->whereNull('starts_at')->orWhere('starts_at', '<', $end)))
                    ->when($data['starts_at'], fn ($q, $start) => $q->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>', $start)))
                    ->first();
                if ($conflict) throw ValidationException::withMessages(['product_id' => "{$product->name} already belongs to the overlapping flash sale \"{$conflict->name}\". Change the schedule or remove this product."]);
            }
            if ($existing && $existing->used_count && ((int) $existing->product_id !== (int) $product->id || $existing->unit !== $product->unit)) throw ValidationException::withMessages(['product_id' => 'A used flash sale must keep its original product and online unit. Create another sale for a different product or unit.']);
            if ($existing && ($data['quantity_limit'] ?? null) !== null && (float) $data['quantity_limit'] < (float) $existing->used_quantity) throw ValidationException::withMessages(['quantity_limit' => 'The limit cannot be lower than the quantity already used.']);
            $data['code'] = null; $data['usage_limit'] = null; $data['minimum_subtotal'] = 0;
        }
        $data += ['usage_limit' => null, 'quantity_limit' => null, 'minimum_subtotal' => 0];
        if ($id) DB::table('shop_promotions')->where('business_id', $businessId)->where('id', $id)->update($data + ['updated_at' => now()]);
        else $id = DB::table('shop_promotions')->insertGetId($data + ['business_id' => $businessId, 'created_at' => now(), 'updated_at' => now()]);
        return $id;
    }

    public function destroyMany(Request $request): array
    {
        $data = $request->validate(['ids' => ['required', 'array', 'min:1', 'max:1000'],
            'ids.*' => ['required', 'integer', 'min:1', 'distinct']]);
        return DB::transaction(function () use ($data) {
            $businessId = $this->tenant->requireId();
            abort_unless(DB::table('storefront_settings')->where('business_id', $businessId)->lockForUpdate()->first(), 404, 'Shop settings not found.');
            $query = DB::table('shop_promotions')->where('business_id', $businessId)->whereIn('id', $data['ids'])->whereNull('deleted_at');
            if ((clone $query)->count() !== count($data['ids'])) {
                throw ValidationException::withMessages(['ids' => 'Some selected promotions are no longer available. Refresh the list and select them again.']);
            }
            $query->update(['enabled' => false, 'deleted_at' => now(), 'updated_at' => now()]);
            return ['ok' => true, 'deleted_count' => count($data['ids'])];
        });
    }

    public function destroy(int $id): array
    {
        return DB::transaction(function () use ($id) {
            $businessId = $this->tenant->requireId();
            abort_unless(DB::table('storefront_settings')->where('business_id', $businessId)->lockForUpdate()->first(), 404, 'Shop settings not found.');
            $updated = DB::table('shop_promotions')->where('business_id', $businessId)->where('id', $id)->whereNull('deleted_at')
                ->update(['enabled' => false, 'deleted_at' => now(), 'updated_at' => now()]);
            abort_unless($updated, 404, 'Promotion not found.');
            return ['ok' => true];
        });
    }
}
