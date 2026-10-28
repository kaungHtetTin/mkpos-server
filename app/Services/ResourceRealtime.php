<?php

namespace App\Services;

use App\Tenancy\TenantContext;
use Illuminate\Database\Events\QueryExecuted;

/** Identifier-only cache invalidation; never a replacement for API authorization. */
class ResourceRealtime
{
    private array $pending = [];

    public function observe(QueryExecuted $query): void
    {
        if (! config('shop_chat.realtime_enabled')) return;
        // Observe successful builder writes, including bulk imports and stock increments.
        if (! preg_match('/^\s*(?:insert\s+(?:ignore\s+)?into|replace\s+into|update|delete\s+from)\s+[`"]?([a-z_]+)[`"]?/i', $query->sql, $match)) return;
        $resources = match (strtolower($match[1])) {
            'products', 'product_prices', 'stock_movements', 'ecommerce_stock_reservations' => ['products', 'reports'],
            'price_type_rules' => ['priceTypes', 'products', 'settings'],
            'settings' => ['settings'],
            'sales', 'sale_items' => ['sales', 'reports'],
            'purchases', 'purchase_items' => ['purchases', 'reports'],
            'expenses' => ['expenses', 'reports'],
            'customers' => ['customers'],
            'suppliers' => ['suppliers'],
            'customer_payments' => ['customerPayments', 'customers', 'reports'],
            'supplier_payments' => ['supplierPayments', 'suppliers', 'reports'],
            'platform_branding' => ['branding'],
            default => [],
        };
        if (! $resources) return;
        $business = app(TenantContext::class)->id();
        if ($resources === ['branding']) $channel = 'public-platform.branding';
        elseif ($business) $channel = 'private-business.resources.'.$business;
        else return; // Never infer a tenant from SQL or a different request's identity.
        $query->connection->afterCommit(fn () => $this->mark($channel, $resources));
    }

    public function assistant(int $businessId): void
    {
        \Illuminate\Support\Facades\DB::afterCommit(fn () => $this->mark('private-business.resources.'.$businessId, ['assistant']));
    }

    private function mark(string $channel, array $resources): void
    {
        $this->pending[$channel] = array_values(array_unique([...($this->pending[$channel] ?? []), ...$resources]));
    }

    public function flush(): void
    {
        $pending = $this->pending;
        $this->pending = [];
        foreach ($pending as $channel => $resources) {
            app(ShopChatRealtime::class)->publish([$channel], 'resources.changed', ['resources' => $resources]);
        }
    }
}
