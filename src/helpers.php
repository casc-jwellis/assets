<?php

declare(strict_types=1);

use App\Core\App;
use App\Core\Csrf;
use App\Core\Icons;

/** Escape for HTML output (text nodes and quoted attributes). Use on every dynamic value. */
function e(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function app(): App
{
    return App::instance();
}

/** Application URL for a route path, honouring a sub-directory install: url('/assets'). */
function url(string $path = '/'): string
{
    return app()->request->basePath . '/' . ltrim($path, '/');
}

/** URL of a static file under public/static/: asset('css/app.css'). */
function asset(string $path): string
{
    return url('/static/' . ltrim($path, '/'));
}

function csrf_token(): string
{
    return app()->csrf->token();
}

/** Hidden input to include in every <form method="post">. */
function csrf_field(): string
{
    return '<input type="hidden" name="' . Csrf::FIELD . '" value="' . e(csrf_token()) . '">';
}

/** CSP nonce for the (few) inline <script> blocks. */
function nonce(): string
{
    return app()->nonce;
}

function icon(string $name, int $size = 18): string
{
    return Icons::svg($name, $size);
}

/** The site name: what an administrator set on the Theme page, else the name entered during setup. */
function app_name(): string
{
    $name = app()->settings->get('site.name');
    return $name !== null && $name !== '' ? $name : (string) app()->config->get('app.name', 'Asset Manager');
}

function current_user(): ?array
{
    return app()->auth->user();
}

/** "2025-02-27 00:00:00" -> "2025-02-27"; empty/NULL -> an em dash. */
function fmt_date(mixed $value): string
{
    return $value === null || $value === '' ? '—' : substr((string) $value, 0, 10);
}

function fmt_money(mixed $value): string
{
    return '$' . number_format((float) $value, 2);
}

/** A byte count for people: 1536 -> "1.5 KB". */
function fmt_bytes(int|float|string $bytes): string
{
    $b = (float) $bytes;
    foreach (['bytes', 'KB', 'MB', 'GB'] as $i => $unit) {
        if ($b < 1024 || $unit === 'GB') {
            return ($i === 0 ? number_format($b) : number_format($b, $b < 10 ? 1 : 0)) . ' ' . $unit;
        }
        $b /= 1024;
    }
    return '';
}

/** A php.ini size setting ("64M", "2G", "-1") in bytes; PHP_INT_MAX when unlimited. */
function ini_bytes(string $key): int
{
    $v = trim((string) ini_get($key));
    if ($v === '' || $v === '-1') {
        return PHP_INT_MAX;
    }
    $n = (int) $v;
    return match (strtolower(substr($v, -1))) {
        'g' => $n * 1024 ** 3,
        'm' => $n * 1024 ** 2,
        'k' => $n * 1024,
        default => $n,
    };
}

/** True if $path is the current page or one of its children (sidebar highlighting). */
function is_active(string $path): bool
{
    $current = app()->request->path;
    return $path === '/' ? $current === '/' : ($current === $path || str_starts_with($current, $path . '/'));
}
