<?php

declare(strict_types=1);

namespace App\Modules\Email;

use App\Core\Module;
use App\Core\Request;
use App\Core\Router;
use App\Core\SecretBox;
use Tehsmtp\Address;
use Tehsmtp\TehsmtpException;

/**
 * Email (administrators only): the mail server, who gets notification emails, which events send one, a test
 * button, and a short log of what was sent. The sending itself is Notifier (which uses lib/tehsmtp/).
 *
 * Other modules ask for a notification through this module (see AssetsModule), so removing this folder simply
 * turns notifications off. Needs migrations 015 (settings) and 016 (email_log).
 */
final class EmailModule extends Module
{
    public function routes(Router $router): void
    {
        $admin = ['admin' => true];
        $router->get('/email', [$this, 'show'], $admin);
        $router->post('/email', [$this, 'save'], $admin);
        $router->post('/email/test', [$this, 'test'], $admin);
    }

    public function nav(): array
    {
        return [['label' => 'Email', 'path' => '/email', 'icon' => 'mail', 'order' => 86, 'admin' => true]];
    }

    /** What other modules call. Each returns null, or a warning for the person who did the action. */
    public function notifier(): Notifier
    {
        return new Notifier($this->app);
    }

    // ------------------------------------------------------------ the page

    public function show(Request $req): string
    {
        $n = $this->notifier();
        return $this->form($n->config(), []);
    }

