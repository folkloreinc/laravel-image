<?php

namespace Folklore\Image;

use Illuminate\Support\Str;
use Illuminate\Support\Facades\Http;

class Utils
{
    protected static function getBytes(string $path, int $offset, ?int $length = 1)
    {
        $isUrl = filter_var($path, FILTER_VALIDATE_URL);
        if ($isUrl) {
            $response = Http::withHeaders([
                'Range' => 'bytes=' . $offset . '-' . ($offset + ($length - 1)),
            ])->get($path);
            if (!$response->successful()) {
                return null;
            }
            $body = $response->body();
            return $response->status() === 206 || strlen($body) === $length
                ? $body
                : substr($body, $offset, $length);
        }

        return file_get_contents($path, false, null, $offset, $length);
    }

    public static function isPngBySignature(string $path): bool
    {
        return self::getBytes($path, 0, 8) === "\x89PNG\r\n\x1a\n";
    }

    public static function isPng(string $url): bool
    {
        $isUrl = filter_var($url, FILTER_VALIDATE_URL);
        $path = $isUrl ? parse_url($url, PHP_URL_PATH) ?? '' : $url;
        if (!Str::endsWith(strtolower($path), '.png') && !self::isPngBySignature($url)) {
            return false;
        }
        return true;
    }

    public static function getExtensionFromMime(string $mime)
    {
        if (preg_match('/image\/([a-z\-]+)/i', $mime, $matches) === 0) {
            return null;
        }

        switch ($matches[1]) {
            case 'jpg':
            case 'jpeg':
                return 'jpg';
                break;
            case 'svg+xml':
                return 'svg';
                break;
        }

        return $matches[1];
    }

    public static function getMimeFromExtension($path): string
    {
        $isUrl = filter_var($path, FILTER_VALIDATE_URL);
        $path = $isUrl ? parse_url($path, PHP_URL_PATH) ?? $path : $path;
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION) ?? $path);
        switch ($extension) {
            case 'jpg':
            case 'jpeg':
                return 'image/jpeg';
                break;
            case 'svg':
                return 'image/svg+xml';
                break;
        }
        return 'image/' . $extension;
    }

    public static function getFormatFromMime(string $mime)
    {
        if (preg_match('/image\/([a-z\-]+)/i', $mime, $matches) === 0) {
            return null;
        }

        switch ($matches[1]) {
            case 'jpg':
            case 'jpeg':
                return 'jpeg';
                break;
            case 'png':
                return 'png';
                break;
        }

        return $matches[1];
    }

    public static function hasTransparency(string $path): bool
    {
        if (self::isPng($path)) {
            $colorByte = self::getBytes($path, 25, 1);
            $colorType = !empty($colorByte) ? ord($colorByte[0]) : null;
            return $colorType === 4 || $colorType === 6;
        }
        return false;
    }

    public static function convertImage(string $path, $mime = 'image/jpeg', $destPath = null, $opts = []): ?string
    {
        try {
            $isUrl = filter_var($path, FILTER_VALIDATE_URL);
            $localPath = $isUrl ? tempnam(sys_get_temp_dir(), 'imgconv_') : $path;
            if ($isUrl) {
                Http::sink($localPath)->get($path);
            }
            if (empty($destPath)) {
                $extension = self::getExtensionFromMime($mime);
                $destPath = tempnam(sys_get_temp_dir(), 'imgconv_').'.' . $extension;
            }
            $format = self::getFormatFromMime($mime);
            $image = app('image')->getImagine()->open($localPath);
            $image->save($destPath, array_merge(['format' => $format], $opts));
            if ($isUrl) {
                unset($localPath);
            }
            return $destPath;
        } catch (\Exception $e) {
            return null;
        }
    }
}
