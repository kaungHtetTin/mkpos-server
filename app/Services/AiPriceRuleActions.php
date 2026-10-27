<?php

namespace App\Services;

use App\Http\Controllers\Api\PriceTypeController;
use App\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Pricing proposals show rule changes and every affected named product price. */
class AiPriceRuleActions
{
    private function table(string $table)
    {
        return DB::table($table)->where('business_id', app(TenantContext::class)->requireId());
    }

    public function read(bool $lock = false): array
    {
        $types = collect(explode(',', $this->table('settings')->where('key', 'price_types')->when($lock, fn ($q) => $q->lockForUpdate())->value('value') ?: 'Retail'))->map('trim')->filter()->values()->all();
        $rules = $this->table('price_type_rules')->orderBy('name')->when($lock, fn ($q) => $q->lockForUpdate())->get()->map(fn ($r) => array_intersect_key((array) $r, array_flip(['name', 'pricing_mode', 'markup_percent', 'rounding', 'minimum_profit'])))->all();

        return ['configured' => $types, 'rules' => $rules, 'markup_basis' => 'Cost markup: cost × (1 + markup_percent / 100), then minimum profit and rounding. Not profit margin.', 'manual_prices' => 'Preserved unless apply_to_existing is explicitly selected.'];
    }

    public function baseline(): array
    {
        return ['configuration' => $this->read(true), 'products' => $this->table('products')->orderBy('id')->lockForUpdate()->get(['id', 'name', 'cost', 'price', 'is_active'])->map(fn ($r) => (array) $r)->all(),
            'prices' => $this->table('product_prices')->orderBy('product_id')->orderBy('name')->lockForUpdate()->get(['product_id', 'name', 'price', 'is_manual'])->map(fn ($r) => (array) $r)->all(),
            'shop' => (array) ($this->table('storefront_settings')->lockForUpdate()->first(['id', 'price_type']) ?? [])];
    }

    public function build(array $input): array
    {
        abort_if(array_diff(array_keys($input), ['changes']), 422, 'Unsupported price-rule arguments.');
        $a = validator($input, ['changes' => 'required|array|min:1|max:20', 'changes.*' => 'array:action,name,new_name,pricing_mode,markup_percent,rounding,minimum_profit,apply_to_existing', 'changes.*.action' => 'required|in:create,update,delete', 'changes.*.name' => 'required|string|max:50', 'changes.*.new_name' => 'sometimes|string|max:50', 'changes.*.pricing_mode' => 'sometimes|in:manual,automatic', 'changes.*.markup_percent' => 'sometimes|numeric|between:0,1000', 'changes.*.rounding' => 'sometimes|integer|between:1,100000', 'changes.*.minimum_profit' => 'sometimes|integer|min:0', 'changes.*.apply_to_existing' => 'sometimes|boolean'])->validate();
        $baseline = $this->baseline();
        $rules = collect($baseline['configuration']['rules'])->keyBy('name')->all();
        $configured = $baseline['configuration']['configured'];
        $prices = $baseline['prices'];
        $products = collect($baseline['products'])->keyBy('id')->all();
        $shop = $baseline['shop'];
        $preview = [];
        $normalized = [];
        $seen = [];
        foreach ($a['changes'] as $change) {
            $name = trim($change['name']);
            $new = trim($change['new_name'] ?? $name);
            foreach ([$name, $new] as $n) {
                abort_if($n === '' || preg_match('/[,\\\\\/]/', $n), 422, 'Price names cannot be empty or contain commas or slashes.');
            }
            abort_if(isset($seen[mb_strtolower($name)]) || isset($seen[mb_strtolower($new)]), 422, 'Change each price type only once per proposal.');
            $seen[mb_strtolower($name)] = true;
            $seen[mb_strtolower($new)] = true;
            $actual = collect(array_unique([...$configured, ...array_keys($rules)]))->first(fn ($n) => strcasecmp($n, $name) === 0);
            $exists = $actual !== null;
            if ($exists) {
                $name = $actual;
            }
            abort_if($change['action'] === 'create' && $exists, 409, 'Price type already exists. Use update.');
            abort_if($change['action'] !== 'create' && ! $exists, 404, 'Price type not found.');
            abort_if(strcasecmp($new, $name) !== 0 && collect(array_unique([...$configured, ...array_keys($rules)]))->contains(fn ($n) => strcasecmp($n, $new) === 0), 409, 'Renamed price type already exists.');
            $old = $rules[$name] ?? ['name' => $name, 'pricing_mode' => 'manual', 'markup_percent' => 0, 'rounding' => 1, 'minimum_profit' => 0];
            if ($change['action'] === 'delete') {
                abort_if(($shop['price_type'] ?? null) === $name, 409, 'Choose a different online-shop price type before deleting this rule.');
                abort_if(count($configured) <= 1, 409, 'At least one price type is required.');
                $inUse = collect($prices)->contains(fn ($p) => $p['name'] === $name && $p['price'] > 0 && ($products[$p['product_id']]['is_active'] ?? false));
                abort_if($inUse, 409, 'This price type is used by active products and cannot be deleted.');
                $after = ['deleted' => true];
                $configured = array_values(array_filter($configured, fn ($n) => $n !== $name));
                unset($rules[$name]);
            } else {
                $after = array_replace($old, array_intersect_key($change, array_flip(['pricing_mode', 'markup_percent', 'rounding', 'minimum_profit'])), ['name' => $new]);
                $after = app(PriceTypeController::class)->validated(Request::create('/', 'POST', $after));
                $configured = array_values(array_unique(array_map(fn ($n) => $n === $name ? $new : $n, $configured)));
                if (! $exists) {
                    $configured[] = $new;
                }
                unset($rules[$name]);
                $rules[$new] = $after;
                if (($shop['price_type'] ?? null) === $name && $new !== $name) {
                    $preview[] = ['name' => 'Online shop price type', 'before' => ['price_type' => $name], 'after' => ['price_type' => $new]];
                    $shop['price_type'] = $new;
                }
            }
            $preview[] = ['name' => 'Price rule: '.$name, 'before' => $exists ? $old : null, 'after' => $after + ['apply_to_existing' => (bool) ($change['apply_to_existing'] ?? false)]];
            foreach ($products as $id => &$product) {
                if (! $product['is_active']) {
                    continue;
                }
                $index = null;
                foreach ($prices as $i => $p) {
                    if ((int) $p['product_id'] === (int) $id && $p['name'] === $name) {
                        $index = $i;
                        break;
                    }
                }
                $before = $index !== null ? $prices[$index] : null;
                $next = $before;
                if ($change['action'] === 'delete') {
                    $next = null;
                    if ($index !== null) {
                        unset($prices[$index]);
                    }
                } else {
                    $apply = ($change['apply_to_existing'] ?? false) && $after['pricing_mode'] === 'automatic' && $product['is_active'];
                    if ($index !== null || $apply) {
                        $next = $before ?? ['product_id' => $id, 'name' => $new, 'price' => 0, 'is_manual' => false];
                        $next['name'] = $new;
                        if ($after['pricing_mode'] === 'manual') {
                            $next['is_manual'] = true;
                        } elseif ($apply) {
                            $next['is_manual'] = false;
                        }
                        if ($after['pricing_mode'] === 'automatic' && ! $next['is_manual']) {
                            $next['price'] = ProductPricing::automatic($product['cost'], (object) $after);
                        }
                        if ($index !== null) {
                            $prices[$index] = $next;
                        } else {
                            $prices[] = $next;
                        }
                        if (strcasecmp($new, 'Retail') === 0 && $after['pricing_mode'] === 'automatic' && ! $next['is_manual']) {
                            $product['price'] = $next['price'];
                        }
                    }
                }
                if ($before != $next) {
                    $preview[] = ['record_id' => $id, 'name' => $product['name'], 'before' => $before ? array_intersect_key($before, array_flip(['name', 'price', 'is_manual'])) : null, 'after' => $next ? array_intersect_key($next, array_flip(['name', 'price', 'is_manual'])) : ['deleted' => true]];
                }
            }unset($product);
            $normalized[] = ['action' => $change['action'], 'name' => $name, 'after' => $after];
        }
        abort_if(count($preview) > 5000, 422, 'This change affects over 5,000 review rows. Narrow the proposal before approving.');

        return ['payload' => ['changes' => $normalized, 'configured' => $configured, 'rules' => array_values($rules), 'prices' => array_values($prices), 'products' => array_values($products), 'shop' => $shop, 'before_shop' => $baseline['shop'], 'before_prices' => $baseline['prices'], 'before_rules' => $baseline['configuration']['rules']], 'baseline' => $baseline, 'preview' => $preview];
    }

