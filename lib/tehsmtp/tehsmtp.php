<?php
/**
 * tehsmtp 1.0.0: a pure-PHP SMTP client library.
 *
 * QUICK START
 *
 * There is nothing to install. Copy this src/ folder into your project and load the entry point;
 * it loads the other four files from its own folder:
 *
 *     require_once 'src/tehsmtp.php';
 *     use Tehsmtp\{Mailer, Message};
 *
 *     $mailer = new Mailer('smtp.example.com', username: 'me@example.com', password: getenv('SMTP_PASSWORD') ?: null);
 *     $result = $mailer->send((new Message)
 *         ->from('Me <me@example.com>')
 *         ->to('you@example.org')                 // a string is exactly ONE mailbox
 *         ->subject('Hello')
 *         ->text("Hi!\nBye."));
 *     echo $result->messageId;
 *
 * The defaults do the safe thing: port 587 with STARTTLS REQUIRED (nothing is sent if the server does
 * not offer it), the certificate chain and the host name verified, AUTH (PLAIN, else LOGIN) only over
 * verified TLS, 7-bit-safe rendering, RFC 5321 timeouts, one connection opened lazily and closed by
 * close() or the destructor. A local development relay is new Mailer('127.0.0.1', port: 1025,
 * tls: Tls::None). The README has the option table and the send() contract; examples/ has six small
 * programs (simple text, HTML with attachments, Gmail XOAUTH2, DKIM, a batch, a local relay).
 *
 * send() RETURNS a Result when at least one recipient succeeded and none is Unknown; it throws
 * SmtpException for a definite failure and DeliveryUnknownException, a sibling class that a
 * `catch (SmtpException)` never catches, when the message MAY have been delivered (the connection
 * died after the final "."). Never re-send an Unknown message automatically.
 *
 * REQUIREMENTS
 *
 * PHP 8.1 or newer. No Composer, no package, no other library, at runtime or to run the tests
 * (php tests/run.php). Only 8.1 syntax and symbols are used in src/.
 *
 * PHP EXTENSIONS
 *
 *     core, standard, pcre, hash, json, date, SPL, random_bytes   always present
 *     openssl    TLS modes, rsa-sha256, pins, verifyPeer. Without it only Tls::None works and Dkim::rsa throws
 *     sodium     ed25519-sha256; sodium_memzero in Secret::wipe() (else the value is overwritten)
 *     intl       UTS #46 IDNA. Without it: the built-in Punycode fallback
 *     fileinfo   MIME type detection for attachment paths
 *     Zend OPcache   not needed; supported (the guard below is verified with it on and off)
 *     mbstring, iconv, ctype, filter   never required
 *
 * THE FIVE FILES (45 declarations; each file guards itself with one `if (!\class_exists(..., false))`)
 *
 *     tehsmtp.php    this file: the entry point and the core (25)
 *                    interfaces TehsmtpException, Clock, RandomSource, ByteStream, Connector
 *                    enums Tls, PlaintextAuth, AuthMethod, Outcome, Reason, Stage, Scope, IoEvent, State
 *                    exceptions SmtpException, DeliveryUnknownException, MessageException, ConfigException,
 *                    UsageException; Secret, Timeouts, SystemClock, SystemRandom; Text, Guard
 *     Message.php    Address, Message; Encoder, Idna; Renderer, Rendered, Spool                      (7)
 *     Dkim.php       Dkim, DkimCanon                                                                 (2)
 *     Protocol.php   Reply; Capabilities, Wire, DotStuffer, Sasl, Session; StreamByteStream,
 *                    StreamConnector                                                                 (8)
 *     Mailer.php     Mailer, RecipientResult, Result                                                 (3)
 *
 * INCLUDING TWICE
 *
 * Every declaration sits inside one conditional block, which PHP never hoists, with or without opcache.
 * So a second include of this file, through another path or from a second bundled copy of src/, is a
 * no-op, and the first version loaded wins; Mailer::VERSION tells which. Keep one copy.
 *
 * Design and rationale: docs/DESIGN.md (section numbers in the docblocks point into it).
 */

declare(strict_types=1);

namespace Tehsmtp;

