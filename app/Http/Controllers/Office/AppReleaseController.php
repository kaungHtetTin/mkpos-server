<?php

namespace App\Http\Controllers\Office;

use App\Http\Controllers\Controller;
use App\Models\AppRelease;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AppReleaseController extends Controller
{
    public function index(): array
    {
        $releases = AppRelease::query()->get()->keyBy('platform');

        return [
            'items' => collect(AppRelease::PLATFORMS)->map(fn (string $platform) => [
                'platform' => $platform,
                'release' => $releases->get($platform)?->publicPayload(),
            ])->values(),
            'max_file_size' => config('mkpos.release_upload_max_bytes'),
        ];
    }

    public function store(Request $request, string $platform): array
    {
        abort_unless(in_array($platform, AppRelease::PLATFORMS, true), 404);

        $data = $request->validate([
            'version' => ['required', 'string', 'max:40', 'regex:/^[A-Za-z0-9][A-Za-z0-9._+-]*$/'],
            'original_name' => ['required', 'string', 'max:255'],
            'file_size' => ['required', 'integer', 'min:1', 'max:'.config('mkpos.release_upload_max_bytes')],
            'release_notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $expectedExtension = $platform === 'windows' ? 'exe' : 'apk';
        $extension = Str::lower(pathinfo($data['original_name'], PATHINFO_EXTENSION));
        if ($extension !== $expectedExtension) {
            throw ValidationException::withMessages([
                'file' => ["The {$platform} release must be an .{$expectedExtension} file."],
            ]);
        }

        $storedPath = 'releases/'.$platform.'/'.Str::uuid().'.'.$expectedExtension;
        $stream = $request->getContent(true);
        if (! is_resource($stream) || ! Storage::disk('local')->put($storedPath, $stream)) {
            abort(500, 'The release file could not be stored.');
        }

        $actualSize = Storage::disk('local')->size($storedPath);
        if ($actualSize !== (int) $data['file_size']) {
            Storage::disk('local')->delete($storedPath);
            throw ValidationException::withMessages([
                'file' => ['The uploaded file was incomplete. Please try again.'],
            ]);
        }

        $mimeType = $platform === 'windows'
            ? 'application/vnd.microsoft.portable-executable'
            : 'application/vnd.android.package-archive';
        $checksum = hash_file('sha256', Storage::disk('local')->path($storedPath));
        $previousPath = AppRelease::query()->where('platform', $platform)->value('file_path');

        try {
            $release = DB::transaction(function () use ($platform, $data, $storedPath, $actualSize, $mimeType, $checksum) {
                return AppRelease::query()->updateOrCreate(
                    ['platform' => $platform],
                    [
                        'version' => trim($data['version']),
                        'original_name' => basename($data['original_name']),
                        'file_path' => $storedPath,
                        'file_size' => $actualSize,
                        'mime_type' => $mimeType,
                        'checksum_sha256' => $checksum,
                        'release_notes' => filled($data['release_notes'] ?? null) ? trim($data['release_notes']) : null,
                        'uploaded_by' => Auth::guard('office')->id(),
                        'published_at' => now(),
                    ]
                );
            });
        } catch (\Throwable $error) {
            Storage::disk('local')->delete($storedPath);
            throw $error;
        }

        if ($previousPath && $previousPath !== $storedPath) {
            Storage::disk('local')->delete($previousPath);
        }

        return ['release' => $release->fresh()->publicPayload()];
    }
}
