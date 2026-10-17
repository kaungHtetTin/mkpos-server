<?php

namespace App\Http\Controllers\Ecommerce;

use App\Http\Controllers\Controller;
use App\Support\BusinessLogo;
use App\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class ShopCoverController extends Controller
{
    public const SETTING_KEY = 'shop_cover_path';

    public function __construct(private TenantContext $tenant) {}

    public function store(Request $request): array
    {
        $data = $request->validate([
            'cover' => ['required', 'image', 'mimes:jpeg,jpg,png,webp', 'max:2048', 'dimensions:max_width=4800,max_height=4800'],
        ]);
        $businessId = $this->tenant->requireId();
        $previous = (string) DB::table('settings')->where('business_id', $businessId)->where('key', self::SETTING_KEY)->value('value');
        $path = $data['cover']->store('shop-covers/'.$businessId, 'local');
        try {
            DB::table('settings')->updateOrInsert(['business_id' => $businessId, 'key' => self::SETTING_KEY], ['value' => $path]);
        } catch (\Throwable $error) {
            Storage::disk('local')->delete($path);
            throw $error;
        }
        if ($previous !== '' && $previous !== $path) Storage::disk('local')->delete($previous);
        return ['cover_data_url' => BusinessLogo::dataUrl($path)];
    }

    public function destroy(): array
    {
        $businessId = $this->tenant->requireId();
        $query = DB::table('settings')->where('business_id', $businessId)->where('key', self::SETTING_KEY);
        $path = (string) $query->value('value');
        $query->delete();
        if ($path !== '') Storage::disk('local')->delete($path);
        return ['cover_data_url' => ''];
    }
}
