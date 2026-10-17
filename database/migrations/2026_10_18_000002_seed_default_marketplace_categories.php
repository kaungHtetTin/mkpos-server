<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        // Immutable deployment defaults. Existing slugs belong to Office staff:
        // preserve their names, parents, ordering and active state on every run.
        $groups = [
            ['groceries-food', 'Groceries & Food', 'စားသောက်ကုန်များ', [
                ['rice-grains', 'Rice & Grains', 'ဆန်နှင့် ကောက်ပဲသီးနှံ'], ['cooking-ingredients', 'Cooking Ingredients', 'ချက်ပြုတ်ပစ္စည်းများ'], ['snacks-sweets', 'Snacks & Sweets', 'မုန့်နှင့် အချိုများ'], ['canned-packaged-food', 'Canned & Packaged Food', 'စည်သွပ်နှင့် အသင့်စားအစားအစာ']]],
            ['fresh-produce', 'Fresh Produce', 'လတ်ဆတ်သော စားသောက်ကုန်', [
                ['fruit-vegetables', 'Fruit & Vegetables', 'သစ်သီးနှင့် ဟင်းသီးဟင်းရွက်'], ['meat-seafood', 'Meat & Seafood', 'အသားနှင့် ပင်လယ်စာ'], ['eggs-dairy', 'Eggs & Dairy', 'ဥနှင့် နို့ထွက်ပစ္စည်း']]],
            ['beverages', 'Beverages', 'သောက်စရာများ', [
                ['water-soft-drinks', 'Water & Soft Drinks', 'ရေနှင့် အချိုရည်'], ['tea-coffee', 'Tea & Coffee', 'လက်ဖက်ရည်နှင့် ကော်ဖီ']]],
            ['home-living', 'Home & Living', 'အိမ်သုံးပစ္စည်းများ', [
                ['cleaning-laundry', 'Cleaning & Laundry', 'သန့်ရှင်းရေးနှင့် အဝတ်လျှော်ပစ္စည်း'], ['kitchen-dining', 'Kitchen & Dining', 'မီးဖိုချောင်နှင့် စားသောက်အသုံးအဆောင်'], ['furniture-bedding', 'Furniture & Bedding', 'ပရိဘောဂနှင့် အိပ်ရာအသုံးအဆောင်']]],
            ['beauty-personal-care', 'Beauty & Personal Care', 'အလှအပနှင့် တစ်ကိုယ်ရေသုံးပစ္စည်း', [
                ['skin-hair-care', 'Skin & Hair Care', 'အသားအရေနှင့် ဆံပင်ထိန်းသိမ်းရေး'], ['bath-hygiene', 'Bath & Hygiene', 'ရေချိုးနှင့် သန့်ရှင်းရေးပစ္စည်း'], ['cosmetics-fragrances', 'Cosmetics & Fragrances', 'အလှကုန်နှင့် ရေမွှေး']]],
            ['fashion-accessories', 'Fashion & Accessories', 'ဖက်ရှင်နှင့် အသုံးအဆောင်', [
                ['clothing', 'Clothing', 'အဝတ်အထည်'], ['shoes-bags', 'Shoes & Bags', 'ဖိနပ်နှင့် အိတ်'], ['jewellery-watches', 'Jewellery & Watches', 'လက်ဝတ်ရတနာနှင့် နာရီ']]],
            ['electronics-accessories', 'Electronics & Accessories', 'အီလက်ထရွန်နစ်နှင့် ဆက်စပ်ပစ္စည်း', [
                ['phones-accessories', 'Phones & Accessories', 'ဖုန်းနှင့် ဆက်စပ်ပစ္စည်း'], ['computers-accessories', 'Computers & Accessories', 'ကွန်ပျူတာနှင့် ဆက်စပ်ပစ္စည်း'], ['home-appliances', 'Home Appliances', 'အိမ်သုံး လျှပ်စစ်ပစ္စည်း']]],
            ['baby-kids', 'Baby & Kids', 'ကလေးအသုံးအဆောင်', [
                ['baby-care', 'Baby Care', 'ကလေးထိန်းသိမ်းရေးပစ္စည်း'], ['toys-games', 'Toys & Games', 'ကစားစရာနှင့် ဂိမ်း']]],
            ['health-wellness', 'Health & Wellness', 'ကျန်းမာရေးပစ္စည်းများ', [
                ['health-supplies', 'Health Supplies', 'ကျန်းမာရေး အသုံးအဆောင်'], ['nutrition-wellness', 'Nutrition & Wellness', 'အာဟာရနှင့် ကျန်းမာရေးထိန်းသိမ်းမှု']]],
            ['stationery-office', 'Stationery & Office', 'စာရေးကိရိယာနှင့် ရုံးသုံးပစ္စည်း', [
                ['school-office-supplies', 'School & Office Supplies', 'ကျောင်းသုံးနှင့် ရုံးသုံးပစ္စည်း'], ['books-crafts', 'Books & Crafts', 'စာအုပ်နှင့် လက်မှုပစ္စည်း']]],
            ['sports-outdoors', 'Sports & Outdoors', 'အားကစားနှင့် ပြင်ပအသုံးအဆောင်', [
                ['sports-fitness', 'Sports & Fitness', 'အားကစားနှင့် လေ့ကျင့်ခန်းပစ္စည်း'], ['travel-outdoors', 'Travel & Outdoors', 'ခရီးသွားနှင့် ပြင်ပအသုံးအဆောင်']]],
            ['pet-supplies', 'Pet Supplies', 'အိမ်မွေးတိရစ္ဆာန် အသုံးအဆောင်', [
                ['pet-food', 'Pet Food', 'အိမ်မွေးတိရစ္ဆာန် အစာ'], ['pet-care', 'Pet Care', 'အိမ်မွေးတိရစ္ဆာန် ထိန်းသိမ်းရေးပစ္စည်း']]],
            ['hardware-tools', 'Hardware & Tools', 'အိမ်ပြုပြင်ရေးနှင့် ကိရိယာများ', [
                ['tools-building-supplies', 'Tools & Building Supplies', 'ကိရိယာနှင့် ဆောက်လုပ်ရေးပစ္စည်း'], ['garden-supplies', 'Garden Supplies', 'ဥယျာဉ်သုံးပစ္စည်း']]],
            ['automotive', 'Automotive', 'မော်တော်ယာဉ် အသုံးအဆောင်', [
                ['vehicle-accessories', 'Vehicle Accessories', 'ယာဉ်ဆက်စပ်ပစ္စည်း'], ['vehicle-care', 'Vehicle Care', 'ယာဉ်ထိန်းသိမ်းရေးပစ္စည်း']]],
        ];
        DB::transaction(function () use ($groups) {
            DB::table('marketplace_category_locks')->where('id', 1)->lockForUpdate()->first();
            $insert = function ($row, $parent, $order) {
                [$slug, $en, $my] = $row;
                $existing = DB::table('marketplace_categories')->where('slug', $slug)->first();
                if ($existing) return $existing->id;
                $id = DB::table('marketplace_categories')->insertGetId(['slug' => $slug, 'name_en' => $en, 'name_my' => $my,
                    'parent_id' => $parent, 'sort_order' => $order, 'is_active' => true, 'version' => 1,
                    // Zero denotes the deployment migration, not a staff account.
                    'created_by_admin_id' => 0, 'updated_by_admin_id' => 0, 'created_at' => now(), 'updated_at' => now()]);
                DB::table('marketplace_category_audits')->insert(['category_id' => $id, 'admin_id' => 0, 'action' => 'seeded',
                    'before' => null, 'after' => json_encode(DB::table('marketplace_categories')->where('id', $id)->first()), 'created_at' => now()]);
                return $id;
            };
            foreach ($groups as $index => $group) {
                $parent = $insert($group, null, ($index + 1) * 10);
                foreach ($group[3] as $childIndex => $child) $insert($child, $parent, ($childIndex + 1) * 10);
            }
        });
    }

    public function down(): void
    {
        // Keep seeded categories: staff may have edited them or linked live products.
    }
};
