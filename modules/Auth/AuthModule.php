<?php

declare(strict_types=1);

namespace App\Modules\Auth;

use App\Core\Auth;
use App\Core\Module;
use App\Core\Request;
use App\Core\Router;

/** Login / logout, and "forgot password". The only routes reachable while logged out. */
final class AuthModule extends Module
{
    public function routes(Router $router): void
    {
        $router->get('/login', [$this, 'showLogin'], ['public' => true]);
        $router->post('/login', [$this, 'login'], ['public' => true]);
        $router->post('/logout', [$this, 'logout'], ['pending_ok' => true]);

        $router->get('/forgot', [$this, 'showForgot'], ['public' => true]);
        $router->post('/forgot', [$this, 'forgot'], ['public' => true]);
        // The secret in the address is 43 URL-safe characters (checked when it is looked up); anything else is simply "not a valid link".
        $router->get('/reset/{token}', [$this, 'showReset'], ['public' => true]);
        $router->post('/reset/{token}', [$this, 'reset'], ['public' => true]);
    }

    private function resets(): PasswordResets
    {
        return new PasswordResets($this->app);
    }

    public function showLogin(Request $req): string
    {
        if ($this->app->auth->check()) {
            $this->redirect('/');
        }
        return $this->loginPage('', null);
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
        return $this->loginPage($username, 'Invalid username or password.'); // same message for unknown user / wrong password
    }

    private function loginPage(string $username, ?string $error): string
    {
        return $this->render('login', [
            'title'    => 'Sign in',
            'username' => $username,
            'error'    => $error,
            'canReset' => $this->resets()->available(),
        ], 'auth');
    }

    public function logout(Request $req): never
    {
        $this->app->auth->logout();
        $this->app->session->start();
        $this->app->session->flash('success', 'You have been signed out.');
        $this->redirect('/login');
    }

    // ------------------------------------------------------------------ forgot password

    public function showForgot(Request $req): string
    {
        $this->requireSignedOut();
        $this->requireResets();
        return $this->render('forgot', ['title' => 'Forgot password', 'typed' => ''], 'auth');
    }

    /**
     * Whoever asks gets the same answer, whether or not an account matched, and gets it at the same speed: the email
     * is sent after the response has gone, so how long it takes cannot show whether there was anyone to write to.
     */
    public function forgot(Request $req): never
    {
        $this->requireSignedOut();
        $this->requireResets();
        $resets = $this->resets();
        $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');

        $user = $resets->find((string) $req->post('identifier'));
        $secret = null;
        if ($user !== null && !$resets->throttled((int) $user['user_id'], $ip)) {
            $secret = $resets->issue((int) $user['user_id'], $ip);
        }

        $this->app->session->flash('info', 'If that matches an account with an email address, a link to choose a new password is on its way. It works once and expires in ' . PasswordResets::LIFETIME_MINUTES . ' minutes.');
        $this->finishResponse(url('/login'));

        if ($secret !== null && $user !== null) {
            $email = $this->app->module('Email');
            if ($email !== null && method_exists($email, 'notifier')) {
                $email->notifier()->sendPasswordReset($user, '/reset/' . $secret, PasswordResets::LIFETIME_MINUTES);
            }
        }
        exit;
    }

    public function showReset(Request $req, array $params): string
    {
        $this->requireSignedOut();
        $user = $this->resets()->available() ? $this->resets()->lookup((string) $params['token']) : null;
        if ($user === null) {
            return $this->resetProblem();
        }
        return $this->resetPage($user, []);
    }

    public function reset(Request $req, array $params): string
    {
        $this->requireSignedOut();
        $resets = $this->resets();
        $user = $resets->available() ? $resets->lookup((string) $params['token']) : null;
        if ($user === null) {
            return $this->resetProblem();
        }

        $new = (string) $req->post('new');
        $confirm = (string) $req->post('confirm');
        $errors = [];
        if (strlen($new) < Auth::MIN_PASSWORD) {
            $errors[] = 'The new password must be at least ' . Auth::MIN_PASSWORD . ' characters.';
        } elseif (strlen($new) > Auth::MAX_PASSWORD) {
            $errors[] = 'The new password can be at most ' . Auth::MAX_PASSWORD . ' characters (letters outside A to Z count as more than one).';
        } elseif ($new !== $confirm) {
            $errors[] = 'The new password and confirmation do not match.';
        }
        $uid = (int) $user['user_id'];
        if ($errors === [] && !$this->app->auth->setPassword($uid, $new)) {
            $errors[] = 'The password could not be saved. Ask an administrator to check the database setup.';
        }
        if ($errors !== []) {
            http_response_code(422);
            return $this->resetPage($user, $errors);
        }

        // Done: this link and any other are spent, and a temporary password no longer needs replacing.
        $resets->clear($uid);
        $this->app->auth->clearMustChangePassword($uid);
        $this->app->session->flash('success', 'Your password has been changed. Sign in with the new one.');
        $this->redirect('/login');
    }

    private function resetPage(array $user, array $errors): string
    {
        return $this->render('reset', [
            'title'    => 'Choose a new password',
            'username' => $user['username'],
            'errors'   => $errors,
            'min'      => Auth::MIN_PASSWORD,
            'max'      => Auth::MAX_PASSWORD,
        ], 'auth');
    }

    /** The link is wrong, used, expired, or the account can no longer be reset. One message for all of them. */
    private function resetProblem(): string
    {
        http_response_code(400);
        return $this->render('reset-invalid', ['title' => 'Link not valid', 'canAsk' => $this->resets()->available()], 'auth');
    }

    // ------------------------------------------------------------------ helpers

    private function requireSignedOut(): void
    {
        if ($this->app->auth->check()) {
            $this->redirect('/account/password');
        }
    }

    /** "Forgot password" needs its table and a working mail setup; without them the page does not exist. */
    private function requireResets(): void
    {
        if (!$this->resets()->available()) {
            $this->app->session->flash('info', 'Resetting a password by email is not set up. Ask an administrator.');
            $this->redirect('/login');
        }
    }

    /** Send the redirect now and let the rest of the script run after the browser has it. */
    private function finishResponse(string $location): void
    {
        ignore_user_abort(true);
        http_response_code(303);
        header('Location: ' . $location);
        header('Content-Length: 0');
        header('Connection: close');
        session_write_close(); // the flash is saved before we let go of the browser
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        } else {
            while (ob_get_level() > 0) {
                ob_end_flush();
            }
            flush();
        }
    }
}