    public function execute(array $p): array
    {
        $id = app(TenantContext::class)->requireId();
        $nextRules = collect($p['rules'])->keyBy('name');
        foreach ($p['before_rules'] as $old) {
            if (! $nextRules->has($old['name'])) {
                $this->table('price_type_rules')->where('name', $old['name'])->delete();
            }
        }
        foreach ($p['rules'] as $r) {
            $this->table('price_type_rules')->updateOrInsert(['business_id' => $id, 'name' => $r['name']], [...$r, 'updated_at' => now()]);
        }
        $this->table('settings')->updateOrInsert(['business_id' => $id, 'key' => 'price_types'], ['value' => implode(',', $p['configured'])]);
        if ($p['shop'] !== $p['before_shop']) {
            $this->table('storefront_settings')->where('id', $p['shop']['id'])->update(['price_type' => $p['shop']['price_type'], 'updated_at' => now()]);
        }
        $nextPrices = collect($p['prices'])->keyBy(fn ($r) => $r['product_id'].'|'.$r['name']);
        $oldPrices = collect($p['before_prices'])->keyBy(fn ($r) => $r['product_id'].'|'.$r['name']);
        foreach ($p['before_prices'] as $old) {
            if (! $nextPrices->has($old['product_id'].'|'.$old['name'])) {
                $this->table('product_prices')->where('product_id', $old['product_id'])->where('name', $old['name'])->delete();
            }
        }
        foreach ($p['prices'] as $r) {
            if ($oldPrices->get($r['product_id'].'|'.$r['name']) != $r) {
                $this->table('product_prices')->updateOrInsert(['business_id' => $id, 'product_id' => $r['product_id'], 'name' => $r['name']], ['price' => $r['price'], 'is_manual' => $r['is_manual']]);
            }
        }
        foreach ($p['products'] as $product) {
            $this->table('products')->where('id', $product['id'])->where('price', '!=', $product['price'])->update(['price' => $product['price'], 'updated_at' => now()]);
        }

        return ['updated_price_rules' => count($p['changes']), 'settings' => ['price_types' => implode(',', $p['configured'])], 'modules' => ['settings', 'products', 'sell', 'reports', 'shop']];
    }
}
