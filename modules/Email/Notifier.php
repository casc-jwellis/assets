<?php

declare(strict_types=1);

namespace App\Modules\Email;

use App\Core\App;
use App\Core\SecretBox;
use Tehsmtp\Address;
use Tehsmtp\DeliveryUnknownException;
use Tehsmtp\Mailer;
use Tehsmtp\Message;
use Tehsmtp\PlaintextAuth;
use Tehsmtp\Timeouts;
use Tehsmtp\TehsmtpException;
use Tehsmtp\Tls;

/**
 * Builds and sends the notification emails, using the tehsmtp library in lib/tehsmtp/.
 *
 * Settings come from the `settings` table (keys mail.*; the SMTP password is stored encrypted, see SecretBox).
 * Sending never gets in the way of the action that caused it: every public notify method returns null when all
 * is well (or when email is simply off) and otherwise a short warning for the person who just did the action.
 * Each attempt is written to the email_log table.
 */
final class Notifier
{
    public const MIGRATION = '016_email_log.sql';

    /** event key => label on the settings page */
    public const EVENTS = [
        'created'     => 'An asset is added',
        'retired'     => 'Assets are retired',
        'transferred' => 'Assets are transferred',
    ];

    public const MAX_RECIPIENTS = 50;
    private const LIST_LIMIT = 50;
    private const LOG_KEEP = 500;

    private static bool $loaded = false;

    public function __construct(private App $app)
    {
    }

    // ------------------------------------------------------------------ settings

    public function ready(): bool
    {
        return $this->app->migrationApplied('015_settings.sql') && $this->app->migrationApplied(self::MIGRATION);
    }

    /**
     * The mail settings with defaults. The password is NOT included: use password().
     *
     * @return array{enabled:bool, events:list<string>, recipients:list<string>, host:string, port:?int, security:string,
     *               username:string, from_address:string, from_name:string, base_url:string, has_password:bool, password_ok:bool}
     */
    public function config(): array
    {
        $s = $this->app->settings;
        $events = array_values(array_intersect(array_keys(self::EVENTS), array_filter(explode(',', (string) $s->get('mail.events', implode(',', array_keys(self::EVENTS)))))));
        $port = (int) $s->get('mail.port', '0');
        $security = (string) $s->get('mail.security', 'starttls');
        return [
            'enabled'      => $s->get('mail.enabled') === '1',
            'events'       => $events,
            'recipients'   => array_values(array_filter(array_map('trim', explode("\n", (string) $s->get('mail.recipients', ''))), 'strlen')),
            'host'         => (string) $s->get('mail.host', ''),
            'port'         => $port > 0 ? $port : null,
            'security'     => in_array($security, ['starttls', 'implicit', 'none'], true) ? $security : 'starttls',
            'username'     => (string) $s->get('mail.username', ''),
            'from_address' => (string) $s->get('mail.from_address', ''),
            'from_name'    => (string) $s->get('mail.from_name', ''),
            'base_url'     => (string) $s->get('mail.base_url', ''),
            'has_password' => $s->get('mail.password') !== null && $s->get('mail.password') !== '',
            // false when a password is saved but cannot be read back (the key file storage/secret.key is gone)
            'password_ok'  => $s->get('mail.password') === null || $s->get('mail.password') === '' || $this->password() !== null,
        ];
    }

    private function password(): ?string
    {
        $stored = $this->app->settings->get('mail.password');
        return $stored === null || $stored === '' ? null : SecretBox::decrypt($stored);
    }

    /** True when a notification could actually go out: enabled, a server, a from address and someone to tell. */
    public function active(array $c): bool
    {
        return $c['enabled'] && $c['host'] !== '' && $c['from_address'] !== '' && $c['recipients'] !== [];
    }

    // ------------------------------------------------------------------ the library

    private function loadLibrary(): void
    {
        if (!self::$loaded) {
            require_once APP_ROOT . '/lib/tehsmtp/tehsmtp.php';
            self::$loaded = true;
        }
    }

