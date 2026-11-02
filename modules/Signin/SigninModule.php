<?php

declare(strict_types=1);

namespace App\Modules\Signin;

use App\Core\GoogleIdToken;
use App\Core\Module;
use App\Core\Request;
use App\Core\Router;

/**
 * Sign-in options (administrators only): "Sign in with Google" on the login page.
 *
 * The button itself, and checking what Google sends back, are in AuthModule and src/Core/GoogleIdToken.php; this
 * page only holds the two settings they read: whether it is on, and the Google client ID of this site. Needs
 * migration 015 (settings). Removing this folder leaves whatever was saved in effect but no way to change it.
 */
final class SigninModule extends Module
{
    public function routes(Router $router): void
    {
        $router->get('/signin', [$this, 'show'], ['admin' => true]);
        $router->post('/signin', [$this, 'save'], ['admin' => true]);
    }

    public function nav(): array
    {
        return [['label' => 'Sign-in', 'path' => '/signin', 'icon' => 'lock', 'order' => 87, 'admin' => true]];
    }

    public function show(Request $req): string
    {
        $s = $this->app->settings;
        return $this->form([
            'enabled'   => $s->get('google.enabled') === '1',
            'client_id' => (string) $s->get('google.client_id', ''),
        ], []);
    }

    public function save(Request $req): string
    {
        $this->requireReady();
        $v = [
            'enabled'   => $req->post('enabled') === '1',
            'client_id' => trim((string) $req->post('client_id')),
        ];
        $errors = [];
        if ($v['client_id'] !== '' && preg_match(GoogleIdToken::CLIENT_ID, $v['client_id']) !== 1) {
            $errors[] = 'That does not look like a Google client ID. It ends in .apps.googleusercontent.com.';
        }
        if ($v['enabled'] && $v['client_id'] === '') {
            $errors[] = 'Enter the client ID before turning Google sign-in on.';
        }
        if ($v['enabled'] && ($problem = GoogleIdToken::problem()) !== null) {
            $errors[] = $problem;
        }
        if ($errors !== []) {
            http_response_code(422);
            return $this->form($v, $errors);
        }

        try {
            $this->app->settings->set('google.enabled', $v['enabled'] ? '1' : '0');
            $this->app->settings->set('google.client_id', $v['client_id']);
        } catch (\PDOException $e) { // its text is not for the screen
            error_log('sign-in settings save failed: ' . $e->getMessage());
            http_response_code(500);
            return $this->form($v, ['The settings could not be saved because of a database error.']);
        }

        // Prove now that this server can fetch Google's signing keys, so a firewall problem shows up here
        // and not as a mysterious failure the first time someone tries the button.
        $warning = null;
        if ($v['enabled']) {
            try {
                (new GoogleIdToken(GoogleIdToken::cacheFile()))->refresh();
            } catch (\RuntimeException $e) {
                $warning = 'Saved, but this server could not reach Google (' . $e->getMessage() . '). '
                    . 'Sign-in with Google will fail until it can make HTTPS requests to www.googleapis.com.';
            }
        }
        $this->app->session->flash($warning === null ? 'success' : 'warning', $warning ?? 'Sign-in settings saved.');
        $this->redirect('/signin');
    }

    private function requireReady(): void
    {
        if (!$this->app->migrationApplied('015_settings.sql')) {
            $this->app->error(503, 'Sign-in settings are not available yet', 'The database needs updating first: apply migration 015_settings.sql on the Migrations page.');
        }
    }

    private function form(array $v, array $errors): string
    {
        $host = (string) ($_SERVER['HTTP_HOST'] ?? '');
        $origin = ($this->app->request->isSecure() ? 'https://' : 'http://') . (preg_match('/^[A-Za-z0-9.\-]+(:\d{1,5})?$/', $host) === 1 ? $host : 'your-site');

        $noEmail = 0;
        try {
            $noEmail = (int) $this->app->db->value("SELECT COUNT(*) FROM users WHERE email = ''" . ($this->app->auth->supportsDisabling() ? ' AND disabled = 0' : ''));
        } catch (\Throwable) {
        }
        return $this->render('index', [
            'title'   => 'Sign-in',
            'ready'   => $this->app->migrationApplied('015_settings.sql'),
            'v'       => $v,
            'errors'  => $errors,
            'problem' => GoogleIdToken::problem(),
            'origin'  => $origin,
            'secure'  => $this->app->request->isSecure(),
            'noEmail' => $noEmail,
        ]);
    }
}