    public function save(Request $req): string
    {
        $n = $this->notifier();
        $this->requireReady($n);
        $old = $n->config();

        $v = [
            'enabled'      => $req->post('enabled') === '1',
            'events'       => array_values(array_intersect(array_keys(Notifier::EVENTS), array_keys($req->postArray('events')))),
            'host'         => trim((string) $req->post('host')),
            'security'     => (string) $req->post('security'),
            'username'     => trim((string) $req->post('username')),
            'from_address' => trim((string) $req->post('from_address')),
            'from_name'    => trim((string) preg_replace('/\s+/u', ' ', (string) $req->post('from_name'))),
            'base_url'     => trim((string) $req->post('base_url')),
        ];
        $portRaw = trim((string) $req->post('port'));
        $password = (string) $req->post('password');
        $clearPassword = $req->post('clear_password') === '1';
        $errors = [];

        // ---- recipients: one per line, or separated by commas / semicolons; bare addresses only
        $recipients = [];
        foreach (preg_split('/[\s,;]+/', (string) $req->post('recipients'), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $raw) {
            try {
                require_once APP_ROOT . '/lib/tehsmtp/tehsmtp.php';
                $a = Address::parse($raw);
                if ($a->name !== '' || strcasecmp($a->email, $raw) !== 0) {
                    throw new \InvalidArgumentException('not a plain address');
                }
                $recipients[strtolower($a->email)] = $a->email; // de-duplicated, case-insensitively
            } catch (TehsmtpException | \InvalidArgumentException) {
                $errors[] = '"' . mb_substr($raw, 0, 60) . '" is not a valid email address.';
            }
        }
        $v['recipients'] = array_values($recipients);
        if (count($v['recipients']) > Notifier::MAX_RECIPIENTS) {
            $errors[] = 'At most ' . Notifier::MAX_RECIPIENTS . ' recipients.';
        }

        // ---- server
        if (!in_array($v['security'], ['starttls', 'implicit', 'none'], true)) {
            $errors[] = 'Choose how to secure the connection.';
            $v['security'] = 'starttls';
        }
        if ($v['host'] !== '' && (strlen($v['host']) > 255 || !preg_match('/^(?:[A-Za-z0-9](?:[A-Za-z0-9.\-]*[A-Za-z0-9])?|\[[0-9A-Fa-f:.]+\])$/', $v['host']))) {
            $errors[] = 'Enter the mail server as a host name such as smtp.gmail.com.';
        }
        $v['port'] = null;
        if ($portRaw !== '') {
            if (!ctype_digit($portRaw) || (int) $portRaw < 1 || (int) $portRaw > 65535) {
                $errors[] = 'The port must be a number from 1 to 65535 (587 for STARTTLS, 465 for SSL/TLS).';
            } else {
                $v['port'] = (int) $portRaw;
            }
        }
        if (strlen($v['username']) > 255 || preg_match('/[\x00-\x1f\x7f]/', $v['username'])) {
            $errors[] = 'The username is too long or has odd characters.';
        }
        if ($v['from_address'] !== '') {
            try {
                require_once APP_ROOT . '/lib/tehsmtp/tehsmtp.php';
                $a = Address::parse($v['from_address']);
                if ($a->name !== '' || strcasecmp($a->email, $v['from_address']) !== 0) {
                    throw new \InvalidArgumentException('not a plain address');
                }
            } catch (TehsmtpException | \InvalidArgumentException) {
                $errors[] = 'The "from" address is not a valid email address.';
            }
        }
        if (mb_strlen($v['from_name']) > 64 || preg_match('/[\x00-\x1f\x7f]/', $v['from_name'])) {
            $errors[] = 'The "from" name can be at most 64 characters.';
        }
        if ($v['base_url'] !== '' && (strlen($v['base_url']) > 200 || !preg_match('#^https?://[A-Za-z0-9.\-]+(:\d{1,5})?(/[A-Za-z0-9._~/-]*)?$#', $v['base_url']))) {
            $errors[] = 'The site address for links must look like https://assets.example.edu (no spaces or query).';
        }
        if (strlen($password) > 500) {
            $errors[] = 'That password is too long.';
        }
        if ($v['enabled']) {
            if ($v['host'] === '') {
                $errors[] = 'Enter the mail server before turning notifications on.';
            }
            if ($v['from_address'] === '') {
                $errors[] = 'Enter the "from" address before turning notifications on.';
            }
            if ($v['recipients'] === []) {
                $errors[] = 'Enter at least one recipient before turning notifications on.';
            }
        }
        if ($v['port'] === 465 && $v['security'] !== 'implicit') {
            $errors[] = 'Port 465 expects SSL/TLS. Choose SSL/TLS, or use STARTTLS with port 587.';
        } elseif (in_array($v['port'], [25, 587], true) && $v['security'] === 'implicit') {
            $errors[] = 'Port ' . $v['port'] . ' is for STARTTLS (or an unencrypted relay), not SSL/TLS. Use SSL/TLS with port 465, or choose STARTTLS.';
        }
        if ($v['host'] !== '' && $v['security'] === 'none' && $v['username'] !== '' && !in_array($v['host'], ['localhost', '127.0.0.1', '[::1]'], true)) {
            $errors[] = 'A login cannot be sent over an unencrypted connection. Choose STARTTLS or SSL/TLS, or clear the username.';
        }
        $hasPassword = ($password !== '' || ($old['has_password'] && !$clearPassword));

        // A username needs a password: the one just typed, or the one already saved (a blank box keeps it).
        if ($v['username'] !== '' && !$hasPassword) {
            $errors[] = 'Enter the password for that username, or clear the username if the server needs no login.';
        }

        // Let the library check the combination (SSL/TLS on port 587, a bad host name, ...): it explains itself.
        // A saved password counts as "a password" here; the real value is only read when mail is actually sent.
        if ($errors === [] && $v['host'] !== '') {
            try {
                $n->mailer($v + ['from_address' => $v['from_address']], $password !== '' ? $password : ($hasPassword ? 'saved-password' : null));
            } catch (TehsmtpException $e) {
                $errors[] = trim((string) preg_replace('/\s+/', ' ', $e->getMessage()));
            }
        }

        $v['has_password'] = $hasPassword;
        if ($errors !== []) {
            http_response_code(422);
            return $this->form($v, $errors);
        }

        try {
            $s = $this->app->settings;
            $s->set('mail.enabled', $v['enabled'] ? '1' : '0');
            $s->set('mail.events', implode(',', $v['events']));
            $s->set('mail.recipients', implode("\n", $v['recipients']));
            $s->set('mail.host', $v['host']);
            $s->set('mail.port', $v['port'] === null ? '0' : (string) $v['port']);
            $s->set('mail.security', $v['security']);
            $s->set('mail.username', $v['username']);
            $s->set('mail.from_address', $v['from_address']);
            $s->set('mail.from_name', $v['from_name']);
            $s->set('mail.base_url', $v['base_url']);
            if ($clearPassword || $v['username'] === '') {
                $s->delete('mail.password');
            } elseif ($password !== '') {
                $s->set('mail.password', SecretBox::encrypt($password));
            }
        } catch (\RuntimeException $e) { // SecretBox: no OpenSSL, or storage/ not writable
            http_response_code(500);
            return $this->form($v, [$e->getMessage() . ' The other settings were saved; the password was not.']);
        } catch (\PDOException $e) {
            error_log('email settings save failed: ' . $e->getMessage());
            http_response_code(500);
            return $this->form($v, ['The settings could not be saved because of a database error.']);
        }

        $this->app->session->flash('success', 'Email settings saved.');
        $this->redirect('/email');
    }

    public function test(Request $req): string
    {
        $n = $this->notifier();
        $this->requireReady($n);
        $to = trim((string) $req->post('test_to'));
        try {
            require_once APP_ROOT . '/lib/tehsmtp/tehsmtp.php';
            $a = Address::parse($to);
            if ($a->name !== '' || strcasecmp($a->email, $to) !== 0) {
                throw new \InvalidArgumentException('not a plain address');
            }
        } catch (TehsmtpException | \InvalidArgumentException) {
            http_response_code(422);
            return $this->form($n->config(), ['Enter a valid email address to send the test to.'], $to);
        }
        [$ok, $message] = $n->sendTest($to);
        $this->app->session->flash($ok ? 'success' : 'danger', $message);
        $this->redirect('/email');
    }

    // ------------------------------------------------------------ helpers

    private function requireReady(Notifier $n): void
    {
        if (!$n->ready()) {
            $this->app->error(503, 'Email is not available yet', 'The database needs updating first: apply migrations 015_settings.sql and ' . Notifier::MIGRATION . ' on the Migrations page.');
        }
    }

    private function form(array $v, array $errors, ?string $testTo = null): string
    {
        $n = $this->notifier();
        $ready = $n->ready();
        $log = [];
        if ($ready) {
            try {
                $log = $this->app->db->all('SELECT created_date, event, subject, recipients, status, detail FROM email_log ORDER BY log_id DESC LIMIT 25');
            } catch (\Throwable) {
                $log = [];
            }
        }
        $me = $this->app->auth->user();
        $v['password_ok'] ??= $ready ? $n->config()['password_ok'] : true;
        return $this->render('index', [
            'title'     => 'Email',
            'ready'     => $ready,
            'v'         => $v,
            'errors'    => $errors,
            'events'    => Notifier::EVENTS,
            'log'       => $log,
            'testTo'    => $testTo ?? (string) ($me['email'] ?? ''),
            'canStore'  => SecretBox::available(),
            'siteName'  => app_name(),
            'maxRecipients' => Notifier::MAX_RECIPIENTS,
        ]);
    }
}