    /**
     * A Mailer for these settings. The library checks every option here and throws ConfigException for a bad
     * combination (for example SSL/TLS on port 587), with a message fit to show an administrator.
     */
    public function mailer(array $c, ?string $password): Mailer
    {
        $this->loadLibrary();
        $tls = match ($c['security']) {
            'implicit' => Tls::Implicit,
            'none'     => Tls::None,
            default    => Tls::StartTls,
        };
        $domain = substr(strrchr($c['from_address'], '@') ?: '', 1);
        return new Mailer(
            host: $c['host'],
            port: $c['port'],
            tls: $tls,
            username: $c['username'] !== '' ? $c['username'] : null,
            password: $c['username'] !== '' ? $password : null,
            // A login is only ever sent over verified TLS, or to a relay on this same machine; never across a network in plaintext.
            plaintextAuth: PlaintextAuth::LoopbackOnly,
            // Web-request timeouts: a mail server that does not answer must not hold the page for minutes.
            timeouts: Timeouts::interactive(),
            idDomain: $domain !== '' ? $domain : null,
        );
    }

    /** A Message from the configured sender to the configured recipients. */
    private function message(array $c, string $subject, string $body): Message
    {
        $this->loadLibrary();
        $from = new Address($c['from_address'], $c['from_name'] !== '' ? $c['from_name'] : app_name());
        return (new Message())
            ->from($from)
            ->to(...array_map(static fn(string $a): Address => new Address($a), $c['recipients']))
            ->subject($this->oneLine($subject))
            ->text($body)
            ->autoSubmitted('auto-generated'); // tells auto-repliers and mail rules this is not a person writing
    }

    // ------------------------------------------------------------------ sending

    /**
     * Send one notification if this event is switched on and email is set up.
     *
     * @return string|null null when sent or not applicable; otherwise a warning to show the person who did the action
     */
    public function notify(string $event, string $subject, string $body): ?string
    {
        if (!$this->ready()) {
            return null;
        }
        $c = $this->config();
        if (!$this->active($c) || !in_array($event, $c['events'], true)) {
            return null;
        }
        [$ok, $detail, $unknown] = $this->deliver($c, $c['recipients'], $subject, $body);
        $this->log($event, $subject, $c['recipients'], $ok ? 'sent' : ($unknown ? 'unknown' : 'failed'), $detail);
        if ($ok && $detail === '') {
            return null;
        }
        $admin = $this->app->auth->isAdmin();
        if ($ok) { // sent to some, refused for others
            return 'The notification email went out, but not to everyone.' . ($admin ? ' ' . $detail : ' An administrator can see details on the Email page.');
        }
        return 'The change was saved, but the notification email ' . ($unknown ? 'may or may not have been delivered' : 'could not be sent')
            . '.' . ($admin ? ' ' . $detail : ' An administrator can see details on the Email page.');
    }

    /**
     * Send a test message to one address, whatever the on/off and event settings.
     *
     * @return array{0:bool, 1:string} success and a message for the administrator
     */
    public function sendTest(string $to): array
    {
        $c = $this->config();
        if ($c['host'] === '' || $c['from_address'] === '') {
            return [false, 'Save the mail server and the from address first.'];
        }
        $subject = '[' . app_name() . '] Test email';
        $body = "This is a test email from " . app_name() . ".\n\nIf you can read this, the mail settings work."
            . "\nSent by " . $this->actor() . ' on ' . date('Y-m-d H:i') . ".\n";
        [$ok, $detail, $unknown] = $this->deliver($c, [$to], $subject, $body);
        $this->log('test', $subject, [$to], $ok ? 'sent' : ($unknown ? 'unknown' : 'failed'), $detail);
        if ($ok && $detail === '') {
            return [true, 'The test email was accepted by the mail server for ' . $to . '. Check that inbox (and its spam folder).'];
        }
        return [$ok, ($ok ? 'The test email went out with a problem: ' : ($unknown ? 'The test email may or may not have been delivered: ' : 'The test email could not be sent: ')) . $detail];
    }

