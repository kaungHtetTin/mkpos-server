<?php

namespace App\Http\Controllers\Office;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class BrandingController extends Controller
{
    public function show(): array
    {
        return \App\Support\PlatformBranding::all();
    }

    public function update(Request $request): array
    {
        $data = $request->validate(['primary_color' => ['required', 'regex:/^#[0-9a-fA-F]{6}$/']]);
        DB::table('platform_branding')->where('id', 1)->update($data + ['updated_at' => now()]);
        return $this->show();
    }

    public function upload(Request $request, string $asset): array
    {
        abort_unless(in_array($asset, ['logo', 'icon', 'favicon'], true), 404);
        $request->validate(['image' => ['required', 'image', 'mimes:png,jpg,jpeg,webp', 'max:2048', 'dimensions:max_width=4096,max_height=4096']]);
        $path = $request->file('image')->store('platform-branding', 'local');
        // Retain previous assets: a rollback must never leave the old setting broken.
        DB::table('platform_branding')->where('id', 1)->update([$asset.'_path' => $path, 'updated_at' => now()]);
        return $this->show();
    }

    public function asset(string $asset)
    {
        abort_unless(in_array($asset, ['logo', 'icon', 'favicon'], true), 404);
        $path = DB::table('platform_branding')->where('id', 1)->value($asset.'_path');
        abort_unless($path && Storage::disk('local')->exists($path), 404);
        return response()->file(Storage::disk('local')->path($path), ['Cache-Control' => 'public, max-age=300', 'X-Content-Type-Options' => 'nosniff']);
    }

    public function reset(string $asset): array
    {
        abort_unless(in_array($asset, ['logo', 'icon', 'favicon'], true), 404);
        DB::table('platform_branding')->where('id', 1)->update([$asset.'_path' => null, 'updated_at' => now()]);
        return $this->show();
    }
}
