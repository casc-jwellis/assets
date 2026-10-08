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
        $router->get('/account/password', [$this, 'showPassword']);
        $router->post('/account/password', [$this, 'changePassword']);
    }

    public function showPassword(Request $req): string
    {
        return $this->render('password', ['title' => 'Change password', 'errors' => [], 'min' => self::MIN_LENGTH]);
    }

    public function changePassword(Request $req): string
    {
        $auth = $this->app->auth;
        $uid = (int) $auth->user()['user_id'];
        $current = (string) $req->post('current');
        $new = (string) $req->post('new');
        $confirm = (string) $req->post('confirm');

        $errors = [];
        if (!$auth->verifyPassword($uid, $current)) {
            $errors[] = 'Your current password is incorrect.';
        }
        if (strlen($new) < self::MIN_LENGTH) {
            $errors[] = 'The new password must be at least ' . self::MIN_LENGTH . ' characters.';
        }
        if ($new !== $confirm) {
            $errors[] = 'The new password and confirmation do not match.';
        }
        if ($errors === [] && !$auth->setPassword($uid, $new)) {
            $errors[] = 'The password could not be saved. Ask an administrator to check the database setup.';
        }

        if ($errors !== []) {
            http_response_code(422);
            return $this->render('password', ['title' => 'Change password', 'errors' => $errors, 'min' => self::MIN_LENGTH]);
        }

        $this->app->session->regenerate();
        $this->app->csrf->rotate();
        $this->app->session->flash('success', 'Your password has been updated.');
        $this->redirect('/account/password');
    }
}
