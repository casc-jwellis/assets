<?php
/**
 * tehsmtp: the Mailer facade and the send results (§1, §2.4, §2.5, §3.2, §3.5, §3.8, §4, §5, §8).
 *
 * Mailer is the one public entry point: a flat list of named constructor options (validated
 * once, scrub first, §2.5), send() with its return-or-throw contract (§8.1), and the connection
 * management around Session: lazy open, the reuse check, the single transparent reconnect,
 * chunking and the 452 carry-over (§3.8). Result and RecipientResult are what send() returns.
 * Loaded only by tehsmtp.php, after the core (§2.1).
 */

declare(strict_types=1);

namespace Tehsmtp;

if (!\class_exists(Mailer::class, false)) {

    /**
     * An SMTP client for ONE server (§2.5, D19): configure it once, then send() messages.
     *
     *     $mailer = new Mailer('smtp.example.com', username: 'me@example.com', password: $pw);
     *     $result = $mailer->send((new Message)->from('me@example.com')->to('you@example.org')
     *         ->subject('Hello')->text("Hi!"));
     *
     * Defaults: port 587 with Tls::StartTls (required and verified, fails closed); AUTH only over
     * verified TLS (PLAIN, else LOGIN; XOAUTH2 when oauthToken is set); one connection, opened
     * lazily, reused after a liveness probe and closed by close() or, best effort, the destructor.
     *
     * send() RETURNS a Result when at least one recipient succeeded and none is Unknown; it throws
     * DeliveryUnknownException (never an SmtpException) when the message MAY have been delivered,
     * and SmtpException for a definite failure (§8.1). Nothing is retried except the single
     * transparent reconnect before anything was committed, the 452 carry-over, one XOAUTH2 token
     * refresh and the HELO fallback (§3.8).
     *
     * A Mailer is not shared: a clone would share the socket (UsageException), a forked child must
     * not use its parent's connection (call close() before pcntl_fork()), and serialize() gives an
     * inert placeholder (§8.4).
     */
    final class Mailer
    {
        /** The library version; tells which bundled copy is active when several are loaded (§2.1). */
        public const VERSION = '1.0.0';

        /** sslOptions keys a caller may set (§4.3); everything else is the library's. */
        private const SSL_OPTIONS = ['security_level', 'verify_depth', 'ciphers', 'capture_peer_cert_chain', 'local_cert', 'local_pk'];

        /** tlsMin values; '1.1' and '1.0' are the legacy opt-in (warning legacy-tls). */
        private const TLS_MIN = ['1.2', '1.3', '1.1', '1.0'];

        /** OPENSSL_VERSION_NUMBER of OpenSSL 1.1.1, the first with TLS 1.3. */
        private const OPENSSL_TLS13 = 0x10101000;

        /** RFC 3461 §4.1 NOTIFY values. */
        private const NOTIFY = ['SUCCESS', 'FAILURE', 'DELAY'];

        // ---- configuration (set by the constructor, never changed) ---------------------------------

        /** The name verified against the certificate (A-label, or an IP literal as given). */
        private string $host = '';

        /** peer_name: $host without brackets (§4.3). */
        private string $peerName = '';

        /** What is dialled: connectAddress ?? host. */
        private string $dial = '';

        private int $port = 0;
        private Tls $tls = Tls::StartTls;
        private ?string $username = null;
        private ?Secret $password = null;

        /** A fixed XOAUTH2 access token. */
        private ?Secret $token = null;

        /** fn(bool $forceRefresh): string, the XOAUTH2 token provider (§5.2). */
        private ?\Closure $provider = null;

        private ?AuthMethod $authMethod = null;
        private ?string $helo = null;

        /** @var list<Dkim> after validation (mixed input before) */
        private array $dkim = [];

        private ?string $bindTo = null;
        private ?string $caFile = null;
        private ?string $caPath = null;

        /** @var list<string> after validation (mixed input before) */
        private array $pins = [];

        /** fn(list<string> $chainPem, string $host, array $tlsInfo): bool (§4.3). */
        private ?\Closure $verifyPeer = null;

        private string $tlsMin = '1.2';

        /** @var array<string, mixed> allowlisted after validation (mixed input before) */
        private array $sslOptions = [];

        private bool $insecureSkipVerify = false;
        private bool $insecureAuth = false;
        private PlaintextAuth $plaintextAuth = PlaintextAuth::Never;
        private bool $allowUnusualPort = false;
        private ?Timeouts $timeouts = null;
        private int $maxRecipients = 100;
        private bool $pipelining = true;
        private bool $allOrNothing = false;
        private bool $strictDsn = false;
        private string $irMode = 'auto';
        private bool $utcDates = false;
        private ?string $idDomain = null;
        private ?string $spoolDir = null;
        private int $spoolMemory = 2097152;
        private ?\Closure $logger = null;
        private ?Clock $clock = null;
        private ?RandomSource $random = null;
        private ?Connector $connector = null;

        /** The `ssl` stream context for every handshake (§4.3). */
        private array $ssl = [];

        /** STREAM_CRYPTO_METHOD_* mask from tlsMin (§4.3); 0 with Tls::None. */
        private int $crypto = 0;

        /** @var list<string> warnings every Result carries (legacy-tls, dkim-weak-key, idn-not-normalized, ...) */
        private array $configWarnings = [];

        // ---- state -----------------------------------------------------------------------------------

        /** The current connection, if any (a property, never a parameter: §8.4). */
        private ?Session $session = null;

        /** The mechanism AUTH succeeded with on the current connection. */
        private ?string $authMechanism = null;

        /** @var list<string> warnings of the current connection's setup; every Result sent over it carries them */
        private array $setupWarnings = [];

        /** Result->tls of the current (or last) connection: [] without TLS. It outlives a discarded connection. */
        private array $lastTls = [];

        /** A send(), connect() or ping() is running: a second call is re-entrancy (§3.8). */
        private bool $busy = false;

        /** Set by __unserialize(): every method throws UsageException (§8.4). */
        private bool $inert = false;

        /** The single transparent reconnect of the current send() was used (§3.8, D20). */
        private bool $reconnected = false;

        /** @var list<string> the warnings of the current send() */
        private array $warnings = [];

        /**
         * Every option is validated here, so a bad setting fails at construction, never mid-send
         * (§2.5). See docs/DESIGN.md §2.5 for each option; the README has the table.
         *
         * @param string                     $host       the name VERIFIED against the certificate; dialled unless $connectAddress
         * @param int|null                   $port       null: $tls->defaultPort()
         * @param Tls|null                   $tls        null: Implicit on port 465, else StartTls
         * @param string|Secret|null         $password   PLAIN or LOGIN
         * @param string|Secret|\Closure|null $oauthToken XOAUTH2: a token, or fn(bool $forceRefresh): string
         * @param Dkim|list<Dkim>            $dkim       signers; the first ends up on top
         * @param string|null                $connectAddress dial this IP or name instead, still verifying $host (§4.3)
         * @param string|null                $bindTo     the local source, "ip:port" (port 0: any)
         * @param string|null                $caFile     REPLACES the platform CA store (§4.3); so does $caPath
         * @param list<string>               $pins       64-hex certificate SHA-256 or "sha256/<base64 SPKI SHA-256>"
         * @param \Closure|null              $verifyPeer fn(list<string> $chainPem, string $host, array $tlsInfo): bool;
         *                                               refuse-only (D30)
         * @param string                     $tlsMin     '1.2' | '1.3' | '1.1' | '1.0' (the last two: legacy opt-in)
         * @param array<string, mixed>       $sslOptions only security_level, verify_depth, ciphers,
         *                                               capture_peer_cert_chain, local_cert, local_pk
         * @param \Closure|null              $logger     fn(string $line): void; lines are redacted and sanitised
         * @throws ConfigException for any invalid option (with $extension when a PHP extension is missing)
         * @throws UsageException  for an unserialized Dkim signer, password Secret or token Secret (§8.4)
         */
        public function __construct(
            string $host,
            ?int $port = null,
            ?Tls $tls = null,
            ?string $username = null,
            #[\SensitiveParameter] string|Secret|null $password = null,
            #[\SensitiveParameter] string|Secret|\Closure|null $oauthToken = null,
            ?AuthMethod $authMethod = null,
            ?string $helo = null,
            Dkim|array $dkim = [],
            ?string $connectAddress = null,
            ?string $bindTo = null,
            ?string $caFile = null,
            ?string $caPath = null,
            array $pins = [],
            ?\Closure $verifyPeer = null,
            string $tlsMin = '1.2',
            array $sslOptions = [],
            bool $insecureSkipVerify = false,
            bool $insecureAuthOverUnverifiedTls = false,
            PlaintextAuth $plaintextAuth = PlaintextAuth::Never,
            bool $allowUnusualPort = false,
            Timeouts $timeouts = new Timeouts(),
            int $maxRecipients = 100,
            bool $pipelining = true,
            bool $allOrNothing = false,
            bool $strictDsn = false,
            string $authInitialResponse = 'auto',
            bool $utcDates = false,
            ?string $idDomain = null,
            ?string $spoolDir = null,
            int $spoolMemory = 2097152,
            ?\Closure $logger = null,
            ?Clock $clock = null,
            ?RandomSource $random = null,
            ?Connector $connector = null,
        ) {
            // Scrub, then validate (§2.5, §8.4, V2): credentials become Secrets and every Closure,
            // injected object or caller-built array moves to a property; the parameters are then
            // emptied with settype(), because opcache removes a plain "= null" as a dead store
            // (ScrubTest). Nothing before this point can throw, so no trace records these values.
            $this->password = Secret::wrap($password);
            if ($oauthToken instanceof \Closure) {
                $this->provider = $oauthToken;
            } else {
                $this->token = Secret::wrap($oauthToken);
            }
            $this->logger = $logger;
            $this->verifyPeer = $verifyPeer;
            $this->clock = $clock;
            $this->random = $random;
            $this->connector = $connector;
            $this->dkim = $dkim instanceof Dkim ? [$dkim] : $dkim;
            $this->pins = $pins;
            $this->sslOptions = $sslOptions;
            \settype($password, 'null');
            \settype($oauthToken, 'null');
            \settype($logger, 'null');
            \settype($verifyPeer, 'null');
            \settype($clock, 'null');
            \settype($random, 'null');
            \settype($connector, 'null');
            \settype($dkim, 'null');
            \settype($pins, 'null');
            \settype($sslOptions, 'null');

            // Validation: private methods whose arguments are scalars (or nothing), so no frame that
            // can throw holds a credential, a Closure or an injected object (§8.4).
            $this->configureEndpoint($host, $port, $tls, $connectAddress, $bindTo, $allowUnusualPort);
            $this->username = $username;
            $this->authMethod = $authMethod;
            $this->plaintextAuth = $plaintextAuth;
            $this->irMode = $authInitialResponse;
            $this->configureCredentials();
            $this->caFile = $caFile;
            $this->caPath = $caPath;
            $this->tlsMin = $tlsMin;
            $this->insecureSkipVerify = $insecureSkipVerify;
            $this->insecureAuth = $insecureAuthOverUnverifiedTls;
            $this->configureTls();
            $this->timeouts = $timeouts;
            $this->maxRecipients = $maxRecipients;
            $this->pipelining = $pipelining;
            $this->allOrNothing = $allOrNothing;
            $this->strictDsn = $strictDsn;
            $this->utcDates = $utcDates;
            $this->spoolMemory = $spoolMemory;
            $this->configureMessages($helo, $idDomain, $spoolDir);
            $this->configureDkim();
            $this->clock ??= new SystemClock();
            $this->random ??= new SystemRandom();
            $this->connector ??= new StreamConnector();
        }

        /**
         * Renders, signs and sends one message (§6, §7, §3).
         *
         * @param string|Address|null $from   the reverse path; '<>' is the null sender; default: Sender, else From
         * @param list<string|Address>|null $to the envelope recipients, each entry exactly ONE mailbox;
         *                                    default: To, Cc and Bcc (de-duplicated)
         * @param list<string>        $notify DSN NOTIFY (RFC 3461): ['NEVER'] or any of SUCCESS, FAILURE, DELAY
         * @param string|null         $ret    DSN RET: 'FULL' or 'HDRS'
         * @param string|null         $envid  DSN ENVID: printable ASCII; at most 100 characters once xtext-encoded
         *                                    (DSN parameters are sent only when the server offers DSN; §3.5)
         * @return Result at least one recipient succeeded and none is Unknown; check rejected() and deferred()
         * @throws MessageException         invalid message or envelope input, before any I/O
         * @throws ConfigException          e.g. allOrNothing over maxRecipients, or a DKIM signer that cannot sign
         *                                  this message (RFC 8058), before any I/O
         * @throws DeliveryUnknownException the message MAY have been delivered: never re-send it automatically
         * @throws SmtpException            nothing was delivered; `result` is attached once RCPT was reached
         * @throws UsageException           a fork, re-entrancy, or an unserialized Mailer or Message
         */
        public function send(
            Message $message,
            string|Address|null $from = null,
            ?array $to = null,
            array $notify = [],
            ?string $ret = null,
            ?string $envid = null,
        ): Result {
            $this->enter();
            try {
                $this->warnings = $this->configWarnings;
                $this->reconnected = false;
                $envelope = $this->envelope($message, $from, $to, $notify, $ret, $envid);
                $rendered = $this->prepare($message, $envelope);
                try {
                    return $this->deliver($rendered, $envelope);
                } finally {
                    $rendered->spool()->close();   // the last use; Spool::__destruct is only the backstop (§6.8)
                }
            } finally {
                $this->busy = false;
            }
        }

        /**
         * Opens the connection now, to fail fast on the network, TLS or AUTH: greeting, EHLO,
         * STARTTLS, EHLO, AUTH (§3.2). An open connection is kept as it is.
         *
         * @return array see info()
         * @throws SmtpException
         * @throws UsageException a fork, re-entrancy, or an unserialized Mailer
         */
        public function connect(): array
        {
            $this->enter();
            try {
                $this->warnings = $this->configWarnings;   // details['warnings'] of a failure: this call's only
                if ($this->session === null || $this->session->state() !== State::Ready) {
                    $this->open();
                }
                return $this->info();
            } finally {
                $this->busy = false;
            }
        }

        /**
         * NOOP on the open connection: true when it is alive. False when there is none or it was
         * discarded (a failed NOOP closes it; the next send() opens a new one). Never connects.
         *
         * @throws UsageException a fork, re-entrancy, or an unserialized Mailer
         */
        public function ping(): bool
        {
            $this->enter();
            try {
                $s = $this->session;
                if ($s === null) {
                    return false;
                }
                if ($s->state() === State::Ready && $s->noop()) {
                    return true;
                }
                $this->discard();
                return false;
            } finally {
                $this->busy = false;
            }
        }

        /**
         * QUIT and 221 when the connection is Ready, else just closes it (§3.11). Idempotent and
         * never throws. It does nothing in a forked child (the socket belongs to the parent), in
         * the middle of a send(), or on an unserialized Mailer.
         */
        public function close(): void
        {
            if ($this->inert || $this->busy || $this->session === null) {
                return;
            }
            try {
                $pid = $this->session->pid();
            } catch (\Throwable) {
                return;
            }
            if ($pid !== 0 && $pid !== (int) \getmypid()) {
                return;
            }
            $this->busy = true;
            try {
                $this->discard();
            } finally {
                $this->busy = false;
            }
        }

        /**
         * The connection as it is now: connected, host, port, mode, server (line 1 of the EHLO
         * reply, sanitised), capabilities (keyword => parameters), tls (protocol, cipher, verified,
         * peerSha256; [] without TLS), authMechanism, localAddress (raw "ip:port").
         *
         * @throws UsageException for an unserialized Mailer
         */
        public function info(): array
        {
            $this->assertUsable();
            $s = $this->session;
            $caps = $s?->caps();
            return [
                'connected' => $s !== null && $s->state() === State::Ready,
                'host' => $this->host,
                'port' => $this->port,
                'mode' => $this->tls->value,
                'server' => $caps?->server() ?? '',
                'capabilities' => $caps === null ? [] : $caps->toArray()['keywords'],
                'tls' => $s?->tlsInfo() ?? [],
                'authMechanism' => $this->authMechanism,
                'localAddress' => $s?->localName(),
            ];
        }

        /** Best-effort close() when the process owns the connection; never throws (§3.11). */
        public function __destruct()
        {
            if ($this->inert || $this->busy) {
                return;
            }
            try {
                $this->close();
            } catch (\Throwable) {
                // A destructor must never throw.
            }
        }

        /** The settings with every secret masked (§8.4). */
        public function __debugInfo(): array
        {
            return [
                'host' => $this->host,
                'port' => $this->port,
                'tls' => $this->tls->value,
                'connectAddress' => $this->dial === $this->host ? null : $this->dial,
                'username' => $this->username,
                'password' => $this->password === null ? null : '[redacted]',
                'oauthToken' => $this->provider !== null ? '[token provider]' : ($this->token === null ? null : '[redacted]'),
                'authMethod' => $this->authMethod?->value,
                'dkim' => \array_map(static fn (Dkim $d): string => "d={$d->domain}; s={$d->selector}", $this->inert ? [] : $this->dkim),
                'pins' => \count($this->pins),
                'verifyPeer' => $this->verifyPeer !== null,
                'logger' => $this->logger !== null,
                'insecureSkipVerify' => $this->insecureSkipVerify,
                'plaintextAuth' => $this->plaintextAuth->name,
                'connected' => !$this->inert && $this->session !== null && $this->session->state() === State::Ready,
                'inert' => $this->inert,
            ];
        }

        /** A one-key placeholder: hooks, secrets and the socket never enter a serialised trace (§8.4). */
        public function __serialize(): array
        {
            return ['mailer' => '[redacted]'];
        }

        /** Gives an INERT Mailer: every method throws UsageException; close() and the destructor do nothing. */
        public function __unserialize(array $data): void
        {
            $this->inert = true;
        }

        /**
         * A clone would share the connection (§2.5).
         *
         * @throws UsageException always
         */
        public function __clone()
        {
            // The clone is destroyed when this throws: make its destructor a no-op first, so it can
            // never QUIT the original's connection.
            $this->inert = true;
            $this->session = null;
            throw new UsageException('a Mailer cannot be cloned: the clone would share the connection; create another Mailer');
        }

        // =============================================================================================
        // Construction (§2.5): each method validates a group of settings already in properties
        // =============================================================================================

        /** Host, port, mode, connectAddress, bindTo and the port/mode check (§4.2). */
        private function configureEndpoint(string $host, ?int $port, ?Tls $tls, ?string $connectAddress, ?string $bindTo,
            bool $allowUnusualPort): void
        {
            $this->host = $this->hostName($host, 'host');
            $this->peerName = \trim($this->host, '[]');
            $this->dial = $connectAddress === null ? $this->host : $this->hostName($connectAddress, 'connectAddress');
            $tls ??= $port === 465 ? Tls::Implicit : Tls::StartTls;
            $port ??= $tls->defaultPort();
            if ($port < 1 || $port > 65535) {
                throw new ConfigException("Invalid port {$port}: 1-65535");
            }
            $this->tls = $tls;
            $this->port = $port;
            if ($bindTo !== null) {
                $colon = \strrpos($bindTo, ':');
                if (Text::socketIp($bindTo) === null || (int) \substr($bindTo, (int) $colon + 1) > 65535) {
                    throw new ConfigException('Invalid bindTo "' . Text::sanitize($bindTo, 100)
                        . '": the local source is "ip:port", e.g. "192.0.2.10:0" or "[2001:db8::10]:0"');
                }
            }
            $this->bindTo = $bindTo;
            $this->allowUnusualPort = $allowUnusualPort;
            // §4.2: the mix-ups that otherwise hang until the greeting timeout (P29, X13).
            if (!$allowUnusualPort && (($port === 465 && $tls !== Tls::Implicit)
                || (($port === 25 || $port === 587) && $tls === Tls::Implicit))) {
                throw new ConfigException($port === 465
                    ? "Tls::{$tls->name} on port 465: that port expects TLS before the greeting (Tls::Implicit);"
                        . ' set allowUnusualPort: true if this server really works this way'
                    : "Tls::Implicit on port {$port}: that port expects plaintext and STARTTLS (Tls::StartTls);"
                        . ' set allowUnusualPort: true if this server really works this way');
            }
        }

        /**
         * A host name (converted to its A-label) or an IP literal, with or without brackets (§2.5).
         *
         * @throws ConfigException
         */
        private function hostName(string $name, string $option): string
        {
            if (Text::isIpLiteral($name)) {
                return $name;
            }
            $warnings = [];
            $ascii = Idna::toAscii($name, $error, $warnings);
            if ($ascii === null) {
                throw new ConfigException("Invalid {$option} \"" . Text::sanitize($name, 100) . "\": {$error}"
                    . ' (a host name or an IP address; the port is a separate option)');
            }
            \array_push($this->configWarnings, ...$warnings);
            return $ascii;
        }

        /** Every credential rule of §2.5 and §5.3. */
        private function configureCredentials(): void
        {
            $credentials = $this->hasCredentials();
            if ($this->password !== null && ($this->token !== null || $this->provider !== null)) {
                throw new ConfigException('Set either password (PLAIN, LOGIN) or oauthToken (XOAUTH2), not both');
            }
            if ($credentials && $this->username === null) {
                throw new ConfigException('A password or oauthToken needs a username');
            }
            if (!$credentials && $this->username !== null) {
                throw new ConfigException('username is set without a password or oauthToken');
            }
            if ($this->authMethod !== null && !$credentials) {
                throw new ConfigException("authMethod {$this->authMethod->value} is set without credentials");
            }
            $oauth = $this->token !== null || $this->provider !== null;
            if ($this->authMethod !== null && ($this->authMethod === AuthMethod::XOAuth2) !== $oauth) {
                throw new ConfigException("authMethod {$this->authMethod->value} does not match the credential: "
                    . ($oauth ? 'oauthToken is used with XOAUTH2' : 'a password is used with PLAIN or LOGIN'));
            }
            if (!\in_array($this->irMode, ['auto', 'always', 'never'], true)) {
                throw new ConfigException('Invalid authInitialResponse "' . Text::sanitize($this->irMode, 40)
                    . "\": 'auto', 'always' or 'never'");
            }
            if (!$credentials) {
                return;
            }
            $user = (string) $this->username;
            if ($user === '' || !Text::isUtf8($user) || \strpbrk($user, "\0\r\n\x01") !== false) {
                throw new ConfigException('Invalid username: it must be non-empty UTF-8 without NUL, CR, LF or ^A');
            }
            if ($this->password !== null) {
                self::assertPassword($this->password);
            }
            if ($this->token !== null && !Sasl::bearerToken($this->token)) {
                throw new ConfigException('Invalid oauthToken: an OAuth 2.0 bearer token is [A-Za-z0-9-._~+/]+ followed by any "="'
                    . ' (RFC 6750 §2.1)');
            }
            if ($this->tls === Tls::Opportunistic) {
                throw new ConfigException('Tls::Opportunistic never sends credentials: its encryption is unauthenticated'
                    . ' (RFC 7435; D12); use Tls::StartTls or Tls::Implicit for AUTH');
            }
            if ($this->tls === Tls::None && $this->plaintextAuth === PlaintextAuth::Never) {
                throw new ConfigException('Tls::None with credentials: they would travel in plaintext. For a local relay set'
                    . ' plaintextAuth: PlaintextAuth::LoopbackOnly (checked against the connected peer IP)');
            }
        }

        /**
         * The password rules (§5.3): non-empty UTF-8 without NUL. Its only argument is the Secret,
         * and the value is revealed only inside internal calls (I13).
         *
         * @throws ConfigException
         * @throws UsageException for an unserialized (poisoned) Secret (§8.4)
         */
        private static function assertPassword(Secret $password): void
        {
            if (\strlen($password->reveal()) === 0) {
                throw new ConfigException('Invalid password: it is empty');
            }
            if (\str_contains($password->reveal(), "\0")) {
                throw new ConfigException('Invalid password: it contains NUL, which PLAIN cannot carry (RFC 4616 §2)');
            }
            if (\preg_match('//u', $password->reveal()) !== 1) {
                throw new ConfigException('Invalid password: it is not valid UTF-8 (credentials are sent as UTF-8 bytes, D15)');
            }
        }

        /** The TLS rules of §2.5 and the context of §4.3. */
        private function configureTls(): void
        {
            $mode = $this->tls;
            $name = "Tls::{$mode->name}";
            if ($mode !== Tls::None && !\extension_loaded('openssl')) {
                throw new ConfigException("{$name} needs ext-openssl; without it only Tls::None works", 'openssl');
            }
            if (!\in_array($this->tlsMin, self::TLS_MIN, true)) {
                throw new ConfigException('Invalid tlsMin "' . Text::sanitize($this->tlsMin, 20) . "\": '1.2', '1.3', '1.1' or '1.0'");
            }
            $openssl = \defined('OPENSSL_VERSION_NUMBER') ? (int) \constant('OPENSSL_VERSION_NUMBER') : 0;
            if ($this->tlsMin === '1.3' && $mode !== Tls::None && $openssl < self::OPENSSL_TLS13) {
                throw new ConfigException("tlsMin '1.3' needs OpenSSL 1.1.1 or later; PHP is linked against "
                    . (\defined('OPENSSL_VERSION_TEXT') ? (string) \constant('OPENSSL_VERSION_TEXT') : 'an older OpenSSL'));
            }
            $pins = [];
            foreach ($this->pins as $i => $pin) {
                if (!\is_string($pin) || !self::isPin($pin)) {
                    throw new ConfigException("Invalid pins[{$i}]: a pin is the certificate SHA-256 as 64 hex digits, or"
                        . ' "sha256/" + the base64 SHA-256 of the SubjectPublicKeyInfo');
                }
                $pins[] = $pin;
            }
            $this->pins = $pins;
            $options = [];
            foreach ($this->sslOptions as $key => $value) {
                $options[(string) $key] = self::sslOption((string) $key, $value);
            }
            $this->sslOptions = $options;
            $verifyOnly = ['insecureSkipVerify' => $this->insecureSkipVerify, 'caFile' => $this->caFile !== null,
                'caPath' => $this->caPath !== null, 'pins' => $pins !== []];
            foreach ($verifyOnly as $option => $set) {
                if ($set && ($mode === Tls::None || $mode === Tls::Opportunistic)) {
                    throw new ConfigException("{$option} with {$name}: "
                        . ($mode === Tls::None ? 'there is no TLS' : 'opportunistic TLS is never verified (RFC 7435)'));
                }
            }
            if ($mode === Tls::None && ($this->verifyPeer !== null || $options !== [])) {
                throw new ConfigException(($this->verifyPeer !== null ? 'verifyPeer' : 'sslOptions') . " with {$name}: there is no TLS");
            }
            if ($this->caFile !== null && !self::guardedCheck('is_file', $this->caFile)) {
                throw new ConfigException('Invalid caFile "' . Text::sanitize($this->caFile, 200) . '": not a readable file');
            }
            if ($this->caPath !== null && !self::guardedCheck('is_dir', $this->caPath)) {
                throw new ConfigException('Invalid caPath "' . Text::sanitize($this->caPath, 200) . '": not a directory');
            }
            if ($mode === Tls::None) {
                return;
            }
            $this->crypto = self::cryptoMask($this->tlsMin, $openssl);
            if ($this->tlsMin === '1.1' || $this->tlsMin === '1.0') {
                $this->configWarnings[] = "legacy-tls: tlsMin '{$this->tlsMin}' allows TLS versions that RFC 8996 deprecates";
            }
            $verify = !$this->insecureSkipVerify && $mode !== Tls::Opportunistic;
            $ssl = [
                'peer_name' => $this->peerName,   // always host: the verified identity comes from the caller (§4.3)
                'verify_peer' => $verify,
                'verify_peer_name' => $verify,
                'allow_self_signed' => !$verify,
                'SNI_enabled' => !Text::isIpLiteral($this->host),   // RFC 6066 §3: no SNI for IP addresses
                'crypto_method' => $this->crypto,
                'disable_compression' => true,
                'capture_peer_cert' => true,
            ];
            if ($this->verifyPeer !== null) {
                $ssl['capture_peer_cert_chain'] = true;
            }
            if ($this->caFile !== null) {
                $ssl['cafile'] = $this->caFile;
            }
            if ($this->caPath !== null) {
                $ssl['capath'] = $this->caPath;
            }
            $this->ssl = $ssl + $options;
        }

        /**
         * The STREAM_CRYPTO_METHOD_* mask for tlsMin (§4.3): TLS 1.3 is named only when the linked
         * OpenSSL has it (1.1.1+), so '1.2' is 97 there and 33 below. TLS_CLIENT, SSLv23_CLIENT and
         * ANY_CLIENT are never used (§2.1). The version is a parameter so tests can cover old OpenSSL.
         */
        private static function cryptoMask(string $tlsMin, int $opensslVersion): int
        {
            $tls13 = $opensslVersion >= self::OPENSSL_TLS13 ? \STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT : 0;
            $tls12 = \STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | $tls13;
            return match ($tlsMin) {
                '1.3' => \STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT,
                '1.2' => $tls12,
                '1.1' => \STREAM_CRYPTO_METHOD_TLSv1_1_CLIENT | $tls12,
                '1.0' => \STREAM_CRYPTO_METHOD_TLSv1_0_CLIENT | \STREAM_CRYPTO_METHOD_TLSv1_1_CLIENT | $tls12,
            };
        }

        /** A certificate SHA-256 (64 hex digits) or "sha256/" + base64 of 32 bytes (§4.3, D23). */
        private static function isPin(string $pin): bool
        {
            if (\preg_match('/\A[0-9A-Fa-f]{64}\z/', $pin) === 1) {
                return true;
            }
            if (!\str_starts_with($pin, 'sha256/')) {
                return false;
            }
            $hash = \base64_decode(\substr($pin, 7), true);
            return $hash !== false && \strlen($hash) === 32 && \base64_encode($hash) === \substr($pin, 7);
        }

        /**
         * One allowlisted sslOptions entry, type-checked (§4.3).
         *
         * @throws ConfigException for any other key or a value of the wrong type
         */
        private static function sslOption(string $key, mixed $value): mixed
        {
            if (!\in_array($key, self::SSL_OPTIONS, true)) {
                throw new ConfigException('sslOptions: "' . Text::sanitize($key, 60) . '" is not allowed; only '
                    . \implode(', ', self::SSL_OPTIONS) . ' can be set (verification, peer_name and the CA store have'
                    . ' their own options: insecureSkipVerify, host, caFile, caPath, pins)');
            }
            $ok = match ($key) {
                'security_level' => \is_int($value) && $value >= 0 && $value <= 5,
                'verify_depth' => \is_int($value) && $value >= 0,
                'capture_peer_cert_chain' => \is_bool($value),
                default => \is_string($value) && \preg_match('/\A[\x20-\x7E]+\z/', $value) === 1,   // ciphers, local_cert, local_pk
            };
            if (!$ok) {
                throw new ConfigException("sslOptions: \"{$key}\" has a value of the wrong type or range ("
                    . \get_debug_type($value) . ')');
            }
            return $value;
        }

        /** maxRecipients, spoolMemory, helo, idDomain and spoolDir (§2.5). */
        private function configureMessages(?string $helo, ?string $idDomain, ?string $spoolDir): void
        {
            if ($this->maxRecipients < 1) {
                throw new ConfigException("Invalid maxRecipients {$this->maxRecipients}: at least 1");
            }
            if ($this->spoolMemory < 0) {
                throw new ConfigException("Invalid spoolMemory {$this->spoolMemory}: at least 0 bytes");
            }
            if ($helo !== null) {
                $this->helo = self::heloIdentity($helo);
            }
            if ($idDomain !== null) {
                $warnings = [];
                $ascii = Idna::toAscii($idDomain, $error, $warnings);
                if ($ascii === null) {
                    throw new ConfigException('Invalid idDomain "' . Text::sanitize($idDomain, 100) . "\": {$error}");
                }
                \array_push($this->configWarnings, ...$warnings);
                $this->idDomain = $ascii;
            }
            if ($spoolDir !== null) {
                if (!self::guardedCheck('is_dir', $spoolDir) || !self::guardedCheck('is_writable', $spoolDir)) {
                    throw new ConfigException('Invalid spoolDir "' . Text::sanitize($spoolDir, 200) . '": not a writable directory');
                }
                $this->spoolDir = $spoolDir;
            }
        }

        /**
         * The EHLO identity override (§3.3): an ASCII domain name (not localhost) or an address
         * literal, "[192.0.2.1]" or "[IPv6:2001:db8::1]".
         *
         * @throws ConfigException
         */
        private static function heloIdentity(string $helo): string
        {
            if (\preg_match('/\A\[(?:IPv6:([0-9A-Fa-f:.]+)|([0-9.]+))\]\z/', $helo, $m) === 1) {
                $ip = $m[1] !== '' ? $m[1] : $m[2];
                $bytes = Text::isIpLiteral($ip) ? \inet_pton($ip) : false;
                if ($bytes !== false && \strlen($bytes) === ($m[1] !== '' ? 16 : 4)) {
                    return $helo;
                }
            } elseif (\preg_match('/\A[\x21-\x7E]{1,255}\z/', $helo) === 1 && Idna::toAscii($helo) === Text::lower($helo)
                && !\in_array(Text::lower($helo), ['localhost', 'localhost.localdomain'], true)) {
                return $helo;
            }
            throw new ConfigException('Invalid helo "' . Text::sanitize($helo, 100) . '": an ASCII domain name other than'
                . ' localhost, or an address literal such as [192.0.2.1] or [IPv6:2001:db8::1] (RFC 5321 §4.1.3)');
        }

        /** Every dkim element is a usable Dkim; its factory warnings go on every Result. */
        private function configureDkim(): void
        {
            $signers = [];
            foreach ($this->dkim as $i => $signer) {
                if (!$signer instanceof Dkim) {
                    throw new ConfigException("Invalid dkim[{$i}]: a Dkim signer (Dkim::rsa() or Dkim::ed25519()), got "
                        . \get_debug_type($signer));
                }
                $signer->dnsName();   // UsageException for an unserialized (poisoned) signer (§8.4)
                \array_push($this->configWarnings, ...$signer->warnings);
                $signers[] = $signer;
            }
            $this->dkim = $signers;
        }

        /** A filesystem check inside Guard (open_basedir warns); false when it warns or fails. */
        private static function guardedCheck(string $check, string $path): bool
        {
            $warnings = [];
            try {
                $ok = match ($check) {
                    'is_file' => Guard::run(static fn () => \is_file($path), $warnings)
                        && Guard::run(static fn () => \is_readable($path), $warnings),
                    'is_dir' => Guard::run(static fn () => \is_dir($path), $warnings),
                    'is_writable' => Guard::run(static fn () => \is_writable($path), $warnings),
                };
            } catch (\Throwable) {
                return false;
            }
            return $ok === true && $warnings === [];
        }

        // =============================================================================================
        // send(): envelope, prepare, deliver
        // =============================================================================================

        /**
         * The envelope (§6.4, §3.5, §3.8), validated before rendering and before any I/O.
         *
         * @return array{rp: ?Address, rcpts: list<Address>, notify: list<string>, ret: ?string,
         *               envid: ?string, orcpt: list<string>} rp null for the null sender; envid
         *               xtext-encoded; orcpt the ORCPT parameter of each recipient (when NOTIFY is set)
         * @throws MessageException fields from, envelope.from, envelope.to, envelope.to[i], notify, ret, envid
         * @throws ConfigException  allOrNothing with more than maxRecipients recipients (§3.5)
         */
        private function envelope(Message $message, string|Address|null $from, ?array $to, array $notify, ?string $ret,
            ?string $envid): array
        {
            $snapshot = $message->snapshot();   // UsageException for an unserialized Message
            if ($snapshot['from'] === null) {
                throw new MessageException('Invalid from: every message needs a From mailbox (RFC 5322 §3.6); call from()', 'from');
            }
            $rp = match (true) {
                $from === '<>' => null,
                $from !== null => Address::of($from, 'envelope.from'),
                default => $snapshot['sender'] ?? $snapshot['from'],
            };
            $listed = [];
            if ($to === null) {
                $listed = $message->recipients();
            } else {
                foreach (\array_values($to) as $i => $entry) {
                    if (!\is_string($entry) && !$entry instanceof Address) {
                        throw new MessageException("Invalid envelope.to[{$i}]: each entry is ONE mailbox, a string or an"
                            . ' Address, got ' . \get_debug_type($entry), "envelope.to[{$i}]");
                    }
                    $listed[] = Address::of($entry, "envelope.to[{$i}]");
                }
            }
            $rcpts = [];
            foreach ($listed as $address) {
                $rcpts[$address->key()] ??= $address;   // exact local part, lower-cased domain (research item 159)
            }
            $rcpts = \array_values($rcpts);
            if ($rcpts === []) {
                throw new MessageException('Invalid envelope.to: there are no recipients (no To, Cc or Bcc, or an empty to:'
                    . ' override)', 'envelope.to');
            }
            if ($this->allOrNothing && \count($rcpts) > $this->maxRecipients) {
                throw new ConfigException('allOrNothing: ' . \count($rcpts) . " recipients need more than one transaction"
                    . " (maxRecipients {$this->maxRecipients}), and atomicity exists only within one (D18)");
            }
            foreach ([$rp, ...$rcpts] as $address) {
                if ($address !== null) {
                    $this->warn(...$address->warnings());
                }
            }
            return ['rp' => $rp, 'rcpts' => $rcpts] + $this->dsn($rcpts, $notify, $ret, $envid);
        }

        /**
         * The DSN parameters (RFC 3461, §3.5), validated whether or not the server will get them.
         *
         * @param list<Address> $rcpts
         * @return array{notify: list<string>, ret: ?string, envid: ?string, orcpt: list<string>}
         * @throws MessageException fields notify, ret, envid, envelope.to[i]
         */
        private function dsn(array $rcpts, array $notify, ?string $ret, ?string $envid): array
        {
            $values = [];
            foreach (\array_values($notify) as $i => $value) {
                $upper = \is_string($value) ? Text::upper($value) : null;
                if ($upper === null || (!\in_array($upper, self::NOTIFY, true) && $upper !== 'NEVER') || \in_array($upper, $values, true)) {
                    throw new MessageException("Invalid notify[{$i}]: NEVER, or any of SUCCESS, FAILURE and DELAY once each"
                        . ' (RFC 3461 §4.1)', 'notify');
                }
                $values[] = $upper;
            }
            if (\in_array('NEVER', $values, true) && \count($values) > 1) {
                throw new MessageException('Invalid notify: NEVER cannot be combined with other values (RFC 3461 §4.1)', 'notify');
            }
            if ($ret !== null) {
                $ret = Text::upper($ret);
                if ($ret !== 'FULL' && $ret !== 'HDRS') {
                    throw new MessageException("Invalid ret: 'FULL' or 'HDRS' (RFC 3461 §4.3)", 'ret');
                }
            }
            if ($envid !== null) {
                if (\preg_match('/\A[\x20-\x7E]*\z/', $envid) !== 1) {
                    throw new MessageException('Invalid envid: printable US-ASCII only, since DSNs echo it (RFC 3461 §4.4)', 'envid', true);
                }
                $envid = Encoder::xtext($envid);
                if ($envid === '' || \strlen($envid) > 100) {
                    throw new MessageException('Invalid envid: 1-100 characters once xtext-encoded, got ' . \strlen($envid)
                        . ' (RFC 3461 §4.4)', 'envid');
                }
            }
            $orcpt = [];
            if ($values !== []) {
                foreach ($rcpts as $i => $address) {
                    $email = $address->forEnvelope();
                    $param = \preg_match('/[\x80-\xFF]/', $email) === 1
                        ? 'ORCPT=utf-8;' . Encoder::utf8AddrXtext($email)   // RFC 6533 §3: valid with or without SMTPUTF8
                        : 'ORCPT=rfc822;' . Encoder::xtext($email);
                    if (\strlen($param) > 500) {
                        throw new MessageException("Invalid envelope.to[{$i}]: its ORCPT parameter would be " . \strlen($param)
                            . ' characters, over 500 (RFC 3461 §4.2)', "envelope.to[{$i}]");
                    }
                    $orcpt[] = $param;
                }
            }
            return ['notify' => $values, 'ret' => $ret, 'envid' => $envid, 'orcpt' => $orcpt];
        }

        /**
         * §7.1 and §6.1: render (Date and Message-ID stamping, folding, transfer encoding), then each
         * signer signs the UNSIGNED Rendered and self-verifies; the fields are prepended in the
         * configured order, so the first signer ends up on top. Nothing changes the bytes after this.
         *
         * @throws MessageException, SmtpException (Local), ConfigException (RFC 8058), UsageException
         */
        private function prepare(Message $message, array $envelope): Rendered
        {
            $renderer = new Renderer($this->clock, $this->random, $this->utcDates, $this->idDomain, $this->spoolDir,
                $this->spoolMemory);
            $unsigned = $renderer->render($message);
            try {
                foreach ($this->dkim as $signer) {
                    $signer->assertSignable($unsigned);   // the RFC 8058 rule for every signer, before any signs
                }
                $time = $this->clock->now()->getTimestamp();
                $fields = [];
                foreach ($this->dkim as $signer) {
                    $fields[] = $signer->signRendered($unsigned, $time);
                }
                $rendered = $fields === [] ? $unsigned : $unsigned->withPrepended(...$fields);
            } catch (\Throwable $e) {
                $unsigned->spool()->close();
                throw $e;
            }
            $this->warn(...$rendered->warnings);
            $fromDomain = $message->snapshot()['from']->domain();
            foreach ($this->dkim as $signer) {
                $misaligned = $signer->misalignment($fromDomain);
                if ($misaligned !== null) {
                    $this->warn($misaligned);
                }
            }
            $rp = $envelope['rp'];
            if ($rp !== null && !Dkim::aligned($rp->domain(), $fromDomain)) {
                $aligned = false;
                foreach ($this->dkim as $signer) {
                    $aligned = $aligned || Dkim::aligned($rp->domain(), $signer->domain);
                }
                if (!$aligned) {
                    $this->warn('envelope-misaligned: the reverse path domain ' . $rp->domain() . " aligns with neither the From"
                        . " domain {$fromDomain} nor a DKIM d= (SPF passes for it would not count for DMARC)");
                }
            }
            return $rendered;
        }

        /**
         * The transactions of one send() (§3.8): chunks of min(maxRecipients, RCPTMAX), the 452
         * carry-over on the same connection (to the front of the pending list; each carry-over
         * follows an accepted recipient, so it always makes progress), MAILMAX, and the single
         * transparent reconnect (EOF or 421 before MAIL got 250, or the per-connection limit).
         * Then the §8.1 contract: return, or throw DeliveryUnknownException or SmtpException.
         */
        private function deliver(Rendered $rendered, array $envelope): Result
        {
            $rcpts = $envelope['rcpts'];
            $pending = \array_keys($rcpts);   // envelope indices, in order
            $decided = [];                    // envelope index => RecipientResult
            $replies = [];
            $chunk = 0;                       // RecipientResult::$transaction of the next transaction
            $error = null;
            $plan = null;
            $planFor = null;
            $ran = false;                     // a transaction was attempted: failures now carry a Result
            $limitReconnect = false;          // the transparent reconnect was used for the per-connection limit

            try {
                $this->ready();
            } catch (SmtpException $e) {
                throw $this->rebuild($e);
            }
            while ($pending !== []) {
                $s = $this->session;
                try {
                    $max = $s->caps()?->mailMax();
                    if ($max !== null && $s->transactions() >= $max) {
                        // RFC 9422 MAILMAX: a planned new connection, not the transparent reconnect.
                        $s->note("reconnect (MAILMAX {$max} reached)");
                        $s = $this->open();
                    }
                    if ($planFor !== $s) {
                        $plan = $this->preflight($rendered, $envelope, $s->caps());
                        $planFor = $s;
                    }
                } catch (SmtpException $e) {
                    if (!$ran) {
                        throw $this->rebuild($e);
                    }
                    foreach ($pending as $i) {
                        $decided[$i] = new RecipientResult($rcpts[$i], $e->outcome, $e->reply, -1, false);
                    }
                    $error = $e;
                    break;
                }
                $cap = \min($this->maxRecipients, $s->caps()->rcptMax() ?? \PHP_INT_MAX);
                $batch = \array_slice($pending, 0, $cap);
                $rest = \array_slice($pending, $cap);
                $lines = [];
                foreach ($batch as $i) {
                    $lines[] = [$rcpts[$i]->forEnvelope(), $plan['rcpt'][$i]];
                }
                $tx = $s->transaction($rendered, $envelope['rp']?->forEnvelope() ?? '', $plan['mail'], $lines,
                    $this->pipelining, $this->allOrNothing);
                $this->drain();
                $ran = true;
                $failure = $tx['error'];
                if ($failure !== null) {
                    $error = $failure;
                }

                // D20: nothing was committed, so one new connection may retry the same chunk.
                $lost = !$tx['committed'] && $failure !== null
                    && ($failure->reason === Reason::ConnectionLost || $failure->reply?->code === 421);
                if ($lost || $tx['perConnectionLimit']) {
                    $cause = $tx['perConnectionLimit'] ? 'per-connection limit' : 'connection lost';
                    if ($tx['perConnectionLimit'] && ($this->reconnected || $limitReconnect)) {
                        $this->warn(self::limitWarning($tx['mail']));
                    }
                    if (!$this->reconnected) {
                        $this->reconnected = true;
                        $limitReconnect = $tx['perConnectionLimit'];
                        $s->note("reconnect ({$cause})");
                        $this->record($decided, $rcpts, $batch, $tx, $chunk);   // provisional, if the reconnect fails
                        try {
                            $this->open();
                        } catch (SmtpException $e) {
                            $error = $e;
                            $this->notAttempted($decided, $rcpts, $rest);
                            break;
                        }
                        continue;
                    }
                    $this->record($decided, $rcpts, $batch, $tx, $chunk);
                    $this->notAttempted($decided, $rcpts, $rest);
                    $this->discard();
                    break;
                }
                if ($limitReconnect && !$tx['committed'] && ($tx['mail']?->code ?? 0) >= 400 && ($tx['mail']?->code ?? 0) < 500) {
                    $this->warn(self::limitWarning($tx['mail']));   // 4yz again on the fresh connection (§3.8)
                }

                $carry = $this->record($decided, $rcpts, $batch, $tx, $chunk);
                if ($tx['final'] !== null) {
                    $replies[] = $tx['final'];
                }
                if ($carry !== [] && $tx['accepted'] === 0) {
                    // The progress guard (X10) as an invariant: a limit reply counts only after an
                    // acceptance (Session::recipientKind), so every carry-over transaction takes at
                    // least one recipient off the pending list and the loop ends.
                    throw new \LogicException('Mailer: recipients carried over from a transaction that accepted none (a tehsmtp bug)');
                }
                $broken = $s->state() !== State::Ready;
                if ($tx['outcome'] === Outcome::Unknown || $broken) {
                    // Stop: the rest was never attempted.
                    $this->notAttempted($decided, $rcpts, $rest);
                    if ($broken) {
                        $this->discard();
                    }
                    break;
                }
                foreach ($carry as $i) {
                    unset($decided[$i]);   // sent again in the next transaction
                }
                $pending = [...$carry, ...$rest];
                $chunk++;
            }
            return $this->conclude($rendered, $decided, $replies, $error);
        }

        /**
         * Records the chunk's recipients (§8.2) and returns the envelope indices to carry over:
         * limit-deferred, or never sent after a limit reply (§3.7). Those are recorded too, as
         * Transient with their limit reply, in case the send stops here; allOrNothing never carries.
         *
         * @param array<int, RecipientResult> $decided
         * @param list<Address>               $rcpts
         * @param list<int>                   $batch envelope indices of this transaction, in order
         * @return list<int>
         */
        private function record(array &$decided, array $rcpts, array $batch, array $tx, int $chunk): array
        {
            $carry = [];
            foreach ($batch as $k => $i) {
                $r = $tx['recipients'][$k];
                $carried = $r['carry'] && !$this->allOrNothing;
                if ($carried) {
                    $carry[] = $i;
                }
                // A recipient never sent was never attempted (transaction -1).
                $index = $carried && $r['reply'] === null ? -1 : $chunk;
                $decided[$i] = new RecipientResult($rcpts[$i], $r['outcome'], $r['reply'], $index, $r['cascaded']);
            }
            return $carry;
        }

        /**
         * Recipients the send never got to (§8.2): Transient, no reply, transaction -1.
         *
         * @param array<int, RecipientResult> $decided
         * @param list<Address>               $rcpts
         * @param list<int>                   $indices
         */
        private function notAttempted(array &$decided, array $rcpts, array $indices): void
        {
            foreach ($indices as $i) {
                $decided[$i] = new RecipientResult($rcpts[$i], Outcome::Transient, null, -1, false);
            }
        }

        /** The warning for a per-connection limit that the single reconnect could not overcome (§3.8). */
        private static function limitWarning(?Reply $mail): string
        {
            return 'per-connection-limit: MAIL was answered ' . Text::sanitize((string) $mail, 200) . ' again after a new'
                . ' connection; the recipients are deferred (research item 204)';
        }

        /**
         * The §8.1 contract: any Unknown recipient → DeliveryUnknownException; any Success → the
         * Result; otherwise the deciding SmtpException with the Result attached.
         *
         * @param array<int, RecipientResult> $decided
         * @param list<Reply>                 $replies
         */
        private function conclude(Rendered $rendered, array $decided, array $replies, ?SmtpException $error): Result
        {
            \ksort($decided);
            $result = new Result($rendered->messageId, $rendered->size(), \array_values($decided), $replies, $this->warnings,
                $this->lastTls);
            if ($result->unknown() !== []) {
                if ($error === null) {
                    throw new \LogicException('Mailer: an Unknown recipient without a cause (a tehsmtp bug)');
                }
                $details = $error->details + ($this->warnings === [] ? [] : ['warnings' => $this->warnings]);
                throw new DeliveryUnknownException('the message may have been delivered, so it must not be re-sent'
                    . ' automatically: ' . $error->getMessage(), $error->stage === Stage::Body ? Stage::Body : Stage::Final,
                    $result, '.', $details, $error->transcript, $error);
            }
            if ($result->accepted() !== []) {
                return $result;
            }
            if ($error === null) {
                throw new \LogicException('Mailer: no recipient succeeded, and there is no failure (a tehsmtp bug)');
            }
            throw $this->rebuild($error, $result);
        }

        /**
         * §3.5, after the connection is Ready and before MAIL: SMTPUTF8 and 8BITMIME, SIZE, the MAIL
         * and RCPT parameters with DSN, the line budgets, and the allOrNothing capacity. A failure
         * leaves the connection Ready: Capability or TooLarge, Permanent, scope Transaction.
         *
         * @return array{mail: list<string>, rcpt: array<int, list<string>>} rcpt by envelope index
         * @throws SmtpException Capability or TooLarge
         * @throws MessageException for a line over its budget (a backstop: the address and ENVID
         *                          limits already keep every line within it)
         */
        private function preflight(Rendered $rendered, array $envelope, Capabilities $caps): array
        {
            $rp = $envelope['rp'];
            $utf8 = $rendered->needsSmtpUtf8 || ($rp?->needsSmtpUtf8() ?? false);
            foreach ($envelope['rcpts'] as $address) {
                $utf8 = $utf8 || $address->needsSmtpUtf8();
            }
            // RFC 6531: SMTPUTF8 needs 8BITMIME as well; never downgraded.
            if ($utf8 && !($caps->has('SMTPUTF8') && $caps->has('8BITMIME'))) {
                throw $this->preflightFailure(Reason::Capability, '5.6.7 SMTPUTF8 required: a local part is non-ASCII, and'
                    . ' the server does not offer both SMTPUTF8 and 8BITMIME (RFC 6531); tehsmtp never downgrades an address',
                    new Reply(553, ['5.6.7 SMTPUTF8 required'], '5.6.7', local: true));
            }
            $mail = [];
            $budget = 512;
            if ($caps->has('SIZE')) {
                $size = $rendered->size();
                $limit = $caps->sizeLimit();
                if ($limit !== null && $limit > 0 && $size > $limit) {
                    throw $this->preflightFailure(Reason::TooLarge, "the message is {$size} octets, over the server's SIZE"
                        . " limit of {$limit} (RFC 1870)", new Reply(552, ['5.3.4 message size exceeds the fixed limit'], '5.3.4',
                        local: true));
                }
                $mail[] = "SIZE={$size}";
                $budget += 26;
            }
            if ($rendered->needsSmtpUtf8) {
                $mail[] = 'BODY=8BITMIME';   // the UTF-8 header octets are the only 8-bit data (§3.5)
                $budget += 16;
            }
            if ($utf8) {
                $mail[] = 'SMTPUTF8';
                $budget += 10;
            }
            $asked = $envelope['notify'] !== [] || $envelope['ret'] !== null || $envelope['envid'] !== null;
            $dsn = $asked && $caps->has('DSN');
            if ($asked && !$dsn) {
                if ($this->strictDsn) {
                    throw $this->preflightFailure(Reason::Capability, 'DSN parameters were asked for, but the server does not'
                        . ' offer DSN (RFC 3461; strictDsn: true)', null);
                }
                $this->warn('dsn-dropped: the server does not offer DSN, so NOTIFY, RET and ENVID were not sent (RFC 3461)');
            }
            if ($dsn && $envelope['ret'] !== null) {
                $mail[] = "RET={$envelope['ret']}";
            }
            if ($dsn && $envelope['envid'] !== null) {
                $mail[] = "ENVID={$envelope['envid']}";
            }
            if ($dsn && ($envelope['ret'] !== null || $envelope['envid'] !== null)) {
                $budget += 100;
            }
            $path = $rp?->forEnvelope() ?? '';
            if (\strlen(\implode(' ', ["MAIL FROM:<{$path}>", ...$mail])) + 2 > $budget) {
                throw new MessageException("Invalid envelope.from: the MAIL command would exceed its {$budget}-octet budget", 'envelope.from');
            }
            $rcpt = [];
            foreach ($envelope['rcpts'] as $i => $address) {
                $params = $dsn && $envelope['notify'] !== [] ? ['NOTIFY=' . \implode(',', $envelope['notify']), $envelope['orcpt'][$i]] : [];
                $line = \implode(' ', ['RCPT TO:<' . $address->forEnvelope() . '>', ...$params]);
                if (\strlen($line) + 2 > ($caps->has('DSN') ? 1036 : 512)) {
                    throw new MessageException("Invalid envelope.to[{$i}]: the RCPT command would exceed its budget", "envelope.to[{$i}]");
                }
                $rcpt[$i] = $params;
            }
            $rcptMax = $caps->rcptMax();
            if ($this->allOrNothing && $rcptMax !== null && \count($envelope['rcpts']) > $rcptMax) {
                throw $this->preflightFailure(Reason::Capability, 'allOrNothing: ' . \count($envelope['rcpts']) . ' recipients,'
                    . " but the server takes at most {$rcptMax} per transaction (LIMITS RCPTMAX, RFC 9422)", null);
            }
            return ['mail' => $mail, 'rcpt' => $rcpt];
        }

        private function preflightFailure(Reason $reason, string $message, ?Reply $reply): SmtpException
        {
            return new SmtpException($message, $reason, Outcome::Permanent, Stage::Mail, Scope::Transaction, null, $reply,
                null, false, true, $this->warnings === [] ? [] : ['warnings' => $this->warnings], $this->session->transcript());
        }

        // =============================================================================================
        // The connection: reuse check, setup, AUTH
        // =============================================================================================

        /**
         * A Ready connection for the next transaction (§3.8). A new one when there is none, it is
         * not Ready, it was idle longer than maxIdle or MAILMAX is reached; otherwise the reuse
         * check: the quiescence check, and RSET after idleProbe seconds. An EOF or 421 found there
         * uses the single transparent reconnect.
         *
         * @throws SmtpException
         */
        private function ready(): void
        {
            $s = $this->session;
            if ($s === null || $s->state() !== State::Ready) {
                $this->open();
                return;
            }
            $max = $s->caps()?->mailMax();
            if ($s->idleSeconds() > $this->timeouts->maxIdle || ($max !== null && $s->transactions() >= $max)) {
                $s->note($max !== null && $s->transactions() >= $max ? "reconnect (MAILMAX {$max} reached)" : 'reconnect (idle)');
                $this->open();
                return;
            }
            try {
                $usable = $s->probe();
            } catch (SmtpException $e) {
                $this->drain();
                $this->discard();
                throw $e;
            }
            $this->drain();
            if (!$usable) {
                $this->reconnected = true;
                $s->note('reconnect (idle EOF)');
                $this->open();
                return;
            }
            // Reused: the warnings of this connection's setup (tls-unauthenticated, helo-fallback, ...)
            // belong to every Result sent over it.
            $this->warn(...$this->setupWarnings);
        }

        /**
         * Opens a new connection (§3.2): greeting, EHLO, STARTTLS and EHLO again when the mode asks
         * for it, then AUTH when credentials are configured. The previous one is closed first. On a
         * failure the new connection is closed too and the exception rethrown.
         *
         * @throws SmtpException
         * @throws UsageException a callback (the token provider, verifyPeer) called back into this Mailer
         */
        private function open(): Session
        {
            $this->discard();
            $s = new Session($this->connector, $this->timeouts, $this->clock, $this->logger, $this->verifyPeer, $this->pins);
            $this->session = $s;
            $this->authMechanism = null;
            $this->setupWarnings = [];
            $starttls = $this->tls === Tls::StartTls || $this->tls === Tls::Opportunistic;
            try {
                $s->open($this->host, $this->dial, $this->port, $this->tls === Tls::Implicit, $this->ssl, $this->crypto,
                    $this->bindTo, $this->tls === Tls::StartTls || $this->tls === Tls::Implicit, $this->hasCredentials());
                $this->lastTls = $s->tlsInfo() ?? [];
                $name = $this->heloName();
                $caps = $s->hello($name, $this->allowHelo());
                if ($starttls && !$s->tls()) {
                    if ($caps->has('STARTTLS')) {
                        // false: an Opportunistic refusal; the session warns plaintext-session itself.
                        if ($s->startTls($this->tls === Tls::Opportunistic)) {
                            $this->lastTls = $s->tlsInfo() ?? [];
                            $s->hello($name, $this->allowHelo());
                        }
                    } elseif ($this->tls === Tls::StartTls) {
                        $s->quit();
                        throw new SmtpException('STARTTLS not offered: possible stripping (Tls::StartTls requires it; the'
                            . ' server did not advertise it, P1)', Reason::Capability, Outcome::Permanent, Stage::StartTls,
                            Scope::Session, transcript: $s->transcript());
                    } else {
                        $this->setupWarnings[] = 'plaintext-session: STARTTLS not offered; the session continues without TLS'
                            . ' (Tls::Opportunistic)';
                    }
                }
                if ($this->hasCredentials()) {
                    $this->authenticate();
                }
            } catch (\Throwable $e) {
                $this->takeSetupWarnings();
                $this->discard();
                throw $e instanceof SmtpException ? $this->rebuild($e) : $e;
            }
            $this->takeSetupWarnings();
            return $s;
        }

        /**
         * AUTH (§5): the §5.1 gate before any credential byte is encoded, then the §5.2 selection:
         * the candidates in the order XOAUTH2 > PLAIN > LOGIN, one token refresh after a JSON
         * status "401", the next candidate after 504 or 534, and nothing else retried. The
         * connection is in $this->session, never a parameter (§8.4).
         *
         * @throws SmtpException Capability or Auth (Stage::Auth, Scope::Session)
         */
        private function authenticate(): void
        {
            $s = $this->session;
            $this->assertAuthGate();
            $offered = $s->caps()->authMechanisms();
            if ($offered === []) {
                throw $this->authRefusal('credentials configured but no AUTH advertised; refusing to send unauthenticated (P43)');
            }
            $oauth = $this->token !== null || $this->provider !== null;
            $candidates = [];
            foreach ($oauth ? [AuthMethod::XOAuth2] : [AuthMethod::Plain, AuthMethod::Login] as $method) {
                if (($this->authMethod === null || $this->authMethod === $method) && \in_array($method->value, $offered, true)) {
                    $candidates[] = $method;
                }
            }
            if ($candidates === []) {
                throw $this->authRefusal('no usable AUTH mechanism: tehsmtp would use '
                    . ($this->authMethod?->value ?? ($oauth ? 'XOAUTH2' : 'PLAIN or LOGIN')) . ', the server offers '
                    . Text::sanitize(\implode(' ', $offered), 200));
            }
            $refreshed = false;
            for ($i = 0, $n = \count($candidates); $i < $n;) {
                $method = $candidates[$i];
                // The provider runs while the session is Ready, before any AUTH byte of this attempt (§5.2).
                $secret = $method === AuthMethod::XOAuth2 ? $this->bearer($refreshed) : $this->password;
                try {
                    $s->auth($method, (string) $this->username, $secret, $this->irMode);
                    $this->authMechanism = $method->value;
                    return;
                } catch (SmtpException $e) {
                    // Checked before the 535 rule: the JSON error step is always followed by a 535.
                    if ($method === AuthMethod::XOAuth2 && $this->provider !== null && !$refreshed && $e->connectionUsable
                        && ($e->details['oauthError']['status'] ?? null) === '401') {
                        $refreshed = true;
                        continue;
                    }
                    if ($e->connectionUsable && ($e->reply?->code === 504 || $e->reply?->code === 534) && $i + 1 < $n) {
                        $i++;   // not supported although advertised, or too weak: the next candidate (research item 103)
                        continue;
                    }
                    throw $e;   // 535, 530, 538 and the rest: no fallback with the same credentials (lockout risk)
                } finally {
                    if ($this->provider !== null) {
                        $secret->wipe();   // a fetched token is used once (§5.3)
                    }
                }
            }
        }

        /**
         * The §5.1 gate (I1): verified TLS; unverified TLS only with insecureAuthOverUnverifiedTls;
         * plaintext only to a connected LOOPBACK peer IP with PlaintextAuth::LoopbackOnly, or with
         * DangerouslyAllowAny. A passing verifyPeer never opens it (D30).
         *
         * @throws SmtpException Capability, Permanent, Stage::Auth, Scope::Session, after QUIT
         */
        private function assertAuthGate(): void
        {
            $s = $this->session;
            if ($s->tls()) {
                if ($s->verified() || $this->insecureAuth) {
                    return;
                }
                throw $this->authRefusal('refusing to send credentials: TLS is active but the server certificate was not'
                    . ' verified (insecureSkipVerify); set insecureAuthOverUnverifiedTls: true to allow it');
            }
            if ($this->plaintextAuth === PlaintextAuth::DangerouslyAllowAny) {
                $s->note('AUTH over plaintext (PlaintextAuth::DangerouslyAllowAny)');
                $this->setupWarnings[] = 'plaintext-session: credentials were sent without TLS (PlaintextAuth::DangerouslyAllowAny)';
                return;
            }
            if ($this->plaintextAuth === PlaintextAuth::LoopbackOnly && $s->peerIsLoopback()) {
                return;
            }
            throw $this->authRefusal($this->plaintextAuth === PlaintextAuth::LoopbackOnly
                ? 'refusing to send credentials in plaintext: the connected peer is not a loopback IP'
                    . ' (PlaintextAuth::LoopbackOnly is judged by the peer IP, never the host name; §5.1)'
                : 'refusing to send credentials without TLS (PlaintextAuth::Never)');
        }

        /** QUIT, then SmtpException(Capability, Permanent, Auth, Session): nothing credential-related was sent. */
        private function authRefusal(string $message): SmtpException
        {
            $s = $this->session;
            $s->quit();
            return new SmtpException($message, Reason::Capability, Outcome::Permanent, Stage::Auth, Scope::Session,
                transcript: $s->transcript());
        }

        /**
         * The XOAUTH2 token: the fixed one, or the provider's answer checked against the bearer
         * syntax (§5.2, §5.3). A provider failure QUITs first: an \Exception is Auth, Transient,
         * retrySafe (the token endpoint may be down); an \Error, a non-string or a malformed token
         * is Auth, Permanent (retrying a broken provider cannot help). A UsageException is neither:
         * the provider misused the Mailer (re-entrancy, X15), a programming error that no retry
         * fixes, so it reaches the caller unchanged (§8.1); open() closes the connection.
         *
         * @throws SmtpException
         * @throws UsageException the provider called send(), connect() or ping() on this Mailer
         */
        private function bearer(bool $forceRefresh): Secret
        {
            if ($this->provider === null) {
                return $this->token;
            }
            try {
                $token = ($this->provider)($forceRefresh);
            } catch (UsageException $e) {
                throw $e;
            } catch (\Exception $e) {
                throw $this->providerFailure(Outcome::Transient, 'the OAuth token provider threw ' . $e::class . ': '
                    . Text::sanitize($e->getMessage(), 200), $e);
            } catch (\Error $e) {
                throw $this->providerFailure(Outcome::Permanent, 'the OAuth token provider failed with ' . $e::class . ': '
                    . Text::sanitize($e->getMessage(), 200), $e);
            }
            if (!\is_string($token)) {
                $type = \get_debug_type($token);
                \settype($token, 'null');
                throw $this->providerFailure(Outcome::Permanent, "the OAuth token provider returned {$type}, not a string", null);
            }
            $secret = Secret::of($token);
            \settype($token, 'null');
            if (!Sasl::bearerToken($secret)) {
                $secret->wipe();
                throw $this->providerFailure(Outcome::Permanent, 'the OAuth token provider returned a token that is not an'
                    . ' RFC 6750 bearer token ([A-Za-z0-9-._~+/]+=*); nothing was sent', null);
            }
            return $secret;
        }

        private function providerFailure(Outcome $outcome, string $message, ?\Throwable $previous): SmtpException
        {
            $s = $this->session;
            $s->quit();
            return new SmtpException($message, Reason::Auth, $outcome, Stage::Auth, Scope::Session, null, null, null,
                $outcome === Outcome::Transient, false, [], $s->transcript(), $previous);
        }

        /**
         * The EHLO identity of this connection (§3.3): helo; else gethostname() when it is a dotted
         * LDH name other than localhost; else an address literal of the local IP ("[192.0.2.1]",
         * "[IPv6:2001:db8::1]", IPv4-mapped written as IPv4). $_SERVER is never read.
         */
        private function heloName(): string
        {
            if ($this->helo !== null) {
                return $this->helo;
            }
            $warnings = [];
            $deprecations = [];
            try {
                $host = Guard::run(static fn () => \gethostname(), $warnings, $deprecations);
            } catch (\Throwable) {
                $host = false;
            }
            foreach ($deprecations as $deprecation) {
                $this->setupWarnings[] = "php-deprecation: {$deprecation}";
            }
            if (\is_string($host) && \str_contains($host, '.') && \strlen($host) <= 255
                && \preg_match('/\A[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?(?:\.[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?)+\z/', $host) === 1
                && !\in_array(Text::lower($host), ['localhost', 'localhost.localdomain'], true)) {
                return Text::lower($host);
            }
            $ip = Text::socketIp($this->session->localName());
            if ($ip === null) {
                throw new \LogicException('Mailer: the local socket name "' . Text::sanitize((string) $this->session->localName(), 100)
                    . '" has no IP address for the EHLO literal (§5.1); set helo:');
            }
            $bytes = (string) \inet_pton($ip);
            if (\strlen($bytes) === 16 && \str_starts_with($bytes, \str_repeat("\0", 10) . "\xFF\xFF")) {
                $bytes = \substr($bytes, 12);   // IPv4-mapped: written as IPv4 (§3.3)
            }
            return \strlen($bytes) === 4 ? '[' . \inet_ntop($bytes) . ']' : '[IPv6:' . \inet_ntop($bytes) . ']';
        }

        /** HELO after EHLO 500/502 only without credentials, and not before TLS in Tls::StartTls (§3.2, D29). */
        private function allowHelo(): bool
        {
            return !$this->hasCredentials() && ($this->tls !== Tls::StartTls || $this->session->tls());
        }

        private function hasCredentials(): bool
        {
            return $this->password !== null || $this->token !== null || $this->provider !== null;
        }

        // =============================================================================================
        // Helpers
        // =============================================================================================

        /**
         * The start of send(), connect() and ping(): an unserialized Mailer, a forked child (§3.11)
         * and re-entrancy (§3.8, X15) are UsageException; the socket is never touched for them.
         */
        private function enter(): void
        {
            $this->assertUsable();
            $pid = $this->session?->pid() ?? 0;
            if ($pid !== 0 && $pid !== (int) \getmypid()) {
                throw new UsageException("this Mailer's connection belongs to process {$pid}: call close() before forking, or"
                    . ' create the Mailer after forking (§3.11)');
            }
            if ($this->busy) {
                throw new UsageException('re-entrant call: this Mailer is busy (send(), connect() or ping() was called again,'
                    . ' e.g. from the logger)');
            }
            $this->busy = true;
        }

        private function assertUsable(): void
        {
            if ($this->inert) {
                throw new UsageException('this Mailer was unserialized and is inert; create a new Mailer (§8.4)');
            }
        }

        /** QUIT (when Ready) and drop the current connection; errors are ignored (§3.11). */
        private function discard(): void
        {
            $s = $this->session;
            if ($s === null) {
                return;
            }
            $this->session = null;
            $this->authMechanism = null;
            try {
                $s->quit();
            } catch (\Throwable) {
                // A logger that throws during QUIT: the socket is closed anyway.
            }
        }

        /** The session's warnings of the setup, kept for every Result sent over this connection. */
        private function takeSetupWarnings(): void
        {
            foreach ($this->session?->takeWarnings() ?? [] as $warning) {
                if (!\in_array($warning, $this->setupWarnings, true)) {
                    $this->setupWarnings[] = $warning;
                }
            }
            $this->warn(...$this->setupWarnings);
        }

        /** The session's warnings of the last exchange, for the current send(). */
        private function drain(): void
        {
            $this->warn(...($this->session?->takeWarnings() ?? []));
        }

        private function warn(string ...$warnings): void
        {
            foreach ($warnings as $warning) {
                if (!\in_array($warning, $this->warnings, true)) {
                    $this->warnings[] = $warning;
                }
            }
        }

        /**
         * $e rebuilt with the Result (when given), the send's warnings in details['warnings'], and
         * connectionUsable as it is now (after a failed setup the connection was closed).
         */
        private function rebuild(SmtpException $e, ?Result $result = null): SmtpException
        {
            $details = $e->details;
            if ($this->warnings !== []) {
                $details['warnings'] = $this->warnings;
            }
            return new SmtpException($e->getMessage(), $e->reason, $e->outcome, $e->stage, $e->scope, $e->command, $e->reply,
                $result ?? $e->result, $e->retrySafe, $this->session?->state() === State::Ready, $details, $e->transcript,
                $e->getPrevious());
        }
    }

    /** One recipient's outcome (§8.2): the deciding reply, the transaction it was in, and whether it was cascaded. */
    final class RecipientResult
    {
        /**
         * @param Reply|null $reply       the DECIDING reply (§8.2); null for Unknown and when never attempted
         * @param int        $transaction the index of the transaction (chunk) that decided it; -1 = never attempted
         * @param bool       $cascaded    inherited from a MAIL or DATA failure (or a failure of the transaction)
         *                                rather than decided by this recipient's own RCPT or the final reply
         */
        public function __construct(
            public readonly Address $address,
            public readonly Outcome $outcome,
            public readonly ?Reply $reply,
            public readonly int $transaction,
            public readonly bool $cascaded,
        ) {
        }
    }

    /**
     * What send() returns, or attaches to its exception (§2.4, §8.1): one RecipientResult per
     * envelope recipient, in envelope order (I8); there is no boolean API.
     */
    final class Result
    {
        /**
         * @param string                $messageId  the rendered Message-ID, with its angle brackets
         * @param int                   $size       the octets declared in SIZE (headers, CRLF, body; I4)
         * @param list<RecipientResult> $recipients
         * @param list<Reply>           $replies    the final reply of each transaction that got one (queue IDs)
         * @param list<string>          $warnings   each "<stable-code>: <message>"
         * @param array                 $tls        protocol, cipher, verified, peerSha256; [] without TLS
         */
        public function __construct(
            public readonly string $messageId,
            public readonly int $size,
            public readonly array $recipients,
            public readonly array $replies,
            public readonly array $warnings,
            public readonly array $tls,
        ) {
        }

        /** Every recipient succeeded. */
        public function ok(): bool
        {
            return \count($this->accepted()) === \count($this->recipients);
        }

        /** @return list<RecipientResult> the recipients with Outcome::Success */
        public function accepted(): array
        {
            return $this->having(Outcome::Success);
        }

        /** @return list<RecipientResult> Permanent: fix the address or the data */
        public function rejected(): array
        {
            return $this->having(Outcome::Permanent);
        }

        /** @return list<RecipientResult> Transient: may be retried later */
        public function deferred(): array
        {
            return $this->having(Outcome::Transient);
        }

        /** @return list<RecipientResult> Unknown: possibly delivered; never re-send automatically */
        public function unknown(): array
        {
            return $this->having(Outcome::Unknown);
        }

        /**
         * The result for one mailbox (compared by Address::key(): exact local part, lower-cased
         * domain), or null when it was not a recipient.
         *
         * @throws MessageException for a string that is not one mailbox
         */
        public function for(string|Address $address): ?RecipientResult
        {
            $key = Address::of($address, 'address')->key();
            foreach ($this->recipients as $recipient) {
                if ($recipient->address->key() === $key) {
                    return $recipient;
                }
            }
            return null;
        }

        /** @return list<RecipientResult> */
        private function having(Outcome $outcome): array
        {
            return \array_values(\array_filter($this->recipients, static fn (RecipientResult $r): bool => $r->outcome === $outcome));
        }
    }
}
