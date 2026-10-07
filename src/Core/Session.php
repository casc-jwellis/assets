<?php

declare(strict_types=1);

namespace App\Core;

final class Session
{
    public function __construct(private Config $config, private Request $request)
    {
    }

    public function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        $secure = $this->config->get('session.secure');
        session_name((string) $this->config->get('session.name', 'assets_session'));
        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => '/',
            'secure'   => $secure ?? $this->request->isSecure(),
            'httponly' => true,       // not readable from JavaScript
            'samesite' => 'Lax',      // extra CSRF layer on top of tokens
        ]);
        ini_set('session.use_strict_mode', '1');   // refuse unknown, client-chosen IDs
        ini_set('session.use_only_cookies', '1');
        session_start();
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $_SESSION[$key] ?? $default;
    }

    public function set(string $key, mixed $value): void
    {
        $_SESSION[$key] = $value;
    }

    public function forget(string $key): void
    {
        unset($_SESSION[$key]);
    }

    /** New session ID, same data. Call on every privilege change (login). */
    public function regenerate(): void
    {
        session_regenerate_id(true);
    }

    public function destroy(): void
    {
        $_SESSION = [];
        if (session_status() === PHP_SESSION_ACTIVE) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', [
                'expires'  => time() - 3600,
                'path'     => $p['path'],
                'secure'   => $p['secure'],
                'httponly' => true,
                'samesite' => $p['samesite'] ?? 'Lax',
            ]);
            session_destroy();
        }
    }

    public function flash(string $type, string $message): void
    {
        $_SESSION['_flash'][] = ['type' => $type, 'message' => $message];
    }

    /** @return list<array{type:string,message:string}> */
    public function pullFlashes(): array
    {
        $flashes = $_SESSION['_flash'] ?? [];
        unset($_SESSION['_flash']);
        return $flashes;
    }
}
