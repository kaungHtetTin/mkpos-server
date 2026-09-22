<?php

namespace App\Http\Controllers;

use App\Models\AppRelease;
use Illuminate\Support\Facades\Storage;

class DownloadController extends Controller
{
    public function show(string $platform)
    {
        abort_unless(in_array($platform, AppRelease::PLATFORMS, true), 404);

        $release = AppRelease::query()->where('platform', $platform)->firstOrFail();
        abort_unless(Storage::disk('local')->exists($release->file_path), 404, 'The release file is unavailable.');

        $release->increment('download_count');

        return Storage::disk('local')->download(
            $release->file_path,
            $this->downloadName($release),
            [
                'Content-Type' => $release->mime_type,
                'X-Content-Type-Options' => 'nosniff',
            ]
        );
    }

    private function downloadName(AppRelease $release): string
    {
        $platform = match ($release->platform) {
            'windows' => 'Windows',
            'windows32' => 'Windows-32bit',
            default => 'Android',
        };
        $extension = AppRelease::isWindows($release->platform) ? 'exe' : 'apk';
        $version = preg_replace('/[^A-Za-z0-9._-]+/', '-', $release->version);

        return "MKPOS-{$platform}-{$version}.{$extension}";
    }
}
