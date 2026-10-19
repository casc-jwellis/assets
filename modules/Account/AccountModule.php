<?php

declare(strict_types=1);

namespace App\Modules\Account;

use App\Core\Module;
use App\Core\Request;
use App\Core\Router;

/** Self-service account settings (no sidebar entry; reached from the top bar). */
final class AccountModule extends Module
{
    private const MIN_LENGTH = 10;

    public function routes(Router $router): void
    {
        // Reachable while a temporary password is still in force: it is the one page such a user may use.
        $router->get('/account/password', [$this, 'showPassword'], ['pending_ok' => true]);
        $router->post('/account/password', [$this, 'changePassword'], ['pending_ok' => true]);
    }

    public function showPassword(Request $req): string
    {
        return $this->page([]);
    }

    public function changePassword(Request $req): string
    {
        $auth = $this->app->auth;
        $uid = (int) $auth->user()['user_id'];
        $forced = $auth->mustChangePassword();
        $current = (string) $req->post('current');
        $new = (string) $req->post('new');
        $confirm = (string) $req->post('confirm');

        $errors = [];
        if (!$auth->verifyPassword($uid, $current)) {
            $errors[] = 'Your ' . ($forced ? 'temporary' : 'current') . ' password is incorrect.';
        }
        if (strlen($new) < self::MIN_LENGTH) {
            $errors[] = 'The new password must be at least ' . self::MIN_LENGTH . ' characters.';
        } elseif ($new === $current) {
            $errors[] = 'The new password must be different from the ' . ($forced ? 'temporary' : 'current') . ' one.';
        }
        if ($new !== $confirm) {
            $errors[] = 'The new password and confirmation do not match.';
        }
        if ($errors === [] && !$auth->setPassword($uid, $new)) {
            $errors[] = 'The password could not be saved. Ask an administrator to check the database setup.';
        }

        if ($errors !== []) {
            http_response_code(422);
            return $this->page($errors);
        }

        $auth->clearMustChangePassword($uid);
        $this->app->session->regenerate();
        $this->app->csrf->rotate();
        $this->app->session->flash('success', 'Your password has been updated.');
        $this->redirect($forced ? '/' : '/account/password');
    }

    /** @param list<string> $errors */
    private function page(array $errors): string
    {
        $forced = $this->app->auth->mustChangePassword();
        return $this->render('password', [
            'title'  => $forced ? 'Choose a new password' : 'Change password',
            'errors' => $errors,
            'min'    => self::MIN_LENGTH,
            'forced' => $forced,
        ]);
    }
}
