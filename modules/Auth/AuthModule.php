<?php

declare(strict_types=1);

namespace App\Modules\Auth;

use App\Core\Module;
use App\Core\Request;
use App\Core\Router;

/** Login / logout. The only routes reachable while logged out. */
final class AuthModule extends Module
{
    public function routes(Router $router): void
    {
        $router->get('/login', [$this, 'showLogin'], ['public' => true]);
        $router->post('/login', [$this, 'login'], ['public' => true]);
        $router->post('/logout', [$this, 'logout']);
    }

    public function showLogin(Request $req): string
    {
        if ($this->app->auth->check()) {
            $this->redirect('/');
        }
        return $this->render('login', ['title' => 'Sign in', 'username' => ''], 'auth');
    }

    public function login(Request $req): string
    {
        $username = trim((string) $req->post('username'));
        $password = (string) $req->post('password');

        if ($this->app->auth->attempt($username, $password)) {
            // Only ever redirect to an internal path we stored ourselves.
            $next = $this->app->session->get('auth.next');
            $this->app->session->forget('auth.next');
            $this->redirect(is_string($next) && str_starts_with($next, '/') && $next !== '/login' ? $next : '/');
        }

        http_response_code(401);
        return $this->render('login', [
            'title'    => 'Sign in',
            'username' => $username,
            'error'    => 'Invalid username or password.', // same message for unknown user / wrong password
        ], 'auth');
    }

    public function logout(Request $req): never
    {
        $this->app->auth->logout();
        $this->app->session->start();
        $this->app->session->flash('success', 'You have been signed out.');
        $this->redirect('/login');
    }
}