    /**
     * @param list<string> $recipients
     * @return array{0:bool, 1:string, 2:bool} sent to at least one recipient, what went wrong (or ''), delivery uncertain
     */
    private function deliver(array $c, array $recipients, string $subject, string $body): array
    {
        try {
            $c['recipients'] = $recipients;
            $mailer = $this->mailer($c, $this->password());
            $message = $this->message($c, $subject, $body);
            $result = $mailer->send($message);
            $mailer->close();
            $refused = array_merge($result->rejected(), $result->deferred(), $result->unknown());
            if ($refused === []) {
                return [true, '', false];
            }
            return [true, 'Not accepted for: ' . implode(', ', array_map(static fn($r): string => $r->address->email, $refused)) . '.', false];
        } catch (DeliveryUnknownException $e) {
            error_log('email delivery unknown: ' . $e->getMessage());
            return [false, $this->clean($e->getMessage()), true];
        } catch (TehsmtpException $e) {
            error_log('email failed: ' . $e->getMessage());
            return [false, $this->clean($e->getMessage()), false];
        } catch (\Throwable $e) {
            error_log('email failed: ' . $e::class . ': ' . $e->getMessage());
            return [false, 'Unexpected error: ' . $this->clean($e->getMessage()), false];
        }
    }

    private function log(string $event, string $subject, array $recipients, string $status, string $detail): void
    {
        try {
            $db = $this->app->db;
            $db->execute(
                'INSERT INTO email_log (created_date, event, subject, recipients, status, detail) VALUES (?, ?, ?, ?, ?, ?)',
                [date('Y-m-d H:i:s'), $event, mb_substr($this->oneLine($subject), 0, 200), mb_substr(implode(', ', $recipients), 0, 600), $status, mb_substr($detail, 0, 500)]
            );
            $db->execute('DELETE FROM email_log WHERE log_id <= (SELECT m FROM (SELECT MAX(log_id) - ' . self::LOG_KEEP . ' AS m FROM email_log) t)');
        } catch (\Throwable $e) {
            error_log('email log failed: ' . $e->getMessage());
        }
    }

    // ------------------------------------------------------------------ the messages

    /** A new asset, read back from the database so the email shows what was really stored. */
    public function assetCreated(int $assetId): ?string
    {
        if (!$this->wanted('created')) {
            return null;
        }
        $a = $this->app->db->one(
            'SELECT a.*, t.name AS type_name, d.abbr AS dept_abbr, d.name AS dept_name, b.name AS building_name
               FROM assets a
               LEFT JOIN asset_types t ON t.type_id = a.type_id
               LEFT JOIN departments d ON d.department_id = a.department_id
               LEFT JOIN buildings b ON b.building_id = a.building_id
              WHERE a.asset_id = ?',
            [$assetId]
        );
        if ($a === null) {
            return null;
        }
        $where = trim((string) $a['building_name'] . ((string) $a['room'] !== '' ? ', room ' . $a['room'] : ''), ', ');
        $body = $this->intro('An asset was added')
            . $this->pairs([
                'Asset'       => (string) $a['asset_number'],
                'Description' => (string) $a['description'],
                'Type'        => (string) ($a['type_name'] ?? ''),
                'Serial'      => (string) $a['serial_number'],
                'Department'  => trim($a['dept_abbr'] . ' - ' . $a['dept_name'], ' -'),
                'Location'    => $where,
                'Cost'        => fmt_money($a['cost']),
                'Purchased'   => fmt_date($a['purchase_date']),
            ])
            . "\nView it: " . $this->link('/assets/' . $assetId) . "\n" . $this->footer();
        return $this->notify('created', '[' . app_name() . '] Asset ' . $a['asset_number'] . ' added', $body);
    }

    /**
     * @param list<array{asset_id:int, asset_number:string, description:string, dept:string, location:string}> $items
     * @param array{date:string, method:string, notes:string} $info
     */
    public function assetsRetired(array $items, array $info): ?string
    {
        if ($items === [] || !$this->wanted('retired')) {
            return null;
        }
        $n = count($items);
        $subject = $n === 1 ? 'Asset ' . $items[0]['asset_number'] . ' retired' : $n . ' assets retired';
        $lines = array_map(static fn(array $i): string => $i['asset_number'] . '  ' . $i['description'] . '  (' . trim($i['dept'] . ' ' . $i['location']) . ')', $items);
        $body = $this->intro($n === 1 ? 'An asset was retired' : $n . ' assets were retired')
            . $this->pairs(['Retired on' => $info['date'], 'Disposal' => $info['method'], 'Notes' => $info['notes']])
            . "\n" . $this->list($lines)
            . "\nView " . ($n === 1 ? 'it' : 'them') . ': ' . ($n === 1 ? $this->link('/assets/' . $items[0]['asset_id']) : $this->link('/assets?status=retired')) . "\n"
            . $this->footer();
        return $this->notify('retired', '[' . app_name() . '] ' . $subject, $body);
    }

