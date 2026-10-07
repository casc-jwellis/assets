<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Synchronizer-token CSRF protection. The front controller verifies the token
 * on every non-GET request, so individual modules cannot forget to.
 * Forms include it with csrf_field(); fetch()/XHR may send an X-CSRF-Token header.
 */
final class Csrf
{
    public const FIELD = '_csrf';

    public function __construct(private Session $session)
    {
    }

    public function token(): string
    {
        $token = $this->session->get('_csrf');
        if (!is_string($token) || $token === '') {
            $token = bin2hex(random_bytes(32));
            $this->session->set('_csrf', $token);
        }
        return $token;
    }

    /** Discard the token (new one is created on next use). */
    public function rotate(): void
    {
        $this->session->forget('_csrf');
    }

    public function verify(Request $request): bool
    {
        $sent = $request->post(self::FIELD) ?? (string) ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
        $expected = $this->session->get('_csrf');
        return is_string($expected) && $expected !== '' && $sent !== '' && hash_equals($expected, $sent);
    }
}
