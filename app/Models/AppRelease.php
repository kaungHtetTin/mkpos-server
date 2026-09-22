<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AppRelease extends Model
{
    /**
     * Keep the original `windows` key for existing 64-bit links and clients.
     * The 32-bit build is published independently so replacing it never
     * changes the current 64-bit download.
     */
    public const PLATFORMS = ['windows', 'windows32', 'android'];

    public static function isWindows(string $platform): bool
    {
        return in_array($platform, ['windows', 'windows32'], true);
    }

    protected $fillable = [
        'platform',
        'version',
        'original_name',
        'file_path',
        'file_size',
        'mime_type',
        'checksum_sha256',
        'release_notes',
        'download_count',
        'uploaded_by',
        'published_at',
    ];

    protected $casts = [
        'file_size' => 'integer',
        'download_count' => 'integer',
        'published_at' => 'datetime',
    ];

    public function publicPayload(): array
    {
        return [
            'platform' => $this->platform,
            'version' => $this->version,
            'original_name' => $this->original_name,
            'file_size' => $this->file_size,
            'release_notes' => $this->release_notes,
            'download_count' => $this->download_count,
            'published_at' => optional($this->published_at)->toIso8601String(),
            'download_url' => route('downloads.show', ['platform' => $this->platform]),
        ];
    }
}
