<?php

namespace App\Services;

use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Read-only evidence checks: errors are rule/data conflicts; warnings need owner judgment. */
class AiPriceChecker
{
    public const ISSUES = ['invalid_price_type', 'duplicate_price_type', 'invalid_pricing_rule', 'unconfigured_pricing_rule',
        'missing_configured_price', 'unconfigured_product_price', 'invalid_selling_price', 'zero_selling_price',
        'invalid_cost', 'missing_cost', 'below_cost', 'automatic_price_mismatch', 'automatic_rule_unavailable', 'primary_price_mismatch'];

    private function table(string $name)
    {
        return DB::table($name)->where('business_id', app(TenantContext::class)->requireId());
    }

    public function run(string $tool, array $args): array
    {
        $limit = $args['limit'] ?? 10;
        $offset = $args['offset'] ?? 0;
        $items = [];
        $summary = ['errors' => 0, 'warnings' => 0, 'by_issue' => []];
        $matched = 0;
        $add = function (array $issue) use (&$items, &$summary, &$matched, $args, $limit, $offset) {
            if (isset($args['price_type']) && strcasecmp((string) ($issue['price_type'] ?? ''), $args['price_type']) !== 0) {
                return;
            }
            if (($args['severity'] ?? 'all') !== 'all' && $args['severity'] !== $issue['severity']) {
                return;
            }
            if (($args['issue'] ?? 'all') !== 'all' && $args['issue'] !== $issue['code']) {
                return;
            }
            $summary[$issue['severity'] === 'error' ? 'errors' : 'warnings']++;
            $summary['by_issue'][$issue['code']] = ($summary['by_issue'][$issue['code']] ?? 0) + 1;
            if ($matched >= $offset && $matched < $offset + $limit) {
                $items[] = $issue;
            }
            $matched++;
        };
        $finding = fn ($code, $severity, $type, $message, $next, $extra = []) => ['code' => $code, 'severity' => $severity, 'price_type' => $type, 'evidence' => $message, 'next_step' => $next] + $extra;
        $includeRules = $tool !== 'check_product_prices' && ($tool === 'check_pricing_rules' || empty($args['after_product_id']));
        $includeProducts = $tool !== 'check_pricing_rules';
        $rawTypes = trim((string) ($this->table('settings')->where('key', 'price_types')->value('value') ?? ''));
        $types = [];
        foreach (explode(',', $rawTypes !== '' ? $rawTypes : 'Retail') as $name) {
            $name = trim($name);
            $key = mb_strtolower($name);
            if ($name === '' || mb_strlen($name) > 50 || preg_match('/[\\\\\/]/', $name)) {
                if ($includeRules) {
                    $add($finding('invalid_price_type', 'error', $name, 'A configured price-type name is empty, too long or contains a slash.', 'Review Settings > Prices.'));
                }

                continue;
            }
            if (isset($types[$key])) {
                if ($includeRules) {
                    $add($finding('duplicate_price_type', 'error', $name, 'The configured price-type list repeats this name ignoring case.', 'Keep one canonical price-type name in Settings > Prices.'));
                }

                continue;
            }
            $types[$key] = $name;
        }
        $storedRules = $this->table('price_type_rules')->orderBy('name')->get();
        $rules = [];
        foreach ($types as $key => $name) {
            $matching = $storedRules->filter(fn ($r) => mb_strtolower($r->name) === $key);
            $rule = $matching->first() ?: (object) ['name' => $name, 'pricing_mode' => 'manual', 'markup_percent' => 0, 'rounding' => 1, 'minimum_profit' => 0];
            $valid = validator((array) $rule, ['pricing_mode' => 'required|in:manual,automatic', 'markup_percent' => 'required|numeric|between:0,1000', 'rounding' => 'required|integer|between:1,100000', 'minimum_profit' => 'required|integer|min:0'])->passes() && $matching->count() <= 1;
            $rules[$key] = ['rule' => $rule, 'valid' => $valid, 'source' => $matching->isEmpty() ? 'default_manual' : 'saved'];
            if (! $valid && $includeRules) {
                $add($finding('invalid_pricing_rule', 'error', $name, 'The saved pricing rule has invalid values or conflicting case variants.', 'Review the pricing mode, markup, rounding and minimum profit in Settings > Prices.'));
            }
        }
        foreach ($storedRules as $rule) {
            if (! isset($types[mb_strtolower($rule->name)]) && $includeRules) {
                $add($finding('unconfigured_pricing_rule', 'warning', $rule->name, 'This saved rule is not in the configured price-type list.', 'Review whether this price type should still be configured.'));
            }
        }
        if (isset($args['price_type']) && ! isset($types[mb_strtolower($args['price_type'])]) && ! $storedRules->contains(fn ($r) => strcasecmp($r->name, $args['price_type']) === 0)
            && ! $this->table('product_prices')->whereRaw('LOWER(name) = ?', [mb_strtolower($args['price_type'])])->exists()) {
            throw ValidationException::withMessages(['price_type' => 'Unknown price type. Read check_pricing_rules first.']);
        }
        // Filter the sink as well as products so rule findings obey the same selected price type.
        $emit = function (array $issue) use ($args, $add) {
            if (isset($args['price_type']) && strcasecmp((string) ($issue['price_type'] ?? ''), $args['price_type']) !== 0) {
                return;
            }
            $add($issue);
        };
        $productsChecked = $manualOverrides = 0;
        $lastId = $args['after_product_id'] ?? 0;
        $scanLimit = $args['scan_limit'] ?? 10000;
        $moreProducts = false;
        if ($includeProducts) {
            $q = $this->table('products')->where('is_active', true)->where('id', '>', $lastId);
            if (isset($args['product_id'])) {
                abort_unless($this->table('products')->where('id', $args['product_id'])->where('is_active', true)->exists(), 404, 'Product unavailable.');
                $q->where('id', $args['product_id']);
            }
            if (isset($args['category'])) {
                $q->where('category', $args['category']);
            }
            if (($search = trim($args['query'] ?? '')) !== '') {
                $q->where(fn ($q) => $q->where('name', 'like', '%'.$search.'%')->orWhere('sku', 'like', '%'.$search.'%')->orWhere('barcode', 'like', '%'.$search.'%'));
            }
            $base = clone $q;
            while ($productsChecked < $scanLimit) {
                $batch = (clone $base)->where('id', '>', $lastId)->orderBy('id')->limit(min(250, $scanLimit - $productsChecked))->get(['id', 'name', 'cost', 'price', 'base_unit']);
                if ($batch->isEmpty()) {
                    break;
                }
                $prices = $this->table('product_prices')->whereIn('product_id', $batch->pluck('id'))->orderBy('id')->get()->groupBy('product_id');
                foreach ($batch as $product) {
                    $productsChecked++;
                    $lastId = (int) $product->id;
                    $rows = $prices->get($product->id, collect());
                    $groups = $rows->groupBy(fn ($p) => mb_strtolower(trim($p->name)));
                    $productFinding = function ($code, $severity, $type, $message, $next, $extra = []) use ($emit, $finding, $product) {
                        $emit($finding($code, $severity, $type, $message, $next, ['product_id' => $product->id, 'product_name' => $product->name, 'base_unit' => $product->base_unit, 'cost' => $product->cost] + $extra));
                    };
                    $validCost = is_numeric($product->cost) && is_finite((float) $product->cost) && $product->cost >= 0;
                    if (! $validCost) {
                        $productFinding('invalid_cost', 'error', null, 'The recorded base-unit cost is invalid.', 'Review product cost before evaluating profit.');
                    } elseif ((float) $product->cost === 0.0) {
                        $productFinding('missing_cost', 'warning', null, 'Recorded cost is zero; this may be intentional, but margin checks are inconclusive.', 'Verify cost; do not assume a zero-cost product is profitable.');
                    }
                    foreach ($types as $key => $name) {
                        if (! $groups->has($key)) {
                            $productFinding('missing_configured_price', 'warning', $name, 'This configured named selling price is not saved on this product.', 'Add the price if this product should use it; otherwise leave it intentionally unavailable.');
                        }
                    }
                    foreach ($groups as $key => $entries) {
                        $entry = $entries->first();
                        if (isset($args['price_type']) && strcasecmp($entry->name, $args['price_type']) !== 0) {
                            continue;
                        }
                        if ($entries->count() > 1) {
                            $productFinding('duplicate_price_type', 'error', $entry->name, 'This product has conflicting case variants of the same named price.', 'Resolve the duplicate before choosing a price.');

                            continue;
                        }
                        if (! isset($types[$key])) {
                            $productFinding('unconfigured_product_price', 'warning', $entry->name, 'This product price is not in the business configured price-type list.', 'Review the product price name and Settings > Prices.');
                        }
                        $validPrice = is_numeric($entry->price) && is_finite((float) $entry->price) && $entry->price >= 0 && floor((float) $entry->price) == $entry->price;
                        if (! $validPrice) {
                            $productFinding('invalid_selling_price', 'error', $entry->name, 'Selling price must be a non-negative whole currency amount.', 'Review this named selling price.', ['current_price' => $entry->price]);

                            continue;
                        }
                        if ((float) $entry->price === 0.0) {
                            $productFinding('zero_selling_price', 'warning', $entry->name, 'This named selling price is zero; free pricing may be intentional.', 'Confirm whether this price should be free or set a selling price.', ['current_price' => 0]);
                        }
                        if ($validCost && $product->cost > 0 && $entry->price < $product->cost) {
                            $productFinding('below_cost', 'warning', $entry->name, 'The base-unit selling price is below the recorded base-unit cost; this is not proof of a pricing mistake.', 'Review whether the lower price is intentional.', ['current_price' => $entry->price, 'difference_from_cost' => $entry->price - $product->cost]);
                        }
                        $saved = $rules[$key] ?? null;
                        if ($saved && $saved['rule']->pricing_mode === 'automatic' && $entry->is_manual) {
                            $manualOverrides++;
                        }
                        if (! $entry->is_manual) {
                            if (! $saved || ! $saved['valid'] || $saved['rule']->pricing_mode !== 'automatic') {
                                $productFinding('automatic_rule_unavailable', 'warning', $entry->name, 'The price is marked automatic, but a valid automatic pricing rule is unavailable.', 'Review the rule or whether this should be a manual price.');
                            } elseif ($validCost) {
                                $expected = ProductPricing::automatic($product->cost, $saved['rule']);
                                if ((int) $entry->price !== $expected) {
                                    $productFinding('automatic_price_mismatch', 'error', $entry->name, 'The saved non-manual price differs from the current MKPOS automatic pricing formula.', 'Review the price and current rule; any correction requires owner confirmation.', ['current_price' => $entry->price, 'expected_price' => $expected, 'difference' => $entry->price - $expected]);
                                }
                            }
                        }
                    }
                    $retail = $groups->get('retail');
                    $primary = $retail ? ($retail->count() === 1 ? $retail->first() : null) : $rows->first();
                    if ($primary && (float) $product->price !== (float) $primary->price) {
                        $productFinding('primary_price_mismatch', 'error', $primary->name, 'The product primary price differs from its Retail price (or first named price when Retail is absent).', 'Review the product primary price and named selling prices.', ['current_price' => $product->price, 'expected_price' => $primary->price]);
                    }
                }
            }
            $moreProducts = (clone $base)->where('id', '>', $lastId)->exists();
        }
        $ruleRows = [];
        foreach ($rules as $key => $saved) {
            if (isset($args['price_type']) && strcasecmp($types[$key], $args['price_type']) !== 0) {
                continue;
            }
            $r = $saved['rule'];
            $ruleRows[] = ['name' => $types[$key], 'pricing_mode' => $r->pricing_mode, 'markup_percent' => $r->markup_percent, 'rounding' => $r->rounding, 'minimum_profit' => $r->minimum_profit, 'valid' => $saved['valid'], 'source' => $saved['source']];
        }

        return ['read_only' => true, 'summary' => $summary + ['total_findings' => $matched, 'products_checked' => $productsChecked, 'manual_overrides' => $manualOverrides],
            'findings' => ['items' => $items, 'total' => $matched, 'has_more' => $offset + count($items) < $matched, 'next_offset' => $offset + count($items) < $matched ? $offset + count($items) : null],
            'rules' => ['items' => array_slice($ruleRows, $tool === 'check_pricing_rules' ? $offset : 0, $limit), 'total' => count($ruleRows), 'truncated' => count($ruleRows) > $limit,
                'next_offset' => $tool === 'check_pricing_rules' && $offset + $limit < count($ruleRows) ? $offset + $limit : null],
            'scan' => ['after_product_id' => $args['after_product_id'] ?? 0, 'last_product_id' => $lastId, 'has_more_products' => $moreProducts, 'next_after_product_id' => $moreProducts ? $lastId : null, 'complete' => ! $moreProducts],
            'basis' => 'Current saved prices and current base-unit costs only; no historical, market-price, coupon or flash-sale verification. Manual overrides are not automatic-rule errors. Warnings require owner judgment; no prices were changed.',
            'source' => ['api_path' => '/api/price-types', 'product_api_path' => '/api/products']];
    }
}
