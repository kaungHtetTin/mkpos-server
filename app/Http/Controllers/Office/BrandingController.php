<?php

namespace App\Http\Controllers\Office;

use App\Http\Controllers\Controller;
use App\Services\PlatformTheme;
use App\Support\PlatformBranding;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class BrandingController extends Controller
{
    public function show(): array
    {
        return PlatformBranding::all();
    }

    public function update(Request $request): array
    {
        $data = $request->validate(['primary_color' => ['required', 'regex:/^#[0-9a-fA-F]{6}$/'], 'theme_mode' => 'sometimes|in:light,dark',
            'theme_version' => 'sometimes|integer|min:1', 'preview_id' => 'sometimes|nullable|uuid', 'request_key' => 'sometimes|uuid']);
        $data['primary_color'] = strtolower($data['primary_color']);
        $current = $this->show();
        $preview = null;
        if ($data['preview_id'] ?? null) {
            $preview = DB::table('platform_theme_previews')->where('id', $data['preview_id'])->where('admin_id', $request->user('office')->id)->first();
            abort_unless($preview && $preview->primary_color === $data['primary_color'], 422, 'Generate a preview for this primary color first.');
        } elseif ($data['primary_color'] !== strtolower($current['primary_color'])) {
            $request->validate(['request_key' => 'required|uuid']);
            $generated = app(PlatformTheme::class)->preview($request->user('office'), $data['primary_color'], $data['request_key']);
            $preview = DB::table('platform_theme_previews')->where('id', $generated['preview_id'])->first();
        }
        DB::transaction(function () use ($data, $preview, $current) {
            $row = DB::table('platform_branding')->where('id', 1)->lockForUpdate()->first();
            abort_unless((int) $row->theme_version === (int) ($data['theme_version'] ?? $current['theme_version']), 409, 'The theme changed. Refresh settings before saving.');
            DB::table('platform_branding')->where('id', 1)->update(['primary_color' => $data['primary_color'], 'theme_mode' => $data['theme_mode'] ?? $row->theme_mode,
                'theme_palettes' => $preview?->palettes ?? $row->theme_palettes, 'theme_source' => $preview ? 'system_agent' : $row->theme_source,
                'theme_version' => $row->theme_version + 1, 'updated_at' => now()]);
        });

        return $this->show();
    }

    public function preview(Request $request, PlatformTheme $theme): array
    {
        $data = $request->validate(['primary_color' => ['required', 'regex:/^#[0-9a-fA-F]{6}$/'], 'request_key' => 'required|uuid']);

        return $theme->preview($request->user('office'), $data['primary_color'], $data['request_key']);
    }

    public function upload(Request $request, string $asset): array
    {
        abort_unless(in_array($asset, ['logo', 'icon', 'favicon'], true), 404);
        $request->validate(['image' => ['required', 'image', 'mimes:png,jpg,jpeg,webp', 'max:2048', 'dimensions:max_width=4096,max_height=4096']]);
        $path = $request->file('image')->store('platform-branding', 'local');
        // Retain previous assets: a rollback must never leave the old setting broken.
        DB::table('platform_branding')->where('id', 1)->update([$asset.'_path' => $path, 'theme_version' => DB::raw('theme_version + 1'), 'updated_at' => now()]);

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
        DB::table('platform_branding')->where('id', 1)->update([$asset.'_path' => null, 'theme_version' => DB::raw('theme_version + 1'), 'updated_at' => now()]);

        return $this->show();
    }
}
