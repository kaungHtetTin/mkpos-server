<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PriceTypeController extends ApiController
{
    public function index(): array
    {
        return $this->response();
    }

    public function store(Request $request): array
    {
        $data = $this->validated($request);
        $name = $this->clean($data['name']);
        abort_if(DB::table('price_type_rules')->where('name', $name)->exists(), 409, 'Price type already exists');
        DB::transaction(function () use ($data, $name) {
            DB::table('price_type_rules')->insert($this->rule($data, $name));
            $this->saveConfigured($this->configured()->push($name)->all());
            if (($data['apply_to_existing'] ?? false) && ($data['pricing_mode'] ?? 'manual') === 'automatic') {
                $this->applyAutomatic($name);
            }
        });

        return $this->response();
    }

    public function update(Request $request, string $priceType): array
    {
        $data = $this->validated($request);
        $old = $this->clean($priceType);
        $name = $this->clean($data['name']);
        $current = DB::table('price_type_rules')->where('name', $old)->first();
        abort_if(! $current, 404, 'Price type not found');
        if (strcasecmp($old, $name) !== 0) {
            abort_if(DB::table('price_type_rules')->where('name', $name)->exists(), 409, 'Price type already exists');
        }
        DB::transaction(function () use ($data, $old, $name, $current) {
            DB::table('product_prices')->where('name', $old)->update(['name' => $name]);
            DB::table('price_type_rules')->where('name', $old)->delete();
            $merged = array_merge((array) $current, $data);
            DB::table('price_type_rules')->insert($this->rule($merged, $name));
            $this->saveConfigured($this->configured()->map(fn ($item) => strcasecmp($item, $old) === 0 ? $name : $item)->all());
            if (($merged['pricing_mode'] ?? 'manual') === 'manual') {
                DB::table('product_prices')->where('name', $name)->update(['is_manual' => true]);
            } elseif ($data['apply_to_existing'] ?? false) {
                $this->applyAutomatic($name);
            } else {
                $this->recalculate($name);
            }
        });

        return $this->response();
    }

    public function destroy(string $priceType): array
    {
        $name = $this->clean($priceType);
        $types = $this->configured();
        abort_if(! $types->contains(fn ($item) => strcasecmp($item, $name) === 0), 404, 'Price type not found');
        abort_if($types->count() <= 1, 409, 'At least one price type is required');
        $usage = DB::table('product_prices as pp')->join('products as p', 'p.id', '=', 'pp.product_id')->where('pp.name', $name)->where('p.is_active', true)->where('pp.price', '>', 0)->distinct('pp.product_id')->count('pp.product_id');
        abort_if($usage > 0, 409, "Price type \"{$name}\" is used by {$usage} product(s) and cannot be deleted");
        DB::transaction(function () use ($name, $types) {
        DB::table('product_prices')->where('name', $name)->delete();
        DB::table('price_type_rules')->where('name', $name)->delete();
        $this->saveConfigured($types->reject(fn ($item) => strcasecmp($item, $name) === 0)->values()->all());
        });

        return $this->response();
    }

    private function validated(Request $request): array
    {
        return $request->validate(['name' => ['required', 'string', 'max:50'], 'pricing_mode' => ['nullable', 'in:manual,automatic'], 'markup_percent' => ['nullable', 'numeric', 'between:0,1000'],
            'rounding' => ['nullable', 'integer', 'between:1,100000'], 'minimum_profit' => ['nullable', 'integer', 'min:0'], 'apply_to_existing' => ['nullable', 'boolean']]);
    }

    private function clean(string $name): string
    {
        $name = trim($name);
        abort_if($name === '' || preg_match('/[,\\\\\/]/', $name), 422, 'Price type name cannot be empty or contain commas or slashes');

        return $name;
    }

    private function configured()
    {
        $types = collect(explode(',', $this->setting('price_types', 'Retail')))->map('trim')->filter()->values();

        return $types->isEmpty() ? collect(['Retail']) : $types;
    }

    private function saveConfigured(array $items): void
    {
        DB::table('settings')->updateOrInsert(['key' => 'price_types'], ['value' => implode(',', array_values(array_unique($items)))]);
    }

    private function rule(array $data, string $name): array
    {
        return ['name' => $name, 'pricing_mode' => $data['pricing_mode'] ?? 'manual', 'markup_percent' => $data['markup_percent'] ?? 0,
            'rounding' => max((int) ($data['rounding'] ?? 1), 1), 'minimum_profit' => $data['minimum_profit'] ?? 0, 'updated_at' => now()];
    }

    private function response(): array
    {
        $types = $this->configured();
        foreach ($types as $name) {
            DB::table('price_type_rules')->updateOrInsert(['name' => $name], ['updated_at' => now()]);
        }
        $rules = DB::table('price_type_rules')->whereIn('name', $types)->get()->keyBy('name');
        $usage = [];
        foreach ($types as $name) {
            $usage[$name] = DB::table('product_prices as pp')->join('products as p', 'p.id', '=', 'pp.product_id')->where('pp.name', $name)->where('p.is_active', true)->where('pp.price', '>', 0)->distinct('pp.product_id')->count('pp.product_id');
        }

        return ['items' => $types->all(), 'usage' => $usage, 'rules' => $types->map(function ($name) use ($rules) {
        $rule = $rules[$name];

        return ['name' => $name, 'pricing_mode' => $rule->pricing_mode, 'markup_percent' => (float) $rule->markup_percent, 'rounding' => (int) $rule->rounding, 'minimum_profit' => (int) $rule->minimum_profit];
        })->all()];
    }

    private function applyAutomatic(string $name): void
    {
        foreach (DB::table('products')->where('is_active', true)->get() as $product) {
            DB::table('product_prices')->updateOrInsert(['product_id' => $product->id, 'name' => $name], ['price' => 0, 'is_manual' => false]);
        }
        $this->recalculate($name);
    }

    private function recalculate(string $name): void
    {
        $rule = DB::table('price_type_rules')->where('name', $name)->first();
        if (! $rule || $rule->pricing_mode !== 'automatic') {
            return;
        }
        foreach (DB::table('product_prices as pp')->join('products as p', 'p.id', '=', 'pp.product_id')->where('pp.name', $name)->where('pp.is_manual', false)->select('pp.id', 'pp.product_id', 'p.cost')->get() as $row) {
            $price = max(ceil($row->cost * (1 + $rule->markup_percent / 100)), $row->cost + $rule->minimum_profit);
            $price = (int) (ceil($price / max($rule->rounding, 1)) * max($rule->rounding, 1));
            DB::table('product_prices')->where('id', $row->id)->update(['price' => $price]);
            if (strcasecmp($name, 'Retail') === 0) {
                DB::table('products')->where('id', $row->product_id)->update(['price' => $price]);
            }
        }
    }
}
