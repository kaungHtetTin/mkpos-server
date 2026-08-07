<?php

namespace App\Tenancy;

use Illuminate\Database\Query\Builder;

class TenantBuilder extends Builder
{
    private const TENANT_TABLES = [
        'products', 'product_prices', 'price_type_rules', 'customers', 'suppliers',
        'sales', 'sale_items', 'purchases', 'purchase_items', 'customer_payments',
        'expenses', 'settings', 'stock_movements',
    ];

    private ?string $tenantTable = null;

    public function from($table, $as = null)
    {
        parent::from($table, $as);

        if (! is_string($table)) {
            return $this;
        }

        $parts = preg_split('/\s+(?:as\s+)?/i', trim($table));
        $name = $parts[0] ?? '';
        if (! in_array($name, self::TENANT_TABLES, true)) {
            return $this;
        }

        $this->tenantTable = $name;
        $businessId = app(TenantContext::class)->id();
        if ($businessId !== null) {
            $qualifier = $as ?: ($parts[1] ?? $name);
            $this->where($qualifier.'.business_id', $businessId);
        }

        return $this;
    }

    public function insert(array $values)
    {
        return parent::insert($this->withBusinessId($values));
    }

    public function insertGetId(array $values, $sequence = null)
    {
        $businessId = $this->businessIdForInsert();
        if ($businessId !== null) {
            $values['business_id'] = $businessId;
        }

        return parent::insertGetId($values, $sequence);
    }

    public function insertOrIgnore(array $values)
    {
        return parent::insertOrIgnore($this->withBusinessId($values));
    }

    public function upsert(array $values, $uniqueBy, $update = null)
    {
        return parent::upsert($this->withBusinessId($values), $uniqueBy, $update);
    }

    private function withBusinessId(array $values): array
    {
        $businessId = $this->businessIdForInsert();
        if ($businessId === null || $values === []) {
            return $values;
        }

        $isSingleRow = ! is_array(reset($values));
        $rows = $isSingleRow ? [$values] : $values;
        foreach ($rows as &$row) {
            $row['business_id'] = $businessId;
        }

        return $isSingleRow ? $rows[0] : $rows;
    }

    private function businessIdForInsert(): ?int
    {
        return $this->tenantTable === null ? null : app(TenantContext::class)->id();
    }
}
