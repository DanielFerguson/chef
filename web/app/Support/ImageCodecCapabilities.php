<?php

namespace App\Support;

use Imagick;

class ImageCodecCapabilities
{
    public function supportsMimeType(string $mimeType): bool
    {
        return match ($mimeType) {
            'image/heic', 'image/x-heic' => $this->supportsFormat('HEIC'),
            'image/heif', 'image/x-heif' => $this->supportsFormat('HEIF'),
            default => false,
        };
    }

    private function supportsFormat(string $format): bool
    {
        return Imagick::queryFormats($format) !== [];
    }
}
