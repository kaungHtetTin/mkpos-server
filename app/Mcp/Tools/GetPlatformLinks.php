<?php

namespace App\Mcp\Tools;

use App\Models\AppRelease;
use App\Support\ClientApps;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\Storage;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use League\Flysystem\FilesystemException;

#[IsReadOnly]
class GetPlatformLinks extends Tool
{
    protected string $name = 'get_platform_links';

    protected string $description = 'Get MKPOS desktop web and mobile web URLs and current Windows 64-bit, Windows 32-bit and Android download links. Checks release records and files on every call; unavailable installers have no download URL. Use for download, install, app, APK or platform URL questions in English or Burmese.';

    public function schema(JsonSchema $schema): array
    {
        return ['language' => $schema->string()->enum(['en', 'my'])->description('Defaults to en.')];
    }

    public function handle(Request $request): ResponseFactory
    {
        $data = $request->validate(['language' => ['sometimes', 'string', 'in:en,my']]);
        $language = $data['language'] ?? 'en';
        $labels = Lang::get('mkpos_access.platforms', [], $language);
        $platforms = [];
        foreach (['web', 'mobile'] as $platform) {
            $base = $platform === 'web' ? ClientApps::webUrl() : ClientApps::mobileUrl();
            $platforms[] = ['platform' => $platform, 'name' => $labels[$platform], 'type' => 'browser', 'url' => $base, 'login_url' => $platform === 'web' ? ClientApps::webUrl('login') : ClientApps::mobileUrl('login')];
        }

        $releaseStatus = 'checked';
        try {
            $releases = AppRelease::query()->whereIn('platform', AppRelease::PLATFORMS)->get()->keyBy('platform');
        } catch (QueryException $exception) {
            report($exception);
            $releases = collect();
            $releaseStatus = 'unavailable';
        }

        foreach (AppRelease::PLATFORMS as $platform) {
            $release = $releases->get($platform);
            $status = $releaseStatus === 'unavailable' ? 'unknown' : 'not_published';
            if ($release) {
                try {
                    $status = Storage::disk('local')->exists($release->file_path) ? 'available' : 'file_missing';
                } catch (FilesystemException $exception) {
                    report($exception);
                    $status = 'unknown';
                }
            }
            $platforms[] = [
                'platform' => $platform, 'name' => $labels[$platform], 'type' => 'installer',
                'status' => $status,
                'version' => $release?->version,
                'download_url' => $status === 'available' ? route('downloads.show', ['platform' => $platform]) : null,
            ];
        }

        return Response::structured([
            'language' => $language,
            'checked_at' => now()->toIso8601String(),
            'release_lookup_status' => $releaseStatus,
            'platforms' => $platforms,
            'note' => Lang::get('mkpos_access.downloads_note', [], $language),
            'source_url' => route('landing').'#platforms',
            'answer_guidance' => 'Answer in the customer language. Share returned URLs exactly. Only available installers can be downloaded. not_published means no release; file_missing means a release file is absent; unknown means availability could not be checked. Browser URLs are configured entry points, not uptime checks. Do not invent iOS/macOS installers or claim unavailable builds are ready. Call again for fresh download questions.',
        ]);
    }
}
