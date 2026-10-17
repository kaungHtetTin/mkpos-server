<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class PlatformCustomerAvatar
{
    public static function url(?string $path): ?string
    {
        return $path ? url('/api/ecommerce/customer/avatars/'.basename($path)) : null;
    }

    public function store(UploadedFile $file): string
    {
        abort_unless(function_exists('imagejpeg'), 503, 'Profile image processing is unavailable.');
        $image = @imagecreatefromstring(file_get_contents($file->getRealPath()));
        abort_unless($image, 422, 'Unable to read this image.');
        $size = min(512, imagesx($image), imagesy($image));
        $side = min(imagesx($image), imagesy($image));
        $avatar = imagecreatetruecolor($size, $size);
        try {
            imagefill($avatar, 0, 0, imagecolorallocate($avatar, 255, 255, 255));
            imagecopyresampled($avatar, $image, 0, 0, (int) ((imagesx($image) - $side) / 2), (int) ((imagesy($image) - $side) / 2), $size, $size, $side, $side);
            ob_start();
            try { $encoded = imagejpeg($avatar, null, 85); $bytes = ob_get_contents(); }
            finally { ob_end_clean(); }
            abort_unless($encoded && strlen($bytes) <= 500 * 1024, 422, 'Unable to process this image.');
            $path = 'platform-customer-avatars/'.Str::uuid().'.jpg';
            abort_unless(Storage::disk('local')->put($path, $bytes), 503, 'Unable to save profile picture.');
            return $path;
        } finally { imagedestroy($image); imagedestroy($avatar); }
    }
}
