<?php
/**
 * tehsmtp: the SMTP protocol layer (§3-§5).
 *
 * Reply, the value object for one server reply (§2.4); the wire layer: Capabilities (the EHLO
 * model, §3.4), Wire (buffering, caps, absolute deadlines, redaction and the transcript ring,
 * §3.6, §8.5) and DotStuffer (§3.9); the session layer: Sasl and Session (§3, §5); and
 * StreamByteStream and StreamConnector, the only code that touches a socket (§2.6).
 * Loaded only by tehsmtp.php, after the core (§2.1).
 */

declare(strict_types=1);

namespace Tehsmtp;

if (!\class_exists(Reply::class, false)) {

    // ---------------------------------------------------------------------------------------
    // Reply (§2.4)
    // ---------------------------------------------------------------------------------------

    /**
     * One server reply: the basic code, the display text of each line and the enhanced status
     * code (RFC 2034, RFC 3463), if any.
     *
     * Decisions are made on $code alone (RFC 5321 §4.2); the enhanced code refines them and never
     * overrides the basic code (§3.6). $lines is display text only: sanitised and capped (§8.5),
     * never parsed. Parsers get the raw texts from Wire::readReply() instead.
     */
    final class Reply
    {
        /**
         * An RFC 3463 §2 code at the start of a raw line: class 2, 4 or 5, then subject and detail of
         * 1-3 digits without leading zeros, then SP, HTAB or the end of the line (§3.6).
         */
        private const ENHANCED = '/\A[245]\.(?:0|[1-9][0-9]{0,2})\.(?:0|[1-9][0-9]{0,2})(?=[ \t]|\z)/';

        /** A whole enhanced code, as the constructor accepts it. */
        private const ENHANCED_ONLY = '/\A[245]\.(?:0|[1-9][0-9]{0,2})\.(?:0|[1-9][0-9]{0,2})\z/';

        /** Longest display line kept (§8.5). */
        private const LINE_MAX = 512;

        /** @var list<string> display text after the code of each line: sanitised, each at most 512 bytes */
        public readonly array $lines;

        /**
         * @param int          $code               200-599 with a second digit of 0-5 (RFC 5321 §4.2)
         * @param list<string> $lines              the text after the code of each line; sanitised here
         * @param string|null  $enhanced           e.g. "5.1.1"; its class must equal the first digit of $code
         * @param bool         $local              synthesised by tehsmtp (connection lost, local 5.3.4, ...)
         * @param bool         $enhancedAdvertised whether the server advertised ENHANCEDSTATUSCODES
         * @throws \LogicException for an impossible code or enhanced code (a library bug)
         */
        public function __construct(
            public readonly int $code,
            array $lines,
            public readonly ?string $enhanced = null,
            public readonly bool $local = false,
            public readonly bool $enhancedAdvertised = true,
        ) {
            if (\preg_match('/\A[2-5][0-5][0-9]\z/', (string) $code) !== 1) {
                throw new \LogicException("Reply: {$code} is not an RFC 5321 reply code");
            }
            if ($enhanced !== null && (\preg_match(self::ENHANCED_ONLY, $enhanced) !== 1 || $enhanced[0] !== ((string) $code)[0])) {
                throw new \LogicException("Reply: \"{$enhanced}\" is not an enhanced code of class " . ((string) $code)[0]);
            }
            $display = [];
            foreach ($lines as $line) {
                $display[] = Text::sanitize($line, self::LINE_MAX);
            }
            $this->lines = $display;
        }

        /**
         * Builds a Reply from the raw texts Wire read (§3.6). The raw texts are not stored.
         *
         * Enhanced codes are taken from the raw text of each line. A code of another class than the
         * basic code is ignored (warning enhanced-code-mismatch); a later line with a different code,
         * or line 1 without one and a later line with one, keeps line 1's code (warning
         * enhanced-code-inconsistent, research item 58). A later line without a code is not flagged.
         *
         * @internal
         * @param list<string> $rawTexts      untruncated, unsanitised text after the code of each line
         * @param bool         $parseEnhanced false for 3yz replies and the greeting, EHLO and HELO (RFC 2034)
         * @param bool         $advertised    whether ENHANCEDSTATUSCODES was advertised (kept as a mark)
         * @param list<string> $warnings      "<stable-code>: <message>" entries are APPENDED
         */
        public static function parse(int $code, array $rawTexts, bool $parseEnhanced, bool $advertised, array &$warnings): self
        {
            $enhanced = null;
            if ($parseEnhanced) {
                $class = ((string) $code)[0];
                $mismatched = [];
                $inconsistent = [];
                foreach (\array_values($rawTexts) as $i => $text) {
                    $found = \preg_match(self::ENHANCED, $text, $m) === 1 ? $m[0] : null;
                    if ($found !== null && $found[0] !== $class) {
                        $mismatched[] = $found;
                        $found = null;
                    }
                    if ($i === 0) {
                        $enhanced = $found;
                    } elseif ($found !== null && $found !== $enhanced) {
                        $inconsistent[] = $found;
                    }
                }
                if ($mismatched !== []) {
                    $warnings[] = "enhanced-code-mismatch: {$code} reply carries " . \implode(', ', \array_unique($mismatched))
                        . ', of another class than the reply code; ignored';
                }
                if ($inconsistent !== []) {
                    $warnings[] = "enhanced-code-inconsistent: {$code} reply lines carry " . ($enhanced ?? 'no code')
                        . ' on line 1 but ' . \implode(', ', \array_unique($inconsistent)) . ' later; line 1 decides';
                }
            }
            return new self($code, $rawTexts, $enhanced, false, $advertised);
        }

        /** By the first digit: 2yz and 3yz Success, 4yz Transient, 5yz Permanent. */
        public function outcome(): Outcome
        {
            return match (\intdiv($this->code, 100)) {
                2, 3 => Outcome::Success,
                4 => Outcome::Transient,
                default => Outcome::Permanent,
            };
        }

        /** 500-504 or 555: a tehsmtp bug or an unadvertised parameter, never a recipient failure (§8.3). */
        public function isClientError(): bool
        {
            return ($this->code >= 500 && $this->code <= 504) || $this->code === 555;
        }

        /** The lines joined with "\n", without the enhanced code at the start of each line that has one. */
        public function text(): string
        {
            return \implode("\n", $this->withoutCodes());
        }

        /** "550 5.1.1 User unknown": code, enhanced code and the text of every line, one line. */
        public function __toString(): string
        {
            $parts = [(string) $this->code];
            if ($this->enhanced !== null) {
                $parts[] = $this->enhanced;
            }
            foreach ($this->withoutCodes() as $line) {
                if ($line !== '') {
                    $parts[] = $line;
                }
            }
            return \implode(' ', $parts);
        }

        /**
         * The display lines with a leading enhanced code of this reply's class removed, together with
         * one separator (SP, or HTAB, which sanitising shows as "\x09"). Only when the reply has an
         * enhanced code: without one, nothing in the text was parsed as a code.
         *
         * @return list<string>
         */
        private function withoutCodes(): array
        {
            if ($this->enhanced === null) {
                return $this->lines;
            }
            $pattern = '/\A' . $this->enhanced[0] . '\.(?:0|[1-9][0-9]{0,2})\.(?:0|[1-9][0-9]{0,2})(?: |\\\\x09|\z)/';
            $out = [];
            foreach ($this->lines as $line) {
                $out[] = (string) \preg_replace($pattern, '', $line);
            }
            return $out;
        }
    }

    // ---------------------------------------------------------------------------------------
    // The wire layer: Capabilities, Wire, DotStuffer (§3.4, §3.6, §3.9)
    // ---------------------------------------------------------------------------------------

    /**
     * The EHLO capability model of ONE connection and ONE TLS epoch (§3.4, I11). Built from the
     * RAW reply texts, never from Reply::$lines; every use checks epoch() against the session's.
     *
     * @internal
     */
    final class Capabilities
    {
        /**
         * @param array<string, list<string>> $keywords upper-cased keyword => its parameters, in
         *                                              the order the server listed them
         */
        private function __construct(
            private int $epoch,
            private bool $tls,
            private bool $viaHelo,
            private string $server,
            private array $keywords,
        ) {
        }

        /**
         * Parses the texts of a 250 reply to EHLO (RFC 5321 §4.1.1.1).
         *
         * Line 1 (`Domain [SP greet]`) is kept only as sanitised display text and never used as an
         * identity. Every later line is `keyword *(SP param)`: a keyword that is not
         * `(ALPHA / DIGIT) *(ALPHA / DIGIT / "-")` is ignored, unknown keywords are kept (and never
         * used), keywords are upper-cased. `AUTH` lines, the legacy `AUTH=` line (research item 28)
         * and duplicate lines are merged and de-duplicated. STARTTLS is dropped under TLS.
         *
         * @param list<string> $rawTexts the untruncated texts of every line (Wire::readReply)
         * @param bool         $tls      TLS was active when this EHLO was answered
         * @throws SmtpException(Protocol) for more than 256 lines or 64 KiB (Wire enforces the same caps)
         */
        public static function fromEhlo(array $rawTexts, int $epoch, bool $tls): self
        {
            $bytes = 0;
            foreach ($rawTexts as $text) {
                $bytes += \strlen($text) + 6;   // "250-" and CRLF: the same count as Wire's MAX_REPLY
            }
            if (\count($rawTexts) > Wire::MAX_LINES || $bytes > Wire::MAX_REPLY) {
                throw new SmtpException(
                    'EHLO reply exceeds ' . Wire::MAX_LINES . ' lines or ' . Wire::MAX_REPLY . ' bytes',
                    Reason::Protocol,
                    Outcome::Transient,
                    Stage::Ehlo,
                    Scope::Session,
                );
            }
            $rawTexts = \array_values($rawTexts);
            $keywords = [];
            foreach (\array_slice($rawTexts, 1) as $text) {
                $tokens = \array_values(\array_filter(\explode(' ', $text), static fn (string $t): bool => $t !== ''));
                if ($tokens === []) {
                    continue;
                }
                $keyword = Text::upper(\array_shift($tokens));
                if (\str_starts_with($keyword, 'AUTH=')) {
                    // Legacy "AUTH=LOGIN PLAIN": the first mechanism is glued to the keyword.
                    if ($keyword !== 'AUTH=') {
                        \array_unshift($tokens, \substr($keyword, 5));
                    }
                    $keyword = 'AUTH';
                } elseif (\preg_match('/\A[A-Z0-9][A-Z0-9-]*\z/', $keyword) !== 1) {
                    continue;
                }
                if ($keyword === 'STARTTLS' && $tls) {
                    continue;   // meaningless under TLS; never offered to the session (§3.4)
                }
                $params = $keywords[$keyword] ?? [];
                foreach ($tokens as $param) {
                    if ($keyword === 'AUTH') {
                        // SASL mechanism names are case-insensitive: 1-20 of A-Z 0-9 - _ (RFC 4422 §3.1).
                        $param = Text::upper($param);
                        if (\preg_match('/\A[A-Z0-9_-]{1,20}\z/', $param) !== 1) {
                            continue;
                        }
                    } elseif (\preg_match('/\A[\x21-\x7E]+\z/', $param) !== 1) {
                        continue;   // ehlo-param = 1*(%d33-126)
                    }
                    if (!\in_array($param, $params, true)) {
                        $params[] = $param;
                    }
                }
                $keywords[$keyword] = $params;
            }
            return new self($epoch, $tls, false, Text::sanitize($rawTexts[0] ?? '', 255), $keywords);
        }

        /** No extensions at all: after a HELO fallback (§3.3). */
        public static function none(int $epoch, bool $tls): self
        {
            return new self($epoch, $tls, true, '', []);
        }

        /** Whether the keyword (any case) was advertised. */
        public function has(string $keyword): bool
        {
            return isset($this->keywords[Text::upper($keyword)]);
        }

        /**
         * The keyword's parameters, or null when it was not advertised.
         *
         * @return list<string>|null
         */
        public function params(string $keyword): ?array
        {
            return $this->keywords[Text::upper($keyword)] ?? null;
        }

        /**
         * The advertised SASL mechanisms, upper-cased and de-duplicated, from `AUTH` and legacy
         * `AUTH=` lines alike.
         *
         * @return list<string>
         */
        public function authMechanisms(): array
        {
            return $this->keywords['AUTH'] ?? [];
        }

        /**
         * The SIZE limit (RFC 1870 §4): null when unknown (SIZE absent, without a value, or with a
         * value that is not 1-20 digits), 0 when unlimited (a 0, or a value beyond PHP_INT_MAX).
         */
        public function sizeLimit(): ?int
        {
            $value = $this->keywords['SIZE'][0] ?? null;
            if ($value === null || \preg_match('/\A[0-9]{1,20}\z/', $value) !== 1) {
                return null;
            }
            return self::integer($value) ?? 0;   // beyond PHP_INT_MAX: no practical limit (P34)
        }

        /** LIMITS RCPTMAX (RFC 9422), clamped to at least 1; null when not advertised. */
        public function rcptMax(): ?int
        {
            return $this->limit('RCPTMAX');
        }

        /** LIMITS MAILMAX (RFC 9422), clamped to at least 1; null when not advertised. */
        public function mailMax(): ?int
        {
            return $this->limit('MAILMAX');
        }

        /** The TLS epoch these capabilities belong to (I11). */
        public function epoch(): int
        {
            return $this->epoch;
        }

        /** True after a HELO fallback: no extension may be used (§3.3). */
        public function viaHelo(): bool
        {
            return $this->viaHelo;
        }

        /** Whether TLS was active when these capabilities were learned. */
        public function tls(): bool
        {
            return $this->tls;
        }

        /** Line 1 of the EHLO reply as sanitised display text ('' after HELO); never an identity. */
        public function server(): string
        {
            return $this->server;
        }

        /**
         * For info() and diagnostics.
         *
         * @return array{epoch: int, tls: bool, viaHelo: bool, server: string,
         *               keywords: array<string, list<string>>, auth: list<string>,
         *               size: ?int, rcptMax: ?int, mailMax: ?int}
         */
        public function toArray(): array
        {
            return [
                'epoch' => $this->epoch,
                'tls' => $this->tls,
                'viaHelo' => $this->viaHelo,
                'server' => $this->server,
                'keywords' => $this->keywords,
                'auth' => $this->authMechanisms(),
                'size' => $this->sizeLimit(),
                'rcptMax' => $this->rcptMax(),
                'mailMax' => $this->mailMax(),
            ];
        }

        /** The first "NAME=digits" parameter of LIMITS with this name, at least 1 (RFC 9422 §4). */
        private function limit(string $name): ?int
        {
            foreach ($this->keywords['LIMITS'] ?? [] as $param) {
                if (\preg_match('/\A([A-Za-z0-9_-]+)=([0-9]{1,20})\z/', $param, $m) === 1 && Text::upper($m[1]) === $name) {
                    return \max(1, self::integer($m[2]) ?? \PHP_INT_MAX);
                }
            }
            return null;
        }

        /** A decimal digit string as an int; null beyond PHP_INT_MAX (compared as strings, so nothing overflows). */
        private static function integer(string $digits): ?int
        {
            $digits = \ltrim($digits, '0');
            if ($digits === '') {
                return 0;
            }
            $max = (string) \PHP_INT_MAX;
            if (\strlen($digits) > \strlen($max) || (\strlen($digits) === \strlen($max) && \strcmp($digits, $max) > 0)) {
                return null;
            }
            return (int) $digits;
        }
    }

    /**
     * Between Session and ByteStream (§2.6, D7, D8): the own read buffer, reply parsing with an
     * ABSOLUTE monotonic deadline per reply and byte and line caps (§3.6, I6), write slicing,
     * quiescence checks (§3.6, §4.4), redaction and the transcript ring (§8.5).
     *
     * Every line written and read is logged once, redacted and sanitised, to the logger and the
     * ring of the last RING lines; failures raise SmtpException carrying that ring as transcript.
     * Exceptions thrown by the logger propagate unchanged. Wire never reads or stores a revealed
     * credential outside the expression handed to ByteStream::writeSome() (I13).
     *
     * @internal
     */
    final class Wire
    {
        /**
         * MAX_LINE: bytes per reply line without CRLF (≥ 12 KiB for SASL challenges, research item 39);
         * MAX_LINES and MAX_REPLY: lines and bytes per reply; READ_CHUNK: every read asks for 32 KiB,
         * the probes included, so no TLS record is ever left inside OpenSSL (V8); WRITE_SLICE: body
         * slice; GROUP_BYTES: a pipelined group fits any TCP window (P14); RING: transcript lines kept;
         * PROBE: seconds a quiescence probe waits (§3.6, §4.4).
         */
        public const MAX_LINE = 16384, MAX_LINES = 256, MAX_REPLY = 65536, READ_CHUNK = 32768, WRITE_SLICE = 16384,
            GROUP_BYTES = 4000, RING = 50, PROBE = 0.2;

        /** Longest transcript line for client lines; server lines use Text::sanitize()'s 512. */
        private const CLIENT_MAX = 1024;

        /** Bytes received and not yet consumed by a reply. */
        private string $buffer = '';

        /** @var list<string> the last RING transcript lines */
        private array $ring = [];

        /** @var list<string> "<stable-code>: <message>" warnings not yet taken, de-duplicated */
        private array $warnings = [];

        /** @var list<string> deprecations captured during the current public call (details['phpDeprecations']) */
        private array $callDeprecations = [];

        /** @var list<string> redacted forms of the commands whose replies are still owed, oldest first */
        private array $pending = [];

        /** Body octets written since the last "[body N octets]" transcript line. */
        private int $bodyOctets = 0;

        private bool $redacting = false;

        /** The enhancedAdvertised flag of the last readReply(), for replies found by a probe. */
        private bool $advertised = false;

        /** Set by __unserialize(): every method then throws UsageException (§8.4). */
        private bool $inert = false;

        /** Never throws. */
        public function __construct(private ByteStream $io, private Clock $clock, private ?\Closure $logger)
        {
        }

        /**
         * Reads one complete reply (§3.6). The deadline is ABSOLUTE: `$timeout` seconds from now for
         * the whole reply, however the server trickles it (I6; fgets would re-arm per read, D7).
         *
         * Lines end in CRLF; a bare LF is tolerated (warning bare-lf-from-server), a bare CR is never
         * a line end. Each line must match `code [("-" / SP) text]` with a code of [2-5][0-5][0-9] (a
         * bare "250" is valid) and carry the same code as line 1, or the reply is a Protocol error.
         * A 3yz code must be in $allowed3xx (354 after DATA, 334 in AUTH).
         *
         * @param list<int>         $allowed3xx
         * @param list<string>|null $rawTexts   set to the untruncated, unsanitised text of each line,
         *                                      for parsers only (Capabilities, SASL, XOAUTH2 JSON)
         * @throws SmtpException Timeout, ConnectionLost or Protocol (Transient; the caller decides
         *                       whether the outcome is Unknown)
         */
        public function readReply(
            Stage $stage,
            float $timeout,
            bool $enhancedAdvertised,
            array $allowed3xx = [],
            ?array &$rawTexts = null,
        ): Reply {
            $this->begin();
            $this->advertised = $enhancedAdvertised;
            $command = \array_shift($this->pending);
            $deadline = $this->clock->monotonic() + $timeout;
            return $this->reply($stage, $deadline, $timeout, $command, $enhancedAdvertised, $allowed3xx, $rawTexts);
        }

        /**
         * Writes one command line plus CRLF in ONE buffer, looping on partial writes. For
         * non-sensitive lines; during the redaction window a line without $logAs is logged as
         * "[redacted N octets]".
         *
         * @param string|null $logAs the transcript form, e.g. "AUTH PLAIN [redacted]"
         * @throws \LogicException when $line contains CR, LF or NUL (commands come only from validated parts, I3)
         * @throws SmtpException Timeout or ConnectionLost
         */
        public function writeLine(string $line, float $timeout, ?string $logAs = null): void
        {
            $this->begin();
            self::assertCommand($line);
            $shown = $this->shown($line, $logAs);
            $this->log('C: ' . $shown);
            $this->pending[] = $shown;
            $this->writeAll($line . "\r\n", $timeout, $this->stageOf($line), $shown);
        }

        /**
         * Writes a credential line plus CRLF. The value is revealed only inside the expression passed
         * to the non-throwing ByteStream::writeSome(), so no frame that can throw ever holds it (I13);
         * the transcript shows $logAs.
         *
         * @throws \LogicException when the value contains CR, LF or NUL (a library bug: Sasl sends base64)
         * @throws SmtpException Timeout or ConnectionLost, raised after the write frame returned
         */
        public function writeSecretLine(Secret $line, float $timeout, string $logAs): void
        {
            $this->begin();
            if (\strcspn($line->reveal(), "\r\n\0") !== $line->length()) {
                throw new \LogicException('Wire: a credential line never contains CR, LF or NUL');
            }
            $shown = Text::sanitize($logAs, self::CLIENT_MAX);
            $this->log('C: ' . $shown);
            $this->pending[] = $shown;
            $total = $line->length() + 2;
            for ($offset = 0; $offset < $total; $offset += $written) {
                $written = $this->io->writeSome(\substr($line->reveal() . "\r\n", $offset), $timeout);
                $this->collect();
                $this->checkWritten($written, $total - $offset, Stage::Auth, $shown, $timeout);
            }
        }

        /**
         * Writes a pipelined group (§3.7) as ONE buffer to writeSome (looped on partial writes); each
         * line is logged on its own and owes one reply, read with readReply() in order.
         *
         * @param list<string> $lines
         * @throws \LogicException for an empty group, CR/LF/NUL in a line, or a group of several lines
         *                         over GROUP_BYTES (the caller packs groups, P14)
         * @throws SmtpException Timeout or ConnectionLost
         */
        public function writeGroup(array $lines, float $timeout): void
        {
            $this->begin();
            if ($lines === []) {
                throw new \LogicException('Wire::writeGroup(): an empty group');
            }
            $lines = \array_values($lines);
            $bytes = '';
            foreach ($lines as $line) {
                self::assertCommand($line);
                $bytes .= $line . "\r\n";
            }
            if (\count($lines) > 1 && \strlen($bytes) > self::GROUP_BYTES) {
                throw new \LogicException('Wire::writeGroup(): ' . \strlen($bytes) . ' octets exceed GROUP_BYTES');
            }
            $first = null;
            foreach ($lines as $line) {
                $shown = $this->shown($line, null);
                $this->log('C: ' . $shown);
                $this->pending[] = $shown;
                $first ??= $shown;
            }
            $this->writeAll($bytes, $timeout, $this->stageOf($lines[0]), $first);
        }

        /**
         * Writes (dot-stuffed) body bytes in WRITE_SLICE slices. Each slice may take up to $block
         * seconds, re-armed by every partial send (RFC 5321 §4.5.3.2.5); $absDeadline, the optional
         * absolute limit on the whole body, is checked between slices, so it is overrun by at most one
         * slice (§3.10). The transcript gets one "C: [body N octets]" line, written before the next
         * line; body bytes are never logged.
         *
         * @throws SmtpException Timeout or ConnectionLost (stage Body, command "[body]")
         */
        public function writeBody(string $bytes, float $block, ?float $absDeadline): void
        {
            $this->live();
            $this->callDeprecations = [];
            for ($offset = 0, $length = \strlen($bytes); $offset < $length; $offset += self::WRITE_SLICE) {
                if ($absDeadline !== null && $this->clock->monotonic() >= $absDeadline) {
                    throw $this->failure(Reason::Timeout, Stage::Body, '[body]',
                        'the body transfer exceeded its total time limit (Timeouts->bodyTotal)');
                }
                $slice = \substr($bytes, $offset, self::WRITE_SLICE);
                $this->writeAll($slice, $block, Stage::Body, '[body]');
                $this->bodyOctets += \strlen($slice);
            }
        }

        /**
         * The quiescence check before every command or group except the first EHLO and the first
         * EHLO after TLS (§3.6, P45): (1) the own buffer must be empty, else Protocol (desync, or a
         * wrong reply count); (2) if the socket is readable, one probe read of READ_CHUNK within
         * PROBE seconds: Timeout is benign (typically a TLS 1.3 NewSessionTicket that OpenSSL absorbed,
         * V9), Eof is ConnectionLost, bytes are parsed as a reply.
         *
         * @return Reply|null null when quiet; an unsolicited 421 (the caller handles it as a 421:
         *                    Broken, Transient)
         * @throws SmtpException ConnectionLost, or Protocol for any other unsolicited data
         */
        public function assertQuiescent(): ?Reply
        {
            $this->begin();
            $this->pending = [];   // a quiescence check means no reply is owed any more
            if ($this->buffer !== '') {
                throw $this->failure(Reason::Protocol, Stage::Idle, null, \strlen($this->buffer)
                    . ' unexpected bytes from the server before the next command (desync or wrong reply count)');
            }
            $readable = $this->io->pollReadable();
            $this->collect();
            if (!$readable) {
                return null;
            }
            $probe = $this->io->readSome(self::READ_CHUNK, self::PROBE);
            $this->collect();
            if ($probe === IoEvent::Timeout) {
                return null;
            }
            if (!\is_string($probe)) {
                throw $this->lost($probe, Stage::Idle, null, 'while the connection was idle');
            }
            $this->buffer .= $probe;
            try {
                $reply = $this->reply(Stage::Idle, $this->clock->monotonic() + self::PROBE, self::PROBE, null,
                    $this->advertised, [], $raw);
            } catch (SmtpException $e) {
                if ($e->reason !== Reason::Timeout) {
                    throw $e;
                }
                throw $this->failure(Reason::Protocol, Stage::Idle, null,
                    'unsolicited, incomplete data from the server while idle');
            }
            if ($reply->code === 421) {
                return $reply;
            }
            throw $this->failure(Reason::Protocol, Stage::Idle, null,
                "unsolicited {$reply->code} reply from the server while idle", $reply);
        }

        /**
         * The STARTTLS injection defence (§4.4, P2, CVE-2011-0411), right after the 220 to STARTTLS:
         * the own buffer is empty, ByteStream::unreadBytes() is 0, and the socket is either not
         * readable or a probe read times out. Any byte before the handshake is an Injection; '' is
         * never treated as benign.
         *
         * @throws SmtpException Injection (Permanent), ConnectionLost, or Tls when the stream cannot
         *                       report its buffer
         */
        public function assertNothingBeforeTls(): void
        {
            $this->begin();
            if ($this->buffer !== '') {
                throw $this->injection(\strlen($this->buffer));
            }
            $unread = $this->io->unreadBytes();
            $this->collect();
            if ($unread > 0) {
                throw $this->injection($unread);
            }
            if ($unread < 0) {
                throw $this->failure(Reason::Tls, Stage::StartTls, 'STARTTLS',
                    'cannot tell whether plaintext is buffered before the TLS handshake', null, $this->io->lastWarnings());
            }
            $readable = $this->io->pollReadable();
            $this->collect();
            if (!$readable) {
                return;
            }
            $probe = $this->io->readSome(self::READ_CHUNK, self::PROBE);
            $this->collect();
            if ($probe === IoEvent::Timeout) {
                return;
            }
            if (\is_string($probe)) {
                $this->log('S: ' . Text::sanitize($probe));
                throw $this->injection(\strlen($probe));
            }
            throw $this->lost($probe, Stage::StartTls, 'STARTTLS', 'after the 220 to STARTTLS');
        }

        /**
         * Starts or ends the redaction window (§5.3). While it is open, 334 replies are logged as
         * "334 [challenge N bytes]" and client lines without $logAs as "[redacted N octets]". It
         * also closes by itself at the first reply that is not 334.
         */
        public function redacting(bool $on): void
        {
            $this->live();
            $this->redacting = $on;
        }

        /** Adds an informational "*  <text>" line to the logger and the transcript (§8.5). */
        public function note(string $text): void
        {
            $this->begin();
            $this->log('*  ' . Text::sanitize($text, self::CLIENT_MAX));
        }

        /**
         * The warnings collected since the last call, each "<stable-code>: <message>":
         * bare-lf-from-server, enhanced-code-mismatch, enhanced-code-inconsistent, php-deprecation.
         *
         * @return list<string>
         */
        public function takeWarnings(): array
        {
            $this->live();
            $warnings = $this->warnings;
            $this->warnings = [];
            return $warnings;
        }

        /**
         * The last RING redacted, sanitised transcript lines, oldest first.
         *
         * @return list<string>
         */
        public function transcript(): array
        {
            $this->live();
            $this->flushBody();
            return $this->ring;
        }

        /** Bytes received but not yet consumed by a reply. */
        public function bufferedBytes(): int
        {
            $this->live();
            return \strlen($this->buffer);
        }

        /**
         * A summary for var_dump() and print_r(): never the ByteStream (a test double may hold every
         * byte written), the logger or the received bytes (§8.4).
         */
        public function __debugInfo(): array
        {
            return ['buffered' => \strlen($this->buffer), 'redacting' => $this->redacting, 'transcript' => $this->ring,
                'inert' => $this->inert];
        }

        /** A one-key placeholder: the logger Closure and the stream never enter a serialised trace (§8.4). */
        public function __serialize(): array
        {
            return ['wire' => '[redacted]'];
        }

        /** An INERT Wire: every method throws UsageException. */
        public function __unserialize(array $data): void
        {
            $this->inert = true;
        }

        /** Reads lines up to the end of one reply and builds it; the core of readReply(). */
        private function reply(
            Stage $stage,
            float $deadline,
            float $timeout,
            ?string $command,
            bool $advertised,
            array $allowed3xx,
            ?array &$rawTexts,
        ): Reply {
            $code = null;
            $texts = [];
            $size = 0;
            do {
                $line = $this->line($stage, $deadline, $timeout, $command);
                $size += \strlen($line) + 2;
                if (\preg_match('/\A([2-5][0-5][0-9])(?:([ -])(.*))?\z/s', $line, $m) !== 1) {
                    // Inside the redaction window even a malformed line is not shown (§5.3).
                    $hidden = '[redacted ' . \strlen($line) . ' bytes]';
                    $this->log('S: ' . ($this->redacting ? $hidden : Text::sanitize($line)));
                    throw $this->failure(Reason::Protocol, $stage, $command, 'malformed reply line '
                        . ($this->redacting ? $hidden : '"' . Text::sanitize($line, 100) . '"') . ' (RFC 5321 §4.2)');
                }
                if ($code === null) {
                    $code = $m[1];
                    if ($code !== '334') {
                        $this->redacting = false;   // the window ends at the first reply that is not 334 (§5.3)
                    }
                }
                $this->log($this->redacting && $m[1] === '334'
                    ? 'S: 334 [challenge ' . \strlen($m[3] ?? '') . ' bytes]'
                    : 'S: ' . Text::sanitize($line));
                if ($m[1] !== $code) {
                    throw $this->failure(Reason::Protocol, $stage, $command,
                        "reply code changed from {$code} to {$m[1]} within one reply (desync or injection)");
                }
                $texts[] = $m[3] ?? '';
                if (\count($texts) > self::MAX_LINES) {
                    throw $this->failure(Reason::Protocol, $stage, $command, 'reply has more than ' . self::MAX_LINES . ' lines');
                }
                if ($size > self::MAX_REPLY) {
                    throw $this->failure(Reason::Protocol, $stage, $command, 'reply is longer than ' . self::MAX_REPLY . ' bytes');
                }
            } while (($m[2] ?? '') === '-');

            $parseEnhanced = $code[0] !== '3' && !\in_array($stage, [Stage::Greeting, Stage::Ehlo, Stage::Helo], true);
            $parseWarnings = [];
            $reply = Reply::parse((int) $code, $texts, $parseEnhanced, $advertised, $parseWarnings);
            foreach ($parseWarnings as $warning) {
                $this->warn($warning);
            }
            if ($code[0] === '3' && !\in_array($reply->code, $allowed3xx, true)) {
                throw $this->failure(Reason::Protocol, $stage, $command, "unexpected intermediate reply {$code}", $reply);
            }
            $rawTexts = $texts;
            return $reply;
        }

        /** One line from the buffer, reading as needed until the deadline; CR stripped. */
        private function line(Stage $stage, float $deadline, float $timeout, ?string $command): string
        {
            while (true) {
                $lf = \strpos($this->buffer, "\n");
                if ($lf !== false) {
                    $line = \substr($this->buffer, 0, $lf);
                    $this->buffer = \substr($this->buffer, $lf + 1);
                    if (\str_ends_with($line, "\r")) {
                        $line = \substr($line, 0, -1);
                    } else {
                        $this->warn('bare-lf-from-server: the server ended a reply line with LF instead of CRLF');
                    }
                    if (\strlen($line) > self::MAX_LINE) {
                        throw $this->failure(Reason::Protocol, $stage, $command, 'reply line longer than ' . self::MAX_LINE . ' bytes');
                    }
                    return $line;
                }
                if (\strlen($this->buffer) > self::MAX_LINE + 1) {   // no LF yet: MAX_LINE bytes and a CR at most
                    throw $this->failure(Reason::Protocol, $stage, $command, 'reply line longer than ' . self::MAX_LINE . ' bytes');
                }
                $remaining = $deadline - $this->clock->monotonic();
                // is_nan() spelled out: opcache rewrites !($x > 0.0) as $x <= 0.0, which lets NAN through.
                if (\is_nan($remaining) || $remaining <= 0.0) {
                    throw $this->failure(Reason::Timeout, $stage, $command,
                        "timed out after {$timeout} s waiting for " . self::awaited($stage, $command));
                }
                // Always READ_CHUNK: a read of at least one TLS record never leaves plaintext inside
                // OpenSSL, which would make a later select fill PHP's buffer and a read stall (V8).
                $read = $this->io->readSome(self::READ_CHUNK, $remaining);
                $this->collect();
                if (\is_string($read)) {
                    if ($read === '') {
                        throw new \LogicException('ByteStream::readSome() returned an empty string');
                    }
                    $this->buffer .= $read;
                } elseif ($read !== IoEvent::Timeout) {   // a Timeout loops until the deadline
                    throw $this->lost($read, $stage, $command, 'waiting for ' . self::awaited($stage, $command));
                }
            }
        }

        /** Writes all bytes, looping on partial writes; each writeSome() gets the whole $timeout. */
        private function writeAll(string $bytes, float $timeout, Stage $stage, ?string $command): void
        {
            for ($offset = 0, $length = \strlen($bytes); $offset < $length; $offset += $written) {
                $written = $this->io->writeSome($offset === 0 ? $bytes : \substr($bytes, $offset), $timeout);
                $this->collect();
                $this->checkWritten($written, $length - $offset, $stage, $command, $timeout);
            }
        }

        /** Turns a writeSome() result into an exception unless it is a byte count in 1..$left. */
        private function checkWritten(int|IoEvent $written, int $left, Stage $stage, ?string $command, float $timeout): void
        {
            if ($written === IoEvent::Timeout) {
                throw $this->failure(Reason::Timeout, $stage, $command, "timed out after {$timeout} s writing " . ($command ?? 'to the server'));
            }
            if ($written instanceof IoEvent) {
                throw $this->lost($written, $stage, $command, 'writing ' . ($command ?? 'to the server'));
            }
            if ($written < 1 || $written > $left) {
                throw new \LogicException("ByteStream::writeSome() returned {$written} for {$left} bytes");
            }
        }

        /** The start of every public call except writeBody(). */
        private function begin(): void
        {
            $this->live();
            $this->callDeprecations = [];
            $this->flushBody();
        }

        private function live(): void
        {
            if ($this->inert) {
                throw new UsageException('this Wire was unserialized and has no connection (§8.4)');
            }
        }

        /** Takes the deprecations of the last ByteStream call: warning php-deprecation, logged (§8.5). */
        private function collect(): void
        {
            foreach ($this->io->lastDeprecations() as $deprecation) {
                $this->callDeprecations[] = $deprecation;
                $this->warn('php-deprecation: ' . $deprecation);
                $this->log('*  php-deprecation: ' . Text::sanitize($deprecation, self::CLIENT_MAX));
            }
        }

        private function warn(string $warning): void
        {
            if (!\in_array($warning, $this->warnings, true)) {
                $this->warnings[] = $warning;
            }
        }

        private function log(string $line): void
        {
            $this->ring[] = $line;
            if (\count($this->ring) > self::RING) {
                \array_shift($this->ring);
            }
            if ($this->logger !== null) {
                ($this->logger)($line);
            }
        }

        private function flushBody(): void
        {
            if ($this->bodyOctets > 0) {
                $octets = $this->bodyOctets;
                $this->bodyOctets = 0;
                $this->log("C: [body {$octets} octets]");
            }
        }

        /** How a client line appears in the transcript. */
        private function shown(string $line, ?string $logAs): string
        {
            if ($logAs === null && $this->redacting) {
                $logAs = '[redacted ' . \strlen($line) . ' octets]';
            }
            return Text::sanitize($logAs ?? $line, self::CLIENT_MAX);
        }

        /** The stage a write failure of this line belongs to, from its verb; the terminator is Body (§2.3). */
        private function stageOf(string $line): Stage
        {
            if ($this->redacting) {
                return Stage::Auth;
            }
            return match (Text::upper(\explode(' ', $line, 2)[0])) {
                'EHLO' => Stage::Ehlo,
                'HELO' => Stage::Helo,
                'STARTTLS' => Stage::StartTls,
                'MAIL' => Stage::Mail,
                'RCPT' => Stage::Rcpt,
                'DATA' => Stage::Data,
                '.' => Stage::Body,
                'RSET' => Stage::Rset,
                'NOOP' => Stage::Idle,
                'QUIT' => Stage::Quit,
                default => Stage::Auth,   // AUTH, or a SASL response line: nothing else is ever written
            };
        }

        private static function assertCommand(string $line): void
        {
            if (\strpbrk($line, "\r\n\0") !== false) {
                throw new \LogicException('Wire: a command line never contains CR, LF or NUL (I3)');
            }
        }

        private static function awaited(Stage $stage, ?string $command): string
        {
            if ($command !== null) {
                return "the reply to {$command}";
            }
            return $stage === Stage::Greeting ? 'the server greeting' : 'a reply';
        }

        /** The §8.3 scope of a failure at this stage. */
        private static function scope(Stage $stage): Scope
        {
            return match ($stage) {
                Stage::Prepare, Stage::Mail, Stage::Rcpt, Stage::Data, Stage::Body, Stage::Final => Scope::Transaction,
                default => Scope::Session,
            };
        }

        /** ConnectionLost for an Eof or Error result, with the ByteStream's warnings for an Error. */
        private function lost(IoEvent $event, Stage $stage, ?string $command, string $while): SmtpException
        {
            if ($event === IoEvent::Eof) {
                return $this->failure(Reason::ConnectionLost, $stage, $command, "the server closed the connection {$while}",
                    null, $this->io->lastWarnings());
            }
            $warnings = $this->io->lastWarnings();
            $cause = $warnings === [] ? '' : ': ' . Text::sanitize((string) \end($warnings), 200);
            return $this->failure(Reason::ConnectionLost, $stage, $command, "connection lost {$while}{$cause}", null, $warnings);
        }

        private function injection(int $bytes): SmtpException
        {
            return $this->failure(Reason::Injection, Stage::StartTls, 'STARTTLS',
                "{$bytes} bytes arrived after the reply to STARTTLS and before the TLS handshake"
                    . ' (possible STARTTLS command injection, RFC 3207 §5); no handshake was attempted',
                null, [], Outcome::Permanent);
        }

        /**
         * @param list<string> $phpWarnings the ByteStream's warnings; sanitised into details['phpWarnings']
         */
        private function failure(
            Reason $reason,
            Stage $stage,
            ?string $command,
            string $message,
            ?Reply $reply = null,
            array $phpWarnings = [],
            Outcome $outcome = Outcome::Transient,
        ): SmtpException {
            $this->flushBody();
            $details = [];
            if ($phpWarnings !== []) {
                $details['phpWarnings'] = \array_map(static fn (string $w): string => Text::sanitize($w), \array_values($phpWarnings));
            }
            if ($this->callDeprecations !== []) {
                $details['phpDeprecations'] = $this->callDeprecations;
            }
            return new SmtpException($message, $reason, $outcome, $stage, self::scope($stage), $command, $reply,
                details: $details, transcript: $this->ring);
        }
    }

    /**
     * Streaming dot-stuffing (§3.9, RFC 5321 §4.5.2), exactly the V6 algorithm: a line-initial "."
     * gets a second "."; an empty chunk never changes the state; a CR at the end of a chunk is held
     * back, so a CRLF split across chunks is still seen. The input is the rendered message, which is
     * CRLF-clean and ends in CRLF (§6.1).
     *
     * @internal
     */
    final class DotStuffer
    {
        private bool $atLineStart = true;
        private string $carry = '';

        /** The stuffed bytes for this chunk; may be '' (an empty chunk, or a lone CR held back). */
        public function push(string $chunk): string
        {
            if ($chunk === '') {
                return '';   // an empty chunk NEVER changes the state (P11, Y15)
            }
            $c = $this->carry . $chunk;
            $this->carry = '';
            if (\str_ends_with($c, "\r")) {
                $this->carry = "\r";   // maybe the first half of a CRLF split across chunks
                $c = \substr($c, 0, -1);
            }
            if ($c === '') {
                return '';   // the chunk was a lone CR: held back, state unchanged
            }
            if ($this->atLineStart && $c[0] === '.') {
                $c = '.' . $c;
            }
            $c = \str_replace("\r\n.", "\r\n..", $c);
            $this->atLineStart = \str_ends_with($c, "\n");   // updated only from non-empty output
            return $c;
        }

        /**
         * Checks that the data ended at a line start with nothing held back, so the terminator makes a
         * correct CRLF.CRLF (I5). The Renderer guarantees the final CRLF; none is added here.
         *
         * @throws \LogicException otherwise (a library bug: abort() and never send ".")
         */
        public function finish(): void
        {
            if ($this->carry !== '' || !$this->atLineStart) {
                throw new \LogicException('DotStuffer: the message data does not end with CRLF');
            }
        }
    }

    // ---------------------------------------------------------------------------------------
    // The session layer: Sasl and Session (§3, §5)
    // ---------------------------------------------------------------------------------------

    /**
     * The SASL exchanges of RFC 4954 (§5.3): PLAIN (RFC 4616), LOGIN (draft-murchison-sasl-login)
     * and XOAUTH2 (Google, Microsoft), inside the Wire's redaction window.
     *
     * Credential lines exist only as Secret and leave only through Wire::writeSecretLine(), so no
     * frame that can throw ever holds a revealed credential (I13); the Secrets built here are wiped
     * after use. run() ends at the first reply that is not 334 and returns it for Session::auth()
     * to classify (§5.2); a broken exchange throws.
     *
     * An instance holds the Wire, so the Wire (and the ByteStream behind it) is never an argument
     * of a frame that can throw: no dump of an exception's trace reaches the transport (§8.4, I13).
     *
     * @internal
     */
    final class Sasl
    {
        /** RFC 4954 §4: the AUTH command, initial response included, is at most 512 octets with CRLF. */
        public const LINE_MAX = 512;

        /** RFC 4616 §2: servers must accept authcid and passwd of up to 255 octets. */
        private const PLAIN_FIELD_MAX = 255;

        /** A bearer token (RFC 6750 §2.1 b64token), as XOAUTH2 carries it (§5.3). */
        private const BEARER = '/\A[A-Za-z0-9\-._~+\/]+=*\z/';

        /**
         * Never throws.
         *
         * @param float $timeout            each write and each reply of the exchange (Timeouts->command)
         * @param bool  $enhancedAdvertised ENHANCEDSTATUSCODES in this epoch (Reply::$enhancedAdvertised)
         */
        public function __construct(private Wire $wire, private float $timeout, private bool $enhancedAdvertised = true)
        {
        }

        /**
         * Runs one AUTH exchange (§5.3). The redaction window opens before "AUTH" is written; Wire
         * closes it at the first reply that is not 334, and so does this method on any exit.
         *
         * Initial response ($irMode): 'auto' sends it when "AUTH MECH <b64>" CRLF fits in 512
         * octets, 'never' always uses two steps, 'always' always one (over 512 octets that leaves
         * RFC 4954 §4: warning auth-long-initial-response). LOGIN never sends one. Challenges are
         * decoded strictly from the RAW reply text. PLAIN and XOAUTH2 accept only an empty 334
         * before the credentials; LOGIN answers by step number; XOAUTH2 answers a 334 after the
         * credentials (the JSON error step) with an empty line, never "*". Anything else is
         * cancelled with "*". No mechanism here answers more than three challenges, which keeps
         * every exchange within §5.3's limit of 8 steps.
         *
         * @param Secret $secret the password (PLAIN, LOGIN) or the access token (XOAUTH2)
         * @return array{reply: Reply, command: string, oauthError: ?array<string, string>, warnings: list<string>}
         *         reply: the first reply that is not 334; command: the AUTH line as the transcript
         *         shows it ("AUTH PLAIN [redacted]" or "AUTH LOGIN"); oauthError: status, schemes and
         *         scope from the XOAUTH2 JSON step, if one came
         * @throws SmtpException Protocol after a cancelled exchange (an undecodable or unexpected
         *                       challenge; connectionUsable false), Auth Permanent after a third LOGIN
         *                       challenge (connectionUsable true when the server answered the
         *                       cancellation), or the Wire's Timeout/ConnectionLost/Protocol; always
         *                       Stage::Auth, Scope::Session, command as above
         * @throws \LogicException for input the Mailer refuses at construction or fetch (a library bug)
         */
        public function run(AuthMethod $method, string $user, Secret $secret, string $irMode): array
        {
            if (!\in_array($irMode, ['auto', 'always', 'never'], true)) {
                throw new \LogicException("Sasl::run(): authInitialResponse must be 'auto', 'always' or 'never'");
            }
            $mechanism = $method->value;
            $warnings = [];
            $credential = match ($method) {
                AuthMethod::Plain => self::plain($user, $secret),
                AuthMethod::XOAuth2 => self::xoauth2($user, $secret),
                AuthMethod::Login => null,
            };
            if ($method === AuthMethod::Plain && (\strlen($user) > self::PLAIN_FIELD_MAX || $secret->length() > self::PLAIN_FIELD_MAX)) {
                $warnings[] = 'long-plain-credential: the PLAIN user name or password is longer than 255 octets,'
                    . ' which servers need not accept (RFC 4616 §2)';
            }
            $initial = false;
            if ($credential !== null) {
                // A zero-length initial response would be sent as "=" (RFC 4954 §4); PLAIN and
                // XOAUTH2 responses are never empty.
                $length = \strlen("AUTH {$mechanism} ") + $credential->length() + 2;
                $initial = $irMode === 'always' || ($irMode === 'auto' && $length <= self::LINE_MAX);
                if ($initial && $length > self::LINE_MAX) {
                    $warnings[] = "auth-long-initial-response: the AUTH {$mechanism} line with its initial response is"
                        . " {$length} octets, over the 512 of RFC 4954 §4 (authInitialResponse: 'always')";
                }
            }
            $command = $initial ? "AUTH {$mechanism} [redacted]" : "AUTH {$mechanism}";

            $this->wire->redacting(true);
            try {
                if ($initial) {
                    $line = Secret::of("AUTH {$mechanism} " . $credential->reveal());
                    try {
                        $this->wire->writeSecretLine($line, $this->timeout, $command);
                    } finally {
                        $line->wipe();
                    }
                } else {
                    $this->wire->writeLine("AUTH {$mechanism}", $this->timeout, $command);
                }
                $sent = $initial;          // the credentials (PLAIN, XOAUTH2) are on the wire
                $oauthError = null;
                $jsonStep = false;
                for ($step = 1; ; $step++) {
                    $reply = $this->wire->readReply(Stage::Auth, $this->timeout, $this->enhancedAdvertised, [334], $raw);
                    if ($reply->code !== 334) {
                        return ['reply' => $reply, 'command' => $command, 'oauthError' => $oauthError, 'warnings' => $warnings];
                    }
                    // A challenge is one line of base64 (RFC 4954 §4); a bare "334" is an empty one.
                    $challenge = \count($raw) === 1 ? \base64_decode($raw[0], true) : false;
                    if ($method === AuthMethod::XOAuth2 && $sent && !$jsonStep) {
                        // The XOAUTH2 error step: an empty line (never "*") asks for the final
                        // reply; unparseable JSON is not fatal (§5.3).
                        $jsonStep = true;
                        $oauthError = self::oauthError($challenge);
                        $this->wire->writeLine('', $this->timeout, '[empty line]');
                        continue;
                    }
                    if ($challenge === false) {
                        throw $this->cancel($command, Reason::Protocol,
                            "the server sent a challenge that is not one line of base64 during AUTH {$mechanism}");
                    }
                    if ($method === AuthMethod::Login) {
                        // By step number, never by the prompt text, which varies between servers (P26).
                        if ($step === 1) {
                            $this->wire->writeLine(\base64_encode($user), $this->timeout);
                        } elseif ($step === 2) {
                            $password = Secret::of(\base64_encode($secret->reveal()));
                            try {
                                $this->wire->writeSecretLine($password, $this->timeout, '[redacted ' . $password->length() . ' octets]');
                            } finally {
                                $password->wipe();
                            }
                        } else {
                            throw $this->cancel($command, Reason::Auth,
                                'the server asked a third LOGIN question after the user name and the password');
                        }
                        continue;
                    }
                    if ($sent || $challenge !== '') {
                        throw $this->cancel($command, Reason::Protocol,
                            "the server sent an unexpected challenge during AUTH {$mechanism}");
                    }
                    $this->wire->writeSecretLine($credential, $this->timeout, '[redacted ' . $credential->length() . ' octets]');
                    $sent = true;
                }
            } catch (SmtpException $e) {
                // Every failure of the exchange names the AUTH command, not a redacted response line.
                throw new SmtpException($e->getMessage(), $e->reason, $e->outcome, Stage::Auth, Scope::Session, $command,
                    $e->reply, null, $e->outcome === Outcome::Transient, $e->connectionUsable, $e->details,
                    $this->wire->transcript(), $e->getPrevious());
            } finally {
                $this->wire->redacting(false);
                $credential?->wipe();
            }
        }

        /**
         * The PLAIN message `authzid NUL authcid NUL passwd` (RFC 4616 §2) with an empty authzid,
         * base64-encoded. The bytes are the caller's, with no SASLprep (D15).
         *
         * @throws \LogicException when the user name or password holds a NUL, which would shift the
         *                         field boundaries (P27); the Mailer refuses both at construction
         */
        public static function plain(string $user, Secret $password): Secret
        {
            if (\str_contains($user, "\0") || \str_contains($password->reveal(), "\0")) {
                throw new \LogicException('Sasl::plain(): a PLAIN user name or password never contains NUL (RFC 4616 §2)');
            }
            return Secret::of(\base64_encode("\0" . $user . "\0" . $password->reveal()));
        }

        /**
         * The XOAUTH2 response `user=U ^A auth=Bearer T ^A ^A`, base64-encoded as ONE line (§5.3).
         *
         * @throws \LogicException for an empty user name or one with ^A, CR, LF or NUL, or a token
         *                         that fails bearerToken() (the Mailer checks both first)
         */
        public static function xoauth2(string $user, Secret $token): Secret
        {
            if ($user === '' || \strpbrk($user, "\x01\r\n\0") !== false) {
                throw new \LogicException('Sasl::xoauth2(): the user name is empty or holds ^A, CR, LF or NUL');
            }
            if (!self::bearerToken($token)) {
                throw new \LogicException('Sasl::xoauth2(): the token is not an RFC 6750 bearer token');
            }
            return Secret::of(\base64_encode('user=' . $user . "\x01auth=Bearer " . $token->reveal() . "\x01\x01"));
        }

        /**
         * Whether the token has the RFC 6750 §2.1 syntax XOAUTH2 needs (`[A-Za-z0-9-._~+/]+=*`, no
         * whitespace). The Mailer checks every token it fetches with this, before any AUTH byte
         * (§5.2, §5.3).
         */
        public static function bearerToken(Secret $token): bool
        {
            return \preg_match(self::BEARER, $token->reveal()) === 1;
        }

        /**
         * Cancels the exchange with "*" (RFC 4954 §4) and reads the answer (usually 501). The
         * session stays usable only for an Auth failure the server answered with a 4yz or 5yz
         * other than 421.
         */
        private function cancel(string $command, Reason $reason, string $why): SmtpException
        {
            $this->wire->writeLine('*', $this->timeout, '*');
            $reply = $this->wire->readReply(Stage::Auth, $this->timeout, $this->enhancedAdvertised, [334]);
            $usable = $reason === Reason::Auth && $reply->code >= 400 && $reply->code !== 421;
            return new SmtpException(
                "{$why}; the exchange was cancelled (answer: " . Text::sanitize((string) $reply, 200) . ')',
                $reason,
                $reason === Reason::Auth ? Outcome::Permanent : Outcome::Transient,
                Stage::Auth,
                Scope::Session,
                $command,
                $reply,
                connectionUsable: $usable,
                transcript: $this->wire->transcript(),
            );
        }

        /**
         * status, schemes and scope from the decoded XOAUTH2 JSON challenge, sanitised for display;
         * null when it is not base64 of a JSON object (not fatal, §5.3).
         *
         * @return array<string, string>|null
         */
        private static function oauthError(string|false $json): ?array
        {
            $data = $json === false ? null : \json_decode($json, true, 8);
            if (!\is_array($data)) {
                return null;
            }
            $error = [];
            foreach (['status', 'schemes', 'scope'] as $key) {
                if (isset($data[$key]) && \is_scalar($data[$key])) {
                    $error[$key] = Text::sanitize((string) $data[$key], 200);
                }
            }
            return $error;
        }
    }

    /**
     * One SMTP connection (§3): the state machine and its gates (§3.1), setup (§3.2), EHLO and the
     * HELO fallback (§3.3), STARTTLS with the injection defence (§4.4), pins and verifyPeer (§4.3),
     * AUTH (§5.3), the transaction with PIPELINING groups and the 452/552 rules (§3.7), the body
     * (§3.9), QUIT and abort (§3.11). Created and discarded by Mailer; never reused after Broken.
     *
     * Every public method checks the state first (a wrong state is a \LogicException, a library
     * bug, so a 503 always means a server problem, §2.3). Between calls the state is Disconnected,
     * Greeted, Ready, NeedEhlo, Closed or Broken. Every failure leaves the session Ready (when the
     * exception's connectionUsable is true) or Broken with the socket closed; an unexpected
     * throwable (a logger that throws, a library bug) in the middle of an exchange closes it too.
     *
     * Session exceptions carry the redacted command, the deciding reply, the transcript and
     * retrySafe = Transient (nothing is committed before "."); scope Session for setup, AUTH and
     * every 421, Transaction for MAIL, DATA, the body and the final reply, Recipient when no
     * recipient was accepted (§8.3). transaction() reports instead of throwing: see there.
     *
     * @internal
     */
    final class Session
    {
        /** Rendered::chunks() size while streaming the data (§3.9). */
        private const DATA_CHUNK = 65536;

        private State $state = State::Disconnected;
        private ?ByteStream $io = null;
        private ?Wire $wire = null;
        private ?Capabilities $caps = null;

        /** The TLS epoch (I11): 0 from the connection, +1 after STARTTLS. */
        private int $epoch = 0;
        private bool $tls = false;
        private bool $verified = false;
        private bool $authed = false;
        private int $pid = 0;
        private float $lastActivity = 0.0;

        /** MAIL commands answered 250 on this connection (§3.8: MAILMAX, the per-connection limit). */
        private int $txCount = 0;

        /** Set by open(): the host verified and handed to verifyPeer, the port, the TLS settings. */
        private string $host = '';
        private int $port = 0;
        private bool $implicit = false;
        private array $ssl = [];
        private int $crypto = 0;

        /** The tlsGate and authGate of §3.1: MAIL needs TLS, or AUTH, first. */
        private bool $requireTls = false;
        private bool $requireAuth = false;

        /** The raw socket names, read once after connecting (§5.1). */
        private ?string $peerName = null;
        private ?string $localName = null;

        /** Result->tls shape: protocol, cipher, verified, peerSha256; null without TLS. */
        private ?array $tlsInfo = null;

        /** The captured server certificate and chain; only in properties, never in a parameter (§8.4). */
        private ?\OpenSSLCertificate $peerCert = null;

        /** @var list<\OpenSSLCertificate> */
        private array $chainCerts = [];

        /** @var list<string> "<stable-code>: <message>" warnings not yet taken */
        private array $warnings = [];

        /**
         * A command or quiescence check is between its write and its reply: an unexpected
         * throwable here (a logger that throws) may leave the stream desynchronised, so settle()
         * closes the session even in a state that is otherwise stable (Ready, Greeted, NeedEhlo).
         */
        private bool $busy = false;

        /** Set by __unserialize(): every method throws UsageException (§8.4). */
        private bool $inert = false;

        /**
         * Never throws. The hooks and pins are kept in properties, so no method that can throw
         * holds a Closure in a parameter (§8.4).
         *
         * @param \Closure|null $verifyPeer fn(list<string> $chainPem, string $host, array $tlsInfo): bool (§4.3)
         * @param list<string>  $pins       64-hex certificate SHA-256 or "sha256/<base64 SPKI SHA-256>",
         *                                  already validated by Mailer
         */
        public function __construct(
            private Connector $connector,
            private Timeouts $t,
            private Clock $clock,
            private ?\Closure $logger,
            private ?\Closure $verifyPeer,
            private array $pins,
        ) {
        }

        /**
         * Connects and reads the greeting (§3.2). Implicit TLS is negotiated before anything is
         * read; writes stay locked until the whole greeting is parsed (§3.1, P30).
         *
         * @param string $host       the name verified against the certificate and given to verifyPeer
         * @param string $dial       connectAddress ?? host
         * @param array  $ssl        the `ssl` context (§4.3), used for the implicit handshake and STARTTLS;
         *                           `verified` needs verify_peer and verify_peer_name set to true
         * @param bool   $requireTls tlsGate: MAIL only with TLS active (Tls::StartTls, Tls::Implicit)
         * @param bool   $requireAuth authGate: MAIL only after AUTH (credentials configured)
         * @return Reply the 220 greeting
         * @throws SmtpException Connect (from the Connector); Tls, TlsVerify or Timeout (implicit
         *         handshake, pins, verifyPeer); Timeout, ConnectionLost or Protocol reading the
         *         greeting; Rejected for 421 (Transient) and 554 or 521 (Permanent, after QUIT)
         * @throws UsageException a verifyPeer hook that called back into the Mailer (§4.3, §8.1): the
         *         connection is closed first, and the exception is not turned into a TlsVerify
         */
        public function open(
            string $host,
            string $dial,
            int $port,
            bool $implicit,
            array $ssl,
            int $crypto,
            ?string $bindTo,
            bool $requireTls = false,
            bool $requireAuth = false,
        ): Reply {
            $this->expect(State::Disconnected);
            $this->host = $host;
            $this->port = $port;
            $this->implicit = $implicit;
            $this->ssl = $ssl;
            $this->crypto = $crypto;
            $this->requireTls = $requireTls;
            $this->requireAuth = $requireAuth;
            $this->state = State::Connecting;
            try {
                $this->io = $this->connector->connect($dial, $port, $this->t->connect, $bindTo);
                $this->wire = new Wire($this->io, $this->clock, $this->logger);
                $this->pid = (int) \getmypid();
                $this->peerName = $this->io->peerName();
                $this->localName = $this->io->localName();
                $this->touch();
                if ($implicit) {
                    $this->state = State::TlsHandshake;
                    $this->secure(false);
                }
                $this->state = State::AwaitGreeting;
                try {
                    $greeting = $this->wire->readReply(Stage::Greeting, $this->t->greeting, false);
                } catch (SmtpException $e) {
                    $this->abort();
                    $hint = $e->reason === Reason::Timeout && !$implicit && $port !== 25 && $port !== 587
                        ? ['hint' => 'no greeting from the server: does this port expect Tls::Implicit?'] : [];
                    throw $this->amend($e, ['details' => $hint]);
                }
                $code = $greeting->code;
                if ($code === 220) {
                    $this->state = State::Greeted;
                    $this->touch();
                    return $greeting;
                }
                if ($code === 554 || $code === 521) {
                    $this->sendQuit();
                    throw $this->failure('the server refused the session: ' . Text::sanitize((string) $greeting, 200),
                        Reason::Rejected, Outcome::Permanent, Stage::Greeting, Scope::Session, null, $greeting);
                }
                $this->abort();
                if ($code === 421) {
                    throw $this->failure('the server is not available: ' . Text::sanitize((string) $greeting, 200),
                        Reason::Rejected, Outcome::Transient, Stage::Greeting, Scope::Session, null, $greeting);
                }
                throw $this->failure('unexpected greeting ' . Text::sanitize((string) $greeting, 200) . ' (RFC 5321 §4.3.1)',
                    Reason::Protocol, Outcome::Transient, Stage::Greeting, Scope::Session, null, $greeting);
            } catch (\Throwable $e) {
                $this->settle();
                throw $e;
            }
        }

        /**
         * EHLO, or HELO after an EHLO 500 or 502 when $allowHelo (§3.3, D29). The first EHLO of an
         * epoch gets no select probe (a TLS 1.3 ticket makes the socket readable, X1), but nothing
         * received earlier may be read as its reply: the own buffer, and after STARTTLS PHP's
         * buffer too, must be empty (§4.4, I2).
         *
         * @param string $name      the connection's identity (§3.3), also used for HELO
         * @param bool   $allowHelo no credentials, and not before TLS in Tls::StartTls (§3.2)
         * @return Capabilities of this epoch (Capabilities::none() after HELO, warning helo-fallback)
         * @throws SmtpException Rejected (Permanent after QUIT for 5yz, a 501 with a hint; Transient
         *         for 4yz, 421 without QUIT), Capability (500/502 when HELO is not allowed), Protocol
         *         (another 2yz, bad grammar, bytes before EHLO), Timeout, ConnectionLost
         */
        public function hello(string $name, bool $allowHelo): Capabilities
        {
            $this->expect(State::Greeted, State::NeedEhlo);
            if (\preg_match('/\A[\x21-\x7E]{1,255}\z/', $name) !== 1) {
                throw new \LogicException('Session::hello(): the EHLO identity must be 1-255 printable ASCII octets');
            }
            try {
                $unread = $this->state === State::NeedEhlo ? $this->io->unreadBytes() : 0;
                if ($this->wire->bufferedBytes() !== 0 || $unread !== 0) {
                    $this->abort();
                    throw $this->failure('the server sent data before EHLO' . ($this->tls ? ' (right after the TLS handshake)' : '')
                        . ' (desync or injection)', Reason::Protocol, Outcome::Transient, Stage::Ehlo, Scope::Session, "EHLO {$name}");
                }
                $raw = [];
                $reply = $this->exchange("EHLO {$name}", Stage::Ehlo, $this->t->command, $raw, false);
                if ($reply->code === 250) {
                    try {
                        $caps = Capabilities::fromEhlo($raw, $this->epoch, $this->tls);
                    } catch (SmtpException $e) {
                        $this->abort();
                        throw $this->amend($e, ['command' => "EHLO {$name}"]);
                    }
                    $this->caps = $caps;
                    $this->ready();
                    return $caps;
                }
                if (($reply->code === 500 || $reply->code === 502) && $allowHelo) {
                    return $this->helo($name, $reply);
                }
                throw $this->refusedHello($reply, "EHLO {$name}", Stage::Ehlo, !$allowHelo);
            } catch (\Throwable $e) {
                $this->settle();
                throw $e;
            }
        }

        /**
         * STARTTLS (§4.1, §4.4): the command alone, the reply, the injection check, the handshake,
         * pins and verifyPeer; then epoch+1, no capabilities, NeedEhlo (EHLO follows at once, no
         * 220 is awaited, P4).
         *
         * @param bool $opportunistic Tls::Opportunistic: a 4yz/5yz other than 421 and 501 is a
         *                            refusal, not a failure (warning plaintext-session)
         * @return bool false: refused, plaintext continues in the same epoch (Opportunistic only)
         * @throws SmtpException Tls (refused: Transient after QUIT; 421 without QUIT), Protocol (501
         *         after QUIT, Permanent; another 2yz), Injection (Permanent, no handshake),
         *         ConnectionLost, Timeout, and the handshake failures of §4.3
         * @throws UsageException a verifyPeer hook that called back into the Mailer (§4.3, §8.1): the
         *         connection is closed first (QUIT over TLS), and the exception is not turned into a TlsVerify
         */
        public function startTls(bool $opportunistic): bool
        {
            $this->expect(State::Ready);
            $caps = $this->currentCaps();
            if ($this->tls || $this->authed || !$caps->has('STARTTLS')) {
                throw new \LogicException('Session::startTls(): only once, before AUTH, and only when this epoch advertised STARTTLS');
            }
            try {
                $this->quiesce();
                $this->state = State::StartTlsSent;
                $reply = $this->exchange('STARTTLS', Stage::StartTls, $this->t->command);
                $code = $reply->code;
                $shown = Text::sanitize((string) $reply, 200);
                if ($code === 220) {
                    try {
                        $this->wire->assertNothingBeforeTls();
                    } catch (SmtpException $e) {
                        $this->abort();
                        throw $this->amend($e);
                    }
                    $this->state = State::TlsHandshake;
                    $this->secure(true);
                    $this->epoch++;
                    $this->caps = null;
                    $this->state = State::NeedEhlo;
                    $this->touch();
                    return true;
                }
                if ($code === 421) {
                    $this->abort();
                    throw $this->failure("STARTTLS failed: {$shown}", Reason::Tls, Outcome::Transient, Stage::StartTls,
                        Scope::Session, 'STARTTLS', $reply);
                }
                if ($code < 300) {
                    $this->abort();
                    throw $this->failure("unexpected reply {$shown} to STARTTLS", Reason::Protocol, Outcome::Transient,
                        Stage::StartTls, Scope::Session, 'STARTTLS', $reply);
                }
                if ($code === 501) {
                    $this->sendQuit();
                    throw $this->failure("the server rejected the STARTTLS syntax: {$shown} (a tehsmtp bug)", Reason::Protocol,
                        Outcome::Permanent, Stage::StartTls, Scope::Session, 'STARTTLS', $reply);
                }
                if ($opportunistic) {
                    $this->warn("plaintext-session: STARTTLS refused ({$code}); the session continues without TLS");
                    $this->ready();
                    return false;
                }
                $this->sendQuit();
                throw $this->failure("the server refused STARTTLS: {$shown}", Reason::Tls, Outcome::Transient, Stage::StartTls,
                    Scope::Session, 'STARTTLS', $reply);
            } catch (\Throwable $e) {
                $this->settle();
                throw $e;
            }
        }

        /**
         * One AUTH attempt (§5.2, §5.3) with a mechanism this epoch advertised. The mechanism
         * choice, the §5.1 gate, the token provider, the 504/534 fallback and the one XOAUTH2
         * refresh after a JSON status "401" (details['oauthError']) belong to Mailer, which may
         * call auth() again while the session is Ready.
         *
         * @param string $irMode 'auto', 'always' or 'never' (§5.3)
         * @throws SmtpException, always Stage::Auth and Scope::Session: Capability for 504, Auth
         *         for 534/535/530/538 and other 5yz (Permanent) and 4yz (Transient; 432 with a hint),
         *         all with the session still Ready (connectionUsable); 421 (Auth, Transient) and
         *         any other 2yz (Protocol) break it; see Sasl::run() for broken exchanges
         */
        public function auth(AuthMethod $method, string $user, Secret $secret, string $irMode): void
        {
            $this->expect(State::Ready);
            $caps = $this->currentCaps();
            if ($this->authed || !\in_array($method->value, $caps->authMechanisms(), true)) {
                throw new \LogicException('Session::auth(): only once per session, and only with a mechanism this epoch advertised');
            }
            try {
                $this->quiesce();
                $this->state = State::AuthInProgress;
                try {
                    $exchange = (new Sasl($this->wire, $this->t->command, $this->adv()))->run($method, $user, $secret, $irMode);
                } catch (SmtpException $e) {
                    $e->connectionUsable ? $this->ready() : $this->abort();
                    throw $e;
                }
                foreach ($exchange['warnings'] as $warning) {
                    $this->warn($warning);
                }
                $reply = $exchange['reply'];
                $code = $reply->code;
                if ($code === 235) {
                    $this->authed = true;
                    $this->ready();
                    return;
                }
                $details = $exchange['oauthError'] === null ? [] : ['oauthError' => $exchange['oauthError']];
                $shown = Text::sanitize((string) $reply, 200);
                if ($code === 421 || $code < 300) {
                    $this->abort();
                    [$reason, $message] = $code === 421
                        ? [Reason::Auth, "authentication failed: {$shown}"]
                        : [Reason::Protocol, "unexpected reply {$shown} to AUTH (never taken as success)"];
                    throw $this->failure($message, $reason, Outcome::Transient, Stage::Auth, Scope::Session,
                        $exchange['command'], $reply, $details);
                }
                $this->ready();
                if ($code === 504) {
                    throw $this->failure("the server does not support AUTH {$method->value} although it advertised it: {$shown}",
                        Reason::Capability, Outcome::Permanent, Stage::Auth, Scope::Session, $exchange['command'], $reply, $details);
                }
                if ($code === 432) {
                    $details['hint'] = 'password change required';
                }
                // 535, 530 and 538 are final for these credentials: no other mechanism is tried with
                // them (lockout risk, research item 103).
                throw $this->failure("authentication failed: {$shown}", Reason::Auth, $reply->outcome(), Stage::Auth,
                    Scope::Session, $exchange['command'], $reply, $details);
            } catch (\Throwable $e) {
                $this->settle();
                throw $e;
            }
        }

        /**
         * One transaction (§3.7, §3.9): MAIL and the RCPTs in PIPELINING groups of at most
         * GROUP_BYTES (or one command at a time), DATA alone, the dot-stuffed body, the terminator
         * in its own write, the final reply. It reports instead of throwing SmtpException, because
         * every outcome needs the per-recipient detail:
         *
         * - `outcome`: Success (final 2yz), Unknown (timeout, EOF or protocol error after ".", or a
         *   failed terminator write), else Transient or Permanent (nothing delivered);
         * - `recipients`: one entry per $rcpts entry, in order: `outcome`, the deciding `reply`
         *   (§8.2; local 421 or 451 4.4.2 when the connection failed first, 451 4.3.0 for a local
         *   failure, null for Unknown and for recipients never sent), `cascaded` (decided by MAIL,
         *   DATA or a failure of the transaction rather than by the recipient's own RCPT reply or
         *   the final reply), `carry` (limit-deferred, or never sent after a limit reply: the
         *   caller may send it in a later transaction, §3.8);
         * - `mail`, `data`, `final`: those replies (mail is local when the connection failed first);
         * - `accepted`: RCPTs answered 250 or 251; `committed`: MAIL got 250 (D20: no transparent
         *   reconnect afterwards); `limitHit`; `perConnectionLimit` (MAIL 4yz other than 421 on a
         *   connection that carried a transaction before: no RSET; §3.8); `allOrNothing` (violated;
         *   RSET sent);
         * - `error`: null on Success, else the SmtpException that decided the transaction:
         *   Rejected (MAIL, DATA, final, body; any 421, Scope::Session), Recipients (none accepted,
         *   or allOrNothing; Scope::Recipient), Local (the spool failed during the body), or the
         *   Wire's Timeout, ConnectionLost (an unsolicited 421 too) or Protocol. After "." it is the
         *   cause of the Unknown outcome (retrySafe false).
         *
         * Afterwards the session is Ready, or Broken after a 421, a connection failure, a protocol
         * violation, a failed body or a non-250 to the RSET that follows a transaction abandoned
         * before "." (§3.1).
         *
         * @param string $reversePath the MAIL FROM mailbox without brackets ('' for the null sender)
         * @param list<string> $mailParams validated ESMTP parameters, e.g. ["SIZE=1834", "SMTPUTF8"]
         * @param list<string|array{string, list<string>}> $rcpts each a forward path without
         *        brackets, or [path, list of validated RCPT parameters]
         * @param bool $pipelining the Mailer option; groups need PIPELINING advertised as well
         * @param bool $allOrNothing any recipient not accepted: RSET, nothing sent (§8.1)
         * @return array{outcome: Outcome, recipients: list<array{outcome: Outcome, reply: ?Reply,
         *         cascaded: bool, carry: bool}>, mail: ?Reply, data: ?Reply, final: ?Reply,
         *         accepted: int, committed: bool, limitHit: bool, perConnectionLimit: bool,
         *         allOrNothing: bool, error: ?SmtpException}
         */
        public function transaction(
            Rendered $rendered,
            string $reversePath,
            array $mailParams,
            array $rcpts,
            bool $pipelining = true,
            bool $allOrNothing = false,
        ): array {
            $this->expect(State::Ready);
            $caps = $this->mailGate();
            if ($rcpts === []) {
                throw new \LogicException('Session::transaction(): no recipients');
            }
            $lines = [self::command("MAIL FROM:<{$reversePath}>", $mailParams)];
            foreach ($rcpts as $rcpt) {
                [$path, $params] = \is_array($rcpt) ? [$rcpt[0] ?? null, $rcpt[1] ?? []] : [$rcpt, []];
                if (!\is_string($path) || !\is_array($params)) {
                    throw new \LogicException('Session::transaction(): a recipient is a path, or [path, list of parameters]');
                }
                $lines[] = self::command("RCPT TO:<{$path}>", $params);
            }
            $count = \count($lines) - 1;
            $mailShown = Text::sanitize($lines[0], 1024);
            $before = $this->txCount;
            try {
                $env = $this->envelope($lines, $pipelining && $caps->has('PIPELINING'));
                $tx = [
                    'outcome' => Outcome::Transient, 'recipients' => [], 'mail' => $env['replies'][0] ?? null,
                    'data' => null, 'final' => null, 'accepted' => \count(\array_keys($env['rcpt'], 'accepted', true)),
                    'committed' => ($env['replies'][0] ?? null)?->code === 250, 'limitHit' => $env['limitHit'],
                    'perConnectionLimit' => false, 'allOrNothing' => false, 'error' => null,
                ];
                // Accepted, but the transaction was abandoned before DATA: not sent, nothing to blame them for.
                $unsent = [Outcome::Transient, null, true];

                if ($env['error'] !== null) {   // a 421, EOF, timeout or protocol error: every later command is lost
                    $this->abort();
                    $lost = self::localReply($env['error']);
                    $tx['mail'] ??= $lost;
                    return $this->report($tx, $env, $count, [Outcome::Transient, $lost, true], $lost, $this->amend($env['error']));
                }

                $mail = $env['replies'][0];
                if ($env['mailFailed']) {
                    if ($mail->code < 500 && $before >= 1) {
                        // §3.8: a spent connection. No RSET; the Mailer quits and reconnects.
                        $tx['perConnectionLimit'] = true;
                        $this->ready();
                        $error = $this->failure('MAIL was answered ' . Text::sanitize((string) $mail, 200) . ' on a connection that'
                            . " already carried {$before} transaction(s): a per-connection limit", Reason::Rejected,
                            Outcome::Transient, Stage::Mail, Scope::Session, $mailShown, $mail);
                    } else {
                        $this->resetAfterFailure();
                        $error = $this->failure('the server refused MAIL FROM: ' . Text::sanitize((string) $mail, 200),
                            Reason::Rejected, $mail->outcome(), Stage::Mail, Scope::Transaction, $mailShown, $mail);
                    }
                    return $this->report($tx, $env, $count, $unsent, null, $error);
                }

                $violated = $allOrNothing && $tx['accepted'] < $count;
                if ($tx['accepted'] === 0 || $violated) {
                    $this->resetAfterFailure();
                    $tx['allOrNothing'] = $violated;
                    $recipients = self::recipients($env, $count, $unsent, null);
                    return $this->report($tx, $env, $count, $unsent, null,
                        $this->recipientsFailure($env, $lines, $recipients, $allOrNothing));
                }

                // DATA, never pipelined (D6): 354, or the transaction ends here.
                try {
                    $this->quiesce();
                    $this->state = State::AwaitData;
                    $data = $this->exchange('DATA', Stage::Data, $this->t->data, allowed3xx: [354]);
                } catch (SmtpException $e) {   // already closed and amended
                    return $this->report($tx, $env, $count, [Outcome::Transient, self::localReply($e), true], null, $e);
                }
                $tx['data'] = $data;
                if ($data->code !== 354) {
                    $shown = Text::sanitize((string) $data, 200);
                    if ($data->code === 421 || $data->code < 300) {
                        $this->abort();
                        $error = $data->code === 421
                            ? $this->failure("DATA failed: {$shown}", Reason::Rejected, Outcome::Transient, Stage::Data,
                                Scope::Session, 'DATA', $data)
                            : $this->failure("unexpected reply {$shown} to DATA", Reason::Protocol, Outcome::Transient, Stage::Data,
                                Scope::Transaction, 'DATA', $data);
                        $fate = [Outcome::Transient, $data->code === 421 ? $data : self::localReply($error), true];
                        return $this->report($tx, $env, $count, $fate, null, $error);
                    }
                    $this->state = State::InTransaction;   // the transaction is still open: RSET ends it
                    $this->resetAfterFailure();
                    $error = $this->failure("the server refused DATA: {$shown}", Reason::Rejected, $data->outcome(), Stage::Data,
                        Scope::Transaction, 'DATA', $data);
                    return $this->report($tx, $env, $count, [$data->outcome(), $data, true], null, $error);
                }

                $this->state = State::Streaming;
                $failure = $this->streamBody($rendered);
                if ($failure !== null) {
                    [$error, $explained] = $failure;
                    return $this->report($tx, $env, $count, [$error->outcome, $explained ?? self::localReply($error), true], null, $error);
                }

                // The terminator alone, in its own write (I5, D21): whether it left the machine is unknown
                // once the write fails, and so is the outcome.
                try {
                    $this->wire->writeLine('.', $this->t->block);
                    $this->state = State::AwaitFinal;
                    $final = $this->wire->readReply(Stage::Final, $this->t->final, $this->adv());
                } catch (SmtpException $e) {
                    $this->abort();
                    $tx['outcome'] = Outcome::Unknown;
                    return $this->report($tx, $env, $count, [Outcome::Unknown, null, false], null,
                        $this->amend($e, ['retrySafe' => false]));
                }
                $tx['final'] = $final;
                if ($final->code < 300) {
                    $this->ready();
                    $tx['outcome'] = Outcome::Success;
                    return $this->report($tx, $env, $count, [Outcome::Success, $final, false], null, null);
                }
                $shown = Text::sanitize((string) $final, 200);
                if ($final->code === 421) {
                    $this->abort();
                    $error = $this->failure("the message was not accepted: {$shown}", Reason::Rejected, Outcome::Transient,
                        Stage::Final, Scope::Session, '.', $final);
                } else {
                    $this->ready();   // the transaction has ended; no RSET is needed (§3.1)
                    $error = $this->failure("the message was not accepted: {$shown}", Reason::Rejected, $final->outcome(),
                        Stage::Final, Scope::Transaction, '.', $final);
                }
                return $this->report($tx, $env, $count, [$final->outcome(), $final, false], null, $error);
            } catch (\Throwable $e) {
                $this->settle();
                throw $e;
            }
        }

        /**
         * RSET (§3.1): 250 leaves the session Ready; anything else breaks it.
         *
         * @throws SmtpException Protocol (another reply; Rejected for a 421), Timeout, ConnectionLost
         */
        public function reset(): void
        {
            $this->expect(State::Ready, State::InTransaction);
            try {
                $this->quiesce();
                $reply = $this->exchange('RSET', Stage::Rset, $this->t->command);
                if ($reply->code === 250) {
                    $this->ready();
                    return;
                }
                $this->abort();
                throw $this->failure('RSET was answered ' . Text::sanitize((string) $reply, 200) . ' instead of 250',
                    $reply->code === 421 ? Reason::Rejected : Reason::Protocol, Outcome::Transient, Stage::Rset,
                    Scope::Session, 'RSET', $reply);
            } catch (\Throwable $e) {
                $this->settle();
                throw $e;
            }
        }

        /** NOOP (Mailer::ping()): true for 250; any other result breaks the session and gives false. */
        public function noop(): bool
        {
            $this->expect(State::Ready);
            try {
                try {
                    $this->quiesce();
                    $reply = $this->exchange('NOOP', Stage::Idle, $this->t->command);
                } catch (SmtpException) {
                    return false;   // quiesce() and exchange() have closed the session
                }
                if ($reply->code === 250) {
                    $this->ready();
                    return true;
                }
                $this->abort();
                return false;
            } catch (\Throwable $e) {
                $this->settle();
                throw $e;
            }
        }

        /**
         * Steps 5 and 6 of the reuse check (§3.8): the quiescence check, and after more than
         * idleProbe seconds of idleness an RSET that must return 250.
         *
         * @return bool false when the server closed the session (EOF, a connection error or a 421):
         *              the session is Broken and the Mailer's single reconnect applies
         * @throws SmtpException for any other failure (Protocol, Timeout)
         */
        public function probe(): bool
        {
            $this->expect(State::Ready);
            try {
                if ($this->idleSeconds() > $this->t->idleProbe) {
                    $this->reset();
                } else {
                    $this->quiesce();
                }
                return true;
            } catch (SmtpException $e) {
                if ($e->reason === Reason::ConnectionLost || $e->reply?->code === 421) {
                    return false;
                }
                throw $e;
            } catch (\Throwable $e) {
                $this->settle();
                throw $e;
            }
        }

        /**
         * QUIT and its 221 within Timeouts->quit, errors ignored, then the socket is closed (§3.11).
         * QUIT is sent only while the session can take a command (Greeted, Ready, NeedEhlo); any
         * other session is simply closed. Idempotent; Broken stays Broken, anything else is Closed.
         */
        public function quit(): void
        {
            $this->expect(...State::cases());
            try {
                if (\in_array($this->state, [State::Greeted, State::Ready, State::NeedEhlo], true)) {
                    $this->sendQuit();
                    return;
                }
                $this->io?->close();
                if ($this->state !== State::Broken) {
                    $this->state = State::Closed;
                }
            } catch (\Throwable $e) {
                $this->settle();   // a logger that throws during QUIT: still closed
                throw $e;
            }
        }

        /** Closes the socket at once, without QUIT: the session is Broken (unless already Closed). Idempotent. */
        public function abort(): void
        {
            $this->expect(...State::cases());
            $this->io?->close();
            if ($this->state !== State::Closed) {
                $this->state = State::Broken;
            }
        }

        /** The current state (§3.1). */
        public function state(): State
        {
            $this->live();
            return $this->state;
        }

        /** The capabilities of the current epoch; null before EHLO and right after STARTTLS (I11). */
        public function caps(): ?Capabilities
        {
            $this->live();
            return $this->caps;
        }

        /**
         * The Result->tls shape: protocol, cipher, verified, peerSha256 (lower-case hex, or null when
         * no certificate was captured); null without TLS.
         *
         * @return array{protocol: string, cipher: string, verified: bool, peerSha256: ?string}|null
         */
        public function tlsInfo(): ?array
        {
            $this->live();
            return $this->tlsInfo;
        }

        /**
         * True only after a handshake with verify_peer and verify_peer_name on and the pins passing
         * (§4.3). A passing verifyPeer never makes a session verified (D30).
         */
        public function verified(): bool
        {
            $this->live();
            return $this->verified;
        }

        /** Whether the CONNECTED peer IP is loopback (§5.1): never decided from a name; unparsable is false. */
        public function peerIsLoopback(): bool
        {
            $this->live();
            $ip = Text::socketIp($this->peerName);
            return $ip !== null && Text::isLoopbackIp($ip);
        }

        /** Whether AUTH succeeded on this connection. */
        public function authenticated(): bool
        {
            $this->live();
            return $this->authed;
        }

        /** Whether TLS is active (after the implicit handshake or STARTTLS). */
        public function tls(): bool
        {
            $this->live();
            return $this->tls;
        }

        /** The TLS epoch (I11): 0 from the connection, 1 after STARTTLS. */
        public function epoch(): int
        {
            $this->live();
            return $this->epoch;
        }

        /** The process that opened the connection (§3.8 reuse check, §3.11). */
        public function pid(): int
        {
            $this->live();
            return $this->pid;
        }

        /** Seconds since the last completed exchange (§3.8: idleProbe, maxIdle). */
        public function idleSeconds(): float
        {
            $this->live();
            return $this->clock->monotonic() - $this->lastActivity;
        }

        /** MAIL commands answered 250 on this connection (§3.8: MAILMAX, the per-connection limit). */
        public function transactions(): int
        {
            $this->live();
            return $this->txCount;
        }

        /** The raw local socket name ("addr:port", §5.1), for the EHLO address literal (§3.3). */
        public function localName(): ?string
        {
            $this->live();
            return $this->localName;
        }

        /**
         * The warnings collected since the last call, each "<stable-code>: <message>": the Wire's
         * (bare-lf-from-server, enhanced-code-*, php-deprecation) and the session's own
         * (helo-fallback, plaintext-session, tls-unauthenticated, long-plain-credential,
         * auth-long-initial-response).
         *
         * @return list<string>
         */
        public function takeWarnings(): array
        {
            $this->live();
            $warnings = $this->warnings;
            $this->warnings = [];
            foreach ($this->wire?->takeWarnings() ?? [] as $warning) {
                if (!\in_array($warning, $warnings, true)) {
                    $warnings[] = $warning;
                }
            }
            return $warnings;
        }

        /**
         * The last Wire::RING redacted, sanitised transcript lines ([] before connecting).
         *
         * @return list<string>
         */
        public function transcript(): array
        {
            $this->live();
            return $this->wire?->transcript() ?? [];
        }

        /** Adds an informational "*  <text>" line to the logger and the transcript (§8.5), e.g. a reconnect. */
        public function note(string $text): void
        {
            $this->live();
            $this->wire?->note($text);
        }

        /**
         * Test seam (§9.4): pretends another process opened the connection, as after a fork, which
         * Windows cannot do.
         *
         * @internal
         */
        public function forgePid(int $pid): void
        {
            $this->live();
            $this->pid = $pid;
        }

        /** A summary for var_dump() and print_r(): never the socket, the hooks, the pins or the certificates (§8.4). */
        public function __debugInfo(): array
        {
            return ['state' => $this->state->name, 'host' => $this->host, 'port' => $this->port, 'epoch' => $this->epoch,
                'tls' => $this->tlsInfo, 'authenticated' => $this->authed, 'transactions' => $this->txCount, 'inert' => $this->inert];
        }

        /** A one-key placeholder: hooks, certificates and the socket never enter a serialised trace (§8.4). */
        public function __serialize(): array
        {
            return ['session' => '[redacted]'];
        }

        /** An INERT Session: every method throws UsageException. */
        public function __unserialize(array $data): void
        {
            $this->inert = true;
        }

        /**
         * MAIL and the RCPTs (§3.7): groups of at most GROUP_BYTES when pipelined, else one command
         * each; each group is ONE write, its replies are read in order, each within its own
         * deadline. No RCPT follows a failed MAIL and no group follows a limit reply, but the rest
         * of a group already written is always read (the 503s after a failed MAIL are cascades).
         * A 421, EOF, timeout or protocol error stops everything (`error`).
         *
         * @param list<string> $lines MAIL first, then one RCPT per recipient
         * @return array{replies: array<int, Reply>, rcpt: array<int, string>, mailFailed: bool,
         *               limitHit: bool, error: ?SmtpException} replies by command index (0 = MAIL);
         *               rcpt by recipient index: 'accepted', 'refused', 'deferred' (limit) or
         *               'limited' (a limit reply before any acceptance)
         */
        private function envelope(array $lines, bool $pipelined): array
        {
            $env = ['replies' => [], 'rcpt' => [], 'mailFailed' => false, 'limitHit' => false, 'error' => null];
            $adv = $this->adv();
            $index = 0;
            foreach (self::groups($lines, $pipelined) as $group) {
                if ($env['mailFailed'] || $env['limitHit']) {
                    break;
                }
                try {
                    $this->quiesce();
                    $this->state = State::InTransaction;
                    $this->wire->writeGroup($group, $this->t->command);
                    foreach ($group as $line) {
                        $i = $index++;
                        $reply = $this->wire->readReply($i === 0 ? Stage::Mail : Stage::Rcpt, $this->t->command, $adv);
                        $env['replies'][$i] = $reply;
                        $stage = $i === 0 ? Stage::Mail : Stage::Rcpt;
                        if ($reply->code === 421) {
                            throw $this->failure('the server is closing the session: ' . Text::sanitize((string) $reply, 200),
                                Reason::Rejected, Outcome::Transient, $stage, Scope::Session, Text::sanitize($line, 1024), $reply);
                        }
                        if ($i === 0) {
                            if ($reply->code === 250) {
                                $this->txCount++;
                            } elseif ($reply->code < 300) {
                                throw $this->unexpected($reply, $stage, $line);
                            } else {
                                $env['mailFailed'] = true;
                            }
                        } elseif (!$env['mailFailed']) {
                            $accepted = \in_array('accepted', $env['rcpt'], true);
                            $env['rcpt'][$i - 1] = $this->recipientKind($reply, $env['limitHit'], $accepted, $line);
                            $env['limitHit'] = $env['limitHit'] || $env['rcpt'][$i - 1] === 'deferred';
                        }
                    }
                } catch (SmtpException $e) {
                    $env['error'] = $e;
                    return $env;
                }
            }
            return $env;
        }

        /**
         * One RCPT reply (§3.7): 250 and 251 are accepted (a 251 forward path is never followed);
         * after a limit reply in this group anything else is limit-deferred whatever its code
         * (research item 165); a limit reply is 452, or 552 with no enhanced code or X.5.3; any
         * other 552 (X.2.2, X.3.4, ...) is refused for THAT recipient and the RCPT phase goes on.
         *
         * A limit reply counts as one only after this transaction accepted a recipient: "too many
         * recipients" (RFC 5321 §4.5.3.1.10) presupposes some. Before that it is 'limited', that
         * recipient's own Transient reply, and the RCPT phase goes on. Otherwise serial mode would
         * stop at a first-RCPT 452 that pipelined mode reads past (keeping the later 250s of the
         * group), and pipelining would change the outcome (research item 140); the carry-over also
         * always makes progress, so no transaction repeats one that accepted nothing (X10).
         *
         * @throws SmtpException Protocol for another 2yz
         */
        private function recipientKind(Reply $reply, bool $limitHit, bool $accepted, string $line): string
        {
            if ($reply->code === 250 || $reply->code === 251) {
                return 'accepted';
            }
            if ($reply->code < 300) {
                throw $this->unexpected($reply, Stage::Rcpt, $line);
            }
            if ($limitHit) {
                return 'deferred';
            }
            if ($reply->code === 452 || ($reply->code === 552 && \in_array($reply->enhanced, [null, '5.5.3'], true))) {
                return $accepted ? 'deferred' : 'limited';
            }
            return 'refused';
        }

        /**
         * Streams the rendered message through the DotStuffer (§3.9) in WRITE_SLICE slices; the
         * Renderer guarantees the final CRLF, so none is added. On a failure the session is
         * Broken and "." is never sent (I5).
         *
         * @return array{SmtpException, ?Reply}|null the failure and the server's explanation, if it
         *         gave one; null when every byte was written
         */
        private function streamBody(Rendered $rendered): ?array
        {
            $stuffer = new DotStuffer();
            $deadline = $this->t->bodyTotal === null ? null : $this->clock->monotonic() + $this->t->bodyTotal;
            try {
                foreach ($rendered->chunks(self::DATA_CHUNK) as $chunk) {
                    $out = $stuffer->push($chunk);
                    if ($out === '') {
                        continue;
                    }
                    try {
                        $this->wire->writeBody($out, $this->t->block, $deadline);
                    } catch (SmtpException $e) {
                        return $this->bodyWriteFailed($e);
                    }
                }
            } catch (SmtpException $e) {
                // The spool could not be read: a local failure, nothing delivered (§3.9).
                $this->abort();
                return [$this->failure('the message data could not be read: ' . $e->getMessage(), Reason::Local,
                    Outcome::Transient, Stage::Body, Scope::Transaction, '[body]', null, $e->details, $e), null];
            }
            $stuffer->finish();   // \LogicException, a library bug: settle() aborts, never "."
            return null;
        }

        /**
         * A body write failed (§3.9): the server may already have explained (552, 554), so one
         * reply is read within Timeouts->quit; then the session is Broken. Permanent if that
         * reply was 5yz, else Transient.
         *
         * @return array{SmtpException, ?Reply}
         */
        private function bodyWriteFailed(SmtpException $e): array
        {
            try {
                $explained = $this->wire->readReply(Stage::Body, $this->t->quit, $this->adv());
            } catch (SmtpException) {
                $explained = null;
            }
            $this->abort();
            if ($explained === null) {
                return [$this->amend($e), null];
            }
            return [$this->failure('the server stopped the message data: ' . Text::sanitize((string) $explained, 200),
                Reason::Rejected, $explained->code >= 500 ? Outcome::Permanent : Outcome::Transient, Stage::Body,
                Scope::Transaction, '[body]', $explained, [], $e), $explained];
        }

        /**
         * The finished transaction array: the recipients from $env and the fate of the accepted
         * ones, and the error.
         *
         * @param array{Outcome, ?Reply, bool} $fate  outcome, reply and cascaded for accepted recipients
         * @param Reply|null                    $lost the local reply of commands never answered
         */
        private function report(array $tx, array $env, int $count, array $fate, ?Reply $lost, ?SmtpException $error): array
        {
            $tx['recipients'] = self::recipients($env, $count, $fate, $lost);
            $tx['error'] = $error;
            if ($error !== null && $tx['outcome'] !== Outcome::Unknown) {
                $tx['outcome'] = $error->outcome;
            }
            return $tx;
        }

        /**
         * Each recipient's outcome and deciding reply (§8.2).
         *
         * @param array{Outcome, ?Reply, bool} $fate
         * @return list<array{outcome: Outcome, reply: ?Reply, cascaded: bool, carry: bool}>
         */
        private static function recipients(array $env, int $count, array $fate, ?Reply $lost): array
        {
            $mail = $env['replies'][0] ?? null;
            $out = [];
            for ($r = 0; $r < $count; $r++) {
                $reply = $env['replies'][$r + 1] ?? null;
                $kind = $env['rcpt'][$r] ?? null;
                $out[] = match (true) {
                    // A failed MAIL decides for everyone; the 503s that followed are not blamed (P16).
                    $env['mailFailed'] => self::fate($mail->outcome(), $mail, true),
                    $kind === 'accepted' => self::fate(...$fate),
                    $kind === 'deferred' => self::fate(Outcome::Transient, $reply, false, true),
                    // A limit reply before any acceptance: temporary (RFC 5321 §4.5.3.1.10), not carried.
                    $kind === 'limited' => self::fate(Outcome::Transient, $reply, false),
                    $kind === 'refused' => self::fate($reply->outcome(), $reply, false),
                    // The 421 that ended the session, or never answered because the connection failed first.
                    $reply !== null => self::fate(Outcome::Transient, $reply, false),
                    $lost !== null => self::fate(Outcome::Transient, $lost, false),
                    // Never sent: a limit reply came first. Carried over to a later transaction (§3.8).
                    default => self::fate(Outcome::Transient, null, false, true),
                };
            }
            return $out;
        }

        /** @return array{outcome: Outcome, reply: ?Reply, cascaded: bool, carry: bool} */
        private static function fate(Outcome $outcome, ?Reply $reply, bool $cascaded, bool $carry = false): array
        {
            return ['outcome' => $outcome, 'reply' => $reply, 'cascaded' => $cascaded, 'carry' => $carry];
        }

        /**
         * No recipient accepted, or an allOrNothing violation (§8.1, §8.3): Recipients, scope
         * Recipient, the first refused RCPT as command. Permanent when a retry cannot help: every
         * recipient refused permanently, or (allOrNothing) any one.
         *
         * @param list<array{outcome: Outcome, reply: ?Reply, cascaded: bool, carry: bool}> $recipients
         */
        private function recipientsFailure(array $env, array $lines, array $recipients, bool $allOrNothing): SmtpException
        {
            $first = null;
            $permanent = 0;
            foreach ($recipients as $r => $recipient) {
                if (($env['rcpt'][$r] ?? null) !== 'accepted' && $recipient['reply'] !== null) {
                    $first ??= $r;
                }
                $permanent += $recipient['outcome'] === Outcome::Permanent ? 1 : 0;
            }
            $outcome = ($allOrNothing ? $permanent > 0 : $permanent === \count($recipients)) ? Outcome::Permanent : Outcome::Transient;
            $reply = $first === null ? null : $recipients[$first]['reply'];
            $accepted = \count(\array_keys($env['rcpt'], 'accepted', true));
            $message = $accepted === 0
                ? 'no recipient was accepted'
                : 'allOrNothing: ' . (\count($recipients) - $accepted) . ' of ' . \count($recipients)
                    . ' recipients were not accepted, so nothing was sent';
            if ($reply !== null) {
                $message .= ' (first refusal: ' . Text::sanitize((string) $reply, 200) . ')';
            }
            return $this->failure($message, Reason::Recipients, $outcome, Stage::Rcpt, Scope::Recipient,
                $first === null ? null : Text::sanitize($lines[$first + 1], 1024), $reply);
        }

        /** A local reply for commands that never got one (§8.2): 421 after a 421, else 451 4.4.2. */
        private static function localReply(SmtpException $e): Reply
        {
            if ($e->reply?->code === 421) {
                return new Reply(421, ['4.4.2 the server closed the session'], '4.4.2', local: true);
            }
            return match ($e->reason) {
                Reason::Timeout => new Reply(451, ['4.4.2 no reply from the server (timeout)'], '4.4.2', local: true),
                Reason::Protocol => new Reply(451, ['4.4.2 protocol error; the connection was closed'], '4.4.2', local: true),
                Reason::Local => new Reply(451, ['4.3.0 local error; the message data could not be read'], '4.3.0', local: true),
                default => new Reply(451, ['4.4.2 connection lost'], '4.4.2', local: true),
            };
        }

        /**
         * The TLS handshake (implicit, or after the 220 to STARTTLS), then the pins and verifyPeer
         * (§4.3). A failed handshake closes the socket at once, never with a plaintext QUIT (P5).
         * A refusal sends QUIT over TLS after STARTTLS; under implicit TLS it just closes, since
         * writes are locked until the greeting (§3.1).
         *
         * @throws SmtpException TlsVerify (Permanent), Tls or Timeout (Transient); always Stage::Tls
         * @throws UsageException what a verifyPeer hook threw when it was misuse (re-entrancy), after
         *         the same closing as for a refusal
         */
        private function secure(bool $starttls): void
        {
            $result = $this->io->enableTls($this->ssl, $this->crypto);
            if (!$result['ok']) {
                $warnings = $this->io->lastWarnings();
                $this->abort();
                throw $this->handshakeFailure($result['timedOut'], $warnings);
            }
            $this->tls = true;
            $this->peerCert = $result['peer'];
            $this->chainCerts = $result['chain'];
            $verifyOn = ($this->ssl['verify_peer'] ?? false) === true && ($this->ssl['verify_peer_name'] ?? false) === true
                && ($this->ssl['allow_self_signed'] ?? false) !== true;
            $info = $result['info'] ?? [];
            $this->tlsInfo = [
                'protocol' => \is_string($info['protocol'] ?? null) ? $info['protocol'] : 'unknown',
                'cipher' => \is_string($info['cipher_name'] ?? null) ? $info['cipher_name'] : 'unknown',
                'verified' => $verifyOn,
                'peerSha256' => $this->certificateSha256(),
            ];
            [$refusal, $previous] = $this->pinRefusal() ?? $this->hookRefusal() ?? [null, null];
            if ($refusal !== null) {
                $this->tlsInfo['verified'] = false;
                if ($starttls) {
                    $this->sendQuit();
                } else {
                    $this->abort();
                }
                // Misuse inside the hook (it called back into the Mailer, §8.1) is not a verdict on the
                // certificate: it reaches the caller as a UsageException, after the connection is closed.
                if ($previous instanceof UsageException) {
                    throw $previous;
                }
                throw $this->failure($refusal, Reason::TlsVerify, Outcome::Permanent, Stage::Tls, Scope::Session, null, null,
                    ['peerSha256' => $this->tlsInfo['peerSha256']], $previous);
            }
            $this->verified = $verifyOn;
            $this->wire->note("TLS {$this->tlsInfo['protocol']} {$this->tlsInfo['cipher']} " . ($verifyOn ? 'verified' : 'NOT VERIFIED'));
            if (!$verifyOn) {
                $this->warn('tls-unauthenticated: TLS is active but the server certificate was not verified'
                    . ' (no protection against an attacker in the middle)');
            }
        }

        /**
         * §4.3: best-effort classification by warning text. Chain, name and fingerprint failures
         * are TlsVerify (Permanent: never retried or weakened), "Handshake timed out" is Timeout,
         * anything else Tls; every warning goes to details['phpWarnings'].
         *
         * @param list<string> $warnings
         */
        private function handshakeFailure(bool $timedOut, array $warnings): SmtpException
        {
            $text = \implode("\n", $warnings);
            $details = ['phpWarnings' => \array_map(static fn (string $w): string => Text::sanitize($w), $warnings)];
            $cause = $warnings === [] ? '' : ': ' . Text::sanitize((string) \end($warnings), 200);
            if ($timedOut || \str_contains($text, 'Handshake timed out')) {
                return $this->failure("the TLS handshake timed out after {$this->t->connect} s (Timeouts->connect bounds every"
                    . ' handshake)', Reason::Timeout, Outcome::Transient, Stage::Tls, Scope::Session, null, null, $details);
            }
            foreach (['certificate verify failed', 'did not match expected CN', 'peer_fingerprint'] as $needle) {
                if (\str_contains($text, $needle)) {
                    $details['tlsDiagnostics'] = $this->tlsDiagnostics();
                    return $this->failure("the server certificate could not be verified{$cause}", Reason::TlsVerify,
                        Outcome::Permanent, Stage::Tls, Scope::Session, null, null, $details);
                }
            }
            if ($this->implicit && \str_contains($text, 'wrong version number')) {
                $details['hint'] = 'server answered in plaintext; did you mean Tls::StartTls?';
            }
            return $this->failure("the TLS handshake failed{$cause}", Reason::Tls, Outcome::Transient, Stage::Tls,
                Scope::Session, null, null, $details);
        }

        /**
         * details['tlsDiagnostics'] for a verification failure (§4.3): where the trusted CAs came
         * from, and hints. Strings and booleans only.
         *
         * @return array<string, mixed>
         */
        private function tlsDiagnostics(): array
        {
            $warnings = [];
            try {
                $locations = Guard::run(static fn () => \openssl_get_cert_locations(), $warnings);
                $default = \is_array($locations) && \is_string($locations['default_cert_file'] ?? null)
                    ? $locations['default_cert_file'] : null;
                $exists = $default !== null && Guard::run(static fn () => \is_file($default), $warnings) === true;
            } catch (\Throwable) {
                $default = null;
                $exists = false;
            }
            $caFile = \is_string($this->ssl['cafile'] ?? null) ? Text::sanitize($this->ssl['cafile'], 300) : null;
            $caPath = \is_string($this->ssl['capath'] ?? null) ? Text::sanitize($this->ssl['capath'], 300) : null;
            $iniFile = self::iniValue('openssl.cafile');
            $iniPath = self::iniValue('openssl.capath');
            $hints = [];
            if ($caFile !== null || $caPath !== null) {
                $hints[] = 'caFile/caPath REPLACE the platform store: the server chain must lead to a CA in them';
            } else {
                $hints[] = 'the platform store was used (on Windows the system store, elsewhere OpenSSL\'s default paths)';
                if ($iniFile !== null || $iniPath !== null) {
                    $hints[] = 'openssl.cafile/openssl.capath are set in php.ini: a stale value hides the system store';
                }
                if ($default !== null && !$exists) {
                    $hints[] = 'OpenSSL\'s default CA file does not exist (a container without ca-certificates?)';
                }
            }
            $hints[] = 'an intercepting proxy or antivirus re-signs certificates: give its CA as caFile, never turn verification off';
            return [
                'caFile' => $caFile,
                'caPath' => $caPath,
                'openssl.cafile' => $iniFile,
                'openssl.capath' => $iniPath,
                'defaultCertFile' => $default === null ? null : Text::sanitize($default, 300),
                'defaultCertFileExists' => $exists,
                'hints' => $hints,
            ];
        }

        /** An ini value for display, or null when unset or empty (read only; nothing is changed, I9). */
        private static function iniValue(string $name): ?string
        {
            $value = \ini_get($name);
            return \is_string($value) && $value !== '' ? Text::sanitize($value, 300) : null;
        }

        /**
         * The pins (§4.3, D23), checked by the library from the captured certificate: any one
         * matching certificate SHA-256 or SPKI SHA-256 passes. No certificate: refused (fail closed).
         *
         * @return array{string, null}|null the refusal, or null when there are no pins or one matches
         */
        private function pinRefusal(): ?array
        {
            if ($this->pins === []) {
                return null;
            }
            $certificate = $this->tlsInfo['peerSha256'];
            $spki = $this->spkiSha256();
            foreach ($this->pins as $pin) {
                $pin = (string) $pin;
                $matched = \str_starts_with($pin, 'sha256/')
                    ? $spki !== null && \hash_equals($spki, \substr($pin, 7))
                    : $certificate !== null && \hash_equals($certificate, Text::lower($pin));
                if ($matched) {
                    return null;
                }
            }
            return [$certificate === null
                ? 'pins are configured but the server certificate was not captured (capture_peer_cert)'
                : 'the server certificate matches none of the configured pins', null];
        }

        /**
         * verifyPeer (§4.3, D30): called after the handshake and the pins, before EHLO (and before
         * the greeting under implicit TLS). Only `=== true` continues; false, any other value or a
         * throw is a refusal, the throwable kept as previous.
         *
         * @return array{string, ?\Throwable}|null
         */
        private function hookRefusal(): ?array
        {
            if ($this->verifyPeer === null) {
                return null;
            }
            $chain = $this->chainPem();
            try {
                $answer = ($this->verifyPeer)($chain, $this->host, $this->tlsInfo);
            } catch (\Throwable $e) {
                return ['verifyPeer refused the server: it threw ' . $e::class . ': ' . Text::sanitize($e->getMessage(), 200), $e];
            }
            return $answer === true ? null : ['verifyPeer refused the server (it returned ' . \get_debug_type($answer) . ')', null];
        }

        /**
         * The server certificates as PEM strings, leaf first (§4.3): the captured chain, else the
         * captured certificate alone. Strings, so a hook's exception stays serialisable (§8.4).
         *
         * @return list<string>
         */
        private function chainPem(): array
        {
            $pems = [];
            // The captured certificate first: a client's peer_certificate_chain usually starts with
            // it too, and the duplicate is dropped.
            foreach ([$this->peerCert, ...$this->chainCerts] as $certificate) {
                if ($certificate === null) {
                    continue;
                }
                $pem = null;
                try {
                    $ok = Guard::run(static function () use ($certificate, &$pem) {
                        return \openssl_x509_export($certificate, $pem);
                    });
                } catch (\Throwable) {
                    $ok = false;
                }
                if ($ok === true && \is_string($pem) && !\in_array($pem, $pems, true)) {
                    $pems[] = $pem;
                }
            }
            return $pems;
        }

        /** The SHA-256 of the captured certificate (lower-case hex), or null. */
        private function certificateSha256(): ?string
        {
            $certificate = $this->peerCert;
            if ($certificate === null) {
                return null;
            }
            try {
                $hash = Guard::run(static fn () => \openssl_x509_fingerprint($certificate, 'sha256'));
            } catch (\Throwable) {
                return null;
            }
            return \is_string($hash) ? Text::lower($hash) : null;
        }

        /** base64 of the SHA-256 of the captured certificate's SubjectPublicKeyInfo (DER), or null. */
        private function spkiSha256(): ?string
        {
            $certificate = $this->peerCert;
            if ($certificate === null) {
                return null;
            }
            try {
                $key = Guard::run(static fn () => \openssl_pkey_get_public($certificate));
                $details = $key === false ? false : Guard::run(static fn () => \openssl_pkey_get_details($key));
            } catch (\Throwable) {
                return null;
            }
            $pem = \is_array($details) && \is_string($details['key'] ?? null) ? $details['key'] : '';
            $der = \base64_decode((string) \preg_replace('/-----[A-Z ]+-----|\s+/', '', $pem), true);
            return $der === false || $der === '' ? null : \base64_encode(\hash('sha256', $der, true));
        }

        /** HELO after an EHLO 500/502 (§3.3, D29): the same identity; no extension afterwards. */
        private function helo(string $name, Reply $ehlo): Capabilities
        {
            $this->quiesce();
            $reply = $this->exchange("HELO {$name}", Stage::Helo, $this->t->command);
            if ($reply->code !== 250) {
                throw $this->refusedHello($reply, "HELO {$name}", Stage::Helo, false);
            }
            $this->caps = Capabilities::none($this->epoch, $this->tls);
            $this->warn("helo-fallback: EHLO was refused ({$ehlo->code}), so HELO was used and no SMTP extension is available"
                . ' on this connection');
            $this->ready();
            return $this->caps;
        }

        /**
         * The §3.3 table for a reply other than 250 (and no HELO fallback): QUIT for 4yz and 5yz,
         * none after a 421 or another 2yz.
         */
        private function refusedHello(Reply $reply, string $command, Stage $stage, bool $heloForbidden): SmtpException
        {
            $code = $reply->code;
            $shown = Text::sanitize((string) $reply, 200);
            $verb = $stage === Stage::Helo ? 'HELO' : 'EHLO';
            if ($code === 421 || $code < 300) {
                $this->abort();
                return $code === 421
                    ? $this->failure("{$verb} failed: {$shown}", Reason::Rejected, Outcome::Transient, $stage, Scope::Session,
                        $command, $reply)
                    : $this->failure("unexpected reply {$shown} to {$verb}", Reason::Protocol, Outcome::Transient, $stage,
                        Scope::Session, $command, $reply);
            }
            $this->sendQuit();
            if (($code === 500 || $code === 502) && $heloForbidden) {
                return $this->failure("the server refused EHLO ({$shown}); HELO fallback is not allowed with credentials or"
                    . ' before TLS', Reason::Capability, Outcome::Permanent, $stage, Scope::Session, $command, $reply);
            }
            return $this->failure("the server refused {$verb}: {$shown}", Reason::Rejected, $reply->outcome(), $stage, Scope::Session,
                $command, $reply, $code === 501 ? ['hint' => 'EHLO identity rejected; set helo:'] : []);
        }

        /**
         * Writes one command and reads its reply within $timeout; a failure closes the session.
         *
         * @param list<string>|null $rawTexts receives the raw reply texts (for EHLO)
         * @param list<int>         $allowed3xx
         */
        private function exchange(string $line, Stage $stage, float $timeout, ?array &$rawTexts = null, ?bool $adv = null,
            array $allowed3xx = []): Reply
        {
            $this->busy = true;
            try {
                $this->wire->writeLine($line, $this->t->command);
                $reply = $this->wire->readReply($stage, $timeout, $adv ?? $this->adv(), $allowed3xx, $rawTexts);
            } catch (SmtpException $e) {
                $this->abort();
                throw $this->amend($e);
            }
            $this->busy = false;
            return $reply;
        }

        /**
         * The quiescence check before a command or group (§3.6). An unsolicited 421 or an EOF
         * while idle closes the session (ConnectionLost), as does any other unsolicited data
         * (Protocol).
         */
        private function quiesce(): void
        {
            $this->busy = true;
            try {
                $unsolicited = $this->wire->assertQuiescent();
            } catch (SmtpException $e) {
                $this->abort();
                throw $this->amend($e);
            }
            $this->busy = false;
            if ($unsolicited !== null) {
                $this->abort();
                throw $this->failure('the server closed the session while idle: ' . Text::sanitize((string) $unsolicited, 200),
                    Reason::ConnectionLost, Outcome::Transient, Stage::Idle, Scope::Session, null, $unsolicited);
            }
        }

        /** QUIT and 221 within Timeouts->quit, errors ignored; then the socket is closed (§3.11). */
        private function sendQuit(): void
        {
            $this->state = State::Quitting;
            try {
                $this->wire->writeLine('QUIT', $this->t->quit);
                $this->wire->readReply(Stage::Quit, $this->t->quit, $this->adv());
            } catch (SmtpException) {
                // Ignored: the session is over either way.
            }
            $this->io?->close();
            $this->state = State::Closed;
        }

        /** RSET after a transaction abandoned before "."; a non-250 leaves the session Broken (§3.1). */
        private function resetAfterFailure(): void
        {
            try {
                $this->reset();
            } catch (SmtpException) {
                // The session is Broken now; the transaction's own failure is what the caller reports.
            }
        }

        /** The capabilities, checked against the current epoch on every use (I11). */
        private function currentCaps(): Capabilities
        {
            if ($this->caps === null || $this->caps->epoch() !== $this->epoch) {
                throw new \LogicException('Session: no capabilities for the current TLS epoch (I11)');
            }
            return $this->caps;
        }

        /** mailGate (§3.1): Ready, capabilities of this epoch, TLS and AUTH first when required. */
        private function mailGate(): Capabilities
        {
            $caps = $this->currentCaps();
            if (($this->requireTls && !$this->tls) || ($this->requireAuth && !$this->authed)) {
                throw new \LogicException('Session: MAIL before ' . ($this->requireTls && !$this->tls ? 'TLS' : 'AUTH')
                    . ' (the mailGate of §3.1)');
            }
            return $caps;
        }

        /** Whether this epoch advertised ENHANCEDSTATUSCODES (Reply::$enhancedAdvertised). */
        private function adv(): bool
        {
            return $this->caps !== null && $this->caps->has('ENHANCEDSTATUSCODES');
        }

        /** Builds a MAIL or RCPT line; parameters are validated by the preflight (§3.5), checked here once more. */
        private static function command(string $head, array $params): string
        {
            foreach ($params as $param) {
                if (!\is_string($param) || \preg_match('/\A[\x21-\x7E]+\z/', $param) !== 1) {
                    throw new \LogicException('Session: an ESMTP parameter is one token of printable ASCII (§3.5)');
                }
            }
            return \implode(' ', [$head, ...$params]);
        }

        /**
         * Consecutive commands packed into groups of at most GROUP_BYTES octets with CRLF (P14), or
         * one command per group without pipelining.
         *
         * @param list<string> $lines
         * @return list<list<string>>
         */
        private static function groups(array $lines, bool $pipelined): array
        {
            $groups = [];
            $group = [];
            $bytes = 0;
            foreach ($lines as $line) {
                $size = \strlen($line) + 2;
                if ($group !== [] && (!$pipelined || $bytes + $size > Wire::GROUP_BYTES)) {
                    $groups[] = $group;
                    $group = [];
                    $bytes = 0;
                }
                $group[] = $line;
                $bytes += $size;
            }
            $groups[] = $group;
            return $groups;
        }

        private function unexpected(Reply $reply, Stage $stage, string $line): SmtpException
        {
            return $this->failure('unexpected reply ' . Text::sanitize((string) $reply, 200) . ' to ' . \explode(' ', $line, 2)[0],
                Reason::Protocol, Outcome::Transient, $stage, Scope::Transaction, Text::sanitize($line, 1024), $reply);
        }

        private function warn(string $warning): void
        {
            if (!\in_array($warning, $this->warnings, true)) {
                $this->warnings[] = $warning;
            }
        }

        private function ready(): void
        {
            $this->state = State::Ready;
            $this->touch();
        }

        private function touch(): void
        {
            $this->lastActivity = $this->clock->monotonic();
        }

        /**
         * Closes a session left in the middle of an exchange by an unexpected throwable (a logger
         * that throws, a library bug), so it can never be used in an undefined state.
         */
        private function settle(): void
        {
            if ($this->busy || !\in_array($this->state, [State::Disconnected, State::Greeted, State::Ready, State::NeedEhlo,
                State::Closed, State::Broken], true)) {
                $this->busy = false;
                $this->io?->close();
                $this->state = State::Broken;
            }
        }

        /**
         * @throws UsageException for an unserialized Session, or one used by another process (§3.11)
         * @throws \LogicException in any other state than $states (a library bug)
         */
        private function expect(State ...$states): void
        {
            $this->live();
            if ($this->io !== null && $this->pid !== (int) \getmypid()) {
                throw new UsageException("this SMTP connection belongs to process {$this->pid}; call close() before forking,"
                    . ' or create the Mailer after forking (§3.11)');
            }
            if (!\in_array($this->state, $states, true)) {
                throw new \LogicException("Session: called in state {$this->state->name}, expected "
                    . \implode(' or ', \array_map(static fn (State $s): string => $s->name, $states)));
            }
        }

        private function live(): void
        {
            if ($this->inert) {
                throw new UsageException('this Session was unserialized and has no connection (§8.4)');
            }
        }

        /**
         * A new SmtpException, retrySafe when Transient (nothing is committed before "."),
         * connectionUsable when the session is Ready, with the current transcript.
         */
        private function failure(
            string $message,
            Reason $reason,
            Outcome $outcome,
            Stage $stage,
            Scope $scope,
            ?string $command,
            ?Reply $reply = null,
            array $details = [],
            ?\Throwable $previous = null,
        ): SmtpException {
            return new SmtpException($message, $reason, $outcome, $stage, $scope, $command, $reply, null,
                $outcome === Outcome::Transient, $this->state === State::Ready, $details, $this->transcript(), $previous);
        }

        /**
         * $e rebuilt (its properties are readonly) with retrySafe = Transient, connectionUsable =
         * Ready, the current transcript, and $changes applied (details are merged).
         *
         * @param array{command?: ?string, retrySafe?: bool, details?: array} $changes
         */
        private function amend(SmtpException $e, array $changes = []): SmtpException
        {
            return new SmtpException(
                $e->getMessage(),
                $e->reason,
                $e->outcome,
                $e->stage,
                $e->scope,
                \array_key_exists('command', $changes) ? $changes['command'] : $e->command,
                $e->reply,
                $e->result,
                $changes['retrySafe'] ?? $e->outcome === Outcome::Transient,
                $this->state === State::Ready,
                ($changes['details'] ?? []) + $e->details,
                $this->transcript(),
                $e->getPrevious(),
            );
        }
    }

    // ---------------------------------------------------------------------------------------
    // The socket (§2.6): StreamByteStream and StreamConnector
    // ---------------------------------------------------------------------------------------

    /**
     * The ONLY code touching the socket (§2.6). Every call runs under Guard (one internal call per
     * frame) inside try/catch (\Throwable), so no method ever throws, not even for a closed resource
     * (I13): failures come back as IoEvent::Error or the method's sentinel, with the warnings and
     * the caught Throwable's class and message in lastWarnings(). The Throwable itself is discarded:
     * its trace may hold the bytes being written.
     *
     * Reads follow the spike rules (V8, V9): no feof() and no select; stream_set_timeout plus ONE
     * fread of min($max, unread_bytes) when PHP's buffer holds bytes, else $max; the result is
     * classified by stream_get_meta_data(): bytes, else timed_out, else eof, else Error ('' and
     * false alike), except that '' from a blocking TLS read is Eof (see readSome). A deadline that
     * has run out returns Timeout without a call, and the timeout passed is never (0, 0), which on
     * TLS means "no timeout". Writes are bounded by the same stream_set_timeout: a
     * stream_socket_client() socket is non-blocking underneath and PHP polls with the timeout.
     *
     * @internal
     */
    final class StreamByteStream implements ByteStream
    {
        /** The longest wait handed to the stream (~31 years), so the float-to-int split stays defined. */
        private const MAX_WAIT = 1.0e9;

        /** @var resource|null the socket; null once closed or unserialized */
        private $stream = null;

        /** @var list<string> */
        private array $warnings = [];

        /** @var list<string> */
        private array $deprecations = [];

        /** Set by __unserialize(): every call fails as on a closed stream (§8.4). */
        private bool $inert = false;

        /**
         * @param resource $stream a connected socket stream (StreamConnector sets its read buffer to 0)
         * @throws \LogicException for anything but an open stream resource (a library bug)
         */
        public function __construct(mixed $stream)
        {
            if (!\is_resource($stream) || \get_resource_type($stream) !== 'stream') {
                throw new \LogicException('StreamByteStream needs an open stream resource');
            }
            $this->stream = $stream;
        }

        public function readSome(int $max, float $timeout): string|IoEvent
        {
            $this->reset();
            if ($this->stream === null) {
                return $this->closed();
            }
            if ($max < 1) {
                $this->warnings[] = "readSome(): max must be at least 1, got {$max}";
                return IoEvent::Error;
            }
            if (\is_nan($timeout) || $timeout <= 0.0) {   // not !($timeout > 0.0): opcache turns that into <=
                return IoEvent::Timeout;   // no time left: never a call (V9)
            }
            [$sec, $usec] = self::wait($timeout);
            $stream = $this->stream;
            try {
                if (Guard::run(static fn () => \stream_set_timeout($stream, $sec, $usec), $this->warnings, $this->deprecations) !== true) {
                    return IoEvent::Error;
                }
                $meta = Guard::run(static fn () => \stream_get_meta_data($stream), $this->warnings, $this->deprecations);
                // After a select PHP's buffer may hold bytes; asking for more than it holds would hand
                // them over only after one more raw read had waited for the whole timeout (V8).
                $length = $meta['unread_bytes'] > 0 ? \min($max, $meta['unread_bytes']) : $max;
                $data = Guard::run(static fn () => \fread($stream, $length), $this->warnings, $this->deprecations);
                if (\is_string($data) && $data !== '') {
                    return $data;   // bytes win over timed_out (V8)
                }
                $meta = Guard::run(static fn () => \stream_get_meta_data($stream), $this->warnings, $this->deprecations);
            } catch (\Throwable $e) {
                return $this->caught($e);
            }
            if ($meta['timed_out']) {
                return IoEvent::Timeout;
            }
            if ($meta['eof']) {
                return IoEvent::Eof;
            }
            // A blocking TLS read that returns '' without timing out means the peer ended the TLS
            // session (close_notify, a bare FIN or a reset); a stalled read would have timed out.
            // PHP sets the eof flag there only when the C errno left over from an earlier call is
            // not EAGAIN, so the flag cannot be trusted on TLS (seen on 8.3: StreamTlsTest).
            return $data === '' && $meta['blocked'] && isset($meta['crypto']) ? IoEvent::Eof : IoEvent::Error;
        }

        public function writeSome(#[\SensitiveParameter] string $bytes, float $timeout): int|IoEvent
        {
            $this->reset();
            if ($this->stream === null) {
                return $this->closed();
            }
            if ($bytes === '') {
                $this->warnings[] = 'writeSome(): nothing to write';
                return IoEvent::Error;
            }
            if (\is_nan($timeout) || $timeout <= 0.0) {
                return IoEvent::Timeout;
            }
            [$sec, $usec] = self::wait($timeout);
            $stream = $this->stream;
            try {
                if (Guard::run(static fn () => \stream_set_timeout($stream, $sec, $usec), $this->warnings, $this->deprecations) !== true) {
                    return IoEvent::Error;
                }
                $written = Guard::run(static fn () => \fwrite($stream, $bytes), $this->warnings, $this->deprecations);
                if (\is_int($written) && $written > 0) {
                    return $written;
                }
                // 0 is a failure like false: after the peer closed, writes return 0 (V9).
                $meta = Guard::run(static fn () => \stream_get_meta_data($stream), $this->warnings, $this->deprecations);
            } catch (\Throwable $e) {
                return $this->caught($e);
            }
            return $meta['timed_out'] ? IoEvent::Timeout : IoEvent::Error;
        }

        /**
         * The blocking handshake on this stream (STARTTLS or implicit). Its time budget is the
         * timeout StreamConnector gave stream_socket_client, afresh for each handshake (V10).
         *
         * @param array<string, mixed> $context the `ssl` context options (§4.3), set on the stream's
         *                                      own context, never the process-wide default
         * @return array{ok: bool, timedOut: bool, info: ?array, peer: ?\OpenSSLCertificate,
         *               chain: list<\OpenSSLCertificate>} timedOut: the warning was "Handshake timed
         *               out"; the stream is then non-blocking and must be closed at once
         */
        public function enableTls(array $context, int $cryptoMethod): array
        {
            $this->reset();
            $failed = ['ok' => false, 'timedOut' => false, 'info' => null, 'peer' => null, 'chain' => []];
            if ($this->stream === null) {
                $this->closed();
                return $failed;
            }
            $stream = $this->stream;
            try {
                foreach ($context as $name => $value) {
                    $name = (string) $name;
                    // One option per call: the two-argument array form is deprecated as of PHP 8.4.
                    if (Guard::run(static fn () => \stream_context_set_option($stream, 'ssl', $name, $value),
                        $this->warnings, $this->deprecations) !== true) {
                        return $failed;
                    }
                }
                Guard::clearOpenssl();
                $ok = Guard::run(static fn () => \stream_socket_enable_crypto($stream, true, $cryptoMethod),
                    $this->warnings, $this->deprecations);
                if ($ok !== true) {
                    foreach (Guard::opensslErrors() as $error) {
                        $this->warnings[] = "openssl: {$error}";
                    }
                    $failed['timedOut'] = $this->warned('Handshake timed out');
                    return $failed;
                }
                $meta = Guard::run(static fn () => \stream_get_meta_data($stream), $this->warnings, $this->deprecations);
                $options = Guard::run(static fn () => \stream_context_get_options($stream), $this->warnings, $this->deprecations);
            } catch (\Throwable $e) {
                $this->caught($e);
                return $failed;
            }
            $peer = $options['ssl']['peer_certificate'] ?? null;
            $chain = [];
            foreach ($options['ssl']['peer_certificate_chain'] ?? [] as $certificate) {
                if ($certificate instanceof \OpenSSLCertificate) {
                    $chain[] = $certificate;
                }
            }
            return [
                'ok' => true,
                'timedOut' => false,
                'info' => \is_array($meta['crypto'] ?? null) ? $meta['crypto'] : null,
                'peer' => $peer instanceof \OpenSSLCertificate ? $peer : null,
                'chain' => $chain,
            ];
        }

        public function unreadBytes(): int
        {
            $this->reset();
            if ($this->stream === null) {
                $this->closed();
                return -1;
            }
            $stream = $this->stream;
            try {
                $meta = Guard::run(static fn () => \stream_get_meta_data($stream), $this->warnings, $this->deprecations);
            } catch (\Throwable $e) {
                $this->caught($e);
                return -1;
            }
            return (int) $meta['unread_bytes'];
        }

        public function pollReadable(): bool
        {
            $this->reset();
            if ($this->stream === null) {
                $this->closed();
                return true;
            }
            $read = [$this->stream];
            $write = null;
            $except = null;
            try {
                $ready = Guard::run(static function () use (&$read, &$write, &$except) {
                    return \stream_select($read, $write, $except, 0, 0);
                }, $this->warnings, $this->deprecations);
            } catch (\Throwable $e) {
                $this->caught($e);
                return true;
            }
            return $ready !== 0;   // false (an error) forces a probe read, which reports it
        }

        public function localName(): ?string
        {
            return $this->name(false);
        }

        public function peerName(): ?string
        {
            return $this->name(true);
        }

        public function lastWarnings(): array
        {
            return $this->warnings;
        }

        public function lastDeprecations(): array
        {
            return $this->deprecations;
        }

        /** Closes the socket; idempotent. Session sends QUIT first when the state allows (§3.11). */
        public function close(): void
        {
            $this->reset();
            if ($this->stream === null) {
                return;
            }
            $stream = $this->stream;
            $this->stream = null;
            try {
                Guard::run(static fn () => \fclose($stream), $this->warnings, $this->deprecations);
            } catch (\Throwable $e) {
                $this->caught($e);
            }
        }

        /** A one-key placeholder (§8.4). */
        public function __serialize(): array
        {
            return ['stream' => '[redacted]'];
        }

        /**
         * An INERT stream. ByteStream methods never throw (I13), so instead of UsageException every
         * call fails as on a closed stream, with the reason in lastWarnings().
         */
        public function __unserialize(array $data): void
        {
            $this->stream = null;
            $this->inert = true;
        }

        private function name(bool $remote): ?string
        {
            $this->reset();
            if ($this->stream === null) {
                $this->closed();
                return null;
            }
            $stream = $this->stream;
            try {
                $name = Guard::run(static fn () => \stream_socket_get_name($stream, $remote), $this->warnings, $this->deprecations);
            } catch (\Throwable $e) {
                $this->caught($e);
                return null;
            }
            return \is_string($name) && $name !== '' ? $name : null;
        }

        private function reset(): void
        {
            $this->warnings = [];
            $this->deprecations = [];
        }

        private function closed(): IoEvent
        {
            $this->warnings[] = $this->inert
                ? 'UsageException: this StreamByteStream was unserialized and has no socket (§8.4)'
                : 'the stream is closed';
            return IoEvent::Error;
        }

        /** Keeps only the class and message: the Throwable's trace may hold the bytes being written. */
        private function caught(\Throwable $e): IoEvent
        {
            $this->warnings[] = $e::class . ': ' . $e->getMessage();
            return IoEvent::Error;
        }

        private function warned(string $needle): bool
        {
            foreach ($this->warnings as $warning) {
                if (\str_contains($warning, $needle)) {
                    return true;
                }
            }
            return false;
        }

        /**
         * Seconds and microseconds for stream_set_timeout(): rounded UP and never (0, 0), which on
         * TLS streams means "no timeout" (V9).
         *
         * @return array{int, int}
         */
        private static function wait(float $timeout): array
        {
            $timeout = \min($timeout, self::MAX_WAIT);
            $sec = (int) \floor($timeout);
            // Rounded up, so any $timeout > 0 (the callers' precondition) gives at least 1 µs.
            $usec = (int) \ceil(($timeout - $sec) * 1e6);
            if ($usec >= 1000000) {
                $sec++;
                $usec = 0;
            }
            return [$sec, $usec];
        }
    }

    /**
     * Opens `tcp://` connections with STREAM_CLIENT_CONNECT (never persistent, §3.11) and hands out
     * a StreamByteStream with PHP's read buffer switched off (V8). TLS, implicit or STARTTLS, is
     * started later on the same stream by ByteStream::enableTls().
     *
     * @internal
     */
    final class StreamConnector implements Connector
    {
        /**
         * @param string      $address an IP (IPv6 with or without brackets) or a host name; DNS is not
         *                             bounded by $timeout (P44)
         * @param float       $timeout bounds the TCP connect AND every later TLS handshake on the
         *                             stream, afresh for each (V10); always passed explicitly, or
         *                             default_socket_timeout would set the handshake budget
         * @param string|null $bindTo  the local source, "ip:port" (port 0: any); a bind PHP could not
         *                             honour fails the connect instead of silently using another source
         * @throws SmtpException(Connect, Transient, retrySafe), with details errno, errstr, phpWarnings
         * @throws \LogicException for arguments the Mailer's validation should have refused
         */
        public function connect(string $address, int $port, float $timeout, ?string $bindTo): ByteStream
        {
            if (\preg_match('/\A(?:\[[0-9A-Fa-f:.]+\]|[0-9A-Za-z._:-]+)\z/', $address) !== 1) {
                throw new \LogicException('StreamConnector: invalid address "' . Text::sanitize($address, 100) . '"');
            }
            if ($port < 1 || $port > 65535 || !\is_finite($timeout) || $timeout <= 0.0) {
                throw new \LogicException("StreamConnector: invalid port {$port} or timeout {$timeout}");
            }
            $bindIp = $bindTo === null ? null : Text::socketIp($bindTo);
            if ($bindTo !== null && $bindIp === null) {
                throw new \LogicException('StreamConnector: invalid bindTo "' . Text::sanitize($bindTo, 100) . '" (not "ip:port")');
            }
            $host = \str_contains($address, ':') && $address[0] !== '[' ? "[{$address}]" : $address;
            $url = "tcp://{$host}:{$port}";
            $options = $bindTo === null ? [] : ['socket' => ['bindto' => $bindTo]];
            $warnings = [];
            $deprecations = [];
            $errno = 0;
            $errstr = '';
            try {
                // Always a context of its own: TLS options set later on a stream opened with the
                // default context would change that process-wide default (I9).
                $context = Guard::run(static fn () => \stream_context_create($options), $warnings, $deprecations);
                $stream = Guard::run(static function () use ($url, &$errno, &$errstr, $timeout, $context) {
                    return \stream_socket_client($url, $errno, $errstr, $timeout, \STREAM_CLIENT_CONNECT, $context);
                }, $warnings, $deprecations);
            } catch (\Throwable $e) {
                $warnings[] = $e::class . ': ' . $e->getMessage();
                $stream = false;
            }
            if (!\is_resource($stream)) {
                throw self::failure("cannot connect to {$address}:{$port}: " . Text::sanitize((string) $errstr, 200)
                    . " (errno {$errno})", $errno, (string) $errstr, $warnings, $deprecations);
            }
            try {
                $unbuffered = Guard::run(static fn () => \stream_set_read_buffer($stream, 0), $warnings, $deprecations);
                $local = $bindIp === null ? null
                    : Guard::run(static fn () => \stream_socket_get_name($stream, false), $warnings, $deprecations);
            } catch (\Throwable $e) {
                $warnings[] = $e::class . ': ' . $e->getMessage();
                $unbuffered = -1;
                $local = false;
            }
            $problem = null;
            if ($unbuffered !== 0) {
                $problem = 'cannot switch off read buffering';
            } elseif ($bindIp !== null && !self::bound($bindIp, \is_string($local) ? $local : null)) {
                $problem = 'the connection could not be bound to ' . Text::sanitize($bindTo, 100);   // PHP only warns
            }
            if ($problem !== null) {
                try {
                    Guard::run(static fn () => \fclose($stream), $warnings, $deprecations);
                } catch (\Throwable $e) {
                    $warnings[] = $e::class . ': ' . $e->getMessage();
                }
                throw self::failure("cannot connect to {$address}:{$port}: {$problem}", $errno, (string) $errstr,
                    $warnings, $deprecations);
            }
            return new StreamByteStream($stream);
        }

        /** Whether the local name has the IP asked for; the unspecified address (0.0.0.0, ::) accepts any. */
        private static function bound(string $bindIp, ?string $localName): bool
        {
            $wanted = \inet_pton($bindIp);
            if ($wanted === \str_repeat("\0", \strlen((string) $wanted))) {
                return true;
            }
            $localIp = Text::socketIp($localName);
            return $localIp !== null && \inet_pton($localIp) === $wanted;
        }

        /**
         * @param list<string> $warnings
         * @param list<string> $deprecations
         */
        private static function failure(string $message, int $errno, string $errstr, array $warnings, array $deprecations): SmtpException
        {
            $details = ['errno' => $errno, 'errstr' => Text::sanitize($errstr)];
            if ($warnings !== []) {
                $details['phpWarnings'] = \array_map(static fn (string $w): string => Text::sanitize($w), $warnings);
            }
            if ($deprecations !== []) {
                $details['phpDeprecations'] = $deprecations;
            }
            return new SmtpException($message, Reason::Connect, Outcome::Transient, Stage::Connect, Scope::Session,
                retrySafe: true, details: $details);
        }
    }
}
