<?php

namespace App\Services;

use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

class EcommerceInventory
{
    public function reserved(int $productId, int $businessId): float
    {
        return (float) DB::table('ecommerce_stock_reservations')->where('business_id', $businessId)
            ->where('product_id', $productId)->where('status', 'active')->sum('quantity');
    }

    // Call only after locking the product row inside the stock-writing transaction.
    public function assertRemaining(object $product, float $remaining): void
    {
        $reserved = $this->reserved($product->id, $product->business_id);
        abort_if($reserved > 0 && $remaining + 0.0005 < $reserved, 409,
            "Stock for {$product->name} is reserved by online orders. Resolve the orders before reducing stock.");
    }

    public function assertEditable(object $product, array $data): void
    {
        $reserved = $this->reserved($product->id, $product->business_id);
        if ($reserved > 0) {
            abort_if(! ($data['is_active'] ?? true), 409, 'Resolve online orders before archiving this product.');
            foreach (['base_unit', 'purchase_unit', 'purchase_conversion_factor'] as $key) {
                abort_if((string) $data[$key] !== (string) $product->$key && ($key !== 'purchase_conversion_factor' || (float) $data[$key] !== (float) $product->$key), 409,
                    'Resolve online orders before changing product units.');
            }
        }
        $this->assertRemaining($product, (float) ($data['stock'] ?? 0));
    }

    public function assertMaintenanceAllowed(int $businessId): void
    {
        // The legacy POS backup format does not contain ecommerce history. Block
        // replacement while any order history exists instead of orphaning snapshots.
        abort_if(DB::table('ecommerce_orders')->where('business_id', $businessId)->exists(), 409,
            'Data replacement is unavailable for a business with online order history. Use a full server backup for recovery.');
    }
}