    /**
     * @param list<array{asset_id:int, asset_number:string, description:string, from:string, to:string}> $items
     */
    public function assetsTransferred(array $items, string $reason): ?string
    {
        if ($items === [] || !$this->wanted('transferred')) {
            return null;
        }
        $n = count($items);
        $subject = $n === 1 ? 'Asset ' . $items[0]['asset_number'] . ' transferred' : $n . ' assets transferred';
        $lines = array_map(static fn(array $i): string => $i['asset_number'] . '  ' . $i['description'] . '  (' . $i['from'] . '  ->  ' . $i['to'] . ')', $items);
        $body = $this->intro($n === 1 ? 'An asset was transferred' : $n . ' assets were transferred')
            . $this->pairs(['Reason' => $reason])
            . "\n" . $this->list($lines)
            . "\nView " . ($n === 1 ? 'it' : 'them') . ': ' . ($n === 1 ? $this->link('/assets/' . $items[0]['asset_id']) : $this->link('/assets')) . "\n"
            . $this->footer();
        return $this->notify('transferred', '[' . app_name() . '] ' . $subject, $body);
    }

    /** Is there anything to do for this event? (Skips the database work of building a message when email is off.) */
    private function wanted(string $event): bool
    {
        if (!$this->ready()) {
            return false;
        }
        $c = $this->config();
        return $this->active($c) && in_array($event, $c['events'], true);
    }

    // ------------------------------------------------------------------ text helpers

    private function intro(string $what): string
    {
        return $what . ' by ' . $this->actor() . ' on ' . date('Y-m-d H:i') . ".\n\n";
    }

    private function actor(): string
    {
        $u = $this->app->auth->user();
        return $u === null ? 'someone' : (trim($u['firstname'] . ' ' . $u['lastname']) ?: (string) $u['username']);
    }

    /** "Label:   value" lines, skipping empty values, labels padded to line up. */
    private function pairs(array $pairs): string
    {
        $pairs = array_filter(array_map(fn($v) => $this->oneLine((string) $v), $pairs), 'strlen');
        $width = $pairs === [] ? 0 : max(array_map('strlen', array_keys($pairs))) + 2;
        $out = '';
        foreach ($pairs as $label => $value) {
            $out .= str_pad($label . ':', $width) . ' ' . $value . "\n";
        }
        return $out;
    }

    /** @param list<string> $lines at most LIST_LIMIT shown, then "...and N more" */
    private function list(array $lines): string
    {
        $out = '';
        foreach (array_slice($lines, 0, self::LIST_LIMIT) as $l) {
            $out .= '  ' . $this->oneLine($l) . "\n";
        }
        return count($lines) > self::LIST_LIMIT ? $out . '  ...and ' . (count($lines) - self::LIST_LIMIT) . " more\n" : $out;
    }

    private function footer(): string
    {
        return "\n--\nSent by " . app_name() . '. An administrator can change who receives these on the Email page: ' . $this->link('/email') . "\n";
    }

    /** An absolute link: the address set on the Email page, else the one this request came in on. */
    private function link(string $path): string
    {
        $base = rtrim($this->config()['base_url'], '/');
        if ($base === '') {
            $req = $this->app->request;
            $host = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
            $base = ($req->isSecure() ? 'https://' : 'http://') . (preg_match('/^[A-Za-z0-9.\-\[\]:]+$/', $host) ? $host : 'localhost') . $req->basePath;
        }
        return $base . $path;
    }

    /** One line, no control characters (a stray line break in a subject or value must never become a header). */
    private function oneLine(string $s): string
    {
        return trim((string) preg_replace('/[\x00-\x1f\x7f]+/u', ' ', mb_scrub($s)));
    }

    /** A library message made safe to store and show: one line, bounded. */
    private function clean(string $s): string
    {
        return mb_substr($this->oneLine($s), 0, 400);
    }
}
