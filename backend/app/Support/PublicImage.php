<?php

namespace App\Support;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class PublicImage
{
    public static function store(UploadedFile $image, string $directory): string
    {
        $path = $image->store($directory, 'public');

        if (! is_string($path)) {
            throw new RuntimeException('Unable to store the uploaded image.');
        }

        return $path;
    }

    public static function url(?string $image): ?string
    {
        if ($image === null || $image === '') {
            return null;
        }

        if (self::isExternal($image)) {
            return $image;
        }

        $url = Storage::disk('public')->url($image);

        return filter_var($url, FILTER_VALIDATE_URL) ? $url : url($url);
    }

    public static function delete(?string $image): void
    {
        if ($image === null || $image === '' || self::isExternal($image)) {
            return;
        }

        Storage::disk('public')->delete($image);
    }

    private static function isExternal(string $image): bool
    {
        return filter_var($image, FILTER_VALIDATE_URL) !== false || str_starts_with($image, '//');
    }
}
