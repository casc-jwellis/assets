<?php

declare(strict_types=1);

namespace App\Core;

final class Request
{
    public readonly string $method;
    /** Route path with the base path stripped, always starting with "/". */
    public readonly string $path;
    public readonly string $basePath;

    public function __construct()
    {
        $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        $this->method = $method === 'HEAD' ? 'GET' : $method;

        // When served from a sub-directory (e.g. /assets/public/index.php) strip it.
        $base = PHP_SAPI === 'cli-server'
            ? ''
            : rtrim(str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? ''))), '/');
        $this->basePath = $base;

        $uri = rawurldecode((string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH));
        if ($base !== '' && str_starts_with($uri, $base)) {
            $uri = substr($uri, strlen($base));
        }
        // Collapse duplicate slashes so "//host" can never look like a network-path reference.
        $uri = preg_replace('#/+#', '/', '/' . $uri) ?? '/';
        $this->path = $uri === '/' ? '/' : rtrim($uri, '/');
    }

    public function query(string $key, ?string $default = null): ?string
    {
        $v = $_GET[$key] ?? null;
        return is_string($v) ? $v : $default;
    }

    public function post(string $key, ?string $default = null): ?string
    {
        $v = $_POST[$key] ?? null;
        return is_string($v) ? $v : $default;
    }

    /** @return array<string,string> an array-valued POST field such as perm[12]=rw (non-string entries dropped) */
    public function postArray(string $key): array
    {
        $v = $_POST[$key] ?? null;
        return is_array($v) ? array_filter($v, 'is_string') : [];
    }

    public function isSecure(): bool
    {
        return !empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off';
    }
}
