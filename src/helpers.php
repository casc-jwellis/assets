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

function current_user(): ?array
{
    return app()->auth->user();
}

/** True if $path is the current page or one of its children (sidebar highlighting). */
function is_active(string $path): bool
{
    $current = app()->request->path;
    return $path === '/' ? $current === '/' : ($current === $path || str_starts_with($current, $path . '/'));
}
