<?php

namespace App\Services\TelegramSupport;

use Laravel\Ai\Files\Image;

class ImageInput
{
    public const MAX_BYTES = 5 * 1024 * 1024;

    public function attachment(string $fileId, int $botId): Image
    {
        $bytes = app(TelegramClient::class)->downloadImage($fileId, $botId, self::MAX_BYTES);
        $info = @getimagesizefromstring($bytes);
        if (! $info || ! in_array($info['mime'] ?? '', ['image/jpeg', 'image/png', 'image/webp'], true)
            || $info[0] < 1 || $info[1] < 1 || $info[0] > 12000 || $info[1] > 12000 || $info[0] * $info[1] > 25000000) {
            throw new \RuntimeException('Unsupported image');
        }
        $ratio = min(1, 2400 / max($info[0], $info[1]));
        $configured = trim((string) ini_get('memory_limit'));
        $limit = (int) $configured * match (strtolower(substr($configured, -1))) {
            'g' => 1024 ** 3, 'm' => 1024 ** 2, 'k' => 1024, default => 1,
        };
        // Reject before GD allocates full-resolution pixels on small shared hosts.
        $estimated = $info[0] * $info[1] * 5 * (1 + $ratio * $ratio) + strlen($bytes) * 2 + 8 * 1024 * 1024;
        if ($limit > 0 && memory_get_usage(true) + $estimated > $limit * 0.9) {
            throw new \RuntimeException('Image exceeds worker memory budget');
        }
        $source = @imagecreatefromstring($bytes);
        if (! $source) {
            throw new \RuntimeException('Unreadable image');
        }
        $image = imagecreatetruecolor(max(1, (int) ($info[0] * $ratio)), max(1, (int) ($info[1] * $ratio)));
        try {
            imagefill($image, 0, 0, imagecolorallocate($image, 255, 255, 255));
            imagecopyresampled($image, $source, 0, 0, 0, 0, imagesx($image), imagesy($image), $info[0], $info[1]);
            ob_start();
            try {
                imagejpeg($image, null, 90);
                $normalized = ob_get_contents();
            } finally {
                ob_end_clean();
            }
        } finally {
            imagedestroy($source);
            imagedestroy($image);
        }
        if (! is_string($normalized) || strlen($normalized) > self::MAX_BYTES) {
            throw new \RuntimeException('Image too large');
        }

        return Image::fromBase64(base64_encode($normalized), 'image/jpeg');
    }
}
