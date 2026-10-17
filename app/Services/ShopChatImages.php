<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ShopChatImages
{
    public const MAX_STORED_BYTES = 500 * 1024;

    public function store(UploadedFile $file, int $businessId): string
    {
        abort_unless(function_exists('imagewebp'), 503, 'Chat image compression is unavailable. Enable PHP GD with WebP support.');
        $image = @imagecreatefromstring(file_get_contents($file->getRealPath()));
        abort_unless($image, 422, 'Unable to read this image.');
        try {
            if (!imageistruecolor($image)) imagepalettetotruecolor($image);
            imagesavealpha($image, true);
            $longest = max(imagesx($image), imagesy($image));
            if ($longest > 1600) $image = $this->resize($image, 1600 / $longest);
            for ($attempt = 0; $attempt < 12; $attempt++) {
                foreach ([85, 75, 65, 55, 45] as $quality) {
                    ob_start();
                    try { $encoded = imagewebp($image, null, $quality); $bytes = ob_get_contents(); }
                    finally { ob_end_clean(); }
                    if ($encoded && $bytes !== '' && strlen($bytes) <= self::MAX_STORED_BYTES) {
                        $path = 'shop-chat-images/'.$businessId.'/'.Str::uuid().'.webp';
                        abort_unless(Storage::disk('local')->put($path, $bytes), 503, 'Unable to save the image. Please try again.');
                        return $path;
                    }
                }
                $image = $this->resize($image, 0.75);
            }
            abort(422, 'Unable to compress this image. Please choose another image.');
        } finally { imagedestroy($image); }
    }

    private function resize($image, float $scale)
    {
        $resized = imagecreatetruecolor(max(1, (int) floor(imagesx($image) * $scale)), max(1, (int) floor(imagesy($image) * $scale)));
        imagealphablending($resized, false);
        imagesavealpha($resized, true);
        imagecopyresampled($resized, $image, 0, 0, 0, 0, imagesx($resized), imagesy($resized), imagesx($image), imagesy($image));
        imagedestroy($image);
        return $resized;
    }
}
