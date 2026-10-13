<?php
/**
 * tehsmtp: the message model and its serialisation (§2.4, §2.5, §2.6, §3.5, §6).
 *
 * Address and Message are the public model (§6.2, §6.4); Encoder and Idna the byte-level encoders
 * (quoted-printable, base64, RFC 2047 and 2231, folding, Punycode; §6.3, §6.6); Renderer turns a
 * Message into the immutable Rendered, its header bytes plus the body held in a refcounted Spool
 * (§6.1, §6.8), the only input DATA accepts. Loaded only by tehsmtp.php, after the core (§2.1).
 */

declare(strict_types=1);

namespace Tehsmtp;

if (!\class_exists(Address::class, false)) {

    /**
     * One mailbox (§2.4, §6.4): an addr-spec and an optional display name. Immutable, and every
     * instance is valid, so setters and the envelope use it as it is.
     *
     * `email` is the addr-spec in canonical form: the local part as given (its case is never
     * changed, RFC 5321 §2.4) with the least quoting it needs (RFC 5321 §4.1.2), "@", and the
     * domain as a lower-case A-label (an IDN goes through Idna::toAscii) or an address literal
     * ("[192.0.2.1]", "[IPv6:2001:db8::1]"). Limits (RFC 5321 §4.5.3.1): local part 64 octets,
     * domain 255, label 63, path ("<" email ">") 256. A non-ASCII local part stays raw UTF-8
     * (RFC 6531, 6532): the one way raw UTF-8 reaches a header, so it needs SMTPUTF8 (§3.5).
     * A quoted local part may not hold SP, "<" or ">" (§6.4: they can never get into MAIL or
     * RCPT, even quoted, since servers that split the command there would read a parameter).
     *
     * `name` is the display name, '' if none: UTF-8 without control characters. It is never
     * parsed; headerTokens() quotes or encodes it (§6.3).
     */
    final class Address
    {
        /** RFC 5322 §3.2.3 atext (ASCII part), for character classes. */
        private const ATEXT = 'A-Za-z0-9!#$%&\'*+\-\/=?^_`{|}~';

        /** A dot-atom-text; RFC 6532 §3.2 adds UTF-8 to atext (the input is checked UTF-8 first). */
        private const DOT_ATOM = '/\A[' . self::ATEXT . '\x80-\xFF]+(?:\.[' . self::ATEXT . '\x80-\xFF]+)*\z/';

        /** A display name that can stay an unquoted phrase: ASCII atext words separated by single spaces. */
        private const PLAIN_PHRASE = '/\A[' . self::ATEXT . ']+(?: [' . self::ATEXT . ']+)*\z/';

        /** Text a reader could take for an encoded-word; a name holding it is encoded itself (§6.3, checklist 5.12). */
        private const LOOKS_ENCODED = '/=\?.*\?=/s';

        /** C1 controls (U+0080-U+009F) as UTF-8: refused like C0, since terminals and some clients act on them. */
        private const C1 = '/\xC2[\x80-\x9F]/';

        /**
         * A phrase token longer than this could not share a line with anything, and one over 997
         * octets could not be folded at all (§6.3), so such a name is encoded, which folds.
         */
        private const MAX_PHRASE_TOKEN = 900;

        /** The message prefix of every exception raised here; of() rewrites it to the setter's field. */
        private const INVALID = 'Invalid address: ';

        /** The addr-spec: minimally quoted local part "@" domain (see the class docblock). */
        public readonly string $email;

        /** The display name; '' if none. */
        public readonly string $name;

        /** The local part in its minimally quoted form (the part of $email before the last "@"). */
        private string $local;

        /** The lower-case A-label domain, or the address literal with its brackets. */
        private string $domain;

        /** The domain as given, when it had non-ASCII labels; else null. */
        private ?string $unicode;

        /** @var list<string> "idn-not-normalized: ..." when the fallback converted the domain */
        private array $warnings = [];

        /**
         * @param string $email an addr-spec (RFC 5322 §3.4.1) without comments or angle brackets:
         *                      a dot-atom or quoted-string local part, "@", a domain (an IDN is
         *                      converted to A-labels) or an address literal
         * @param string $name  the display name, used as given (never parsed); whitespace only means none
         * @throws MessageException field "address"; injection for control characters
         */
        public function __construct(string $email, string $name = '')
        {
            $this->init($email, $name);
        }

        /**
         * Parses EXACTLY one mailbox (RFC 5322 §3.4 name-addr or addr-spec, e.g.
         * `Ann Lee <ann@example.org>`, `"Lee, Ann" <ann@example.org>`, `ann@example.org`), with a
         * hand-written parser, never filter_var (§6.4). Comments are dropped. A list, a group or
         * trailing text is refused, so a web-form value such as "victim@x, attacker@y" can never
         * become two recipients (T3, D9). obs-route, obs-local-part and obs-domain are refused.
         * The text is one unfolded value: every control character, HTAB included, is refused
         * (an address is a structured value, §6.2). Display names are taken as written: an
         * encoded-word in one is not decoded (the API is UTF-8, D27).
         *
         * @throws MessageException field "address"; injection for control characters
         */
        public static function parse(string $mailbox): self
        {
            $tokens = self::lex($mailbox);
            $i = 0;
            $address = self::mailboxAt($tokens, $i, false);
            if (isset($tokens[$i])) {
                throw self::invalid($tokens[$i][0] === ','
                    ? 'a string names exactly one mailbox, and this one holds a list; use Address::parseList() for lists'
                    : 'unexpected ' . self::describe($tokens[$i]) . ' after the mailbox');
            }
            return $address;
        }

        /**
         * Parses an RFC 5322 §3.4 address-list: mailboxes and groups separated by commas. Comments
         * are dropped; groups are flattened and their names discarded (an empty group gives
         * nothing); empty list elements (RFC 5322 §4.4) are skipped. The same lexical rules as
         * parse() apply: an unfolded value, no control characters (replace HTAB with SP first).
         *
         * @return list<self>
         * @throws MessageException field "address"; injection for control characters
         */
        public static function parseList(string $list): array
        {
            $tokens = self::lex($list);
            $n = \count($tokens);
            $out = [];
            $i = 0;
            while ($i < $n) {
                if ($tokens[$i][0] === ',') {
                    $i++;
                    continue;
                }
                $j = $i;
                while ($j < $n && self::isWordish($tokens[$j])) {
                    $j++;
                }
                if ($j < $n && $tokens[$j][0] === ':') {
                    if ($j === $i) {
                        throw self::invalid('a group needs a name before ":"');
                    }
                    $i = $j + 1;
                    while (true) {
                        if (!isset($tokens[$i])) {
                            throw self::invalid('the group is not closed with ";"');
                        }
                        if ($tokens[$i][0] === ';') {
                            $i++;
                            break;
                        }
                        if ($tokens[$i][0] === ',') {
                            $i++;
                            continue;
                        }
                        $out[] = self::mailboxAt($tokens, $i, true);
                        if (isset($tokens[$i]) && $tokens[$i][0] !== ',' && $tokens[$i][0] !== ';') {
                            throw self::invalid('unexpected ' . self::describe($tokens[$i]) . ' in the group');
                        }
                    }
                } else {
                    $out[] = self::mailboxAt($tokens, $i, false);
                }
                if (isset($tokens[$i]) && $tokens[$i][0] !== ',') {
                    throw self::invalid('unexpected ' . self::describe($tokens[$i]) . ' after an address');
                }
            }
            return $out;
        }

        /**
         * One mailbox from a setter argument, with errors reported against $field ("to[1]", "from").
         *
         * @internal
         * @throws MessageException with $field
         */
        public static function of(string|self $mailbox, string $field): self
        {
            if ($mailbox instanceof self) {
                return $mailbox;
            }
            try {
                return self::parse($mailbox);
            } catch (MessageException $e) {
                $message = $e->getMessage();
                if (\str_starts_with($message, self::INVALID)) {
                    $message = "Invalid {$field}: " . \substr($message, \strlen(self::INVALID));
                }
                throw new MessageException($message, $field, $e->injection);
            }
        }

        /** The local part as it appears in $email: case kept, minimally quoted. */
        public function localPart(): string
        {
            return $this->local;
        }

        /** The lower-case A-label domain, or the address literal with its brackets. */
        public function domain(): string
        {
            return $this->domain;
        }

        /** The domain as given when it was an IDN (had non-ASCII labels); else null. */
        public function unicodeDomain(): ?string
        {
            return $this->unicode;
        }

        /** The local part is non-ASCII: the message needs SMTPUTF8 and 8BITMIME (§3.5, RFC 6531). */
        public function needsSmtpUtf8(): bool
        {
            return \preg_match('/[\x80-\xFF]/', $this->local) === 1;
        }

        /**
         * The path content for MAIL FROM and RCPT TO (without the angle brackets): the minimally
         * quoted local part "@" the A-label domain (RFC 5321 §4.1.2), which is $email. It can hold
         * no CR, LF, NUL or unquoted SP or ">", so no command or parameter can be smuggled in (§6.4).
         */
        public function forEnvelope(): string
        {
            return $this->email;
        }

        /**
         * The fold-ready tokens of this mailbox for Encoder::foldStructured (§6.3): without a name,
         * just the addr-spec; otherwise the words of an unquoted phrase (ASCII atext words
         * separated by single spaces), or one quoted-string (any other ASCII name; only "\" and
         * '"' escaped), or the name's encoded-words of at most 64 characters (non-ASCII, text that
         * looks like an encoded-word, or a token too long to fold), then "<addr-spec>".
         *
         * @return list<string>
         */
        public function headerTokens(): array
        {
            if ($this->name === '') {
                return [$this->email];
            }
            $name = $this->name;
            $phrase = null;
            if (\preg_match('/[\x80-\xFF]/', $name) !== 1 && \preg_match(self::LOOKS_ENCODED, $name) !== 1) {
                if (\preg_match(self::PLAIN_PHRASE, $name) === 1) {
                    $phrase = \explode(' ', $name);
                } else {
                    $phrase = [Encoder::quoteString($name)];
                }
                foreach ($phrase as $token) {
                    if (\strlen($token) > self::MAX_PHRASE_TOKEN) {
                        $phrase = null;
                        break;
                    }
                }
            }
            $phrase ??= Encoder::encodedWords($name, 0, Encoder::WORD_MAX, true);
            $phrase[] = '<' . $this->email . '>';
            return $phrase;
        }

        /** The header form for display (logs, __toString): headerTokens() joined with SP, unfolded. */
        public function forHeader(): string
        {
            return \implode(' ', $this->headerTokens());
        }

        /**
         * The de-duplication key (research item 159): the exact local part "@" the lower-cased
         * domain. Equal keys name the same mailbox.
         */
        public function key(): string
        {
            return $this->local . '@' . Text::lower($this->domain);
        }

        public function __toString(): string
        {
            return $this->forHeader();
        }

        /**
         * Warnings about this address for Rendered::$warnings, each "code: message".
         *
         * @internal
         * @return list<string>
         */
        public function warnings(): array
        {
            return $this->warnings;
        }

        /** @return array{email: string, name: string} */
        public function __serialize(): array
        {
            return ['email' => $this->email, 'name' => $this->name];
        }

        /**
         * Rebuilds and RE-VALIDATES the address, so even an unserialized Address is valid.
         *
         * @throws MessageException for data that is not a valid address
         */
        public function __unserialize(array $data): void
        {
            $email = $data['email'] ?? null;
            $name = $data['name'] ?? null;
            if (!\is_string($email) || !\is_string($name)) {
                throw self::invalid('the serialized data holds no address');
            }
            $this->init($email, $name);
        }

        // -------------------------------------------------------------------------------------
        // Validation
        // -------------------------------------------------------------------------------------

        private function init(string $email, string $name): void
        {
            self::assertText($email, 'the address');
            $at = \strrpos($email, '@');
            if ($at === false) {
                throw self::invalid('"' . Text::sanitize($email, 100) . '" has no "@"');
            }
            $local = self::canonicalLocal(\substr($email, 0, $at));
            $domainText = \substr($email, $at + 1);
            $domain = self::canonicalDomain($domainText, $this->warnings);
            $canonical = $local . '@' . $domain;
            if (\strlen($canonical) > 254) {
                throw self::invalid('"' . Text::sanitize($canonical, 100) . '" is longer than 254 octets'
                    . ' (a path, the address in angle brackets, is at most 256: RFC 5321 §4.5.3.1.3)');
            }
            self::assertText($name, 'the display name');
            if (\preg_match('/\A[ ]*\z/', $name) === 1) {
                $name = '';   // an empty or whitespace-only name is omitted (checklist 3.12)
            }
            $this->local = $local;
            $this->domain = $domain;
            $this->unicode = \preg_match('/[\x80-\xFF]/', $domainText) === 1 ? $domainText : null;
            $this->email = $canonical;
            $this->name = $name;
        }

        /**
         * UTF-8 without C0 controls (HTAB included: address fields are structured, §6.2), DEL or
         * C1 controls.
         */
        private static function assertText(string $s, string $what): void
        {
            if (!Text::isUtf8($s)) {
                throw self::invalid("{$what} is not valid UTF-8");
            }
            if (\preg_match('/[\x00-\x1F\x7F]/', $s, $m) === 1) {
                throw self::invalid(\sprintf('%s contains the control character 0x%02X (CR, LF, NUL, HTAB and other controls'
                    . ' are not allowed)', $what, \ord($m[0])), true);
            }
            if (\preg_match(self::C1, $s, $m) === 1) {
                throw self::invalid(\sprintf('%s contains the C1 control character U+%04X', $what, \ord($m[0][1])), true);
            }
        }

        /** The minimally quoted form of a local part given as a dot-atom or a quoted-string. */
        private static function canonicalLocal(string $text): string
        {
            if (\preg_match('/\A"((?:[^"\\\\]|\\\\.)*)"\z/s', $text, $m) === 1) {
                $value = (string) \preg_replace('/\\\\(.)/s', '$1', $m[1]);
            } elseif (\preg_match(self::DOT_ATOM, $text) === 1) {
                $value = $text;
            } else {
                throw self::invalid('the local part "' . Text::sanitize($text, 100) . '" must be a dot-atom'
                    . ' (no leading, trailing or double dot) or a quoted string');
            }
            if ($value === '') {
                throw self::invalid('the local part is empty');
            }
            if (\strpbrk($value, ' <>') !== false) {
                throw self::invalid('the local part "' . Text::sanitize($text, 100) . '" contains SP, "<" or ">", which'
                    . ' are refused even quoted, so they can never reach MAIL FROM or RCPT TO (§6.4)');
            }
            $local = \preg_match(self::DOT_ATOM, $value) === 1 ? $value : '"' . \addcslashes($value, '\\"') . '"';
            if (\strlen($local) > 64) {
                throw self::invalid('the local part "' . Text::sanitize($local, 100) . '" is longer than 64 octets (RFC 5321 §4.5.3.1.1)');
            }
            return $local;
        }

        /**
         * The A-label domain or the normalised address literal.
         *
         * @param list<string> $warnings idn-not-normalized is appended
         */
        private static function canonicalDomain(string $text, array &$warnings): string
        {
            if (\str_starts_with($text, '[')) {
                if (\preg_match('/\A\[(?:(?i:IPv6:)([0-9A-Fa-f:.]+)|([0-9.]+))\]\z/', $text, $m) === 1) {
                    if ($m[1] !== '' && \str_contains($m[1], ':') && Text::isIpLiteral($m[1])) {
                        return '[IPv6:' . Text::lower($m[1]) . ']';
                    }
                    if (($m[2] ?? '') !== '' && \preg_match('/\A[0-9]{1,3}(?:\.[0-9]{1,3}){3}\z/', $m[2]) === 1 && Text::isIpLiteral($m[2])) {
                        return '[' . $m[2] . ']';
                    }
                }
                throw self::invalid('the address literal "' . Text::sanitize($text, 100) . '" must be [IPv4] or [IPv6:...] (RFC 5321 §4.1.3)');
            }
            $error = null;
            $ascii = Idna::toAscii($text, $error, $warnings);
            if ($ascii === null) {
                throw self::invalid('the domain "' . Text::sanitize($text, 100) . "\" is not valid: {$error}");
            }
            if (\preg_match('/(?:\A|\.)[0-9]+\z/', $ascii) === 1) {
                throw self::invalid("the domain \"{$ascii}\" ends in a numeric label; write an IP address as a literal, e.g. [192.0.2.1]");
            }
            return $ascii;
        }

        private static function invalid(string $reason, bool $injection = false): MessageException
        {
            return new MessageException(self::INVALID . $reason, 'address', $injection);
        }

        // -------------------------------------------------------------------------------------
        // The RFC 5322 §3.4 parser: a lexer, then recursive descent over its tokens
        // -------------------------------------------------------------------------------------

        /**
         * Splits a header value into tokens: [type, text, whitespace-before, offset], where type is
         * "atom", "qstring" (text: the content, quoted-pairs resolved), "dot", "literal" (text:
         * "[...]") or one of the specials < > @ , ; :. SP and (nested) comments count as
         * whitespace-before; everything else is an error. HTAB is refused like every other C0
         * control: an address is a structured value, and HTAB is allowed only in unstructured
         * ones (§6.2), so a caller unfolding a received header replaces it with SP first.
         *
         * @return list<array{string, string, bool, int}>
         */
        private static function lex(string $s): array
        {
            if (!Text::isUtf8($s)) {
                throw self::invalid('the text is not valid UTF-8');
            }
            if (\preg_match('/[\x00-\x1F\x7F]/', $s, $m, \PREG_OFFSET_CAPTURE) === 1) {
                throw self::invalid(\sprintf('control character 0x%02X at offset %d (CR, LF, NUL, HTAB and other controls are not allowed)',
                    \ord($m[0][0]), $m[0][1]), true);
            }
            $tokens = [];
            $n = \strlen($s);
            $i = 0;
            $space = false;
            while ($i < $n) {
                $c = $s[$i];
                if ($c === ' ') {
                    $space = true;
                    $i++;
                } elseif ($c === '(') {
                    $i = self::skipComment($s, $i);
                    $space = true;
                } elseif ($c === '"') {
                    $start = $i;
                    $text = '';
                    for ($i++; ; $i++) {
                        if ($i >= $n) {
                            throw self::invalid("the quoted string at offset {$start} is not closed");
                        }
                        if ($s[$i] === '"') {
                            break;
                        }
                        if ($s[$i] === '\\') {
                            if (++$i >= $n) {
                                throw self::invalid("the quoted string at offset {$start} is not closed");
                            }
                        }
                        $text .= $s[$i];
                    }
                    $i++;
                    $tokens[] = ['qstring', $text, $space, $start];
                    $space = false;
                } elseif ($c === '[') {
                    if (\preg_match('/\G\[[\x21-\x5A\x5E-\x7E]*\]/', $s, $m, 0, $i) !== 1) {
                        throw self::invalid("the domain literal at offset {$i} is not valid");
                    }
                    $tokens[] = ['literal', $m[0], $space, $i];
                    $i += \strlen($m[0]);
                    $space = false;
                } elseif (\preg_match('/\G[' . self::ATEXT . '\x80-\xFF]+/', $s, $m, 0, $i) === 1) {
                    $tokens[] = ['atom', $m[0], $space, $i];
                    $i += \strlen($m[0]);
                    $space = false;
                } elseif (\strpos('.<>@,;:', $c) !== false) {
                    $tokens[] = [$c === '.' ? 'dot' : $c, $c, $space, $i];
                    $i++;
                    $space = false;
                } else {
                    throw self::invalid('unexpected "' . Text::sanitize($c, 16) . "\" at offset {$i}");
                }
            }
            return $tokens;
        }

        /** Skips the comment starting at $i (RFC 5322 §3.2.2: nested, with quoted-pairs); returns the offset after it. */
        private static function skipComment(string $s, int $i): int
        {
            $start = $i;
            $depth = 0;
            for ($n = \strlen($s); $i < $n; $i++) {
                $c = $s[$i];
                if ($c === '\\') {
                    $i++;
                } elseif ($c === '(') {
                    $depth++;
                } elseif ($c === ')' && --$depth === 0) {
                    return $i + 1;
                }
            }
            throw self::invalid("the comment at offset {$start} is not closed");
        }

        /** An atom, a quoted string or a dot: the tokens a phrase or a local part is made of. */
        private static function isWordish(array $token): bool
        {
            return $token[0] === 'atom' || $token[0] === 'qstring' || $token[0] === 'dot';
        }

        /**
         * One mailbox starting at token $i (name-addr or addr-spec); $i moves past it.
         *
         * @param list<array{string, string, bool, int}> $tokens
         */
        private static function mailboxAt(array $tokens, int &$i, bool $inGroup): self
        {
            $phrase = [];
            while (isset($tokens[$i]) && self::isWordish($tokens[$i])) {
                $phrase[] = $tokens[$i++];
            }
            $next = $tokens[$i][0] ?? null;
            if ($next === '<') {
                $i++;
                if (($tokens[$i][0] ?? null) === '@') {
                    throw self::invalid('a source route (<@relay:user@domain>, obs-route) is obsolete syntax and not accepted');
                }
                $local = [];
                while (isset($tokens[$i]) && self::isWordish($tokens[$i])) {
                    $local[] = $tokens[$i++];
                }
                if (($tokens[$i][0] ?? null) !== '@') {
                    throw self::invalid('the address in angle brackets needs a local part, "@" and a domain');
                }
                $i++;
                $addrSpec = self::addrSpec($local, self::domainAt($tokens, $i));
                if (($tokens[$i][0] ?? null) !== '>') {
                    throw self::invalid('the address in angle brackets is not closed with ">"');
                }
                $i++;
                return new self($addrSpec, self::displayName($phrase));
            }
            if ($next === '@' && $phrase !== []) {
                $i++;
                return new self(self::addrSpec($phrase, self::domainAt($tokens, $i)));
            }
            if ($next === ':') {
                throw self::invalid($inGroup ? 'groups cannot be nested' : 'a group ("name: a@x, b@y;") is not a mailbox; use Address::parseList()');
            }
            if ($next === null) {
                throw self::invalid($phrase === [] ? 'no mailbox given' : 'no address: the text needs "<local@domain>" or "local@domain"');
            }
            throw self::invalid('unexpected ' . self::describe($tokens[$i]));
        }

        /**
         * The domain after "@": one address literal, or atoms joined by dots with nothing between
         * them (obs-domain, with comments or spaces between the labels, is refused).
         *
         * @param list<array{string, string, bool, int}> $tokens
         */
        private static function domainAt(array $tokens, int &$i): string
        {
            if (($tokens[$i][0] ?? null) === 'literal') {
                return $tokens[$i++][1];
            }
            $text = '';
            $previous = null;
            while (isset($tokens[$i]) && ($tokens[$i][0] === 'atom' || $tokens[$i][0] === 'dot')) {
                if ($previous !== null && $tokens[$i][2]) {
                    if ($tokens[$i][0] === 'atom' && $previous === 'atom') {
                        break;   // "a@x.example b@y.example": the domain ended; the caller reports "b"
                    }
                    throw self::invalid('spaces or comments inside a domain (obs-domain) are obsolete syntax and not accepted');
                }
                $previous = $tokens[$i][0];
                $text .= $tokens[$i++][1];
            }
            if ($text === '') {
                throw self::invalid('a domain is missing after "@"');
            }
            return $text;
        }

        /**
         * The addr-spec text of a local part's tokens and a domain, for the constructor: one
         * quoted string, or atoms and dots with nothing between them (obs-local-part, such as
         * `"a".b` or `a . b`, is refused).
         *
         * @param list<array{string, string, bool, int}> $local
         */
        private static function addrSpec(array $local, string $domain): string
        {
            if (\count($local) === 1 && $local[0][0] === 'qstring') {
                return '"' . \addcslashes($local[0][1], '\\"') . '"@' . $domain;
            }
            $text = '';
            foreach ($local as $k => $token) {
                if ($token[0] === 'qstring' || ($k > 0 && $token[2])) {
                    throw self::invalid('a local part made of several words (obs-local-part) is obsolete syntax and not accepted');
                }
                $text .= $token[1];
            }
            return $text . '@' . $domain;
        }

        /**
         * The display name of a phrase's tokens: the words joined by one SP where whitespace or a
         * comment separated them (RFC 5322 §3.2.2), quoted strings unquoted; the "." of obs-phrase
         * ("John Q. Public") is kept.
         *
         * @param list<array{string, string, bool, int}> $phrase
         */
        private static function displayName(array $phrase): string
        {
            if ($phrase === []) {
                return '';
            }
            if ($phrase[0][0] === 'dot') {
                throw self::invalid('a display name cannot start with "."');
            }
            $name = '';
            foreach ($phrase as $k => $token) {
                $name .= ($k > 0 && $token[2] ? ' ' : '') . $token[1];
            }
            return $name;
        }

        /** @param array{string, string, bool, int} $token */
        private static function describe(array $token): string
        {
            return '"' . Text::sanitize($token[0] === 'qstring' ? '"' . $token[1] . '"' : $token[1], 40) . "\" at offset {$token[3]}";
        }
    }

    /**
     * An email message under construction (§2.5, §6.2): the public model a Mailer sends. Every
     * setter validates at once, so an error surfaces at the line that caused it (MessageException
     * with the field), and returns the Message for chaining. Nothing is rendered until
     * Mailer::send() or toString().
     *
     * Each string that names a mailbox is exactly ONE mailbox (Address::parse): lists come in only
     * through `...Address::parseList($s)` (D9). Header inputs never carry CR, LF, NUL, other C0
     * controls or DEL (HTAB only in unstructured values); only rawHeader() takes folds.
     *
     * Content-IDs come from the Message's own RandomSource (default SystemRandom), because they
     * exist before any Renderer does; Date, Message-ID and boundaries come from the Renderer's
     * (the Mailer's). A clone is a new message (new Date, Message-ID and CIDs, D25).
     */
    final class Message
    {
        /**
         * Field names the library writes or never writes, compared lower-case; header() and
         * rawHeader() refuse them, and every "content-*" name (§6.2).
         */
        private const OWNED = [
            'return-path', 'received', 'bcc', 'dkim-signature', 'mime-version', 'date', 'from', 'sender', 'to', 'cc',
            'reply-to', 'message-id', 'in-reply-to', 'references', 'subject', 'list-unsubscribe', 'list-unsubscribe-post',
            'auto-submitted',
        ];

        /** RFC 5322 §3.6.4 msg-id: "<" dot-atom-text "@" (dot-atom-text / no-fold-literal) ">" (ASCII). */
        private const MSG_ID = '/\A<[A-Za-z0-9!#$%&\'*+\-\/=?^_`{|}~]+(?:\.[A-Za-z0-9!#$%&\'*+\-\/=?^_`{|}~]+)*'
            . '@(?:[A-Za-z0-9!#$%&\'*+\-\/=?^_`{|}~]+(?:\.[A-Za-z0-9!#$%&\'*+\-\/=?^_`{|}~]+)*|\[[\x21-\x5A\x5E-\x7E]*\])>\z/';

        /** A msg-id or URI token must fit on a folded line with its separator (§6.3: 998 octets). */
        private const MAX_TOKEN = 994;

        /** Extension map for attachments without a caller type when finfo has no answer (§6.6, message item 4.3). */
        private const TYPES = [
            '7z' => 'application/x-7z-compressed', 'avif' => 'image/avif', 'bmp' => 'image/bmp', 'css' => 'text/css',
            'csv' => 'text/csv', 'doc' => 'application/msword', 'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'gif' => 'image/gif', 'gz' => 'application/gzip', 'heic' => 'image/heic', 'htm' => 'text/html', 'html' => 'text/html',
            'ics' => 'text/calendar', 'jpeg' => 'image/jpeg', 'jpg' => 'image/jpeg', 'js' => 'text/javascript',
            'json' => 'application/json', 'md' => 'text/markdown', 'mov' => 'video/quicktime', 'mp3' => 'audio/mpeg',
            'mp4' => 'video/mp4', 'odp' => 'application/vnd.oasis.opendocument.presentation',
            'ods' => 'application/vnd.oasis.opendocument.spreadsheet', 'odt' => 'application/vnd.oasis.opendocument.text',
            'ogg' => 'audio/ogg', 'pdf' => 'application/pdf', 'png' => 'image/png', 'ppt' => 'application/vnd.ms-powerpoint',
            'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation', 'rtf' => 'application/rtf',
            'svg' => 'image/svg+xml', 'tar' => 'application/x-tar', 'tif' => 'image/tiff', 'tiff' => 'image/tiff',
            'txt' => 'text/plain', 'vcf' => 'text/vcard', 'wav' => 'audio/wav', 'webm' => 'video/webm', 'webp' => 'image/webp',
            'xls' => 'application/vnd.ms-excel', 'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'xml' => 'application/xml', 'zip' => 'application/zip',
        ];

        /** finfo answers that say nothing about the content; the next source is tried. */
        private const UNINFORMATIVE = ['application/octet-stream', 'application/x-empty', 'inode/x-empty'];

        /** Content-IDs; shared by clones. Null only in an unserialized (inert) Message. */
        private ?RandomSource $random = null;

        private ?Address $from = null;
        private ?Address $sender = null;
        /** @var list<Address> */
        private array $to = [];
        /** @var list<Address> */
        private array $cc = [];
        /** @var list<Address> */
        private array $bcc = [];
        /** @var list<Address> */
        private array $replyTo = [];
        private ?string $subject = null;
        private ?string $text = null;
        private ?string $html = null;

        /** @var list<array{path: ?string, data: ?string, name: string, type: string}> */
        private array $attachments = [];

        /** @var list<array{path: ?string, data: ?string, name: string, type: string, cid: string}> cid without brackets */
        private array $inline = [];

        /** @var list<array{name: string, value: string, raw: bool}> custom fields, in insertion order */
        private array $headers = [];

        /** @var list<string> */
        private array $listUnsubscribe = [];
        private bool $oneClick = false;
        private ?string $autoSubmitted = null;
        private ?\DateTimeImmutable $date = null;
        /** With its angle brackets. */
        private ?string $messageId = null;
        /** @var list<string> */
        private array $inReplyTo = [];
        /** @var list<string> */
        private array $references = [];

        /** Set by __unserialize(): every method then throws UsageException (§8.4). */
        private bool $inert = false;

        /**
         * @param RandomSource|null $random the source of Content-IDs (§6.2), default SystemRandom;
         *                                  pass a seeded one for reproducible CIDs (I10). Clones share it.
         */
        public function __construct(?RandomSource $random = null)
        {
            // Scrub first (§8.4): no frame that can throw keeps an injected object as an argument.
            $source = $random;
            \settype($random, 'null');
            $this->random = $source ?? new SystemRandom();
        }

        /** The author: exactly one mailbox (RFC 5322 §3.6.2). Mandatory: rendering without it fails. */
        public function from(string|Address $mailbox): static
        {
            $this->assertUsable();
            $this->from = Address::of($mailbox, 'from');
            return $this;
        }

        /** The Sender field (one mailbox), omitted when it is the From mailbox; null removes it. */
        public function sender(string|Address|null $mailbox): static
        {
            $this->assertUsable();
            $this->sender = $mailbox === null ? null : Address::of($mailbox, 'sender');
            return $this;
        }

        /** REPLACES the To list; each string is exactly one mailbox (field "to[i]", i the argument index). */
        public function to(string|Address ...$mailboxes): static
        {
            $this->assertUsable();
            $this->to = self::mailboxes($mailboxes, 'to');
            return $this;
        }

        /** Appends to the To list. */
        public function addTo(string|Address ...$mailboxes): static
        {
            $this->assertUsable();
            \array_push($this->to, ...self::mailboxes($mailboxes, 'to', \count($this->to)));
            return $this;
        }

        /** REPLACES the Cc list. */
        public function cc(string|Address ...$mailboxes): static
        {
            $this->assertUsable();
            $this->cc = self::mailboxes($mailboxes, 'cc');
            return $this;
        }

        /** Appends to the Cc list. */
        public function addCc(string|Address ...$mailboxes): static
        {
            $this->assertUsable();
            \array_push($this->cc, ...self::mailboxes($mailboxes, 'cc', \count($this->cc)));
            return $this;
        }

        /** REPLACES the Bcc list: envelope recipients only, never rendered (§6.3). */
        public function bcc(string|Address ...$mailboxes): static
        {
            $this->assertUsable();
            $this->bcc = self::mailboxes($mailboxes, 'bcc');
            return $this;
        }

        /** Appends to the Bcc list. */
        public function addBcc(string|Address ...$mailboxes): static
        {
            $this->assertUsable();
            \array_push($this->bcc, ...self::mailboxes($mailboxes, 'bcc', \count($this->bcc)));
            return $this;
        }

        /** REPLACES the Reply-To list. */
        public function replyTo(string|Address ...$mailboxes): static
        {
            $this->assertUsable();
            $this->replyTo = self::mailboxes($mailboxes, 'replyTo');
            return $this;
        }

        /** UTF-8; HTAB allowed; RFC 2047 is applied when rendering (§6.3). */
        public function subject(string $subject): static
        {
            $this->assertUsable();
            self::assertUnstructured($subject, 'subject');
            $this->subject = $subject;
            return $this;
        }

        /** The text/plain body: UTF-8 without NUL; any line ending is normalised to CRLF when rendered. */
        public function text(string $utf8): static
        {
            $this->assertUsable();
            self::assertBody($utf8, 'text');
            $this->text = $utf8;
            return $this;
        }

        /** The text/html body: UTF-8 without NUL; reference embed() parts with the "cid:" URL it returned. */
        public function html(string $utf8): static
        {
            $this->assertUsable();
            self::assertBody($utf8, 'html');
            $this->html = $utf8;
            return $this;
        }

        /**
         * Attaches a file by PATH (never a stream or resource, §6.2). It must be a readable file
         * now; every render re-opens and streams it as it is then, never reading it whole (I12).
         *
         * @param string|null $name the file name shown (default: the path's base name); cleaned (§6.6)
         * @param string|null $type "type/subtype" without parameters; default: finfo, else the
         *                          extension map, else application/octet-stream (§6.6)
         * @throws MessageException field "attachment[i]" (the path), "attachment[i].name", "attachment[i].type"
         */
        public function attach(string $path, ?string $name = null, ?string $type = null): static
        {
            $this->assertUsable();
            $this->attachments[] = self::filePart($path, $name, $type, 'attachment[' . \count($this->attachments) . ']');
            return $this;
        }

        /** Attaches bytes already in memory as the file $name (§6.2). */
        public function attachData(string $bytes, string $name, ?string $type = null): static
        {
            $this->assertUsable();
            $this->attachments[] = self::dataPart($bytes, $name, $type, 'attachment[' . \count($this->attachments) . ']');
            return $this;
        }

        /**
         * Embeds a file by PATH as an inline part of the HTML (multipart/related) and returns its
         * exact "cid:" URL for the HTML, e.g. `<img src="cid:0f3a…@cid.invalid">`. Needs html().
         *
         * @throws MessageException field "attachment[i]" (i counts the embedded parts), as attach()
         * @throws SmtpException    Local, Transient, Prepare, retrySafe, when the RandomSource fails
         */
        public function embed(string $path, ?string $name = null, ?string $type = null): string
        {
            $this->assertUsable();
            $part = self::filePart($path, $name, $type, 'attachment[' . \count($this->inline) . ']');
            return $this->addInline($part);
        }

        /** Embeds bytes already in memory; returns the "cid:" URL (see embed()). */
        public function embedData(string $bytes, string $name, ?string $type = null): string
        {
            $this->assertUsable();
            $part = self::dataPart($bytes, $name, $type, 'attachment[' . \count($this->inline) . ']');
            return $this->addInline($part);
        }

        /**
         * A custom UNSTRUCTURED field (RFC 5322 §3.6.8), RFC 2047-encoded and folded as needed.
         * The name is one field name (§6.2); names the library owns are refused.
         *
         * @throws MessageException field "header.name" or "header.value"
         */
        public function header(string $name, string $value): static
        {
            $this->assertUsable();
            self::assertCustomName($name);
            self::assertUnstructured($value, 'header.value');
            $this->headers[] = ['name' => $name, 'value' => $value, 'raw' => false];
            return $this;
        }

        /**
         * A custom field whose value is already folded ASCII, for structured values the library
         * does not model. The value is %x20-7E and HTAB, with CRLF only directly before SP or HTAB
         * (a fold); no leading or trailing CRLF, no whitespace-only continuation line, every line
         * at most 998 octets (the first counts "Name: "). It is emitted byte for byte.
         *
         * @throws MessageException field "header.name", or "header.value" (injection) for any breach
         */
        public function rawHeader(string $name, string $foldedAscii): static
        {
            $this->assertUsable();
            self::assertCustomName($name);
            $value = $foldedAscii;
            if (\str_starts_with($value, "\r\n")
                || \preg_match('/\A[\t\x20-\x7E]*(?:\r\n[\t ]+[\x21-\x7E][\t\x20-\x7E]*)*\z/', $value) !== 1) {
                throw new MessageException('Invalid header.value: a raw header value is printable ASCII and HTAB, with CRLF'
                    . ' only as a fold (CRLF directly followed by SP or HTAB and more text); no leading or trailing CRLF', 'header.value', true);
            }
            foreach (\explode("\r\n", $value) as $k => $line) {
                if (($k === 0 ? \strlen($name) + 2 : 0) + \strlen($line) > 998) {
                    throw new MessageException("Invalid header.value: line {$k} of the folded value is longer than 998 octets"
                        . ($k === 0 ? ' (counting "Name: ")' : ''), 'header.value', true);
                }
            }
            $this->headers[] = ['name' => $name, 'value' => $value, 'raw' => true];
            return $this;
        }

        /**
         * List-Unsubscribe (RFC 2369): absolute https:, http: or mailto: URIs of printable ASCII
         * without "<", ">", '"' and ",". With $oneClick (RFC 8058) also List-Unsubscribe-Post,
         * which needs an https: URI; DKIM must then sign both fields (§7.4).
         *
         * @param list<string> $uris
         * @throws MessageException field "listUnsubscribe[i]" or "listUnsubscribe"
         */
        public function listUnsubscribe(array $uris, bool $oneClick = false): static
        {
            $this->assertUsable();
            if ($uris === []) {
                throw new MessageException('Invalid listUnsubscribe: give at least one URI', 'listUnsubscribe');
            }
            $list = [];
            $https = false;
            foreach (\array_values($uris) as $i => $uri) {
                $field = "listUnsubscribe[{$i}]";
                if (!\is_string($uri)) {
                    throw new MessageException("Invalid {$field}: a URI is a string, got " . \get_debug_type($uri), $field);
                }
                Text::assertClean($uri, $field);
                if (\preg_match('/[<>",]/', $uri) === 1) {
                    throw new MessageException("Invalid {$field}: a URI cannot contain <, >, \" or \",\" (RFC 2369)", $field, true);
                }
                if (\preg_match('/\A(?:(?i:https?):\/\/[^\/?#\x00-\x20\x7F-\xFF][\x21-\x7E]*|(?i:mailto):[\x21-\x7E]+)\z/', $uri) !== 1) {
                    throw new MessageException("Invalid {$field}: \"" . Text::sanitize($uri, 100) . '" is not an absolute'
                        . ' https:, http: or mailto: URI of printable ASCII', $field);
                }
                if (\strlen($uri) > self::MAX_TOKEN - 2) {
                    throw new MessageException("Invalid {$field}: a URI is at most " . (self::MAX_TOKEN - 2) . ' octets', $field);
                }
                $https = $https || \preg_match('/\A(?i:https):/', $uri) === 1;
                $list[] = $uri;
            }
            if ($oneClick && !$https) {
                throw new MessageException('Invalid listUnsubscribe: one-click unsubscribe needs an https: URI (RFC 8058 §3.1)', 'listUnsubscribe');
            }
            $this->listUnsubscribe = $list;
            $this->oneClick = $oneClick;
            return $this;
        }

        /** Auto-Submitted (RFC 3834 §5): "auto-generated", "auto-replied", "no" or another token; lower-cased. */
        public function autoSubmitted(string $value = 'auto-generated'): static
        {
            $this->assertUsable();
            Text::assertClean($value, 'autoSubmitted');
            if (!Encoder::isToken($value)) {
                throw new MessageException('Invalid autoSubmitted: "' . Text::sanitize($value, 60) . '" is not a token (RFC 3834 §5)', 'autoSubmitted');
            }
            $this->autoSubmitted = Text::lower($value);
            return $this;
        }

        /**
         * Fixes the Date field; default: the time of the first render, from the Mailer's Clock.
         *
         * @throws MessageException field "date", for a year outside 1900-9999
         */
        public function date(\DateTimeInterface $d): static
        {
            $this->assertUsable();
            Encoder::date($d);
            $this->date = \DateTimeImmutable::createFromInterface($d);
            return $this;
        }

        /**
         * Fixes the Message-ID (RFC 5322 §3.6.4), with or without its angle brackets.
         *
         * @throws MessageException field "messageId"
         */
        public function messageId(string $id): static
        {
            $this->assertUsable();
            $this->messageId = self::msgId($id, 'messageId');
            return $this;
        }

        /** REPLACES In-Reply-To: msg-ids with or without brackets (field "inReplyTo[i]"). */
        public function inReplyTo(string ...$ids): static
        {
            $this->assertUsable();
            $this->inReplyTo = self::msgIds($ids, 'inReplyTo');
            return $this;
        }

        /** REPLACES References: msg-ids with or without brackets (field "references[i]"). */
        public function references(string ...$ids): static
        {
            $this->assertUsable();
            $this->references = self::msgIds($ids, 'references');
            return $this;
        }

        /**
         * The envelope recipients: To, Cc and Bcc de-duplicated by Address::key() (exact local
         * part, lower-cased domain), first occurrence kept.
         *
         * @return list<Address>
         */
        public function recipients(): array
        {
            $this->assertUsable();
            $out = [];
            foreach ([...$this->to, ...$this->cc, ...$this->bcc] as $address) {
                $out[$address->key()] ??= $address;
            }
            return \array_values($out);
        }

        /**
         * The message as an .eml string, without DKIM: rendered like send() does (a Renderer with
         * no spool directory and a 2 MiB spool), which stamps Date and Message-ID on a first render.
         *
         * @param Clock|null        $clock  default SystemClock
         * @param RandomSource|null $random for the Message-ID and the boundaries; default: the Message's own
         * @throws MessageException field "from" when from() was never called, and other render-time checks
         * @throws SmtpException    Local, Transient, Prepare: an attachment that can no longer be read, a spool failure
         */
        public function toString(?Clock $clock = null, ?RandomSource $random = null): string
        {
            $theClock = $clock;
            $theRandom = $random;
            \settype($clock, 'null');
            \settype($random, 'null');
            $this->assertUsable();
            $renderer = new Renderer($theClock ?? new SystemClock(), $theRandom ?? $this->random, false, null, null, 2097152);
            $rendered = $renderer->render($this);
            try {
                return \implode('', \iterator_to_array($rendered->chunks(), false));
            } finally {
                $rendered->spool()->close();
            }
        }

        /**
         * A clone is a new message (§6.2, D25): Date and Message-ID are cleared, every Content-ID
         * is regenerated from the SHARED RandomSource (so a seeded sequence continues
         * reproducibly), and each "cid:<old>" in the clone's HTML is rewritten to the new one by
         * exact-token replacement. Attachments share only their path strings.
         *
         * @throws SmtpException Local, Transient, Prepare, retrySafe, when the RandomSource fails
         */
        public function __clone()
        {
            if ($this->inert) {
                return;
            }
            $this->date = null;
            $this->messageId = null;
            $rewrite = [];
            foreach ($this->inline as $k => $part) {
                $cid = $this->newCid();
                $rewrite['cid:' . $part['cid']] = 'cid:' . $cid;
                $this->inline[$k]['cid'] = $cid;
            }
            if ($rewrite !== [] && $this->html !== null) {
                $this->html = \strtr($this->html, $rewrite);
            }
        }

        /** A placeholder: a Message may hold an injected RandomSource (§8.4). */
        public function __serialize(): array
        {
            return ['message' => '[not serialisable]'];
        }

        /** Gives an INERT Message: every method throws UsageException (§8.4). */
        public function __unserialize(array $data): void
        {
            $this->inert = true;
        }

        /**
         * The model for the Renderer and the Mailer (reverse path, envelope).
         *
         * @internal
         * @return array{from: ?Address, sender: ?Address, replyTo: list<Address>, to: list<Address>,
         *     cc: list<Address>, bcc: list<Address>, subject: ?string, text: ?string, html: ?string,
         *     attachments: list<array{path: ?string, data: ?string, name: string, type: string}>,
         *     inline: list<array{path: ?string, data: ?string, name: string, type: string, cid: string}>,
         *     headers: list<array{name: string, value: string, raw: bool}>, listUnsubscribe: list<string>,
         *     oneClick: bool, autoSubmitted: ?string, date: ?\DateTimeImmutable, messageId: ?string,
         *     inReplyTo: list<string>, references: list<string>}
         */
        public function snapshot(): array
        {
            $this->assertUsable();
            return [
                'from' => $this->from, 'sender' => $this->sender, 'replyTo' => $this->replyTo,
                'to' => $this->to, 'cc' => $this->cc, 'bcc' => $this->bcc,
                'subject' => $this->subject, 'text' => $this->text, 'html' => $this->html,
                'attachments' => $this->attachments, 'inline' => $this->inline, 'headers' => $this->headers,
                'listUnsubscribe' => $this->listUnsubscribe, 'oneClick' => $this->oneClick, 'autoSubmitted' => $this->autoSubmitted,
                'date' => $this->date, 'messageId' => $this->messageId,
                'inReplyTo' => $this->inReplyTo, 'references' => $this->references,
            ];
        }

        /**
         * Fixes Date and Message-ID after the first render (§6.2), so every later render (another
         * send(), toString()) reuses them; only a clone clears them.
         *
         * @internal
         */
        public function stamp(\DateTimeImmutable $date, string $messageId): void
        {
            $this->assertUsable();
            if (\preg_match(self::MSG_ID, $messageId) !== 1) {
                throw new \LogicException('Message::stamp(): not a bracketed msg-id (a tehsmtp bug)');
            }
            $this->date = $date;
            $this->messageId = $messageId;
        }

        // -------------------------------------------------------------------------------------
        // Validation helpers
        // -------------------------------------------------------------------------------------

        private function assertUsable(): void
        {
            if ($this->inert) {
                throw new UsageException('this Message was unserialized and is inert; build a new Message (§8.4)');
            }
        }

        /**
         * The setter arguments as Addresses. An error names the position in the resulting list:
         * "to[2]" is the first argument of addTo() after two earlier To mailboxes.
         *
         * @param list<string|Address> $mailboxes
         * @return list<Address>
         */
        private static function mailboxes(array $mailboxes, string $field, int $offset = 0): array
        {
            $out = [];
            foreach (\array_values($mailboxes) as $i => $mailbox) {
                $out[] = Address::of($mailbox, $field . '[' . ($offset + $i) . ']');
            }
            return $out;
        }

        /** UTF-8 without CR, LF, NUL, other C0 controls or DEL; HTAB allowed (§6.2). */
        private static function assertUnstructured(string $value, string $field): void
        {
            if (!Text::isUtf8($value)) {
                throw new MessageException("Invalid {$field}: not valid UTF-8 (the API is UTF-8 only, D27)", $field);
            }
            Text::assertClean($value, $field, true);
        }

        private static function assertBody(string $utf8, string $field): void
        {
            if (!Text::isUtf8($utf8)) {
                throw new MessageException("Invalid {$field}: the body is not valid UTF-8 (the API is UTF-8 only, D27)", $field);
            }
            $nul = \strpos($utf8, "\0");
            if ($nul !== false) {
                throw new MessageException("Invalid {$field}: NUL at offset {$nul} (a body cannot carry NUL)", $field, true);
            }
        }

        /** One field name that the library does not own (§6.2). */
        private static function assertCustomName(string $name): void
        {
            Text::assertFieldName($name);
            $lower = Text::lower($name);
            if (\in_array($lower, self::OWNED, true) || \str_starts_with($lower, 'content-')) {
                throw new MessageException("Invalid header.name \"{$name}\": the library owns this field; use its setter"
                    . ' (from, to, subject, listUnsubscribe, ...) or leave it to the library', 'header.name');
            }
        }

        /** A validated msg-id with its angle brackets. */
        private static function msgId(string $id, string $field): string
        {
            Text::assertClean($id, $field);
            $bracketed = \str_starts_with($id, '<') && \str_ends_with($id, '>') ? $id : '<' . $id . '>';
            if (\preg_match(self::MSG_ID, $bracketed) !== 1) {
                throw new MessageException("Invalid {$field}: \"" . Text::sanitize($id, 100) . '" is not a msg-id'
                    . ' (RFC 5322 §3.6.4: id-left@id-right in ASCII, without spaces)', $field);
            }
            if (\strlen($bracketed) > self::MAX_TOKEN) {
                throw new MessageException("Invalid {$field}: a msg-id is at most " . self::MAX_TOKEN . ' octets', $field);
            }
            return $bracketed;
        }

        /**
         * @param list<string> $ids
         * @return list<string>
         */
        private static function msgIds(array $ids, string $field): array
        {
            $out = [];
            foreach (\array_values($ids) as $i => $id) {
                $out[] = self::msgId($id, "{$field}[{$i}]");
            }
            return $out;
        }

        /**
         * A file part: the path checked now (a readable regular file, no stream URL), the name
         * cleaned, the type given or inferred.
         *
         * @return array{path: string, data: null, name: string, type: string}
         */
        private static function filePart(string $path, ?string $name, ?string $type, string $field): array
        {
            Text::assertClean($path, $field);
            if ($path === '' || \preg_match('/\A[A-Za-z][A-Za-z0-9+.\-]+:\/\//', $path) === 1) {
                throw new MessageException("Invalid {$field}: give a file path (not a stream URL such as php:// or"
                    . ' http://); for bytes in memory use attachData() or embedData()', $field);
            }
            $warnings = [];
            try {
                $isFile = Guard::run(static fn () => \is_file($path), $warnings);
                $readable = $isFile && Guard::run(static fn () => \is_readable($path), $warnings);
            } catch (\Throwable $e) {
                $isFile = $readable = false;
                $warnings[] = $e::class . ': ' . $e->getMessage();
            }
            if (!$readable) {
                throw new MessageException("Invalid {$field}: \"" . Text::sanitize($path, 200) . '" is '
                    . ($isFile ? 'not readable' : 'not a file (missing, or a directory)')
                    . ($warnings === [] ? '' : ' (' . Text::sanitize(\implode('; ', $warnings), 200) . ')'), $field);
            }
            $clean = Encoder::filename($name ?? $path, "{$field}.name");
            return [
                'path' => $path,
                'data' => null,
                'name' => $clean,
                'type' => $type === null ? self::inferType($path, $clean) : self::explicitType($type, "{$field}.type"),
            ];
        }

        /** @return array{path: null, data: string, name: string, type: string} */
        private static function dataPart(string $bytes, string $name, ?string $type, string $field): array
        {
            $clean = Encoder::filename($name, "{$field}.name");
            return [
                'path' => null,
                'data' => $bytes,
                'name' => $clean,
                'type' => $type === null ? self::inferType(null, $clean) : self::explicitType($type, "{$field}.type"),
            ];
        }

        /**
         * A caller's MIME type (§6.6): "type/subtype" of RFC 2045 tokens, lower-cased; parameters
         * belong to the library (charset, name), and message/* or multipart/* would need a
         * composite encoding while every part here is base64 (RFC 2046 §5, M42, M50).
         */
        private static function explicitType(string $type, string $field): string
        {
            Text::assertClean($type, $field);
            if (!self::isMimeType($type)) {
                $structural = \preg_match('/[^!#$%&\'*+\-.0-9A-Za-z^_`{|}~\/]/', $type) === 1;
                throw new MessageException("Invalid {$field}: \"" . Text::sanitize($type, 100) . '" must be "type/subtype"'
                    . ' without parameters or quotes (charset and name are set by the library)', $field, $structural);
            }
            $lower = Text::lower($type);
            if (\str_starts_with($lower, 'message/') || \str_starts_with($lower, 'multipart/')) {
                throw new MessageException("Invalid {$field}: {$lower} cannot be attached, since every attachment is base64"
                    . ' and RFC 2046 allows message/* and multipart/* only 7bit, 8bit or binary; omit the type to send'
                    . ' the file as application/octet-stream', $field);
            }
            return $lower;
        }

        /**
         * The inferred type (§6.6): finfo for paths when ext-fileinfo is loaded, else the extension
         * map, else application/octet-stream. An answer that is not "token/token" is discarded; an
         * inferred message/* or multipart/* (finfo says message/rfc822 for many .eml files) becomes
         * application/octet-stream, so such a file goes as a generic base64 file (S8).
         */
        private static function inferType(?string $path, string $name): string
        {
            $candidates = [];
            if ($path !== null && \function_exists('finfo_open')) {
                $flags = \FILEINFO_MIME_TYPE;
                $warnings = [];
                try {
                    $finfo = Guard::run(static fn () => \finfo_open($flags), $warnings);
                    if ($finfo !== false) {
                        $candidates[] = Guard::run(static fn () => \finfo_file($finfo, $path), $warnings);
                    }
                } catch (\Throwable) {
                    // No magic database or an unreadable file: the extension map decides.
                }
            }
            if (\preg_match('/\.([A-Za-z0-9]{1,8})\z/', $name, $m) === 1) {
                $candidates[] = self::TYPES[Text::lower($m[1])] ?? null;
            }
            foreach ($candidates as $candidate) {
                if (!\is_string($candidate) || !self::isMimeType($candidate)) {
                    continue;
                }
                $type = Text::lower($candidate);
                if (\str_starts_with($type, 'message/') || \str_starts_with($type, 'multipart/')) {
                    return 'application/octet-stream';
                }
                if (!\in_array($type, self::UNINFORMATIVE, true)) {
                    return $type;
                }
            }
            return 'application/octet-stream';
        }

        /** "type/subtype", each an RFC 2045 §5.1 token (so no parameters, quotes or spaces). */
        private static function isMimeType(string $type): bool
        {
            $parts = \explode('/', $type);
            return \count($parts) === 2 && Encoder::isToken($parts[0]) && Encoder::isToken($parts[1]);
        }

        /**
         * Stores an inline part under a fresh Content-ID; returns its "cid:" URL.
         *
         * @param array{path: ?string, data: ?string, name: string, type: string} $part
         */
        private function addInline(array $part): string
        {
            $part['cid'] = $this->newCid();
            $this->inline[] = $part;
            return 'cid:' . $part['cid'];
        }

        /** "<32 hex>@cid.invalid" (without brackets) from the Message's RandomSource (§6.2). */
        private function newCid(): string
        {
            try {
                $bytes = $this->random->bytes(16);
            } catch (SmtpException $e) {
                throw $e;   // SystemRandom's failure is already the §8.3 one
            } catch (\Exception $e) {
                throw new SmtpException(
                    'no random bytes for a Content-ID: ' . Text::sanitize($e::class . ': ' . $e->getMessage(), 200),
                    Reason::Local,
                    Outcome::Transient,
                    Stage::Prepare,
                    Scope::Transaction,
                    retrySafe: true,
                    previous: $e,
                );
            }
            if (\strlen($bytes) !== 16) {
                throw new \LogicException('RandomSource::bytes(16) returned ' . \strlen($bytes) . ' bytes');
            }
            return \bin2hex($bytes) . '@cid.invalid';
        }
    }

    /**
     * The byte-level encoders and folders of the message side (§2.6, §3.5, §6.3, §6.6).
     *
     * Every method is static and pure, works on bytes and needs no mbstring, iconv or locale.
     * PHP's own encoders (quoted_printable_encode, mb_encode_mimeheader, iconv_mime_encode,
     * chunk_split) are never used: each gets a trailing-whitespace, prefix or line-length rule
     * wrong, or depends on an extension (M22-M30, G29-G33). The setters validate every input, so
     * bad input reaching this class is a library bug and raises \LogicException; the methods that
     * can raise MessageException or SmtpException say so.
     *
     * @internal
     */
    final class Encoder
    {
        /** RFC 2047 §2: an encoded-word is at most 75 characters. */
        public const WORD_MAX = 75;

        /**
         * The limit for an encoded-word inside a phrase (§6.3): "Reply-To: " (10, the longest
         * address-field prefix) + 64 = 74, so the first word always fits on the first line.
         */
        public const PHRASE_WORD_MAX = 64;

        /** RFC 2047 §2: a line containing an encoded-word is at most 76 characters. */
        private const LINE_EW = 76;

        /** RFC 5322 §2.1.1: lines SHOULD be at most 78 characters ... */
        private const LINE_AIM = 78;

        /** ... and MUST be at most 998 octets, CRLF excluded. */
        private const LINE_MAX = 998;

        /** "=?UTF-8?B?" plus "?=". */
        private const WORD_OVERHEAD = 12;

        /** The shortest word that holds any one code point: a 4-byte sequence in B (8 characters). */
        private const WORD_MIN = 20;

        /** An encoded run starts on the current line only if at least this much room is left on it. */
        private const RUN_ROOM = 24;

        /** RFC 2047 §5(3): the octets a Q word may NOT carry literally (SP is written "_"). */
        private const Q_ENCODED = '/[^A-Za-z0-9!*+\-\/ ]/';

        /** A plain word a decoder could mistake for an encoded-word is encoded itself (checklist 5.12). */
        private const LOOKS_ENCODED = '/=\?.*\?=/s';

        /** A token that is an RFC 2047 encoded-word: the 76-character line rule applies to its line. */
        private const ENCODED_WORD = '/\A=\?[^?\x00-\x20\x7F]+\?[BbQq]\?[^?\x00-\x20\x7F]*\?=\z/';

        /**
         * Extended_Pictographic, approximately (emoji-data.txt), without the regional indicators
         * and the skin-tone modifiers, which never start a cluster of their own.
         */
        private const PICTOGRAPH = '[\x{00A9}\x{00AE}\x{203C}\x{2049}\x{2122}\x{2139}\x{2194}-\x{2199}\x{21A9}\x{21AA}'
            . '\x{231A}-\x{23FF}\x{24C2}\x{25AA}-\x{25FE}\x{2600}-\x{27BF}\x{2934}\x{2935}\x{2B05}-\x{2B55}\x{3030}'
            . '\x{303D}\x{3297}\x{3299}\x{1F000}-\x{1F1E5}\x{1F200}-\x{1F3FA}\x{1F400}-\x{1FAFF}\x{1FC00}-\x{1FFFD}]';

        /** QP units: one UTF-8 sequence, so a soft break never splits a character (checklist 7.14), or one byte. */
        private const QP_UNITS = '/[\xC2-\xDF][\x80-\xBF]|[\xE0-\xEF][\x80-\xBF]{2}|[\xF0-\xF4][\x80-\xBF]{3}|[\x00-\xFF]/';

        /** base64 input block: 57 × 1024 bytes, exactly 1024 lines of 76 characters (§6.6). */
        private const B64_BLOCK = 58368;

        /** Empty reads without end of file tolerated in a row before a stream counts as stuck. */
        private const MAX_EMPTY_READS = 100;

        // -------------------------------------------------------------------------------------
        // Transfer encodings (§6.6)
        // -------------------------------------------------------------------------------------

        /**
         * Quoted-printable (RFC 2045 §6.7) of CRLF-normalised text; the Renderer normalises first.
         *
         * Only CRLF is a hard line break; any other CR or LF byte is encoded. `=XX` uses upper-case
         * hex, `=` is always `=3D`, octets 33-60 and 62-126 are literal; SP and TAB are literal except
         * at the end of a line (before a hard break or at the end of the input), where they become
         * `=20`/`=09`. Every line is at most 76 characters including the soft-break `=`. A soft break
         * never splits an `=XX` triplet or a UTF-8 sequence. A line that would start with `.` starts
         * with `=2E`, one that would start with `From ` with `=46rom ` (RFC 2049 §3), after hard and
         * soft breaks alike. No CRLF is added at the end. Since `=` only ever starts `=XX` or a soft
         * break, the output never contains `=_`, the boundary prefix (§6.5).
         */
        public static function qp(string $text): string
        {
            $lines = [];
            foreach (\explode("\r\n", $text) as $line) {
                $lines[] = self::qpLine($line);
            }
            return \implode("\r\n", $lines);
        }

        /**
         * base64 (RFC 2045 §6.8) of bytes in memory: lines of exactly 76 characters (the last one
         * shorter), CRLF between lines and none after the last; '' gives ''.
         */
        public static function base64(string $bytes): string
        {
            // The base64 alphabet has no whitespace, so wordwrap() with cut breaks every 76
            // characters and adds nothing after the last line (M44: chunk_split appends a CRLF);
            // unlike str_split() it builds no array of lines (about 5 times the input in memory).
            return \wordwrap(\base64_encode($bytes), 76, "\r\n", true);
        }

        /**
         * Streams base64 from $in to $out with the same output as base64() of the whole input, so
         * an attachment is never read whole (§6.6, I12). Reads ask for 57 × 1024 bytes; a carry
         * buffer absorbs short and empty reads, so the output does not depend on how the stream
         * delivers the bytes. Nothing is written for an empty input.
         *
         * @param resource $in  a readable stream, e.g. fopen($path, 'rb'), read from its current position
         * @param mixed    $out a writable stream resource, or an object with a public
         *                      write(string $bytes): void method (a Spool, or the Renderer's tee)
         * @return int the number of bytes written to $out
         * @throws SmtpException Local, Transient, Prepare, retrySafe (§6.8), when reading or writing
         *                       the streams fails; nothing has been sent at that point
         */
        public static function base64Stream($in, $out): int
        {
            if (!\is_resource($in)) {
                throw new \LogicException('Encoder::base64Stream(): needs an open input stream');
            }
            if (!\is_resource($out) && !(\is_object($out) && \method_exists($out, 'write'))) {
                throw new \LogicException('Encoder::base64Stream(): needs an output stream or an object with write()');
            }
            $carry = '';
            $written = 0;
            $emptyReads = 0;
            while (($chunk = self::readChunk($in)) !== null) {
                if ($chunk === '') {
                    if (++$emptyReads > self::MAX_EMPTY_READS) {
                        throw self::localFailure('reading the attachment stalled: repeated empty reads before the end of the file');
                    }
                    continue;
                }
                $emptyReads = 0;
                $carry .= $chunk;
                if (\strlen($carry) >= self::B64_BLOCK) {
                    $whole = \strlen($carry) - \strlen($carry) % 57;   // whole 57-byte lines only
                    $written += self::emit($out, ($written > 0 ? "\r\n" : '') . self::base64(\substr($carry, 0, $whole)));
                    $carry = \substr($carry, $whole);
                }
            }
            if ($carry !== '') {
                $written += self::emit($out, ($written > 0 ? "\r\n" : '') . self::base64($carry));
            }
            return $written;
        }

        // -------------------------------------------------------------------------------------
        // RFC 2047 encoded-words and header folding (§6.3)
        // -------------------------------------------------------------------------------------

        /**
         * Encodes UTF-8 text as RFC 2047 encoded-words (charset UTF-8), to be separated by FWS.
         *
         * The text is split at grapheme clusters (\X, see clusters()), so combining marks and ZWJ
         * emoji stay in one word; only a cluster too long for a word on its own is split at code
         * points. Each word is independently decodable and at most $maxWord characters: B (its own
         * padded base64) or Q, whichever is shorter (B on a tie). Q always uses the restricted
         * phrase set of RFC 2047 §5(3) (A-Z a-z 0-9 ! * + - / literal, SP as "_", everything else
         * `=XX`), so every word is valid in unstructured text and in phrases alike (checklist 5.8).
         * Whitespace inside $utf8 is encoded inside the words, since whitespace between adjacent
         * encoded-words is not displayed (RFC 2047 §6.2).
         *
         * The first word is also at most 76 - $firstLineUsed characters, so it fits on a line of
         * which $firstLineUsed characters are taken (e.g. strlen("Subject: ")). When the first
         * cluster does not fit in that room, the first word is sized like the others instead and
         * the caller must fold before it.
         *
         * @param int  $maxWord 75 in unstructured fields; phrases use 64 (§6.3)
         * @param bool $phrase  the words form a display-name phrase: each is then at most
         *                      PHRASE_WORD_MAX characters (39 octets in B) whatever $maxWord says
         * @return list<string> [] for ''
         */
        public static function encodedWords(string $utf8, int $firstLineUsed = 0, int $maxWord = self::WORD_MAX, bool $phrase = false): array
        {
            if (!Text::isUtf8($utf8)) {
                throw new \LogicException('Encoder::encodedWords(): the text is not valid UTF-8');
            }
            if ($maxWord < self::WORD_MIN || $maxWord > self::WORD_MAX || $firstLineUsed < 0) {
                throw new \LogicException("Encoder::encodedWords(): bad budget (maxWord {$maxWord}, firstLineUsed {$firstLineUsed})");
            }
            if ($phrase) {
                $maxWord = \min($maxWord, self::PHRASE_WORD_MAX);
            }
            if ($utf8 === '') {
                return [];
            }
            // Units: grapheme clusters, or the code points of a cluster that no word could hold.
            $units = [];
            foreach (self::clusters($utf8) as $cluster) {
                if (self::wordLength(\strlen($cluster), self::qLength($cluster)) <= $maxWord) {
                    $units[] = $cluster;
                } else {
                    \preg_match_all('/./su', $cluster, $codePoints);
                    \array_push($units, ...$codePoints[0]);
                }
            }
            $budget = \min($maxWord, self::LINE_EW - $firstLineUsed);
            if (self::wordLength(\strlen($units[0]), self::qLength($units[0])) > $budget) {
                $budget = $maxWord;
            }
            // Greedy fill: min(B, Q) never shrinks when a unit is added, so a word is closed
            // exactly when the next unit would push the shorter encoding over the budget.
            $words = [];
            $current = '';
            $q = 0;
            foreach ($units as $unit) {
                $unitQ = self::qLength($unit);
                if ($current !== '' && self::wordLength(\strlen($current) + \strlen($unit), $q + $unitQ) > $budget) {
                    $words[] = self::encodedWord($current, $q);
                    [$current, $q, $budget] = ['', 0, $maxWord];
                }
                $current .= $unit;
                $q += $unitQ;
            }
            $words[] = self::encodedWord($current, $q);
            return $words;
        }

        /**
         * One unstructured field (Subject, custom headers; RFC 5322 §3.6.5, §3.6.8), folded and
         * ending in CRLF: "Name: value\r\n".
         *
         * Words are encoded only when needed (§6.3): non-ASCII, looking like `=?…?=`, or too long
         * for a line of 998 octets. Adjacent words that need encoding form one run, the whitespace
         * between them encoded inside the words; whitespace next to plain text stays literal. A
         * fold inserts CRLF before existing whitespace only (or between the encoded-words of a run,
         * where RFC 2047 §6.2 makes whitespace invisible) and never produces a whitespace-only
         * line, so unfolding and decoding give back the value exactly. Any line containing an
         * encoded-word is at most 76 characters, "Name: " included; other lines aim for 78; none
         * exceeds 998. Every valid value can be folded this way, so no MessageException arises.
         *
         * @param string $value UTF-8 without CR, LF or other controls except HTAB (validated by the setter)
         */
        public static function foldUnstructured(string $name, string $value): string
        {
            self::assertName($name);
            if (!Text::isUtf8($value) || \preg_match('/[\x00-\x08\x0A-\x1F\x7F]/', $value) === 1) {
                throw new \LogicException('Encoder::foldUnstructured(): the value must be UTF-8 without controls other than HTAB');
            }
            if ($value === '') {
                return "{$name}:\r\n";
            }
            if (\preg_match('/\A[\t ]+\z/', $value) === 1) {
                // Whitespace only. It must stay on the first line (a continuation line may not be
                // whitespace-only), so when it is too long for one line it is encoded.
                if (\strlen($name) + 2 + \strlen($value) <= self::LINE_MAX) {
                    return "{$name}: {$value}\r\n";
                }
                $atoms = [[' ', $value, true]];
            } else {
                $atoms = self::unstructuredAtoms($value);
            }
            $lines = [];   // list<array{string, bool $hasEncodedWord}>
            $line = "{$name}:";
            $hasWord = false;
            $first = true;
            foreach ($atoms as [$separator, $text, $encode]) {
                if (!$encode) {
                    // A plain first word stays after "Name:" when its own line would not be within 78 either.
                    $stay = $first && \strlen($separator) + \strlen($text) > self::LINE_AIM;
                    self::append($lines, $line, $hasWord, $separator, $text, false, $stay);
                    $first = false;
                    continue;
                }
                $first = false;
                $used = \strlen($line) + \strlen($separator);
                $words = self::LINE_EW - $used >= self::RUN_ROOM ? self::encodedWords($text, $used) : [];
                if ($words === [] || \strlen($words[0]) > self::LINE_EW - $used) {
                    // Start the run on a fresh line. A separator too long to share a 76-character line
                    // with the first word keeps one whitespace character and the rest moves into the
                    // run: whitespace before an encoded-word and inside it display the same.
                    $lines[] = [$line, $hasWord];
                    $words = self::encodedWords($text, \strlen($separator));
                    if (\strlen($separator) + \strlen($words[0]) > self::LINE_EW) {
                        $words = self::encodedWords(\substr($separator, 1) . $text, 1);
                        $separator = $separator[0];
                    }
                    $line = '';
                }
                $line .= $separator . $words[0];
                $hasWord = true;
                foreach (\array_slice($words, 1) as $word) {
                    self::append($lines, $line, $hasWord, ' ', $word, true);
                }
            }
            $lines[] = [$line, $hasWord];
            return self::finishField($lines);
        }

        /**
         * One structured field built from tokens (§6.3), folded and ending in CRLF.
         *
         * $items is a list of items, each a list of tokens: a mailbox's Address::headerTokens()
         * (phrase words or encoded-words, then "<addr-spec>"), one msg-id, or one MIME parameter.
         * Tokens are joined with SP, items with "$separator " ("," for address lists, ";" for MIME
         * parameters, "" for msg-id lists, which are separated by whitespace only). Lines are filled
         * greedily; the only fold points are the SP after an item's separator and the SP between two
         * tokens of an item, so no token (addr-spec, encoded-word, quoted-string, msg-id, parameter)
         * is ever split. An item that does not fit on the current line but fits on a fresh one moves
         * there whole, so a mailbox is folded inside only when it is longer than a line. A line
         * containing an encoded-word is at most 76 characters, "Name: " included, so the folder
         * breaks before any token that would push it over, an <addr-spec> after an encoded-word
         * included; other lines aim for 78.
         *
         * @param list<list<string>> $items     non-empty; tokens are non-empty, without CR, LF or
         *                                      controls other than HTAB, without surrounding
         *                                      whitespace, and encoded-words are at most 75 characters
         * @param string             $separator ",", ";" or ""
         * @throws MessageException field: the lower-cased name, when a token (with its separator)
         *                          is longer than 997 octets and so fits on no line
         */
        public static function foldStructured(string $name, array $items, string $separator = ','): string
        {
            self::assertName($name);
            if ($items === [] || !\in_array($separator, [',', ';', ''], true)) {
                throw new \LogicException('Encoder::foldStructured(): needs at least one item and a separator of ",", ";" or ""');
            }
            $lines = [];   // list<array{string, bool $hasEncodedWord}>
            $line = "{$name}:";
            $hasWord = false;
            $lastItem = \count($items) - 1;
            foreach (\array_values($items) as $index => $tokens) {
                [$tokens, $encoded] = self::itemTokens($name, $tokens, $index < $lastItem ? $separator : '');
                $item = \implode(' ', $tokens);
                $itemHasWord = \in_array(true, $encoded, true);
                if ($index > 0
                    && \strlen($line) + 1 + \strlen($item) > ($hasWord || $itemHasWord ? self::LINE_EW : self::LINE_AIM)
                    && 1 + \strlen($item) <= ($itemHasWord ? self::LINE_EW : self::LINE_AIM)) {
                    $lines[] = [$line, $hasWord];   // fold after the previous item's separator (checklist 1.7)
                    $line = ' ' . $item;
                    $hasWord = $itemHasWord;
                    continue;
                }
                foreach ($tokens as $i => $token) {
                    // A plain first token stays after "Name:" when its own line would not be within 78 either.
                    $stay = $index === 0 && $i === 0 && 1 + \strlen($token) > self::LINE_AIM;
                    self::append($lines, $line, $hasWord, ' ', $token, $encoded[$i], $stay);
                }
            }
            $lines[] = [$line, $hasWord];
            return self::finishField($lines);
        }

        // -------------------------------------------------------------------------------------
        // MIME parameters and file names (§6.6; RFC 2045 §5.1, RFC 2183, RFC 2231)
        // -------------------------------------------------------------------------------------

        /**
         * One MIME parameter as items of a Content-Type or Content-Disposition field (for
         * foldStructured(..., ';')), without the "; " separators.
         *
         * An ASCII value is one `attr=token`, or `attr="quoted-string"` when it is not a token
         * (RFC 2045 §5.1; a boundary contains "=", so it is always quoted). A non-ASCII value gives
         * an ASCII fallback `attr="…"`, always quoted (each non-ASCII code point replaced by "_"), for
         * readers without RFC 2231 (which take the extended form when present), followed by the
         * RFC 2231 extended form: `attr*=utf-8''<percent-encoded>` when that fits on one line, else
         * the continuations `attr*0*=utf-8''…`, `attr*1*=…`, numbered from 0 without gaps. The
         * charset appears only in section 0; sections end on a code point boundary, never inside a
         * `%XX`, and each fits on a line of at most 78 characters with its leading SP and trailing
         * ";". Extended values are never quoted, and RFC 2047 is never used in parameters. The
         * percent-encoding is rawurlencode(): upper-case hex, and its unreserved characters
         * (A-Z a-z 0-9 - . _ ~) are all RFC 2231 attribute-chars.
         *
         * @param string $attribute an RFC 2045 token without "*", "'" or "%"
         * @param string $value     UTF-8 without controls (validated by the caller)
         * @return list<string>
         */
        public static function param(string $attribute, string $value): array
        {
            if (!self::isToken($attribute) || \strpbrk($attribute, "*'%") !== false) {
                throw new \LogicException('Encoder::param(): bad attribute ' . Text::sanitize($attribute, 80));
            }
            if (!Text::isUtf8($value) || \preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
                throw new \LogicException("Encoder::param(): the {$attribute} value must be UTF-8 without controls");
            }
            if (\preg_match('/[\x80-\xFF]/', $value) !== 1) {
                return [$attribute . '=' . (self::isToken($value) ? $value : self::quoteString($value))];
            }
            $params = [$attribute . '=' . self::quoteString((string) \preg_replace('/[^\x00-\x7F]/u', '_', $value))];
            \preg_match_all('/./su', $value, $chars);
            $encoded = \array_map('rawurlencode', $chars[0]);
            $room = self::LINE_AIM - 2;   // the leading SP and the trailing ";"
            $single = "{$attribute}*=utf-8''" . \implode('', $encoded);
            if (\strlen($single) <= $room) {
                $params[] = $single;
                return $params;
            }
            $section = "{$attribute}*0*=utf-8''";
            $index = 0;
            $fresh = true;
            foreach ($encoded as $piece) {
                if (!$fresh && \strlen($section) + \strlen($piece) > $room) {
                    $params[] = $section;
                    $index++;
                    $section = "{$attribute}*{$index}*=";
                }
                $section .= $piece;
                $fresh = false;
            }
            $params[] = $section;
            return $params;
        }

        /**
         * Cleans a caller-given attachment or inline file name (§6.6; RFC 2183 §2.3, §5): directory
         * parts (up to the last "/" or "\") are stripped, SP is trimmed, and a name over 255 octets
         * is cut at a grapheme boundary, keeping an extension of up to 32 octets.
         *
         * @param string $field for the exception, e.g. "attachment[2].name"
         * @throws MessageException invalid UTF-8; a control character (C0, DEL or C1; injection:
         *                          true); nothing left after cleaning
         */
        public static function filename(string $name, string $field): string
        {
            if (!Text::isUtf8($name)) {
                throw new MessageException("Invalid {$field}: the file name is not valid UTF-8", $field);
            }
            Text::assertClean($name, $field);
            if (\preg_match('/[\x{80}-\x{9F}]/u', $name) === 1) {
                throw new MessageException("Invalid {$field}: the file name contains a C1 control character", $field, true);
            }
            $base = \trim((string) \preg_replace('~\A.*[/\\\\]~s', '', $name), ' ');
            if ($base === '' || $base === '.' || $base === '..') {
                throw new MessageException("Invalid {$field}: the file name is empty after removing directory parts", $field);
            }
            if (\strlen($base) <= 255) {
                return $base;
            }
            $extension = \preg_match('/\.[^.]{1,32}\z/', $base, $m) === 1 ? $m[0] : '';
            $kept = '';
            foreach (self::clusters(\substr($base, 0, \strlen($base) - \strlen($extension))) as $cluster) {
                if (\strlen($kept) + \strlen($cluster) + \strlen($extension) > 255) {
                    break;
                }
                $kept .= $cluster;
            }
            return $kept . $extension;
        }

        /** An RFC 2045 §5.1 token: 1*(any CHAR except SP, CTLs and tspecials `()<>@,;:\"/[]?=`). */
        public static function isToken(string $s): bool
        {
            return \preg_match('/\A[!#$%&\'*+\-.0-9A-Z^_`a-z{|}~]+\z/', $s) === 1;
        }

        /**
         * An RFC 5322 §3.2.4 / RFC 2045 quoted-string: only "\" and '"' are escaped (as quoted-pairs);
         * SP, HTAB and every other character stay literal (checklist 1.13).
         */
        public static function quoteString(string $s): string
        {
            if (\preg_match('/[\x00-\x08\x0A-\x1F\x7F]/', $s) === 1) {
                throw new \LogicException('Encoder::quoteString(): controls other than HTAB cannot be quoted');
            }
            return '"' . \addcslashes($s, '\\"') . '"';
        }

        // -------------------------------------------------------------------------------------
        // ESMTP parameter encodings (§3.5)
        // -------------------------------------------------------------------------------------

        /**
         * RFC 3461 §4 xtext: "!" to "~" except "+" and "=" are literal; every other octet becomes
         * "+HH" with upper-case hex.
         */
        public static function xtext(string $s): string
        {
            return (string) \preg_replace_callback(
                '/[^\x21-\x2A\x2C-\x3C\x3E-\x7E]/',
                static fn (array $m): string => \sprintf('+%02X', \ord($m[0])),
                $s,
            );
        }

        /**
         * RFC 6533 §3 utf-8-addr-xtext of a UTF-8 mailbox, the 7-bit ORCPT form valid with or
         * without SMTPUTF8: QCHARs (printable ASCII except SP, "+", "=" and "\") are literal; every
         * other code point becomes "\x{HEX}", upper-case hex without leading zeros beyond two digits.
         * The output is also valid RFC 3461 xtext.
         *
         * @throws \LogicException for '', invalid UTF-8, or a control character the ABNF cannot express
         */
        public static function utf8AddrXtext(string $address): string
        {
            if ($address === '' || !Text::isUtf8($address)) {
                throw new \LogicException('Encoder::utf8AddrXtext(): the address must be non-empty UTF-8');
            }
            $out = '';
            foreach (self::codePoints($address) as $cp) {
                if ($cp >= 0x21 && $cp <= 0x7E && $cp !== 0x2B && $cp !== 0x3D && $cp !== 0x5C) {
                    $out .= \chr($cp);
                    continue;
                }
                // HEXPOINT's 2-digit forms below 0x20 are 01-09, 10 and 11-19 only.
                if ($cp < 0x20 && ($cp === 0 || ($cp & 0x0F) > 9)) {
                    throw new \LogicException(\sprintf('Encoder::utf8AddrXtext(): U+%04X has no RFC 6533 encoding', $cp));
                }
                $out .= \sprintf('\x{%02X}', $cp);
            }
            return $out;
        }

        // -------------------------------------------------------------------------------------
        // Date (RFC 5322 §3.3)
        // -------------------------------------------------------------------------------------

        /**
         * The Date field value: `D, d M Y H:i:s O` with English names whatever the locale (format()
         * is locale-independent; never strftime) and a numeric zone, or +0000 with $utc (§6.2). A
         * zone offset with seconds cannot be written, so such a time is given in UTC.
         *
         * @throws MessageException field "date", for a year outside 1900-9999 (RFC 5322 §3.3)
         */
        public static function date(\DateTimeInterface $d, bool $utc = false): string
        {
            $d = \DateTimeImmutable::createFromInterface($d);
            if ($utc || $d->getOffset() % 60 !== 0) {
                $d = $d->setTimezone(new \DateTimeZone('UTC'));
            }
            $year = (int) $d->format('Y');
            if ($year < 1900 || $year > 9999) {
                throw new MessageException("Invalid date: the year must be 1900-9999 (RFC 5322 §3.3), got {$year}", 'date');
            }
            return $d->format('D, d M Y H:i:s O');
        }

        /**
         * The code points of valid UTF-8, decoded without mbstring.
         *
         * @return list<int>
         */
        public static function codePoints(string $utf8): array
        {
            if (!Text::isUtf8($utf8)) {
                throw new \LogicException('Encoder::codePoints(): not valid UTF-8');
            }
            \preg_match_all('/./su', $utf8, $m);
            $points = [];
            foreach ($m[0] as $c) {
                $b = \ord($c[0]);
                $points[] = match (\strlen($c)) {
                    1 => $b,
                    2 => ($b & 0x1F) << 6 | \ord($c[1]) & 0x3F,
                    3 => ($b & 0x0F) << 12 | (\ord($c[1]) & 0x3F) << 6 | \ord($c[2]) & 0x3F,
                    default => ($b & 0x07) << 18 | (\ord($c[1]) & 0x3F) << 12 | (\ord($c[2]) & 0x3F) << 6 | \ord($c[3]) & 0x3F,
                };
            }
            return $points;
        }

        // -------------------------------------------------------------------------------------
        // Private helpers
        // -------------------------------------------------------------------------------------

        /**
         * The extended grapheme clusters of valid UTF-8: PCRE's \X, with one correction. PCRE2 up
         * to at least 10.42 (bundled with PHP 8.3) keeps any run of pictographs in one match
         * ("😀😀" is one \X), so a match is also split before a pictograph that does not follow a
         * ZWJ (GB11 joins pictographs only across a ZWJ). With a PCRE that segments emoji
         * correctly this changes nothing.
         *
         * @return list<string>
         */
        private static function clusters(string $utf8): array
        {
            \preg_match_all('/\X/u', $utf8, $m);
            $clusters = [];
            foreach ($m[0] as $match) {
                foreach (\preg_split('/(?<!\x{200D})(?=' . self::PICTOGRAPH . ')/u', $match, -1, \PREG_SPLIT_NO_EMPTY) as $cluster) {
                    $clusters[] = $cluster;
                }
            }
            return $clusters;
        }

        /**
         * The atoms of an unstructured value with at least one word (RFC 5322 §3.2.5:
         * *([FWS] VCHAR) *WSP): each word (a run of non-whitespace) with the whitespace before it,
         * the SP after "Name:" being the first separator. Trailing whitespace joins the last word,
         * since it can never start a line. Words that need encoding (non-ASCII, looking encoded,
         * or too long for a line even alone) are merged with their neighbours that need it into
         * runs that hold the whitespace between them.
         *
         * @return list<array{string $separator, string $text, bool $encode}>
         */
        private static function unstructuredAtoms(string $value): array
        {
            // word, whitespace, word, ..., word; the first word is '' when the value starts with
            // whitespace, the last one '' when it ends with whitespace.
            $parts = \preg_split('/([\t ]+)/', $value, -1, \PREG_SPLIT_DELIM_CAPTURE);
            $trailing = '';
            if (\end($parts) === '') {
                \array_pop($parts);
                $trailing = \array_pop($parts);
            }
            $separator = ' ';
            if ($parts[0] === '') {
                \array_shift($parts);
                $separator .= \array_shift($parts);
            }
            $atoms = [];
            for ($i = 0, $n = \count($parts); $i < $n; $i += 2) {
                $word = $parts[$i] . ($i === $n - 1 ? $trailing : '');
                $encode = \preg_match('/[\x80-\xFF]/', $word) === 1 || \preg_match(self::LOOKS_ENCODED, $word) === 1
                    || \strlen($separator) + \strlen($word) > self::LINE_MAX;
                $last = \array_key_last($atoms);
                if ($encode && $last !== null && $atoms[$last][2]) {
                    $atoms[$last][1] .= $separator . $word;   // extend the run, whitespace included
                } else {
                    $atoms[] = [$separator, $word, $encode];
                }
                $separator = $parts[$i + 1] ?? '';
            }
            return $atoms;
        }

        /**
         * Validates the tokens of one foldStructured() item and appends the item separator to its
         * last token.
         *
         * @return array{list<string>, list<bool>} the tokens, and which of them are encoded-words
         * @throws MessageException when a token cannot fit on any line
         */
        private static function itemTokens(string $name, mixed $tokens, string $separator): array
        {
            if (!\is_array($tokens) || $tokens === []) {
                throw new \LogicException('Encoder::foldStructured(): every item is a non-empty list of tokens');
            }
            $tokens = \array_values($tokens);
            $encoded = [];
            foreach ($tokens as $token) {
                if (!\is_string($token) || \preg_match('/\A[^\x00-\x20\x7F](?:[^\x00-\x08\x0A-\x1F\x7F]*[^\x00-\x20\x7F])?\z/', $token) !== 1) {
                    throw new \LogicException('Encoder::foldStructured(): bad token ' . Text::sanitize(\is_string($token) ? $token : \get_debug_type($token), 80));
                }
                $isWord = \preg_match(self::ENCODED_WORD, $token) === 1;
                if ($isWord && \strlen($token) > self::WORD_MAX) {
                    throw new \LogicException('Encoder::foldStructured(): an encoded-word longer than 75 characters');
                }
                $encoded[] = $isWord;
            }
            if ($separator !== '') {
                if (\end($encoded)) {
                    // RFC 2047 §5(3): an encoded-word needs whitespace before a special.
                    throw new \LogicException('Encoder::foldStructured(): an item must not end in an encoded-word');
                }
                $tokens[\array_key_last($tokens)] .= $separator;
            }
            foreach ($tokens as $token) {
                if (\strlen($token) > self::LINE_MAX - 1) {
                    $field = Text::lower($name);
                    throw new MessageException(
                        "Invalid {$field}: a token of " . \strlen($token) . ' octets cannot be folded into lines of at most 998 octets',
                        $field,
                    );
                }
            }
            return [$tokens, $encoded];
        }

        /**
         * Appends $separator . $atom to the current line, or folds before $separator when the line
         * would get too long: 76 when the line holds an encoded-word or $atom is one, else 78.
         * $stay keeps a plain atom on the current line anyway, up to 998 octets.
         *
         * @param list<array{string, bool}> $lines   the finished lines
         * @param bool                      $hasWord whether $line holds an encoded-word
         */
        private static function append(array &$lines, string &$line, bool &$hasWord, string $separator, string $atom, bool $encoded, bool $stay = false): void
        {
            $length = \strlen($line) + \strlen($separator) + \strlen($atom);
            if ($length > ($hasWord || $encoded ? self::LINE_EW : self::LINE_AIM) && !($stay && !$encoded && $length <= self::LINE_MAX)) {
                $lines[] = [$line, $hasWord];
                $line = '';
                $hasWord = false;
            }
            $line .= $separator . $atom;
            $hasWord = $hasWord || $encoded;
        }

        /**
         * Joins folded lines into one field ending in CRLF, after checking the limits the folders
         * guarantee: every line at most 998 octets, and at most 76 when it holds an encoded-word.
         *
         * @param list<array{string, bool}> $lines each line and whether it holds an encoded-word
         */
        private static function finishField(array $lines): string
        {
            $out = [];
            foreach ($lines as [$line, $hasWord]) {
                if (\strlen($line) > ($hasWord ? self::LINE_EW : self::LINE_MAX)) {
                    throw new \LogicException('Encoder: a folded line of ' . \strlen($line) . ' octets breaks the §6.3 limits');
                }
                $out[] = $line;
            }
            return \implode("\r\n", $out) . "\r\n";
        }

        /** One CRLF-free line of qp(). */
        private static function qpLine(string $line): string
        {
            // Fast path: short, literal, not starting with "." or "From ", not ending in whitespace.
            if (\strlen($line) <= 76 && \preg_match('/\A(?!\.|From )[\t\x20-\x3C\x3E-\x7E]*(?<![\t ])\z/', $line) === 1) {
                return $line;
            }
            \preg_match_all(self::QP_UNITS, $line, $m);
            $units = $m[0];
            $last = \count($units) - 1;
            $out = '';
            $current = '';
            foreach ($units as $i => $unit) {
                $encoded = '';
                foreach (\str_split($unit) as $byte) {
                    $o = \ord($byte);
                    $literal = ($o >= 33 && $o <= 126 && $o !== 61) || (($o === 32 || $o === 9) && $i !== $last);
                    $encoded .= $literal ? $byte : \sprintf('=%02X', $o);
                }
                // A soft break leaves room for its "="; the last unit of the line needs none.
                if ($current !== '' && \strlen($current) + \strlen($encoded) > ($i === $last ? 76 : 75)) {
                    $out .= $current . "=\r\n";
                    $current = '';
                }
                if ($current === '') {   // the start of a physical line (RFC 2049 §3)
                    if ($unit === '.') {
                        $encoded = '=2E';
                    } elseif ($unit === 'F' && \implode('', \array_slice($units, $i, 5)) === 'From ') {
                        $encoded = '=46';
                    }
                }
                $current .= $encoded;
            }
            return $out . $current;
        }

        /**
         * The next chunk of $in: bytes, '' for an empty read, or null at the end of the file.
         *
         * @param resource $in
         */
        private static function readChunk($in): ?string
        {
            $warnings = [];
            $length = self::B64_BLOCK;
            try {
                $chunk = Guard::run(static fn () => \fread($in, $length), $warnings);
                if ($chunk === '' && Guard::run(static fn () => \feof($in), $warnings)) {
                    return null;
                }
            } catch (\Throwable $e) {
                throw self::localFailure('reading the attachment failed: ' . \get_class($e) . ': ' . $e->getMessage(), $warnings);
            }
            if (!\is_string($chunk)) {
                throw self::localFailure('reading the attachment failed', $warnings);
            }
            return $chunk;
        }

        /**
         * Writes $bytes to a stream or a write() sink; returns strlen($bytes).
         *
         * @param resource|object $out
         */
        private static function emit($out, string $bytes): int
        {
            if (\is_object($out)) {
                $out->write($bytes);
                return \strlen($bytes);
            }
            $warnings = [];
            try {
                $n = Guard::run(static fn () => \fwrite($out, $bytes), $warnings);
            } catch (\Throwable $e) {
                throw self::localFailure('writing the render spool failed: ' . \get_class($e) . ': ' . $e->getMessage(), $warnings);
            }
            if ($n !== \strlen($bytes)) {
                throw self::localFailure('writing the render spool failed (disk full?)', $warnings);
            }
            return $n;
        }

        /** The §6.8 failure: nothing was sent, so the send may be retried later. */
        private static function localFailure(string $message, array $warnings = []): SmtpException
        {
            return new SmtpException(
                Text::sanitize($message, 200),
                Reason::Local,
                Outcome::Transient,
                Stage::Prepare,
                Scope::Transaction,
                retrySafe: true,
                details: $warnings === [] ? [] : ['phpWarnings' => $warnings],
            );
        }

        /** The length of the encoded-word for $bytes octets whose Q text is $q characters. */
        private static function wordLength(int $bytes, int $q): int
        {
            return self::WORD_OVERHEAD + \min(4 * \intdiv($bytes + 2, 3), $q);
        }

        /** The length of the Q-encoded text of $bytes. */
        private static function qLength(string $bytes): int
        {
            return \strlen($bytes) + 2 * (int) \preg_match_all(self::Q_ENCODED, $bytes);
        }

        /** One encoded-word of $bytes, whose Q text is $q characters: B unless Q is shorter. */
        private static function encodedWord(string $bytes, int $q): string
        {
            if ($q < 4 * \intdiv(\strlen($bytes) + 2, 3)) {
                $text = (string) \preg_replace_callback(self::Q_ENCODED, static fn (array $m): string => \sprintf('=%02X', \ord($m[0])), $bytes);
                return '=?UTF-8?Q?' . \strtr($text, ' ', '_') . '?=';
            }
            return '=?UTF-8?B?' . \base64_encode($bytes) . '?=';
        }

        private static function assertName(string $name): void
        {
            if (!Text::isFieldName($name)) {
                throw new \LogicException('Encoder: bad header name ' . Text::sanitize($name, 80));
            }
        }
    }

    /**
     * Domain names to A-labels (§6.4; RFC 5890, 5891, 3492).
     *
     * ASCII-only domains are validated and lower-cased by the library's own rules, with or without
     * ext-intl, so the common case never depends on the extension. A domain with non-ASCII goes
     * through UTS #46 (non-transitional, with BIDI, CONTEXTJ and STD3 checks) when ext-intl is
     * loaded, else through the built-in RFC 3492 encoder label by label, with warning
     * `idn-not-normalized`: without Unicode tables there is no mapping and no NFC, so the caller
     * must supply NFC, lower-case input (README).
     *
     * @internal
     */
    final class Idna
    {
        /** RFC 5321 §4.5.3.1.2. */
        private const MAX_DOMAIN = 255;

        /** A longer input is refused before any conversion. */
        private const MAX_INPUT = 1024;

        /** RFC 3492 §5 parameters. */
        private const BASE = 36;
        private const TMIN = 1;
        private const TMAX = 26;
        private const SKEW = 38;
        private const DAMP = 700;
        private const INITIAL_BIAS = 72;
        private const INITIAL_N = 128;

        /**
         * Blocks whose letters UTS #46 maps to other characters (compatibility forms), so the
         * fallback cannot convert them without mapping tables: letterlike symbols, Hangul
         * compatibility jamo, CJK compatibility ideographs, alphabetic and Arabic presentation
         * forms, variation selectors, half- and full-width forms, mathematical alphanumerics.
         */
        private const COMPATIBILITY = '/[\x{2100}-\x{214F}\x{3130}-\x{318F}\x{F900}-\x{FAFF}\x{FB00}-\x{FDFF}\x{FE00}-\x{FE0F}'
            . '\x{FE70}-\x{FEFF}\x{FF00}-\x{FFEF}\x{1D400}-\x{1D7FF}\x{2F800}-\x{2FA1F}\x{E0100}-\x{E01EF}]/u';

        /** Scripts written right to left (the R and AL letters of RFC 5893). */
        private const RTL = '\p{Hebrew}\p{Arabic}\p{Syriac}\p{Thaana}\p{Nko}\p{Samaritan}\p{Mandaic}';

        /**
         * UTS #46 error bits (ext-intl) and their meaning. DOMAIN_NAME_TOO_LONG is not listed: the
         * 255-octet check below applies in both modes.
         */
        private const INTL_ERRORS = [
            'IDNA_ERROR_EMPTY_LABEL' => 'empty label',
            'IDNA_ERROR_LABEL_TOO_LONG' => 'a label is longer than 63 octets',
            'IDNA_ERROR_LEADING_HYPHEN' => 'a label starts with a hyphen',
            'IDNA_ERROR_TRAILING_HYPHEN' => 'a label ends with a hyphen',
            'IDNA_ERROR_HYPHEN_3_4' => 'a label has "--" in the third and fourth positions',
            'IDNA_ERROR_LEADING_COMBINING_MARK' => 'a label starts with a combining mark',
            'IDNA_ERROR_DISALLOWED' => 'a character is not allowed',
            'IDNA_ERROR_PUNYCODE' => 'bad Punycode',
            'IDNA_ERROR_LABEL_HAS_DOT' => 'a label contains a dot',
            'IDNA_ERROR_INVALID_ACE_LABEL' => 'an xn-- label is not valid Punycode',
            'IDNA_ERROR_BIDI' => 'the bidirectional rule (RFC 5893) is violated',
            'IDNA_ERROR_CONTEXTJ' => 'a joiner is not allowed in its context (RFC 5892)',
        ];

        /**
         * The A-label form of $domain, lower-case, or null when it is not a valid host name
         * ($error then says why). A trailing dot, an empty label, a label over 63 octets or with a
         * leading or trailing hyphen, a character outside letters, digits and hyphens (after
         * conversion), and a domain over 255 octets are invalid; address literals are not domains
         * (the Address parser handles them). Labels already in A-label form pass unchanged.
         *
         * @param string|null       $error    set to the reason on failure, null on success
         * @param list<string>|null $warnings `idn-not-normalized: …` is APPENDED when the fallback converted a U-label
         * @param bool              $useIntl  false forces the built-in fallback (a test seam; the library passes true)
         */
        public static function toAscii(string $domain, ?string &$error = null, ?array &$warnings = null, bool $useIntl = true): ?string
        {
            $error = null;
            if ($domain === '') {
                $error = 'the domain is empty';
                return null;
            }
            if (\strlen($domain) > self::MAX_INPUT) {
                $error = 'the domain is longer than ' . self::MAX_INPUT . ' octets';
                return null;
            }
            if (!Text::isUtf8($domain)) {
                $error = 'the domain is not valid UTF-8';
                return null;
            }
            if (\preg_match('/[\x00-\x20\x7F]/', $domain) === 1) {
                $error = 'the domain contains a space or a control character';
                return null;
            }
            $converted = false;
            if (\preg_match('/[\x80-\xFF]/', $domain) === 1) {
                $ascii = $useIntl && \function_exists('idn_to_ascii')
                    ? self::viaIntl($domain, $error)
                    : self::viaFallback($domain, $error, $converted);
                if ($ascii === null) {
                    return null;
                }
            } else {
                $ascii = $domain;
            }
            $ascii = self::checkAscii($ascii, $error);
            if ($ascii !== null && $converted) {
                $warnings[] = 'idn-not-normalized: "' . Text::sanitize($domain, 200) . "\" was converted to {$ascii} without ext-intl"
                    . ' (no UTS #46 mapping or NFC normalisation); supply NFC, lower-case domains or load ext-intl';
            }
            return $ascii;
        }

        /**
         * RFC 3492 Punycode of one label (UTF-8): the basic code points in order, "-" if there were
         * any, then the encoded deltas. No "xn--" prefix, no case folding, and no mixed-case
         * annotation (the encoded digits are lower-case; basic code points keep their case).
         */
        public static function punycode(string $label): string
        {
            $input = Encoder::codePoints($label);
            $output = '';
            foreach ($input as $cp) {
                if ($cp < 0x80) {
                    $output .= \chr($cp);
                }
            }
            $basic = \strlen($output);
            $handled = $basic;
            if ($basic > 0) {
                $output .= '-';
            }
            $n = self::INITIAL_N;
            $delta = 0;
            $bias = self::INITIAL_BIAS;
            $total = \count($input);
            while ($handled < $total) {
                $m = \PHP_INT_MAX;   // the smallest code point >= n still to encode
                foreach ($input as $cp) {
                    if ($cp >= $n && $cp < $m) {
                        $m = $cp;
                    }
                }
                $delta += ($m - $n) * ($handled + 1);
                $n = $m;
                foreach ($input as $cp) {
                    if ($cp < $n) {
                        $delta++;
                    } elseif ($cp === $n) {
                        $q = $delta;
                        for ($k = self::BASE; ; $k += self::BASE) {
                            $t = $k <= $bias ? self::TMIN : ($k >= $bias + self::TMAX ? self::TMAX : $k - $bias);
                            if ($q < $t) {
                                break;
                            }
                            $output .= self::digit($t + ($q - $t) % (self::BASE - $t));
                            $q = \intdiv($q - $t, self::BASE - $t);
                        }
                        $output .= self::digit($q);
                        $bias = self::adapt($delta, $handled + 1, $handled === $basic);
                        $delta = 0;
                        $handled++;
                    }
                }
                $delta++;
                $n++;
            }
            return $output;
        }

        /** UTS #46 through ext-intl; the result still passes checkAscii(). */
        private static function viaIntl(string $domain, ?string &$error): ?string
        {
            $info = [];
            \idn_to_ascii(
                $domain,
                \IDNA_NONTRANSITIONAL_TO_ASCII | \IDNA_CHECK_BIDI | \IDNA_CHECK_CONTEXTJ | \IDNA_USE_STD3_RULES,
                \INTL_IDNA_VARIANT_UTS46,
                $info,
            );
            $errors = (int) ($info['errors'] ?? -1) & ~\IDNA_ERROR_DOMAIN_NAME_TOO_LONG;
            if ($errors !== 0 || !\is_string($info['result'] ?? null)) {
                $reasons = [];
                foreach (self::INTL_ERRORS as $constant => $reason) {
                    if (($errors & (int) \constant($constant)) !== 0) {
                        $reasons[] = $reason;
                    }
                }
                $error = 'IDNA (UTS #46): ' . ($reasons === [] ? \sprintf('error mask 0x%X', $errors) : \implode('; ', $reasons));
                return null;
            }
            return $info['result'];
        }

        /**
         * The built-in conversion: labels split at "." and its ideographic and full-width forms,
         * ASCII labels kept (checkAscii() validates them), every other label ASCII-lower-cased and
         * Punycode-encoded with "xn--". Without mapping tables a label is converted only when UTS #46
         * would leave it unchanged as far as can be told: lower-case and other letters, marks and
         * decimal digits, none from a compatibility block, no leading mark or hyphen. Anything else
         * (upper-case, symbols, joiners, compatibility forms) could give an A-label that names a
         * different domain, so it needs ext-intl. Two validity rules that need no tables are
         * applied too: no "--" in the third and fourth positions (RFC 5891 §4.2.3.1), and a label
         * with right-to-left letters holds only those, marks, digits of one kind and hyphens, and
         * starts with such a letter (RFC 5893 §2, per label).
         */
        private static function viaFallback(string $domain, ?string &$error, bool &$converted): ?string
        {
            $labels = [];
            foreach (\preg_split('/[.\x{3002}\x{FF0E}\x{FF61}]/u', $domain) as $label) {
                if (\preg_match('/[\x80-\xFF]/', $label) !== 1) {
                    $labels[] = $label;
                    continue;
                }
                $label = Text::lower($label);
                $shown = '"' . Text::sanitize($label, 100) . '"';
                if (\preg_match('/\A(?!-|\p{M})[a-z0-9\-\p{Ll}\p{Lm}\p{Lo}\p{M}\p{Nd}]+(?<!-)\z/u', $label) !== 1
                    || \preg_match(self::COMPATIBILITY, $label) === 1) {
                    $error = "the label {$shown} cannot be converted without ext-intl:"
                        . ' only lower-case letters, marks, digits and inner hyphens can be (upper-case, symbols, joiners'
                        . ' and compatibility forms need UTS #46 mapping)';
                    return null;
                }
                if (\substr($label, 2, 2) === '--') {
                    $error = "the label {$shown} has \"--\" in the third and fourth positions";
                    return null;
                }
                // European digits (EN: 0-9, U+06F0-U+06F9) and Arabic-Indic ones (AN) never together (rule 4).
                if (\preg_match('/[' . self::RTL . ']/u', $label) === 1
                    && (\preg_match('/\A(?=\p{L})[' . self::RTL . '][' . self::RTL . '\p{M}0-9\-]*\z/u', $label) !== 1
                        || (\preg_match('/[0-9\x{06F0}-\x{06F9}]/u', $label) === 1 && \preg_match('/[\x{0660}-\x{0669}]/u', $label) === 1))) {
                    $error = "the label {$shown} breaks the bidirectional rule (RFC 5893): it mixes right-to-left letters"
                        . ' with other letters or both kinds of digits, or does not start with a right-to-left letter';
                    return null;
                }
                $labels[] = 'xn--' . self::punycode($label);
                $converted = true;
            }
            return \implode('.', $labels);
        }

        /** Validates an ASCII domain (LDH labels, RFC 5321 §4.1.2 / RFC 1035) and lower-cases it. */
        private static function checkAscii(string $domain, ?string &$error): ?string
        {
            if (\strlen($domain) > self::MAX_DOMAIN) {
                $error = 'the domain is longer than ' . self::MAX_DOMAIN . ' octets';
                return null;
            }
            foreach (\explode('.', $domain) as $label) {
                if ($label === '') {
                    $error = 'the domain has an empty label (or a leading or trailing dot)';
                    return null;
                }
                if (\strlen($label) > 63) {
                    $error = 'the label "' . Text::sanitize($label, 100) . '" is longer than 63 octets';
                    return null;
                }
                if (\preg_match('/\A[A-Za-z0-9](?:[A-Za-z0-9-]*[A-Za-z0-9])?\z/', $label) !== 1) {
                    $error = 'the label "' . Text::sanitize($label, 100)
                        . '" must be letters, digits and hyphens, not starting or ending with a hyphen';
                    return null;
                }
            }
            return Text::lower($domain);
        }

        /** RFC 3492 §6.1 bias adaptation. */
        private static function adapt(int $delta, int $points, bool $first): int
        {
            $delta = $first ? \intdiv($delta, self::DAMP) : \intdiv($delta, 2);
            $delta += \intdiv($delta, $points);
            $k = 0;
            while ($delta > \intdiv((self::BASE - self::TMIN) * self::TMAX, 2)) {
                $delta = \intdiv($delta, self::BASE - self::TMIN);
                $k += self::BASE;
            }
            return $k + \intdiv((self::BASE - self::TMIN + 1) * $delta, $delta + self::SKEW);
        }

        /** RFC 3492 §5 digit values: 0-25 are a-z, 26-35 are 0-9. */
        private static function digit(int $d): string
        {
            return \chr($d < 26 ? 0x61 + $d : 0x16 + $d);
        }
    }

    /**
     * Renders a Message into a Rendered (§6.1): the header fields in the fixed §6.3 order, the
     * §6.5 MIME tree with the §6.6 transfer encodings, and the body written into a Spool through
     * the validator tee (write()). Date, Message-ID and the boundaries come from this Renderer's
     * Clock and RandomSource (the Mailer's); Content-IDs were fixed by the Message.
     *
     * The output never depends on the server (S1): bodies are 7-bit (7bit, quoted-printable or
     * base64), headers use RFC 2047 and A-labels, and the only raw UTF-8 is a non-ASCII local part
     * (Rendered::$needsSmtpUtf8). Attachments are streamed from their paths, never read whole (I12).
     *
     * @internal
     */
    final class Renderer
    {
        /** RFC 2046 §5.1.1: text for readers that do not understand MIME; only on the top-level multipart. */
        private const PREAMBLE = 'This is a multi-part message in MIME format.';

        /** RFC 5322 §2.1.1 / RFC 5321 §4.5.3.1.6: octets per line, CRLF excluded. */
        private const LINE_MAX = 998;

        /** 57 × 1024 bytes: whole 76-character base64 lines, so blocks join with CRLF (§6.6). */
        private const B64_BLOCK = 58368;

        /** The body being written (tee state, only during render()). */
        private ?Spool $spool = null;

        /** Octets of the current body line so far. */
        private int $line = 0;

        /** The last body byte written was a CR, which the next write must continue with LF. */
        private bool $cr = false;

        /** Set by __unserialize(): render() then throws UsageException (§8.4). */
        private bool $inert = false;

        /**
         * Never throws (§8.4): it only stores its arguments. $idDomain is an A-label the Mailer
         * validated (Idna::toAscii); $spoolDir a directory it checked for writability.
         */
        public function __construct(
            private readonly Clock $clock,
            private readonly RandomSource $random,
            private readonly bool $utcDates,
            private readonly ?string $idDomain,
            private readonly ?string $spoolDir,
            private readonly int $spoolMemory,
        ) {
        }

        /**
         * Renders the message, and on success stamps its Date and Message-ID (§6.2) when it had none.
         *
         * @throws MessageException field "from" when there is no From mailbox; field "html" for
         *                          embedded parts without an HTML body; field "date" for a clock
         *                          year outside 1900-9999
         * @throws SmtpException    Local, Transient, Prepare, retrySafe: an attachment that can no
         *                          longer be read, a spool failure, a RandomSource failure (§6.8)
         * @throws UsageException   for an unserialized Message or Renderer
         */
        public function render(Message $message): Rendered
        {
            if ($this->inert) {
                throw new UsageException('this Renderer was unserialized and is inert (§8.4)');
            }
            $s = $message->snapshot();
            $from = $s['from'];
            if ($from === null) {
                throw new MessageException('Invalid from: every message needs a From mailbox (RFC 5322 §3.6); call from()', 'from');
            }
            if ($s['inline'] !== [] && $s['html'] === null) {
                throw new MessageException('Invalid html: embedded parts are shown by the HTML body, and there is none;'
                    . ' add html(), or use attach() for files that are not shown inline', 'html');
            }
            $now = $this->clock->now();
            $date = $s['date'] ?? $now;
            $messageId = $s['messageId'] ?? $this->newMessageId($now, $from);
            $boundaries = [];
            $root = $this->body($s, $boundaries);
            $headers = $this->headerSection($s, $date, $messageId) . $root['headers'];
            $spool = Spool::open($this->spoolDir, $this->spoolMemory);
            try {
                $this->spool = $spool;
                $this->line = 0;
                $this->cr = false;
                $this->entityBody($root, true);
                $this->finishBody();
            } catch (\Throwable $e) {
                $spool->close();
                throw $e;
            } finally {
                $this->spool = null;
            }
            $message->stamp($date, $messageId);
            return new Rendered($headers, $spool, $spool->size(), self::needsSmtpUtf8($s), $messageId, self::warnings($s));
        }

        /**
         * The validator tee (§6.1): every body byte passes through here into the Spool. It checks
         * the wire rules that every input was already encoded for: no NUL, no octet above 0x7F
         * (S1), CR and LF only as CRLF, no line over 998 octets; finishBody() adds that a
         * non-empty body ends in CRLF. The checks carry state across writes, so they hold however
         * the bytes are split. A breach is a library bug (\LogicException), never bad input.
         * Public only so Encoder::base64Stream() can write here.
         *
         * @internal
         */
        public function write(string $bytes): void
        {
            if ($this->spool === null) {
                throw new \LogicException('Renderer::write() outside render() (a tehsmtp bug)');
            }
            if ($bytes === '') {
                return;
            }
            if (\preg_match('/[\x00\x80-\xFF]/', $bytes, $m, \PREG_OFFSET_CAPTURE) === 1) {
                throw $this->breach(\sprintf('octet 0x%02X', \ord($m[0][0])), $m[0][1]);
            }
            $s = ($this->cr ? "\r" : '') . $bytes;
            $this->cr = $s[-1] === "\r";   // may start a CRLF that the next write completes
            if ($this->cr) {
                $s = \substr($s, 0, -1);
            }
            if (\preg_match('/\r(?!\n)|(?<!\r)\n/', $s, $m, \PREG_OFFSET_CAPTURE) === 1) {
                throw $this->breach('a bare CR or LF', $m[0][1]);
            }
            // Line lengths by strpos (a regex such as [^\r\n]{999} would retry at every offset).
            $start = 0;
            while (($crlf = \strpos($s, "\r\n", $start)) !== false) {
                if ($this->line + $crlf - $start > self::LINE_MAX) {
                    throw $this->breach('a line longer than 998 octets', $start);
                }
                $this->line = 0;
                $start = $crlf + 2;
            }
            $this->line += \strlen($s) - $start;
            if ($this->line > self::LINE_MAX) {
                throw $this->breach('a line longer than 998 octets', $start);
            }
            $this->spool->write($bytes);
        }

        /** A placeholder: a Renderer holds the injected Clock and RandomSource (§8.4). */
        public function __serialize(): array
        {
            return ['renderer' => '[not serialisable]'];
        }

        /** Gives an INERT Renderer. */
        public function __unserialize(array $data): void
        {
            $this->inert = true;
        }

        // -------------------------------------------------------------------------------------
        // Header section (§6.3)
        // -------------------------------------------------------------------------------------

        /**
         * The message's own fields in the fixed order: Date, From, Sender, Reply-To, To, Cc,
         * Subject, Message-ID, In-Reply-To, References, List-* / Auto-Submitted, custom fields,
         * MIME-Version. Bcc, Return-Path, Received and X-Mailer are never emitted.
         */
        private function headerSection(array $s, \DateTimeImmutable $date, string $messageId): string
        {
            $fields = 'Date: ' . Encoder::date($date, $this->utcDates) . "\r\n";
            $fields .= Encoder::foldStructured('From', [$s['from']->headerTokens()]);
            if (self::renderedSender($s) !== null) {
                $fields .= Encoder::foldStructured('Sender', [$s['sender']->headerTokens()]);
            }
            $fields .= self::addressField('Reply-To', $s['replyTo']);
            if ($s['to'] !== []) {
                $fields .= self::addressField('To', $s['to']);
            } elseif ($s['cc'] === [] && $s['bcc'] !== []) {
                $fields .= "To: undisclosed-recipients:;\r\n";   // RFC 5322 §3.6.3: Bcc only, never shown
            }
            $fields .= self::addressField('Cc', $s['cc']);
            if ($s['subject'] !== null) {
                $fields .= Encoder::foldUnstructured('Subject', $s['subject']);
            }
            $fields .= Encoder::foldStructured('Message-ID', [[$messageId]], '');
            foreach (['In-Reply-To' => $s['inReplyTo'], 'References' => $s['references']] as $name => $ids) {
                if ($ids !== []) {
                    $fields .= Encoder::foldStructured($name, \array_map(static fn (string $id): array => [$id], $ids), '');
                }
            }
            if ($s['listUnsubscribe'] !== []) {
                $fields .= Encoder::foldStructured('List-Unsubscribe', \array_map(static fn (string $uri): array => ["<{$uri}>"], $s['listUnsubscribe']));
                if ($s['oneClick']) {
                    $fields .= "List-Unsubscribe-Post: List-Unsubscribe=One-Click\r\n";   // RFC 8058 §3.1
                }
            }
            if ($s['autoSubmitted'] !== null) {
                $fields .= "Auto-Submitted: {$s['autoSubmitted']}\r\n";
            }
            foreach ($s['headers'] as $h) {
                $fields .= $h['raw']
                    ? $h['name'] . ':' . ($h['value'] === '' ? '' : ' ' . $h['value']) . "\r\n"
                    : Encoder::foldUnstructured($h['name'], $h['value']);
            }
            return $fields . "MIME-Version: 1.0\r\n";
        }

        /** @param list<Address> $list */
        private static function addressField(string $name, array $list): string
        {
            return $list === [] ? '' : Encoder::foldStructured($name, \array_map(static fn (Address $a): array => $a->headerTokens(), $list));
        }

        /** The Sender when it is rendered: set, and a different mailbox than From (RFC 5322 §3.6.2). */
        private static function renderedSender(array $s): ?Address
        {
            $sender = $s['sender'];
            return $sender !== null && $sender->key() !== $s['from']->key() ? $sender : null;
        }

        /** @return list<Address> every address that appears in a header field */
        private static function headerAddresses(array $s): array
        {
            return \array_values(\array_filter([$s['from'], self::renderedSender($s), ...$s['replyTo'], ...$s['to'], ...$s['cc']]));
        }

        /** A raw UTF-8 local part in a header field, the one 8-bit thing a built message carries (S1). */
        private static function needsSmtpUtf8(array $s): bool
        {
            foreach (self::headerAddresses($s) as $address) {
                if ($address->needsSmtpUtf8()) {
                    return true;
                }
            }
            return false;
        }

        /**
         * Rendered::$warnings: the addresses' own (idn-not-normalized), display-name-at-sign,
         * cid-dangling and cid-unreferenced, each once.
         *
         * @return list<string>
         */
        private static function warnings(array $s): array
        {
            $warnings = [];
            foreach (\array_filter([$s['from'], $s['sender'], ...$s['replyTo'], ...$s['to'], ...$s['cc'], ...$s['bcc']]) as $address) {
                \array_push($warnings, ...$address->warnings());
            }
            foreach (self::headerAddresses($s) as $address) {
                if (\str_contains($address->name, '@')) {
                    $warnings[] = 'display-name-at-sign: the display name "' . Text::sanitize($address->name, 100)
                        . "\" of <{$address->email}> contains \"@\", a common spoofing trick";
                }
            }
            if ($s['html'] !== null) {
                \preg_match_all('/cid:([^\s"\'<>()\\\\]+)/i', $s['html'], $m);
                $referenced = \array_values(\array_unique(\array_map(static fn (string $r): string => Text::lower(\rawurldecode($r)), $m[1])));
                $cids = \array_map(static fn (array $part): string => Text::lower($part['cid']), $s['inline']);
                foreach ($referenced as $cid) {
                    if (!\in_array($cid, $cids, true)) {
                        $warnings[] = 'cid-dangling: the HTML references cid:' . Text::sanitize($cid, 100) . ', which no embedded part has';
                    }
                }
                foreach ($s['inline'] as $part) {
                    if (!\in_array(Text::lower($part['cid']), $referenced, true)) {
                        $warnings[] = 'cid-unreferenced: the embedded part "' . Text::sanitize($part['name'], 100) . "\" (cid:{$part['cid']})"
                            . ' is not referenced by the HTML; many clients show it as an attachment';
                    }
                }
            }
            return \array_values(\array_unique($warnings));
        }

        /** `<{hex(time)}.{hex(random 16)}@{idDomain ?? From-domain}>` (§6.2); never uniqid() or gethostname(). */
        private function newMessageId(\DateTimeImmutable $now, Address $from): string
        {
            $domain = $this->idDomain ?? $from->domain();
            if (\preg_match('/\A[a-z0-9](?:[a-z0-9.\-]*[a-z0-9])?\z|\A\[[\x21-\x5A\x5E-\x7E]+\]\z/', $domain) !== 1) {
                throw new \LogicException('Renderer: the Message-ID domain is not a validated A-label (a tehsmtp bug)');
            }
            return '<' . \dechex(\max(0, $now->getTimestamp())) . '.' . \bin2hex($this->randomBytes(16)) . '@' . $domain . '>';
        }

        // -------------------------------------------------------------------------------------
        // MIME tree (§6.5) and transfer encodings (§6.6)
        // -------------------------------------------------------------------------------------

        /**
         * The top-level entity, levels that are not needed dropped: multipart/mixed only with
         * attachments; an empty text/plain for a message with no body and no attachments.
         * Boundaries are drawn top-down, so the RandomSource sequence follows document order.
         *
         * @param list<string> $boundaries the boundaries drawn so far (all distinct)
         */
        private function body(array $s, array &$boundaries): array
        {
            if ($s['attachments'] === []) {
                return $this->content($s, $boundaries) ?? self::textLeaf('text/plain', '');
            }
            $boundary = $this->boundary($boundaries);
            $parts = [];
            $content = $this->content($s, $boundaries);
            if ($content !== null) {
                $parts[] = $content;
            }
            foreach ($s['attachments'] as $i => $part) {
                $parts[] = self::fileLeaf($part, 'attachment', "attachment[{$i}]");
            }
            return self::multipart('mixed', $boundary, $parts);
        }

        /** The body proper: text, HTML (with its inline parts), or both as multipart/alternative, plain first. */
        private function content(array $s, array &$boundaries): ?array
        {
            if ($s['text'] !== null && $s['html'] !== null) {
                $boundary = $this->boundary($boundaries);
                return self::multipart('alternative', $boundary, [self::textLeaf('text/plain', $s['text']), $this->htmlEntity($s, $boundaries)]);
            }
            if ($s['html'] !== null) {
                return $this->htmlEntity($s, $boundaries);
            }
            return $s['text'] === null ? null : self::textLeaf('text/plain', $s['text']);
        }

        /** text/html, or multipart/related with the HTML as its root (first part) and the inline parts (RFC 2387). */
        private function htmlEntity(array $s, array &$boundaries): array
        {
            if ($s['inline'] === []) {
                return self::textLeaf('text/html', $s['html']);
            }
            $boundary = $this->boundary($boundaries);
            $parts = [self::textLeaf('text/html', $s['html'])];
            foreach ($s['inline'] as $i => $part) {
                $parts[] = self::fileLeaf($part, 'inline', "inline part {$i}");
            }
            return self::multipart('related', $boundary, $parts);
        }

        /**
         * A text leaf, CRLF-normalised and encoded (§6.6): ASCII with every line at most 998
         * octets and no line starting with "--=_" (the boundary prefix, §6.5) → 7bit; otherwise
         * at most a third of the octets non-ASCII → quoted-printable, else base64.
         */
        private static function textLeaf(string $type, string $utf8): array
        {
            $text = Text::crlf($utf8);
            $high = (int) \preg_match_all('/[\x80-\xFF]/', $text);
            // "^" in multiline mode: each line is tried once, from its start.
            if ($high === 0 && \preg_match('/^[^\r\n]{999}/m', $text) !== 1 && \preg_match('/^--=_/m', $text) !== 1) {
                [$cte, $content] = ['7bit', $text];
            } elseif (3 * $high <= \strlen($text)) {
                [$cte, $content] = ['quoted-printable', Encoder::qp($text)];
            } else {
                [$cte, $content] = ['base64', Encoder::base64($text)];
            }
            $contentType = [[$type]];
            foreach (Encoder::param('charset', 'utf-8') as $param) {
                $contentType[] = [$param];
            }
            return [
                'kind' => 'text',
                'headers' => Encoder::foldStructured('Content-Type', $contentType, ';') . "Content-Transfer-Encoding: {$cte}\r\n",
                'content' => $content,
            ];
        }

        /**
         * An attachment or inline part: base64, never normalised (§6.6), with the file name in
         * Content-Disposition filename= and Content-Type name= (RFC 2183, 2231).
         *
         * @param array{path: ?string, data: ?string, name: string, type: string, cid?: string} $part
         */
        private static function fileLeaf(array $part, string $disposition, string $label): array
        {
            $contentType = [[$part['type']]];
            foreach (Encoder::param('name', $part['name']) as $param) {
                $contentType[] = [$param];
            }
            $contentDisposition = [[$disposition]];
            foreach (Encoder::param('filename', $part['name']) as $param) {
                $contentDisposition[] = [$param];
            }
            $headers = Encoder::foldStructured('Content-Type', $contentType, ';') . "Content-Transfer-Encoding: base64\r\n";
            if (isset($part['cid'])) {
                $headers .= Encoder::foldStructured('Content-ID', [['<' . $part['cid'] . '>']], '');
            }
            return [
                'kind' => 'file',
                'headers' => $headers . Encoder::foldStructured('Content-Disposition', $contentDisposition, ';'),
                'path' => $part['path'],
                'data' => $part['data'],
                'label' => $label . ' "' . Text::sanitize($part['name'], 100) . '"',
            ];
        }

        /** A multipart entity; composite parts are always 7bit (RFC 2045 §6.4, message item 6.12). */
        private static function multipart(string $subtype, string $boundary, array $parts): array
        {
            $contentType = [["multipart/{$subtype}"]];
            if ($subtype === 'related') {
                foreach (Encoder::param('type', 'text/html') as $param) {   // RFC 2387 §3.1: required, the root's type
                    $contentType[] = [$param];
                }
            }
            foreach (Encoder::param('boundary', $boundary) as $param) {   // contains "=", so always quoted
                $contentType[] = [$param];
            }
            return [
                'kind' => 'multipart',
                'headers' => Encoder::foldStructured('Content-Type', $contentType, ';') . "Content-Transfer-Encoding: 7bit\r\n",
                'boundary' => $boundary,
                'parts' => $parts,
            ];
        }

        /**
         * "=_" + 24 hex (§6.5): all the same length, so none is a prefix of another; "=_" never
         * occurs in quoted-printable or base64 output, and a 7bit text line starting with "--=_"
         * forces quoted-printable, so no boundary can occur inside a part.
         *
         * @param list<string> $boundaries
         */
        private function boundary(array &$boundaries): string
        {
            do {
                $boundary = '=_' . \bin2hex($this->randomBytes(12));
            } while (\in_array($boundary, $boundaries, true));
            $boundaries[] = $boundary;
            return $boundary;
        }

        /**
         * Writes an entity's body (RFC 2046 §5.1.1): a multipart as [preamble CRLF] "--b" CRLF
         * part *(CRLF "--b" CRLF part) CRLF "--b--" CRLF, where the CRLF before each delimiter
         * belongs to the delimiter and every part has its blank line; the CRLF after the
         * close-delimiter starts the empty epilogue, so every multipart ends in CRLF. A top-level
         * leaf gets a final CRLF when its content lacks one (§6.1).
         */
        private function entityBody(array $entity, bool $top): void
        {
            if ($entity['kind'] === 'multipart') {
                $delimiter = '--' . $entity['boundary'];
                $this->write(($top ? self::PREAMBLE . "\r\n" : '') . $delimiter . "\r\n");
                foreach ($entity['parts'] as $k => $part) {
                    if ($k > 0) {
                        $this->write("\r\n{$delimiter}\r\n");
                    }
                    $this->write($part['headers'] . "\r\n");
                    $this->entityBody($part, false);
                }
                $this->write("\r\n{$delimiter}--\r\n");
            } elseif ($entity['kind'] === 'text') {
                $this->write($entity['content']);
                if ($top && $entity['content'] !== '' && !\str_ends_with($entity['content'], "\r\n")) {
                    $this->write("\r\n");
                }
            } else {
                $this->fileBody($entity);   // file parts are always inside a multipart
            }
        }

        /** base64 of a file part: data in 57 KiB blocks, a path streamed from a fresh 'rb' handle (§6.2, §6.8). */
        private function fileBody(array $entity): void
        {
            if ($entity['data'] !== null) {
                $data = $entity['data'];
                for ($offset = 0, $n = \strlen($data); $offset < $n; $offset += self::B64_BLOCK) {
                    $this->write(($offset > 0 ? "\r\n" : '') . Encoder::base64(\substr($data, $offset, self::B64_BLOCK)));
                }
                return;
            }
            $path = $entity['path'];
            $mode = 'rb';
            $warnings = [];
            try {
                $in = Guard::run(static fn () => \is_file($path), $warnings) ? Guard::run(static fn () => \fopen($path, $mode), $warnings) : false;
            } catch (\Throwable $e) {
                $in = false;
                $warnings[] = $e::class . ': ' . $e->getMessage();
            }
            if (!\is_resource($in)) {
                throw self::localFailure("{$entity['label']}: the file can no longer be opened (deleted or moved since attach()?)", $warnings);
            }
            try {
                Encoder::base64Stream($in, $this);
            } catch (SmtpException $e) {
                throw new SmtpException(
                    Text::sanitize("{$entity['label']}: {$e->getMessage()}", 300),
                    Reason::Local,
                    Outcome::Transient,
                    Stage::Prepare,
                    Scope::Transaction,
                    retrySafe: true,
                    details: $e->details,
                    previous: $e,
                );
            } finally {
                try {
                    Guard::run(static fn () => \fclose($in));
                } catch (\Throwable) {
                    // Closing a read handle cannot lose data.
                }
            }
        }

        /** The end of the body: no CR left waiting for its LF, and a non-empty body ends in CRLF (§6.1). */
        private function finishBody(): void
        {
            if ($this->cr) {
                throw $this->breach('a bare CR at the end', 0);
            }
            if ($this->spool->size() > 0 && $this->line !== 0) {
                throw $this->breach('a body that does not end in CRLF', 0);
            }
        }

        private function breach(string $what, int $offset): \LogicException
        {
            return new \LogicException("Renderer: the body breaks the §6.1 wire rules ({$what}, near offset {$offset} of a write"
                . ' after ' . ($this->spool?->size() ?? 0) . ' octets); a tehsmtp bug');
        }

        /** Bytes from the Renderer's RandomSource; a failure is the §8.3 Local one. */
        private function randomBytes(int $length): string
        {
            try {
                $bytes = $this->random->bytes($length);
            } catch (SmtpException $e) {
                throw $e;   // SystemRandom's failure is already the §8.3 one
            } catch (\Exception $e) {
                throw new SmtpException(
                    'no random bytes for the Message-ID or a boundary: ' . Text::sanitize($e::class . ': ' . $e->getMessage(), 200),
                    Reason::Local,
                    Outcome::Transient,
                    Stage::Prepare,
                    Scope::Transaction,
                    retrySafe: true,
                    previous: $e,
                );
            }
            if (\strlen($bytes) !== $length) {
                throw new \LogicException("RandomSource::bytes({$length}) returned " . \strlen($bytes) . ' bytes');
            }
            return $bytes;
        }

        /** @param list<string> $warnings */
        private static function localFailure(string $message, array $warnings): SmtpException
        {
            return new SmtpException(
                Text::sanitize($message, 300),
                Reason::Local,
                Outcome::Transient,
                Stage::Prepare,
                Scope::Transaction,
                retrySafe: true,
                details: $warnings === [] ? [] : ['phpWarnings' => $warnings],
            );
        }
    }

    /**
     * A rendered message (§2.6): the ONLY input DATA accepts, so the trust boundary (§6.1). The
     * header bytes are a public property; the body lives in a Spool that DKIM, SIZE and DATA each
     * re-read, so all of them see the same bytes (I4). Immutable: withPrepended() returns a new
     * Rendered that shares the SAME Spool (D32), so the unsigned Rendered going out of scope closes
     * nothing; whoever holds the last reference, or Mailer::send()'s `finally`, closes it.
     *
     * @internal
     */
    final class Rendered
    {
        /**
         * @param string        $headers       the header section: complete fields, each ending in
         *                                     CRLF, without the blank line
         * @param int           $bodySize      octets in $spool
         * @param bool          $needsSmtpUtf8 a raw UTF-8 local part is in a header (§3.5)
         * @param string        $messageId     the rendered Message-ID, with its angle brackets
         * @param list<string>  $warnings      each "code: message"
         * @param array<string, string> $bodyHashes the cache of bodyHash(), carried by withPrepended()
         * @throws \LogicException for a header section that breaks the §6.1 rules (a tehsmtp bug)
         */
        public function __construct(
            public readonly string $headers,
            private readonly Spool $spool,
            public readonly int $bodySize,
            public readonly bool $needsSmtpUtf8,
            public readonly string $messageId,
            public readonly array $warnings,
            private array $bodyHashes = [],
        ) {
            self::assertHeaderSection($headers, $needsSmtpUtf8);
            if ($bodySize !== $spool->size()) {
                throw new \LogicException("Rendered: bodySize {$bodySize} is not the spool's " . $spool->size() . ' octets (a tehsmtp bug)');
            }
        }

        /**
         * A new Rendered with $fields (complete header fields, each ending in CRLF, e.g. the
         * DKIM-Signature fields) before the headers; the body is the SAME Spool object.
         */
        public function withPrepended(string ...$fields): self
        {
            foreach ($fields as $field) {
                if (\preg_match('/\A[\x21-\x39\x3B-\x7E]+:/', $field) !== 1 || !\str_ends_with($field, "\r\n")
                    || \preg_match('/\r\n(?![\t ])/', \substr($field, 0, -2)) === 1) {
                    throw new \LogicException('Rendered::withPrepended(): each argument must be one complete header field ending in CRLF');
                }
            }
            return new self(\implode('', $fields) . $this->headers, $this->spool, $this->bodySize, $this->needsSmtpUtf8,
                $this->messageId, $this->warnings, $this->bodyHashes);
        }

        /** The body's Spool, shared by every Rendered derived from the same render. */
        public function spool(): Spool
        {
            return $this->spool;
        }

        /** The octets SIZE declares and DATA sends before dot-stuffing: headers, CRLF, body (I4). */
        public function size(): int
        {
            return \strlen($this->headers) + 2 + $this->bodySize;
        }

        /**
         * The raw SHA-256 of the body canonicalised for DKIM (§7.2): streamed from the spool
         * through DkimCanon once per canonicalisation, then cached.
         */
        public function bodyHash(bool $relaxed): string
        {
            $key = $relaxed ? 'relaxed' : 'simple';
            if (!isset($this->bodyHashes[$key])) {
                $canon = new DkimCanon($relaxed);
                $this->spool->rewind();
                while (($chunk = $this->spool->read(65536)) !== '') {
                    $canon->push($chunk);
                }
                $this->bodyHashes[$key] = $canon->digest();
            }
            return $this->bodyHashes[$key];
        }

        /**
         * The message bytes: the headers, "\r\n", then body chunks of exactly $size octets (the
         * last one shorter). Never yields ''. Each iteration starts from the beginning of the
         * spool; iterate one generator at a time.
         *
         * @return \Generator<int, string>
         */
        public function chunks(int $size = 65536): \Generator
        {
            if ($size < 1) {
                throw new \LogicException("Rendered::chunks(): the chunk size must be at least 1, got {$size}");
            }
            return $this->stream($size);
        }

        /** @return \Generator<int, string> */
        private function stream(int $size): \Generator
        {
            yield $this->headers;
            yield "\r\n";
            $this->spool->rewind();
            while (($chunk = $this->spool->read($size)) !== '') {
                yield $chunk;
            }
        }

        /**
         * Complete fields, each ending in CRLF; lines of at most 998 octets; CR and LF only as CRLF;
         * no NUL, no blank or whitespace-only line; 8-bit octets only as the UTF-8 of an SMTPUTF8
         * message (§6.1, S1).
         */
        private static function assertHeaderSection(string $headers, bool $utf8): void
        {
            $problem = null;
            if ($headers === '' || !\str_ends_with($headers, "\r\n")) {
                $problem = 'it must be complete fields ending in CRLF';
            } elseif (\str_contains($headers, "\0")) {
                $problem = 'NUL';
            } elseif (\preg_match('/[\x80-\xFF]/', $headers) === 1 && (!$utf8 || !Text::isUtf8($headers))) {
                $problem = '8-bit octets without SMTPUTF8, or invalid UTF-8';
            } else {
                foreach (\explode("\r\n", \substr($headers, 0, -2)) as $k => $line) {
                    if (\strlen($line) > 998 || \strpbrk($line, "\r\n") !== false) {
                        $problem = "line {$k} is longer than 998 octets or holds a bare CR or LF";
                    } elseif ($line === '' || \preg_match('/\A[\t ]*\z/', $line) === 1) {
                        $problem = "line {$k} is blank";
                    } elseif ($line[0] === ' ' || $line[0] === "\t" ? $k === 0 : \preg_match('/\A[\x21-\x39\x3B-\x7E]+:/', $line) !== 1) {
                        $problem = "line {$k} neither starts a field nor continues one";
                    }
                    if ($problem !== null) {
                        break;
                    }
                }
            }
            if ($problem !== null) {
                throw new \LogicException("Rendered: the header section breaks the §6.1 rules ({$problem}); a tehsmtp bug");
            }
        }
    }

    /**
     * The render spool (§6.8, S5): owns ONE stream, `php://temp/maxmemory:<n>` (spilling to
     * sys_temp_dir) or a `tempnam()` file in the Mailer's spoolDir, and is the only code that
     * fcloses or unlinks it. Written once, then read (rewind() and read() any number of times).
     *
     * Shared, never copied (D32): PHP's refcount keeps it open while any Rendered holds it, the
     * destructor closes it when the last one lets go, and Mailer::send() / Message::toString()
     * close it explicitly in `finally`. Failures are SmtpException(Local, Transient, Prepare,
     * retrySafe), since the whole message is rendered before any transaction exists (§6.8).
     *
     * @internal
     */
    final class Spool
    {
        /** @var resource|null */
        private $stream = null;

        /** The spoolDir file to unlink on close(), or null for php://temp. */
        private ?string $path = null;

        private int $size = 0;

        /** Reading has started: the content is final (write() is a bug from now on). */
        private bool $reading = false;

        private bool $closed = false;

        /** Set by __unserialize(): every method then throws UsageException (§8.4). */
        private bool $inert = false;

        private function __construct()
        {
        }

        /**
         * @param string|null $dir    a writable directory (the Mailer's spoolDir), or null for php://temp
         * @param int         $memory bytes php://temp keeps in memory before it spills to a file
         * @throws SmtpException Local, Transient, Prepare, retrySafe
         */
        public static function open(?string $dir, int $memory): self
        {
            if ($memory < 0) {
                throw new \LogicException("Spool::open(): negative memory limit {$memory}");
            }
            $spool = new self();
            $mode = 'w+b';
            $warnings = [];
            $stream = false;
            try {
                if ($dir === null) {
                    $url = 'php://temp/maxmemory:' . $memory;
                    $stream = Guard::run(static fn () => \fopen($url, $mode), $warnings);
                } else {
                    $prefix = 'tehsmtp';
                    $path = Guard::run(static fn () => \tempnam($dir, $prefix), $warnings);
                    if (\is_string($path)) {
                        $spool->path = $path;   // unlinked by close(), whatever happens next
                    }
                    // tempnam() falls back to the system temp directory with a notice: not the one asked for.
                    if (\is_string($path) && $warnings === []) {
                        $stream = Guard::run(static fn () => \fopen($path, $mode), $warnings);
                    }
                }
            } catch (\Throwable $e) {
                $warnings[] = $e::class . ': ' . $e->getMessage();
            }
            if (!\is_resource($stream)) {
                $spool->close();
                throw self::failure('cannot create the render spool' . ($dir === null ? '' : ' in ' . $dir), $warnings);
            }
            $spool->stream = $stream;
            return $spool;
        }

        /** Appends bytes; only before reading starts. */
        public function write(string $bytes): void
        {
            $stream = $this->handle();
            if ($this->reading) {
                throw new \LogicException('Spool::write() after reading started (a tehsmtp bug)');
            }
            $length = \strlen($bytes);
            $done = 0;
            $warnings = [];
            while ($done < $length) {
                $piece = $done === 0 ? $bytes : \substr($bytes, $done);
                try {
                    $n = Guard::run(static fn () => \fwrite($stream, $piece), $warnings);
                } catch (\Throwable $e) {
                    $warnings[] = $e::class . ': ' . $e->getMessage();
                    $n = false;
                }
                if (!\is_int($n) || $n <= 0) {
                    throw self::failure('writing the render spool failed (disk full?)', $warnings);
                }
                $done += $n;
            }
            $this->size += $length;
        }

        /** Moves to the start; reading has begun. */
        public function rewind(): void
        {
            $stream = $this->handle();
            $this->reading = true;
            $warnings = [];
            try {
                $ok = Guard::run(static fn () => \rewind($stream), $warnings);
            } catch (\Throwable $e) {
                $warnings[] = $e::class . ': ' . $e->getMessage();
                $ok = false;
            }
            if ($ok !== true) {
                throw self::failure('rewinding the render spool failed', $warnings);
            }
        }

        /** Exactly min($max, octets left) octets; '' at the end. */
        public function read(int $max): string
        {
            $stream = $this->handle();
            if ($max < 1) {
                throw new \LogicException("Spool::read(): max must be at least 1, got {$max}");
            }
            $this->reading = true;
            $out = '';
            $warnings = [];
            while (($want = $max - \strlen($out)) > 0) {
                try {
                    $piece = Guard::run(static fn () => \fread($stream, $want), $warnings);
                } catch (\Throwable $e) {
                    $warnings[] = $e::class . ': ' . $e->getMessage();
                    $piece = false;
                }
                if (!\is_string($piece)) {
                    throw self::failure('reading the render spool failed', $warnings);
                }
                if ($piece === '') {
                    break;   // the end: php://temp and plain files read short only there
                }
                $out .= $piece;
            }
            return $out;
        }

        /** The octets written. */
        public function size(): int
        {
            $this->assertUsable();
            return $this->size;
        }

        /** Idempotent and never throws: fclose, then unlink when file-backed. Later reads are a bug. */
        public function close(): void
        {
            if ($this->closed) {
                return;
            }
            $this->closed = true;
            $stream = $this->stream;
            $path = $this->path;
            $this->stream = null;
            $this->path = null;
            if (\is_resource($stream)) {
                try {
                    Guard::run(static fn () => \fclose($stream));
                } catch (\Throwable) {
                    // Nothing more can be done for a handle that will not close.
                }
            }
            if ($path !== null) {
                try {
                    Guard::run(static fn () => \unlink($path));
                } catch (\Throwable) {
                    // The file is 0600 and in the caller's spoolDir; a failed unlink leaves only it.
                }
            }
        }

        /** The backstop when the last holder lets go (D32). */
        public function __destruct()
        {
            $this->close();
        }

        /** A Spool is shared, never copied: two owners would close one stream twice (D32). */
        public function __clone()
        {
            throw new \LogicException('a Spool cannot be cloned; share the object (D32)');
        }

        /** A placeholder: a stream cannot be serialised (§8.4). */
        public function __serialize(): array
        {
            return ['spool' => '[not serialisable]'];
        }

        /** Gives an INERT, closed Spool. */
        public function __unserialize(array $data): void
        {
            $this->inert = true;
            $this->closed = true;
        }

        /** @return resource */
        private function handle()
        {
            $this->assertUsable();
            if ($this->closed || !\is_resource($this->stream)) {
                throw new \LogicException('the render spool is closed (a tehsmtp bug)');
            }
            return $this->stream;
        }

        private function assertUsable(): void
        {
            if ($this->inert) {
                throw new UsageException('this Spool was unserialized and is inert (§8.4)');
            }
        }

        /** @param list<string> $warnings */
        private static function failure(string $message, array $warnings): SmtpException
        {
            return new SmtpException(
                Text::sanitize("render spool: {$message}", 300),
                Reason::Local,
                Outcome::Transient,
                Stage::Prepare,
                Scope::Transaction,
                retrySafe: true,
                details: $warnings === [] ? [] : ['phpWarnings' => $warnings],
            );
        }
    }
}
