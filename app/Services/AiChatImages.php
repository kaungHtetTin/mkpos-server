<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Ai\Files\Image;

class AiChatImages
{
    public function inspect(UploadedFile $file): array
    {
        $info = @getimagesizefromstring($file->get());
        abort_unless($info && in_array($info['mime'], ['image/jpeg', 'image/png', 'image/webp'], true)
            && $info[0] <= 8192 && $info[1] <= 8192 && $file->getSize() <= 5242880, 422, 'Choose a JPEG, PNG or WebP image up to 5 MB and 8192 pixels per side.');

        return ['type' => 'image', 'name' => Str::limit(basename($file->getClientOriginalName()), 120, ''),
            'mime' => $info['mime'], 'size' => $file->getSize(), 'width' => $info[0], 'height' => $info[1],
            '_image_sha256' => hash('sha256', $file->get())];
    }

    public function attachment(array $metadata, int $businessId, string $conversation): Image
    {
        [$bytes, $mime] = $this->read($metadata, $businessId, $conversation);

        return Image::fromBase64(base64_encode($bytes), $mime);
    }

    public function read(array $metadata, int $businessId, string $conversation): array
    {
        $path = $metadata['_image_path'] ?? '';
        $prefix = 'ai-chat-images/'.$businessId.'/'.$conversation.'/';
        abort_unless(str_starts_with($path, $prefix) && ! str_contains($path, '..') && Storage::disk('local')->exists($path), 422, 'The saved image is unavailable. Attach it again in a new message.');
        abort_unless(Storage::disk('local')->size($path) <= 5242880, 422);
        $bytes = Storage::disk('local')->get($path);
        abort_unless(hash_equals($metadata['_image_sha256'] ?? '', hash('sha256', $bytes)), 422, 'The saved image changed. Attach it again in a new message.');

        return [$bytes, $metadata['mime']];
    }

    public function publicMetadata(array $metadata): array
    {
        return array_filter($metadata, fn ($key) => ! str_starts_with($key, '_image_'), ARRAY_FILTER_USE_KEY);
    }
}
