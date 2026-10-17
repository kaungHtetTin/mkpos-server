<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class MerchantMarketplaceCategory
{
    public function lock(array $data): void
    {
        if (array_key_exists('marketplace_category_id', $data)) {
            DB::table('marketplace_category_locks')->where('id', 1)->lockForUpdate()->first();
        }
    }

    public function save(int $id, array $data, int $userId): void
    {
        if (!array_key_exists('marketplace_category_id', $data)) return;
        $product = DB::table('products')->where('id', $id)->first(['business_id']);
        abort_unless($product, 404);
        $link = DB::table('marketplace_product_categories')->where('product_id', $id)->where('business_id', $product->business_id)->first();
        $version = (int) ($link->version ?? 0);
        if (isset($data['marketplace_category_version']) && (int) $data['marketplace_category_version'] !== $version) {
            throw ValidationException::withMessages(['marketplace_category_id' => 'The marketplace category changed. Reopen this product before changing its category.']);
        }
        $categoryId = $data['marketplace_category_id'] ? (int) $data['marketplace_category_id'] : null;
        if (($link?->category_id ? (int) $link->category_id : null) === $categoryId) return;
        $categories = app(MarketplaceTaxonomy::class)->categories();
        $category = $categoryId ? $categories->get($categoryId) : null;
        if ($categoryId && (!$category || !$category['effective_active'])) {
            throw ValidationException::withMessages(['marketplace_category_id' => 'Choose an active marketplace category with active parent categories.']);
        }
        $before = $link?->category_id ? $categories->get($link->category_id) : null;
        $values = ['business_id' => $product->business_id, 'category_id' => $categoryId, 'version' => $version + 1,
            'updated_by_admin_id' => null, 'updated_by_user_id' => $userId, 'updated_at' => now()];
        if ($link) DB::table('marketplace_product_categories')->where('product_id', $id)->update($values);
        else DB::table('marketplace_product_categories')->insert($values + ['product_id' => $id, 'created_at' => now()]);
        DB::table('marketplace_product_category_audits')->insert(['product_id' => $id, 'business_id' => $product->business_id,
            'admin_id' => null, 'user_id' => $userId, 'before' => $before ? json_encode($before) : null,
            'after' => $category ? json_encode($category) : null, 'created_at' => now()]);
    }

    public function details(int $id, int $businessId): array
    {
        $link = DB::table('marketplace_product_categories')->where('product_id', $id)->where('business_id', $businessId)->first();
        $category = $link?->category_id ? app(MarketplaceTaxonomy::class)->categories()->get($link->category_id) : null;
        return ['marketplace_category_id' => $link?->category_id, 'marketplace_category_version' => (int) ($link->version ?? 0),
            'marketplace_category' => $category];
    }
}
