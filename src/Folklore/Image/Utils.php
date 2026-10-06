<?php

namespace Folklore\Image;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class Utils
{
    protected static function getBytes(string $path, int $offset, ?int $length = 1)
    {
        $isUrl = filter_var($path, FILTER_VALIDATE_URL);
        if ($isUrl) {
            if (! self::isAllowedUrl($path)) {
                return null;
            }
            $response = self::http()->withHeaders([
                'Range' => 'bytes='.$offset.'-'.($offset + ($length - 1)),
            ])->get($path);
            if (! $response->successful()) {
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
        if (! Str::endsWith(strtolower($path), '.png') && ! self::isPngBySignature($url)) {
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

        return 'image/'.$extension;
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
            $colorType = ! empty($colorByte) ? ord($colorByte[0]) : null;

            return $colorType === 4 || $colorType === 6;
        }

        return false;
    }

    public static function convertImage(string $path, $mime = 'image/jpeg', $destPath = null, $opts = []): ?string
    {
        $isUrl = filter_var($path, FILTER_VALIDATE_URL) !== false;
        if ($isUrl && ! self::isAllowedUrl($path)) {
            return null;
        }

        $downloadPath = null;
        $destinationBase = null;
        $converted = false;

        try {
            $localPath = $path;
            if ($isUrl) {
                $response = self::http()->get($path);
                if (! $response->successful()) {
                    return null;
                }
                $downloadPath = tempnam(sys_get_temp_dir(), 'imgconv_');
                file_put_contents($downloadPath, $response->body());
                $localPath = $downloadPath;
            }

            if (empty($destPath)) {
                // tempnam() creates the file; the result gets its own name with the extension.
                $destinationBase = tempnam(sys_get_temp_dir(), 'imgconv_');
                $destPath = $destinationBase.'.'.self::getExtensionFromMime($mime);
            }

            $format = self::getFormatFromMime($mime);
            $image = app('image')->getImagine()->open($localPath);
            $image->save($destPath, array_merge(['format' => $format], $opts));
            $converted = true;

            return $destPath;
        } catch (\Exception $e) {
            return null;
        } finally {
            if ($downloadPath !== null) {
                @unlink($downloadPath);
            }
            if ($destinationBase !== null) {
                @unlink($destinationBase);
                if (! $converted) {
                    @unlink($destPath);
                }
            }
        }
    }

    /**
     * Whether a remote URL may be fetched: only http and https, and only the hosts in
     * `image.utils.allowed_hosts` when that list is set.
     */
    public static function isAllowedUrl(string $url): bool
    {
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        if (! in_array($scheme, ['http', 'https'], true)) {
            return false;
        }

        $allowedHosts = config('image.utils.allowed_hosts');
        if (empty($allowedHosts)) {
            return true;
        }

        $host = strtolower((string) parse_url($url, PHP_URL_HOST));

        return in_array($host, array_map('strtolower', (array) $allowedHosts), true);
    }

    /**
     * The HTTP client for remote images. With an allow-list, redirects aren't followed,
     * so they can't lead to another host.
     */
    protected static function http()
    {
        $request = Http::timeout(30);

        return empty(config('image.utils.allowed_hosts')) ? $request : $request->withoutRedirecting();
    }
}
