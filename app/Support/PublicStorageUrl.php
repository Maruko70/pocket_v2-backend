<?php

namespace App\Support;

use Illuminate\Support\Facades\Storage;

class PublicStorageUrl
{
    /**
     * Build a public URL for a storage path.
     * Legacy hosts (alnahda-group.com, localhost) are rewritten to APP_URL/storage/*.
     */
    public static function resolve(?string $path): ?string
    {
        if ($path === null || trim($path) === '') {
            return null;
        }

        $path = trim($path);

        if (preg_match('#/storage/(https?://.+)$#i', $path, $matches)) {
            return self::resolve($matches[1]);
        }

        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
            return self::encodeUrl(self::normalizeFullUrl($path));
        }

        return self::encodeUrl(url(Storage::url($path)));
    }

    private static function encodeUrl(string $url): string
    {
        $parsed = parse_url($url);
        if ($parsed === false || empty($parsed['path'])) {
            return $url;
        }

        $segments = array_values(array_filter(
            explode('/', $parsed['path']),
            static fn (string $segment): bool => $segment !== ''
        ));

        if ($segments === []) {
            return $url;
        }

        $encodedPath = implode('/', array_map(static function (string $segment): string {
            return rawurlencode(rawurldecode($segment));
        }, $segments));

        $scheme = $parsed['scheme'] ?? 'https';
        $host = $parsed['host'] ?? '';
        $port = isset($parsed['port']) ? ':'.$parsed['port'] : '';

        return $scheme.'://'.$host.$port.'/'.$encodedPath;
    }

    private static function normalizeFullUrl(string $url): string
    {
        if (preg_match('#/storage/(https?://.+)$#i', $url, $matches)) {
            return self::resolve($matches[1]);
        }

        $parsed = parse_url($url);
        if ($parsed === false || empty($parsed['path'])) {
            return $url;
        }

        if (! str_starts_with($parsed['path'], '/storage/')) {
            return $url;
        }

        $host = strtolower($parsed['host'] ?? '');

        if (self::shouldRewriteHost($host)) {
            $relative = ltrim(substr($parsed['path'], strlen('/storage/')), '/');

            return url(Storage::url($relative));
        }

        return $url;
    }

    private static function shouldRewriteHost(string $host): bool
    {
        if ($host === '' || str_contains($host, 'alnahda-group.com')) {
            return true;
        }

        if ($host === 'localhost' || $host === '127.0.0.1' || str_ends_with($host, '.local')) {
            return true;
        }

        if (str_starts_with($host, '192.168.') || str_starts_with($host, '10.')) {
            return true;
        }

        return false;
    }
}