// Every declaration lives inside this one conditional block (§2.1, D26). Declarations inside a
// block are never hoisted or early-bound, with or without opcache (V1, V4), so a second include
// of this file, or of a second copy of src/, skips everything. Nothing is hoisted, so the order
// below is topological: interfaces first, then everything that implements them.
if (!\class_exists(SmtpException::class, false)) {

    // ---------------------------------------------------------------------------------------
    // Interfaces (§2.2)
    // ---------------------------------------------------------------------------------------

    /** Marker implemented by every exception the library throws: `catch (TehsmtpException)` catches them all. */
    interface TehsmtpException extends \Throwable
    {
    }

    /** Wall-clock and monotonic time; inject a fixed clock for reproducible Date headers and deadlines (I10). */
    interface Clock
    {
        public function now(): \DateTimeImmutable;

        /** Monotonic seconds from an arbitrary origin; only differences are meaningful. */
        public function monotonic(): float;
    }

    /** Source of random bytes for Message-IDs, MIME boundaries and Content-IDs (I10). */
    interface RandomSource
    {
        /** Exactly $length bytes. */
        public function bytes(int $length): string;
    }

    /**
     * The thin I/O primitive below Wire (§2.2, D8). Implementations NEVER throw, not even internal
     * Errors: a frame that can throw must never hold revealed credential bytes (I13, §8.4).
     */
    interface ByteStream
    {
        /** At least one byte, or IoEvent::Timeout, IoEvent::Eof or IoEvent::Error. */
        public function readSome(int $max, float $timeout): string|IoEvent;

        /** The number of bytes written (at least one), or IoEvent::Timeout or IoEvent::Error. */
        public function writeSome(#[\SensitiveParameter] string $bytes, float $timeout): int|IoEvent;

        /**
         * Performs the TLS handshake.
         *
         * @return array{ok: bool, timedOut: bool, info: ?array, peer: ?\OpenSSLCertificate,
         *                chain: list<\OpenSSLCertificate>}
         */
        public function enableTls(array $context, int $cryptoMethod): array;

        /** Bytes buffered inside PHP's stream layer (stream_get_meta_data); -1 when unknown. */
        public function unreadBytes(): int;

        /** stream_select with zero wait; true on error, which forces a probe read. */
        public function pollReadable(): bool;

        /** Raw stream_socket_get_name($s, false), e.g. "192.0.2.1:50123"; parsed with Text::socketIp (§5.1). */
        public function localName(): ?string;

        /** Raw stream_socket_get_name($s, true); parsed with Text::socketIp (§5.1). */
        public function peerName(): ?string;

        /** @return list<string> warnings, notices and caught-Throwable class and message of the last call */
        public function lastWarnings(): array;

        /** @return list<string> deprecations captured during the last call (§8.5) */
        public function lastDeprecations(): array;

        public function close(): void;
    }

    /** Opens the TCP connection. Throws SmtpException(Reason::Connect); never handles secrets. */
    interface Connector
    {
        public function connect(string $address, int $port, float $timeout, ?string $bindTo): ByteStream;
    }

    // ---------------------------------------------------------------------------------------
    // Enums (§2.2)
    // ---------------------------------------------------------------------------------------

    /** Transport security mode (§4.1). The mode is never inferred from how the server behaves. */
    enum Tls: string
    {
        /** Plaintext. AUTH only through PlaintextAuth (§5.1). */
        case None = 'none';
        /** STARTTLS required and verified; fails closed (the default unless port 465). */
        case StartTls = 'starttls';
        /** RFC 7435: STARTTLS if offered, UNAUTHENTICATED encryption, never AUTH (port-25 relaying only). */
        case Opportunistic = 'opportunistic';
        /** SMTPS: TLS before the greeting. */
        case Implicit = 'implicit';

        /** None 25, StartTls 587, Opportunistic 25, Implicit 465. */
        public function defaultPort(): int
        {
            return match ($this) {
                self::None, self::Opportunistic => 25,
                self::StartTls => 587,
                self::Implicit => 465,
            };
        }
    }

    /** Whether credentials may be sent over plaintext (§5.1). */
    enum PlaintextAuth
    {
        case Never;
        /** Only when the CONNECTED PEER IP is loopback, never judged from a host name. */
        case LoopbackOnly;
        case DangerouslyAllowAny;
    }

    /** SASL mechanisms; null in the Mailer means automatic selection (§5.2). */
    enum AuthMethod: string
    {
        case XOAuth2 = 'XOAUTH2';
        case Plain = 'PLAIN';
        case Login = 'LOGIN';
    }

    /** Every outcome is one of these (I7). Unknown means "possibly delivered" and is never retried. */
    enum Outcome
    {
        case Success;
        case Transient;
        case Permanent;
        case Unknown;
    }

    /** Why a send failed (§8.3). */
    enum Reason
    {
        case Connect;
        case Timeout;
        case ConnectionLost;
        /** Handshake or STARTTLS failure (network or protocol). */
        case Tls;
        /** Chain, name, pin or verifyPeer refusal: Permanent; never retried with weaker settings. */
        case TlsVerify;
        /** Unsolicited bytes around STARTTLS: Permanent (an integrity problem that needs a human). */
        case Injection;
        /** Bad grammar, mixed codes, limits exceeded, wrong reply count, desync, unexpected 2yz. */
        case Protocol;
        /** A negative reply to the greeting, EHLO/HELO, MAIL, DATA or the final reply. */
        case Rejected;
        /** No recipient accepted, or an allOrNothing violation. */
        case Recipients;
        case Auth;
        case Capability;
        case TooLarge;
        /** Spool or temp failure, an attachment that vanished or changed, an RNG failure. */
        case Local;
    }

    /** Where in the session a failure happened. */
    enum Stage: string
    {
        case Prepare = 'prepare';
        case Connect = 'connect';
        case Tls = 'tls';
        case Greeting = 'greeting';
        case Ehlo = 'ehlo';
        case Helo = 'helo';
        case StartTls = 'starttls';
        case Auth = 'auth';
        case Mail = 'mail';
        case Rcpt = 'rcpt';
        case Data = 'data';
        case Body = 'body';
        case Final = 'final';
        case Rset = 'rset';
        case Quit = 'quit';
        case Idle = 'idle';
    }

    /** What a failure applies to (§8.3). */
    enum Scope: string
    {
        case Session = 'session';
        case Transaction = 'transaction';
        case Recipient = 'recipient';
    }

    /** The non-byte results of ByteStream calls. */
    enum IoEvent
    {
        case Timeout;
        case Eof;
        case Error;
    }

    /**
     * Session states (§3.1).
     *
     * @internal
     */
    enum State
    {
        case Disconnected;
        case Connecting;
        case TlsHandshake;
        case AwaitGreeting;
        case Greeted;
        case Ready;
        case StartTlsSent;
        case NeedEhlo;
        case AuthInProgress;
        case InTransaction;
        case AwaitData;
        case Streaming;
        case AwaitFinal;
        case Quitting;
        case Closed;
        case Broken;
    }

    // ---------------------------------------------------------------------------------------
    // Exceptions (§2.3): flat; the classification is data (Reason, Outcome, Stage, Scope).
    // Every one must survive serialize() (§8.4): they hold only strings, arrays of strings,
    // enums and library value objects.
    // ---------------------------------------------------------------------------------------

    /**
     * A definite failure: nothing was delivered to the recipients it covers (S2).
     *
     * Its outcome is Transient or Permanent, never Unknown: "possibly delivered" is the sibling
     * DeliveryUnknownException, so `catch (SmtpException)` can never retry it (I7).
     */
    final class SmtpException extends \RuntimeException implements TehsmtpException
    {
        /**
         * @param Outcome     $outcome          Transient or Permanent only
         * @param Scope       $scope            what the failure applies to (§8.3)
         * @param string|null $command          the REDACTED command that got the deciding reply
         *                                      ("RCPT TO:<a@b>", "AUTH PLAIN [redacted]", "[body]", ".");
         *                                      null for connect, TLS, greeting and prepare
         * @param Result|null $result           per-recipient detail once RCPT was reached
         * @param bool        $retrySafe        Transient AND nothing was committed
         * @param bool        $connectionUsable the session is still Ready (e.g. after RSET)
         * @param array       $details          phpWarnings, phpDeprecations, errno, tlsDiagnostics,
         *                                      oauthError, hint, warnings
         * @param list<string> $transcript      the last <= 50 redacted, sanitised transcript lines
         */
        public function __construct(
            string $message,
            public readonly Reason $reason,
            public readonly Outcome $outcome,
            public readonly Stage $stage,
            public readonly Scope $scope,
            public readonly ?string $command = null,
            public readonly ?Reply $reply = null,
            public readonly ?Result $result = null,
            public readonly bool $retrySafe = false,
            public readonly bool $connectionUsable = false,
            public readonly array $details = [],
            public readonly array $transcript = [],
            ?\Throwable $previous = null,
        ) {
            if ($outcome !== Outcome::Transient && $outcome !== Outcome::Permanent) {
                throw new \LogicException("SmtpException needs a Transient or Permanent outcome, got {$outcome->name}");
            }
            if ($retrySafe && $outcome !== Outcome::Transient) {
                throw new \LogicException('SmtpException: retrySafe requires a Transient outcome');
            }
            parent::__construct($message, 0, $previous);
        }

        /** Worth retrying later (4yz and local transient failures). */
        public function isTransient(): bool
        {
            return $this->outcome === Outcome::Transient;
        }

        /** Fix the configuration or the data; retrying cannot help. */
        public function isPermanent(): bool
        {
            return $this->outcome === Outcome::Permanent;
        }

        /** The deciding reply was 500-504 or 555: a tehsmtp bug or an unadvertised parameter (§8.3). */
        public function isClientError(): bool
        {
            return $this->reply !== null && $this->reply->isClientError();
        }

        /** Serialisable even when PHP 8.2+ recorded a trace argument as SensitiveParameterValue (§8.4). */
        public function __serialize(): array
        {
            return Guard::serializable($this);
        }
    }

    /**
     * The message was POSSIBLY delivered: a timeout, EOF or protocol error after "." was sent, or
     * the terminator write failed (§8.1). A sibling of SmtpException, not a subclass, so a generic
     * retry catch never re-sends it (I7, X5). Never retry automatically.
     */
    final class DeliveryUnknownException extends \RuntimeException implements TehsmtpException
    {
        /** Always Scope::Transaction. */
        public readonly Scope $scope;

        /**
         * @param Stage        $stage      Final, or Body for a failed terminator write
         * @param Result       $result     every recipient, including those delivered in earlier chunks
         * @param string|null  $command    the terminator "."
         * @param list<string> $transcript the last <= 50 redacted, sanitised transcript lines
         */
        public function __construct(
            string $message,
            public readonly Stage $stage,
            public readonly Result $result,
            public readonly ?string $command = '.',
            public readonly array $details = [],
            public readonly array $transcript = [],
            ?\Throwable $previous = null,
        ) {
            if ($stage !== Stage::Final && $stage !== Stage::Body) {
                throw new \LogicException("DeliveryUnknownException needs Stage::Final or Stage::Body, got {$stage->name}");
            }
            $this->scope = Scope::Transaction;
            parent::__construct($message, 0, $previous);
        }

        /** Serialisable even when PHP 8.2+ recorded a trace argument as SensitiveParameterValue (§8.4). */
        public function __serialize(): array
        {
            return Guard::serializable($this);
        }
    }

    /** Invalid message or envelope input, raised by the setter that received it, before any I/O. */
    final class MessageException extends \InvalidArgumentException implements TehsmtpException
    {
        /**
         * @param string $field     "to[1]", "subject", "from", "envelope.to", "header.name",
         *                          "attachment[2].name", "envid", ...
         * @param bool   $injection CR/LF/NUL/C0/DEL, a ':' or SP in a header name, a bad rawHeader fold (T3)
         */
        public function __construct(
            string $message,
            public readonly string $field,
            public readonly bool $injection = false,
            ?\Throwable $previous = null,
        ) {
            parent::__construct($message, 0, $previous);
        }

        /** Serialisable even when PHP 8.2+ recorded a trace argument as SensitiveParameterValue (§8.4). */
        public function __serialize(): array
        {
            return Guard::serializable($this);
        }
    }

    /** Invalid configuration, or a missing PHP extension (then $extension names it). */
    final class ConfigException extends \LogicException implements TehsmtpException
    {
        public function __construct(
            string $message,
            public readonly ?string $extension = null,
            ?\Throwable $previous = null,
        ) {
            parent::__construct($message, 0, $previous);
        }

        /** Serialisable even when PHP 8.2+ recorded a trace argument as SensitiveParameterValue (§8.4). */
        public function __serialize(): array
        {
            return Guard::serializable($this);
        }
    }

    /**
     * Misuse: a fork, re-entrancy, a clone of a Mailer, use of an UNSERIALIZED Mailer, Message,
     * Dkim or Secret (§8.4).
     */
    final class UsageException extends \LogicException implements TehsmtpException
    {
        /** Serialisable even when PHP 8.2+ recorded a trace argument as SensitiveParameterValue (§8.4). */
        public function __serialize(): array
        {
            return Guard::serializable($this);
        }
    }

    // ---------------------------------------------------------------------------------------
    // Core values (§2.4)
    // ---------------------------------------------------------------------------------------

    /**
     * A credential, token or key (§8.4, D31). Credentials exist only as Secret inside the library.
     *
     * The value sits in a per-instance anonymous holder whose __debugInfo() is empty, reached only
     * through a static closure; the closure is the one property. So var_export(), var_dump(),
     * print_r(), debug_zval_dump(), json_encode() and array casts never show it (V5). serialize()
     * gives a placeholder and unserialize() a POISONED Secret. There is no __toString(): casting a
     * Secret to string is an Error.
     */
    final class Secret
    {
        /** A static closure returning the holder (V5); null once poisoned by __unserialize(). */
        private ?\Closure $sealed = null;

        private function __construct()
        {
        }

        /** Wraps a value. One parameter, so on PHP 8.1 no neighbouring argument can leak it (V3); never throws. */
        public static function of(#[\SensitiveParameter] string $value): self
        {
            $secret = new self();
            $secret->sealed = self::seal($value);
            return $secret;
        }

        /**
         * null stays null, a Secret is returned as is, a string is wrapped. Never throws; callers
         * move Closures elsewhere before calling it.
         *
         * @internal
         */
        public static function wrap(#[\SensitiveParameter] string|self|null $value): ?self
        {
            if ($value === null || $value instanceof self) {
                return $value;
            }
            return self::of($value);
        }

        /**
         * The value. Call it only inside an expression passed to an INTERNAL function, so no
         * userland frame that can throw ever holds the result (I13).
         *
         * @internal
         * @throws UsageException for an unserialized (poisoned) Secret
         */
        public function reveal(): string
        {
            if ($this->sealed === null) {
                throw new UsageException('this Secret was unserialized and holds no value (§8.4)');
            }
            return ($this->sealed)()->get();
        }

        /** The length in bytes; 0 after wipe() or unserialize(). */
        public function length(): int
        {
            return $this->sealed === null ? 0 : \strlen(($this->sealed)()->get());
        }

        /** Clears the value: sodium_memzero when ext-sodium is loaded, else an in-place overwrite. */
        public function wipe(): void
        {
            if ($this->sealed !== null) {
                ($this->sealed)()->wipe();
            }
        }

        /** What var_dump() and print_r() show: only the length, as `[redacted 17 bytes]` (§8.4). */
        public function __debugInfo(): array
        {
            return ['secret' => '[redacted ' . $this->length() . ' bytes]'];
        }

        /** What serialize() stores: a placeholder, never the value (§8.4). */
        public function __serialize(): array
        {
            return ['secret' => '[redacted]'];
        }

        /** Gives a POISONED Secret: reveal() throws UsageException and length() is 0. */
        public function __unserialize(array $data): void
        {
            $this->wipe();
            $this->sealed = null;
        }

        /** A clone gets its own holder, so wiping one copy never clears the other. */
        public function __clone()
        {
            if ($this->sealed !== null) {
                $this->sealed = self::seal(($this->sealed)()->get());
            }
        }

        /** Puts the value in a fresh holder and returns the static closure that reaches it. */
        private static function seal(#[\SensitiveParameter] string $value): \Closure
        {
            $holder = new class ($value) {
                public function __construct(#[\SensitiveParameter] private ?string $value)
                {
                }

                public function __debugInfo(): array
                {
                    return [];
                }

                public function get(): string
                {
                    return $this->value ?? '';
                }

                public function wipe(): void
                {
                    if ($this->value === null || $this->value === '') {
                        return;
                    }
                    if (\function_exists('sodium_memzero')) {
                        \sodium_memzero($this->value);   // zeroes the buffer when unshared, then nulls the property
                    } else {
                        // Offset writes change an unshared string in place; a shared one is copied first,
                        // so the caller's own string is never modified.
                        for ($i = 0, $n = \strlen($this->value); $i < $n; $i++) {
                            $this->value[$i] = "\0";
                        }
                    }
                    $this->value = null;
                }
            };
            return static fn (): object => $holder;
        }
    }

    /**
     * Timeouts in seconds, each validated positive and finite (§3.10). Reads have an absolute
     * deadline per reply (I6); `block` bounds each 16 KiB body slice; `bodyTotal`, when set, bounds
     * the whole body transfer.
     */
    final class Timeouts
    {
        /**
         * @param float      $connect   TCP connect AND each TLS handshake (D22); DNS is not bounded (P44)
         * @param float      $greeting  the 220 greeting
         * @param float      $command   each reply to EHLO/HELO/STARTTLS/AUTH step/RSET/NOOP/MAIL/RCPT
         * @param float      $data      DATA until the 354
         * @param float      $block     each 16 KiB body slice (RFC 5321 §4.5.3.2.5)
         * @param float      $final     the reply after "."; shorter means more duplicate deliveries (P13)
         * @param float      $quit      the 221 after QUIT
         * @param float|null $bodyTotal optional absolute limit on the whole body transfer
         * @param float      $idleProbe idle longer than this: RSET must return 250 before reuse
         * @param float      $maxIdle   idle longer than this: QUIT and reconnect (servers allow 300)
         * @throws ConfigException for a value that is not positive and finite
         */
        public function __construct(
            public readonly float $connect = 30.0,
            public readonly float $greeting = 300.0,
            public readonly float $command = 300.0,
            public readonly float $data = 120.0,
            public readonly float $block = 180.0,
            public readonly float $final = 600.0,
            public readonly float $quit = 10.0,
            public readonly ?float $bodyTotal = null,
            public readonly float $idleProbe = 30.0,
            public readonly float $maxIdle = 240.0,
        ) {
            $values = [
                'connect' => $connect, 'greeting' => $greeting, 'command' => $command, 'data' => $data,
                'block' => $block, 'final' => $final, 'quit' => $quit, 'bodyTotal' => $bodyTotal,
                'idleProbe' => $idleProbe, 'maxIdle' => $maxIdle,
            ];
            foreach ($values as $name => $seconds) {
                if ($seconds !== null && (!\is_finite($seconds) || $seconds <= 0.0)) {
                    throw new ConfigException(
                        "Timeouts: {$name} must be a positive, finite number of seconds, got "
                            . \var_export($seconds, true)
                    );
                }
            }
        }

        /**
         * Short timeouts for interactive use: connect 10, greeting 30, command 30, data 30,
         * block 60, final 120, quit 5.
         *
         * WARNING: the final-reply timeout is 120 s instead of 600 s. A server that needs longer to
         * answer "." makes the send DeliveryUnknownException although the message may well have been
         * delivered; re-sending it then creates a duplicate (P13).
         */
        public static function interactive(): self
        {
            return new self(
                connect: 10.0, greeting: 30.0, command: 30.0, data: 30.0, block: 60.0, final: 120.0, quit: 5.0,
            );
        }
    }

    /** The system clock: DateTimeImmutable('now') and hrtime(). */
    final class SystemClock implements Clock
    {
        public function now(): \DateTimeImmutable
        {
            return new \DateTimeImmutable('now');
        }

        public function monotonic(): float
        {
            return \hrtime(true) / 1e9;
        }
    }

    /** random_bytes(); a failure becomes SmtpException(Local, Transient, Prepare, retrySafe) (§8.3). */
    final class SystemRandom implements RandomSource
    {
        public function bytes(int $length): string
        {
            if ($length < 0) {
                throw new \LogicException("SystemRandom::bytes(): negative length {$length}");
            }
            if ($length === 0) {
                return '';   // random_bytes(0) is a ValueError
            }
            try {
                return \random_bytes($length);
            } catch (\Exception $e) {   // \Exception on 8.1, \Random\RandomException (a subclass) on 8.2+ (G28)
                throw self::failure($e);
            }
        }

        private static function failure(\Exception $e): SmtpException
        {
            return new SmtpException(
                'no random bytes available: ' . Text::sanitize($e->getMessage(), 200),
                Reason::Local,
                Outcome::Transient,
                Stage::Prepare,
                Scope::Transaction,
                retrySafe: true,
                previous: $e,
            );
        }
    }

    // ---------------------------------------------------------------------------------------
    // Helpers (§2.6)
    // ---------------------------------------------------------------------------------------

    /**
     * Byte, ASCII and UTF-8 helpers. Never mbstring or ctype, never a locale-dependent function
     * (8.1's strtolower() follows the locale, §2.1). Every regex anchors with \A and \z: `$` would
     * also match before a trailing "\n".
     *
     * @internal
     */
    final class Text
    {
        private const UPPER = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';
        private const LOWER = 'abcdefghijklmnopqrstuvwxyz';

        /**
         * The pieces sanitize() splits its input into, tried in this order: group 1, a run of printable
         * ASCII (kept; may be cut); a C1 control U+0080-U+009F (escaped); group 2, one well-formed
         * UTF-8 sequence (RFC 3629 §4: no overlongs or surrogates; kept whole); any other byte (escaped).
         */
        private const DISPLAY_PIECES = '/([\x20-\x7E]+)|\xC2[\x80-\x9F]'
            . '|([\xC2-\xDF][\x80-\xBF]|\xE0[\xA0-\xBF][\x80-\xBF]'
            . '|[\xE1-\xEC\xEE\xEF][\x80-\xBF]{2}|\xED[\x80-\x9F][\x80-\xBF]|\xF0[\x90-\xBF][\x80-\xBF]{2}'
            . '|[\xF1-\xF3][\x80-\xBF]{3}|\xF4[\x80-\x8F][\x80-\xBF]{2})|[\x00-\xFF]/';

        /** Normalises every line ending to CRLF. "\r\n" is tried first, so a CRLF stays one line break (M40). */
        public static function crlf(string $s): string
        {
            return \preg_replace('/\r\n|\r|\n/', "\r\n", $s)
                ?? throw new \LogicException('Text::crlf(): ' . \preg_last_error_msg());
        }

        /** Well-formed UTF-8 (PCRE's check: no overlongs, surrogates or code points above U+10FFFF). */
        public static function isUtf8(string $s): bool
        {
            return \preg_match('//u', $s) === 1;
        }

        /**
         * Rejects CR, LF, NUL, the other C0 controls and DEL; HTAB only when $allowTab (§6.2).
         * Bytes >= 0x80 are not checked here (see isUtf8()).
         *
         * @throws MessageException with $field and injection: true
         */
        public static function assertClean(string $s, string $field, bool $allowTab = false): void
        {
            $pattern = $allowTab ? '/[\x00-\x08\x0A-\x1F\x7F]/' : '/[\x00-\x1F\x7F]/';
            if (\preg_match($pattern, $s, $m, \PREG_OFFSET_CAPTURE) === 1) {
                throw new MessageException(
                    \sprintf(
                        'Invalid %s: control character 0x%02X at offset %d (CR, LF, NUL, C0 controls and DEL are not allowed)',
                        $field,
                        \ord($m[0][0]),
                        $m[0][1],
                    ),
                    $field,
                    true,
                );
            }
        }

        /** An RFC 5322 field name (ftext: %x21-39 / %x3B-7E), at most 76 characters so "Name: " leaves room to fold. */
        public static function isFieldName(string $name): bool
        {
            return \preg_match('/\A[\x21-\x39\x3B-\x7E]{1,76}\z/', $name) === 1;
        }

        /**
         * A header name is always exactly one field name: no SP, no ':', no control characters (I3, §6.2).
         *
         * @throws MessageException with $field and injection: true
         */
        public static function assertFieldName(string $name, string $field = 'header.name'): void
        {
            if (!self::isFieldName($name)) {
                throw new MessageException(
                    "Invalid {$field} \"" . self::sanitize($name, 100) . '": a header name is 1-76 printable ASCII'
                        . " characters without SP or ':' (RFC 5322 ftext)",
                    $field,
                    true,
                );
            }
        }

        /** ASCII-only lower-casing; other bytes are unchanged whatever the locale. */
        public static function lower(string $s): string
        {
            return \strtr($s, self::UPPER, self::LOWER);
        }

        /** ASCII-only upper-casing; other bytes are unchanged whatever the locale. */
        public static function upper(string $s): string
        {
            return \strtr($s, self::LOWER, self::UPPER);
        }

        /**
         * Display text for logs, transcripts and exceptions, never for parsers (§8.5, §3.6).
         * C0 controls (TAB, CR, LF and ESC included), DEL, C1 controls and every byte of invalid UTF-8
         * become "\xHH"; well-formed UTF-8 is kept. The result is one line of at most $max bytes;
         * a longer one is cut at a character boundary and ends in "...".
         */
        public static function sanitize(string $s, int $max = 512): string
        {
            if ($max < 8) {
                throw new \LogicException("Text::sanitize(): \$max must be at least 8, got {$max}");
            }
            \preg_match_all(self::DISPLAY_PIECES, $s, $sets, \PREG_SET_ORDER | \PREG_UNMATCHED_AS_NULL);
            $pieces = [];   // list<array{string, bool $cuttable}>
            $total = 0;
            foreach ($sets as $set) {
                if ($set[1] !== null) {
                    $pieces[] = [$set[0], true];
                } elseif ($set[2] !== null) {
                    $pieces[] = [$set[0], false];
                } else {
                    $escaped = '';
                    foreach (\str_split($set[0]) as $byte) {
                        $escaped .= \sprintf('\x%02X', \ord($byte));
                    }
                    $pieces[] = [$escaped, false];
                }
                $total += \strlen($pieces[\array_key_last($pieces)][0]);
            }
            if ($total <= $max) {
                return \implode('', \array_column($pieces, 0));
            }
            $out = '';
            $budget = $max - 3;
            foreach ($pieces as [$piece, $cuttable]) {
                if (\strlen($piece) > $budget) {
                    if ($cuttable) {
                        $out .= \substr($piece, 0, $budget);
                    }
                    break;
                }
                $out .= $piece;
                $budget -= \strlen($piece);
            }
            return $out . '...';
        }

        /** An IPv4 or IPv6 address, with or without one pair of brackets ("[::1]", "192.0.2.1"). */
        public static function isIpLiteral(string $host): bool
        {
            if (\strlen($host) > 2 && $host[0] === '[' && $host[-1] === ']') {
                $host = \substr($host, 1, -1);
            }
            return self::ipBytes($host) !== null;
        }

        /** 127.0.0.0/8, ::1 or ::ffff:127.0.0.0/104 (§5.1). Anything unparsable is not loopback. */
        public static function isLoopbackIp(string $ip): bool
        {
            $bytes = self::ipBytes($ip);
            if ($bytes === null) {
                return false;
            }
            if (\strlen($bytes) === 4) {
                return $bytes[0] === "\x7F";
            }
            return $bytes === \str_repeat("\0", 15) . "\x01"
                || \strncmp($bytes, \str_repeat("\0", 10) . "\xFF\xFF\x7F", 13) === 0;
        }

        /**
         * The IP of a raw stream_socket_get_name() result (§5.1): the text before the LAST ':', with
         * one pair of brackets removed, which inet_pton() must accept; the part after it must be a
         * port number. "[::1]:25" and "::1:25" both give "::1". Returns null for null or anything
         * unparsable (the caller fails closed).
         */
        public static function socketIp(?string $name): ?string
        {
            if ($name === null) {
                return null;
            }
            $colon = \strrpos($name, ':');
            if ($colon === false || \preg_match('/\A[0-9]{1,5}\z/', \substr($name, $colon + 1)) !== 1) {
                return null;
            }
            $ip = \substr($name, 0, $colon);
            if (\strlen($ip) > 2 && $ip[0] === '[' && $ip[-1] === ']') {
                $ip = \substr($ip, 1, -1);
            }
            return self::ipBytes($ip) === null ? null : $ip;
        }

        /** The 4 or 16 address bytes, or null. */
        private static function ipBytes(string $ip): ?string
        {
            // inet_pton() reads a C string and silently stops at a NUL ("127.0.0.1\0x" passes), so the
            // characters are checked first. Zone ids ("%eth0") are rejected too.
            if (\preg_match('/\A[0-9A-Fa-f.:]{2,45}\z/', $ip) !== 1) {
                return null;
            }
            $bytes = \inet_pton($ip);
            return $bytes === false ? null : $bytes;
        }
    }

    /**
     * Runs one internal-function call under a scoped error handler (§8.4, §8.5).
     *
     * Warnings and notices are collected into $warnings, deprecations into $deprecations, and the
     * application's handler never runs inside the frame (it may throw, and the frame may hold
     * revealed credential bytes: I13). The previous handler is restored in `finally`. Guard::run()
     * does not catch: every call site catches \Throwable and keeps only class and message.
     *
     * Call-site rule (§2.1): the closure is a single internal-function call whose arguments are
     * variables, literals, property reads or ->reveal(), so no userland code of ours runs inside.
     *
     * @internal
     */
    final class Guard
    {
        /**
         * Test hook, the library's only static property (I9): when set, receives every captured
         * deprecation as "<message> (<file>:<line>)". Only tests/bootstrap.php assigns it; the
         * library only reads it.
         *
         * @internal
         */
        public static ?\Closure $deprecationSink = null;

        /**
         * @param array<int, string>|null $warnings     E_WARNING and E_NOTICE messages are APPENDED
         * @param array<int, string>|null $deprecations E_DEPRECATED and E_USER_DEPRECATED entries,
         *                                              "<message> (<file>:<line>)", are APPENDED
         * @return mixed what $fn returns
         */
        public static function run(\Closure $fn, ?array &$warnings = null, ?array &$deprecations = null): mixed
        {
            $caughtWarnings = [];
            $caughtDeprecations = [];
            \set_error_handler(
                static function (int $type, string $message, string $file = '', int $line = 0)
                    use (&$caughtWarnings, &$caughtDeprecations): bool {
                    if ($type === \E_DEPRECATED || $type === \E_USER_DEPRECATED) {
                        $entry = "{$message} ({$file}:{$line})";
                        $caughtDeprecations[] = $entry;
                        $sink = self::$deprecationSink;
                        if ($sink !== null) {
                            try {
                                $sink($entry);
                            } catch (\Throwable) {
                                // A throwing sink must not unwind through a frame that may hold secrets (I13).
                            }
                        }
                    } else {
                        $caughtWarnings[] = $message;
                    }
                    return true;
                },
                \E_WARNING | \E_NOTICE | \E_DEPRECATED | \E_USER_DEPRECATED,
            );
            try {
                return $fn();
            } finally {
                \restore_error_handler();
                $warnings = \array_merge($warnings ?? [], $caughtWarnings);
                $deprecations = \array_merge($deprecations ?? [], $caughtDeprecations);
            }
        }

        /**
         * Drains OpenSSL's error queue and returns its entries, oldest first. Successful calls can
         * leave stale entries, so call sites clear the queue before a call and read it only after
         * one failed (§7.6).
         *
         * @return list<string>
         */
        public static function opensslErrors(): array
        {
            $errors = [];
            if (\function_exists('openssl_error_string')) {
                while (($error = \openssl_error_string()) !== false) {
                    $errors[] = $error;
                }
            }
            return $errors;
        }

        /** Discards OpenSSL's queued errors. */
        public static function clearOpenssl(): void
        {
            self::opensslErrors();
        }

        /**
         * The data a library exception's __serialize() returns (§8.4.2): its own properties, with
         * every trace argument that PHP 8.2+ recorded as a SensitiveParameterValue replaced by
         * '[sensitive]'. Such an object refuses serialisation, so without this, serialize($e)
         * fails for any exception whose trace passes through a #[\SensitiveParameter] parameter
         * (Mailer::__construct, the Dkim factories), even after the scrub emptied the parameter.
         * No __unserialize() is needed: PHP assigns the array back as properties. The class name
         * is compared as a string, because the symbol does not exist on 8.1 (§2.1).
         *
         * @internal
         * @return array<string, mixed>
         */
        public static function serializable(\Throwable $e): array
        {
            $data = (array) $e;
            $key = "\0Exception\0trace";
            foreach ($data[$key] ?? [] as $i => $frame) {
                foreach ($frame['args'] ?? [] as $j => $arg) {
                    if (\is_object($arg) && \get_class($arg) === 'SensitiveParameterValue') {
                        $data[$key][$i]['args'][$j] = '[sensitive]';
                    }
                }
            }
            return $data;
        }
    }

    // The rest of the library, after the core it depends on. Each file has its own guard.
    require_once __DIR__ . '/Message.php';
    require_once __DIR__ . '/Dkim.php';
    require_once __DIR__ . '/Protocol.php';
    require_once __DIR__ . '/Mailer.php';
}
