<?php
/**
 * tehsmtp: DKIM signing (RFC 6376, RFC 8301, RFC 8463; DESIGN.md §7).
 *
 * Dkim is the signer. Its factories load an RSA or Ed25519 key (scrub, then validate: §8.4),
 * validate the header lists and publish the DNS record; signRendered() signs a Rendered message
 * and verifies its own signature before anything is sent. DkimCanon holds the canonicalisation
 * algorithms (RFC 6376 §3.4), including the streaming body canonicaliser that
 * Rendered::bodyHash() feeds.
 *
 * Loaded only by tehsmtp.php, after the core (§2.1).
 */

declare(strict_types=1);

namespace Tehsmtp;

if (!\class_exists(Dkim::class, false)) {

    /**
     * A DKIM signer (§7): one key, one d=/s= pair, one canonicalisation and one header policy.
     *
     *     $rsa = Dkim::rsa('example.com', 's2026', 'file:///etc/dkim/s2026.pem');          // rsa-sha256
     *     $ed  = Dkim::ed25519('example.com', 'ed2026', getenv('DKIM_ED25519_SEED_B64'));  // needs ext-sodium
     *     $mailer = new Mailer('smtp.example.com', ..., dkim: [$ed, $rsa]);
     *     echo $rsa->dnsRecord();   // s2026._domainkey.example.com. IN TXT ( "v=DKIM1; k=rsa; p=MIIB..." )
     *
     * The Mailer signs each message after rendering and before connecting, so the signed bytes
     * are exactly the bytes sent (I4); every signature is verified before it is used (§7.7). A
     * Dkim signs only messages the library rendered (S8): there is no API for arbitrary bytes.
     *
     * The key stays inside: var_export(), var_dump(), print_r(), array casts and json_encode()
     * show no key material, serialize() gives a placeholder and unserialize() a POISONED signer
     * whose methods throw UsageException (§8.4).
     */
    final class Dkim
    {
        /**
         * The fields signed when present (§7.4; RFC 6376 §5.4.1, RFC 8058 §4). `headers:` replaces
         * this list.
         */
        public const SIGN_SET = [
            'from', 'sender', 'reply-to', 'subject', 'date', 'to', 'cc', 'message-id', 'in-reply-to',
            'references', 'mime-version', 'content-type', 'content-transfer-encoding', 'auto-submitted',
            'list-id', 'list-help', 'list-subscribe', 'list-unsubscribe', 'list-unsubscribe-post',
            'list-post', 'list-owner', 'list-archive', 'resent-date', 'resent-from', 'resent-sender',
            'resent-to', 'resent-cc', 'resent-message-id',
        ];

        /**
         * The fields listed in h= once more than they occur, so that nobody can add one later
         * without breaking the signature (RFC 6376 §5.4, §8.15). List-* is left out on purpose:
         * mailing lists add those fields, and oversigning them breaks list delivery (M14).
         */
        public const OVERSIGN_SET = [
            'from', 'sender', 'reply-to', 'subject', 'date', 'to', 'cc', 'message-id', 'in-reply-to',
            'references', 'mime-version', 'content-type', 'content-transfer-encoding',
        ];

        /**
         * Never listed in h= (§7.4), so refused in `headers:` and `oversign:`; so is every `arc-*`
         * name. They are added or changed in transit (RFC 6376 §5.4.1), and a signature never lists
         * the DKIM-Signature being created (§3.5).
         */
        private const REFUSED = ['dkim-signature', 'authentication-results', 'received', 'return-path', 'comments', 'keywords'];

        /** An unencrypted RFC 8410 Ed25519 PKCS#8 key is exactly this prefix followed by the 32-byte seed. */
        private const ED25519_PKCS8 = "\x30\x2e\x02\x01\x00\x30\x05\x06\x03\x2b\x65\x70\x04\x22\x04\x20";

        /** t= and x= are at most 12 digits (RFC 6376 §3.5). */
        private const MAX_TIME = 999999999999;

        /** Below this, x= warns: retries can deliver a message after its signature expired (RFC 5321 §4.5.4.1). */
        private const SHORT_EXPIRY = 432000;

        /** Folding width of the emitted field (§7.5). */
        private const LINE = 78;

        /** "rsa-sha256" or "ed25519-sha256"; '' when unserialized. */
        public readonly string $algorithm;

        /** d=: an ASCII (A-label) domain, lower-cased; '' when unserialized. */
        public readonly string $domain;

        /** s=, lower-cased; '' when unserialized. */
        public readonly string $selector;

        /** @var list<string> "<code>: <message>" (D24), e.g. "dkim-weak-key: RSA 1024 bits < 2048 recommended" */
        public readonly array $warnings;

        private bool $relaxedHeader = true;
        private bool $relaxedBody = true;

        /** @var list<string> the sign set: lower-cased, de-duplicated, always with "from" */
        private array $headers = [];

        /** @var list<string> a subset of $headers */
        private array $oversign = [];

        private ?int $expireAfter = null;
        private bool $selfVerify = true;

        /** RSA: the private key. Only ever a property or a local, never an argument (V7). */
        private ?\OpenSSLAsymmetricKey $rsaKey = null;

        /** Ed25519: the 64-byte libsodium secret key (seed + public key). */
        private ?Secret $edKey = null;

        /** RSA: the public key as PEM; Ed25519: the raw 32-byte public key. For self-verification. */
        private string $publicKey = '';

        /** The DNS p= value: base64 of the SubjectPublicKeyInfo DER (RSA) or of the raw key (Ed25519). */
        private string $dnsKey = '';

        private bool $poisoned = false;

        private function __construct()
        {
        }

        /**
         * An rsa-sha256 signer (RFC 6376; RFC 8301 for the key size).
         *
         * @param string                            $domain     d=, an ASCII domain (give the A-label of an IDN)
         * @param string                            $selector   s=; the key's DNS name is <selector>._domainkey.<domain>
         * @param string|Secret|\OpenSSLAsymmetricKey $key      PEM (PKCS#1 "RSA PRIVATE KEY", PKCS#8
         *                                                      "PRIVATE KEY" or "ENCRYPTED PRIVATE KEY"), DER,
         *                                                      "file://" + a path to either, or a key object
         * @param string|Secret|null                $passphrase for an encrypted key
         * @param string                            $canonicalization c=: "relaxed/relaxed" (default), "relaxed/simple",
         *                                                      "simple/relaxed", "simple/simple"; a bare "relaxed" or
         *                                                      "simple" names the header algorithm, the body one is simple
         * @param list<string>|null                 $headers    the fields to sign when present (default SIGN_SET);
         *                                                      must include "from"
         * @param list<string>|null                 $oversign   fields listed once more than they occur, a subset
         *                                                      of $headers (default: OVERSIGN_SET within $headers)
         * @param int|null                          $expireAfter x= = t= + this many seconds (none by default);
         *                                                      under 5 days warns "dkim-short-expiry"
         * @param bool                              $selfVerify verify every signature before it is used (§7.7)
         * @throws ConfigException for a bad key or setting, or without ext-openssl (extension: 'openssl')
         */
        public static function rsa(
            string $domain,
            string $selector,
            #[\SensitiveParameter] string|Secret|\OpenSSLAsymmetricKey $key,
            #[\SensitiveParameter] string|Secret|null $passphrase = null,
            string $canonicalization = 'relaxed/relaxed',
            ?array $headers = null,
            ?array $oversign = null,
            ?int $expireAfter = null,
            bool $selfVerify = true,
        ): self {
            // Scrub, then validate (§8.4, V2): the key and the passphrase leave the parameters before
            // anything can throw. A key object moves to a property, so no frame ever has it as an
            // argument (V7). Secrets made here from strings are wiped once the key is loaded.
            $signer = new self();
            $signer->rsaKey = $key instanceof \OpenSSLAsymmetricKey ? $key : null;
            $material = $key instanceof \OpenSSLAsymmetricKey ? null : Secret::wrap($key);
            $created = \is_string($key) ? [$material] : [];
            $secretPassphrase = Secret::wrap($passphrase);
            if (\is_string($passphrase)) {
                $created[] = $secretPassphrase;
            }
            \settype($key, 'null');          // settype(), not "= null": opcache drops a dead store (ScrubTest)
            \settype($passphrase, 'null');
            try {
                if (!\function_exists('openssl_sign')) {
                    throw new ConfigException('Dkim::rsa() needs ext-openssl for rsa-sha256 signing', 'openssl');
                }
                $warnings = $signer->configure('rsa-sha256', $domain, $selector, $canonicalization, $headers, $oversign, $expireAfter, $selfVerify);
                $signer->warnings = [...$warnings, ...$signer->loadRsa($material, $secretPassphrase)];
            } finally {
                foreach ($created as $secret) {
                    $secret->wipe();
                }
            }
            return $signer;
        }

        /**
         * An ed25519-sha256 signer (RFC 8463). Needs ext-sodium.
         *
         * @param string|Secret $key a 32-byte seed; a 64-byte libsodium secret key (seed + public key, which
         *                           must match); base64 of either; an unencrypted PKCS#8 "PRIVATE KEY" PEM
         *                           (RFC 8410); or "file://" + a path to any of these
         * @see Dkim::rsa() for the other parameters
         * @throws ConfigException for a bad key or setting, or without ext-sodium (extension: 'sodium')
         */
        public static function ed25519(
            string $domain,
            string $selector,
            #[\SensitiveParameter] string|Secret $key,
            string $canonicalization = 'relaxed/relaxed',
            ?array $headers = null,
            ?array $oversign = null,
            ?int $expireAfter = null,
            bool $selfVerify = true,
        ): self {
            // Scrub, then validate (§8.4, V2), as in rsa().
            $signer = new self();
            $material = Secret::wrap($key);
            $created = \is_string($key) ? [$material] : [];
            \settype($key, 'null');
            try {
                if (!\function_exists('sodium_crypto_sign_seed_keypair')) {
                    throw new ConfigException('Dkim::ed25519() needs ext-sodium for ed25519-sha256 signing (RFC 8463)', 'sodium');
                }
                $warnings = $signer->configure('ed25519-sha256', $domain, $selector, $canonicalization, $headers, $oversign, $expireAfter, $selfVerify);
                $signer->warnings = [...$warnings, ...$signer->loadEd25519($material)];
            } finally {
                foreach ($created as $secret) {
                    $secret->wipe();
                }
            }
            return $signer;
        }

        /** The key's DNS name, "<selector>._domainkey.<domain>" (no trailing dot). */
        public function dnsName(): string
        {
            $this->assertUsable();
            return "{$this->selector}._domainkey.{$this->domain}";
        }

        /** The TXT record value: "v=DKIM1; k=rsa; p=<SPKI DER, base64>" or "v=DKIM1; k=ed25519; p=<raw key, base64>" (§7.9). */
        public function dnsValue(): string
        {
            $this->assertUsable();
            return 'v=DKIM1; k=' . ($this->algorithm === 'rsa-sha256' ? 'rsa' : 'ed25519') . '; p=' . $this->dnsKey;
        }

        /** A zone-file line, the value split into TXT strings of at most 255 characters (RFC 1035 §3.3.14). */
        public function dnsRecord(): string
        {
            return $this->dnsName() . '. IN TXT ( "' . \implode('" "', \str_split($this->dnsValue(), 255)) . '" )';
        }

        /**
         * The "dkim-misaligned" warning when d= does not align (relaxed) with the From domain,
         * else null (§7.9). The Mailer adds it to Result->warnings.
         *
         * @internal
         */
        public function misalignment(string $fromDomain): ?string
        {
            $this->assertUsable();
            return self::aligned($this->domain, $fromDomain) ? null
                : "dkim-misaligned: d={$this->domain} does not align with the From domain "
                    . Text::sanitize($fromDomain, 255) . ' (DMARC relaxed alignment, RFC 7489 §3.1)';
        }

        /**
         * Relaxed alignment (RFC 7489 §3.1), approximated without a public-suffix list: the domains
         * are equal or one is a subdomain of the other, compared case-insensitively (a trailing dot
         * ignored). Sibling subdomains of one organisation (a.example.com, b.example.com) count as
         * not aligned, so this advisory check errs towards warning.
         *
         * @internal also used by the Mailer for "envelope-misaligned" (§6.4)
         */
        public static function aligned(string $a, string $b): bool
        {
            $a = Text::lower(\rtrim($a, '.'));
            $b = Text::lower(\rtrim($b, '.'));
            return $a !== '' && $b !== ''
                && ($a === $b || \str_ends_with($a, '.' . $b) || \str_ends_with($b, '.' . $a));
        }

        /**
         * Signs a rendered message (§7.1): re-parses the header section from the rendered bytes,
         * applies the RFC 8058 rule, hashes the body through Rendered::bodyHash() and returns the
         * DKIM-Signature field, ending in CRLF, for the caller to prepend. Each signer signs the
         * UNSIGNED header section; h= never lists DKIM-Signature.
         *
         * @internal
         * @param int $time t=, Unix seconds from the Mailer's Clock
         * @throws ConfigException for a message with List-Unsubscribe-Post whose List-Unsubscribe
         *                         fields this signer does not sign (RFC 8058 §4), or a clock outside
         *                         t='s 12 digits
         * @throws UsageException  for an unserialized signer
         * @throws \LogicException when the signature does not verify (a library bug, §7.7)
         */
        public function signRendered(Rendered $r, int $time): string
        {
            $this->assertUsable();
            $fields = self::fields($r->headers);
            $names = $this->signedNames($fields);
            return $this->sign($r->headers, $fields, $names, \base64_encode($r->bodyHash($this->relaxedBody)), $time);
        }

        /**
         * The check signRendered() makes before hashing anything (§7.1 step 4): a message with
         * List-Unsubscribe-Post needs both List-Unsubscribe fields in this signer's h= (RFC 8058
         * §4). The Mailer runs it for every signer first, so nothing is signed when one signer
         * cannot sign the message.
         *
         * @internal
         * @throws ConfigException for the RFC 8058 §4 rule
         * @throws UsageException  for an unserialized signer
         */
        public function assertSignable(Rendered $r): void
        {
            $this->assertUsable();
            $this->signedNames(self::fields($r->headers));
        }

        /**
         * Test seam (§7.7): signs a caller-supplied DKIM-Signature template byte for byte and returns
         * the complete field (the template, the folded b= value, CRLF). The template must end in
         * "b=", and its a= and c= must be this signer's; its h= picks the fields of $headerSection
         * (bottom-up; a whole message may be given, it is cut at the first blank line). That
         * reproduces RFC 8463 Appendix A, with its i=, q= and "from : to" spacing, exactly.
         *
         * @internal
         */
        public function signTemplate(string $headerSection, string $unsignedField): string
        {
            $this->assertUsable();
            $end = \strpos($headerSection, "\r\n\r\n");
            $section = $end === false ? $headerSection : \substr($headerSection, 0, $end + 2);
            $template = self::fields($unsignedField . "\r\n");
            $tags = $template[0][0] === 'dkim-signature' && \count($template) === 1 && \str_ends_with($unsignedField, 'b=')
                ? self::tags(\substr($unsignedField, \strpos($unsignedField, ':') + 1)) : null;
            if ($tags === null) {
                throw new \LogicException('Dkim::signTemplate(): the template must be one DKIM-Signature field whose tag list ends in "b="');
            }
            if (($tags['a'][0] ?? '') !== $this->algorithm
                || self::canonPair($tags['c'][0] ?? 'simple/simple') !== [$this->relaxedHeader, $this->relaxedBody]) {
                throw new \LogicException("Dkim::signTemplate(): the template's a= and c= must be this signer's: "
                    . $this->algorithm . ', ' . $this->canonicalization());
            }
            $names = self::headerList($tags['h'][0] ?? '');
            if ($names === [] || \in_array('dkim-signature', $names, true)) {
                throw new \LogicException('Dkim::signTemplate(): the template needs an h= list without dkim-signature');
            }
            $input = self::hashInput(self::fields($section), $names, $unsignedField, $this->relaxedHeader);
            $field = self::emit($unsignedField, \base64_encode($this->signature($input)));
            if ($this->selfVerify) {
                $this->verify($field, $section, \preg_replace('/[ \t\r\n]+/', '', $tags['bh'][0] ?? '') ?? '');
            }
            return $field;
        }

        /** Masked: no key material, only the settings. */
        public function __debugInfo(): array
        {
            return [
                'algorithm' => $this->algorithm ?? '',
                'domain' => $this->domain ?? '',
                'selector' => $this->selector ?? '',
                'canonicalization' => $this->canonicalization(),
                'headers' => $this->headers,
                'oversign' => $this->oversign,
                'expireAfter' => $this->expireAfter,
                'selfVerify' => $this->selfVerify,
                'warnings' => $this->warnings ?? [],
                'key' => $this->poisoned ? '[none: unserialized]' : '[redacted]',
            ];
        }

        /** A placeholder: the key is never serialised (§8.4). */
        public function __serialize(): array
        {
            return ['dkim' => 'd=' . ($this->domain ?? '') . '; s=' . ($this->selector ?? '') . '; [key redacted]'];
        }

        /** Gives a POISONED signer: signing and the DNS helpers throw UsageException (§8.4). */
        public function __unserialize(array $data): void
        {
            $this->edKey?->wipe();
            $this->edKey = null;
            $this->rsaKey = null;
            $this->poisoned = true;
            if (!isset($this->algorithm)) {   // unserialize() made this object: initialise the public fields
                $this->algorithm = '';
                $this->domain = '';
                $this->selector = '';
                $this->warnings = [];
            }
        }

        /** A clone gets its own copy of the Ed25519 key, so the destructor of one never wipes the other's. */
        public function __clone()
        {
            if ($this->edKey !== null) {
                $this->edKey = clone $this->edKey;
            }
        }

        public function __destruct()
        {
            $this->edKey?->wipe();
        }

        // -----------------------------------------------------------------------------------------
        // Configuration and keys (§7.4, §7.5, §7.8)
        // -----------------------------------------------------------------------------------------

        /**
         * Validates and stores everything but the key.
         *
         * @return list<string> warnings
         */
        private function configure(
            string $algorithm,
            string $domain,
            string $selector,
            string $canonicalization,
            ?array $headers,
            ?array $oversign,
            ?int $expireAfter,
            bool $selfVerify,
        ): array {
            $this->algorithm = $algorithm;
            $this->domain = self::dnsLabels($domain, 'domain (d=)', 2);
            $this->selector = self::dnsLabels($selector, 'selector (s=)', 1);
            if (\strlen($this->dnsName()) > 253) {
                throw new ConfigException("Dkim: the DNS name {$this->dnsName()} is longer than 253 characters");
            }

            $pair = self::canonPair($canonicalization);
            if ($pair === null) {
                throw new ConfigException('Dkim canonicalization "' . Text::sanitize($canonicalization, 60) . '" is not one of'
                    . ' relaxed/relaxed, relaxed/simple, simple/relaxed, simple/simple, relaxed, simple');
            }
            [$this->relaxedHeader, $this->relaxedBody] = $pair;

            $this->headers = $headers === null ? self::SIGN_SET : self::fieldNames($headers, 'headers');
            if (!\in_array('from', $this->headers, true)) {
                throw new ConfigException('Dkim headers must include "from" (RFC 6376 §5.4)');
            }
            if ($oversign === null) {
                $this->oversign = \array_values(\array_intersect(self::OVERSIGN_SET, $this->headers));
            } else {
                $this->oversign = self::fieldNames($oversign, 'oversign');
                $unsigned = \array_diff($this->oversign, $this->headers);
                if ($unsigned !== []) {
                    throw new ConfigException('Dkim oversign lists ' . \implode(', ', $unsigned)
                        . ', which headers does not sign: oversigning a field means listing it once more than it occurs');
                }
            }

            $warnings = [];
            if ($expireAfter !== null) {
                if ($expireAfter < 1 || $expireAfter > self::MAX_TIME) {
                    throw new ConfigException("Dkim expireAfter must be 1 to " . self::MAX_TIME . " seconds (x= must exceed t=, RFC 6376 §3.5), got {$expireAfter}");
                }
                if ($expireAfter < self::SHORT_EXPIRY) {
                    $warnings[] = "dkim-short-expiry: x= is {$expireAfter} s after t=; under 5 days, a message still"
                        . ' queued for retry can arrive with an expired signature';
                }
            }
            $this->expireAfter = $expireAfter;
            $this->selfVerify = $selfVerify;
            return $warnings;
        }

        /**
         * A d= or s= value: ASCII DNS labels (letters, digits, inner hyphens; 1-63 characters),
         * returned lower-cased. d= has at least two labels (RFC 6376 §3.5 domain-name, selector).
         * A U-label is refused: d= must be the A-label (message item 11.7).
         */
        private static function dnsLabels(string $name, string $what, int $minLabels): string
        {
            $shown = Text::sanitize($name, 100);
            if (\preg_match('/[\x80-\xFF]/', $name) === 1) {
                throw new ConfigException("Dkim {$what} \"{$shown}\" is not ASCII: give the A-label (xn--...) of an international domain");
            }
            $label = '[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?';
            $more = $minLabels > 1 ? '+' : '*';
            if (\strlen($name) > 253 || \preg_match('/\A' . $label . '(?:\.' . $label . ')' . $more . '\z/', $name) !== 1) {
                throw new ConfigException("Dkim {$what} \"{$shown}\" is not valid: " . ($minLabels > 1 ? 'a domain of at least two' : 'one or more')
                    . ' dot-separated labels of letters, digits and inner hyphens, no trailing dot');
            }
            return Text::lower($name);
        }

        /**
         * `headers:` or `oversign:` entries: RFC 5322 field names, lower-cased and de-duplicated;
         * DKIM-Signature, ARC-*, Authentication-Results, Received, Return-Path, Comments and Keywords
         * are refused (§7.4).
         *
         * @return list<string>
         */
        private static function fieldNames(array $names, string $what): array
        {
            $out = [];
            foreach ($names as $key => $name) {
                $at = 'Dkim ' . $what . '[' . Text::sanitize((string) $key, 40) . ']';
                if (!\is_string($name)) {
                    throw new ConfigException("{$at}: expected a header field name, got " . \get_debug_type($name));
                }
                if (!Text::isFieldName($name)) {
                    throw new ConfigException("{$at}: \"" . Text::sanitize($name, 80) . '" is not a header field name (1-76 printable ASCII characters, no SP or ":")');
                }
                $lower = Text::lower($name);
                if (\in_array($lower, self::REFUSED, true) || \str_starts_with($lower, 'arc-')) {
                    throw new ConfigException("{$at}: {$lower} must not be signed; it is added or changed in transit (RFC 6376 §3.5, §5.4.1)");
                }
                if (!\in_array($lower, $out, true)) {
                    $out[] = $lower;
                }
            }
            return $out;
        }

        /**
         * Loads the RSA key from $material (§7.8), or takes the key object rsa() already moved into
         * $this->rsaKey ($material null), then checks it: an RSA private key of at least 1024 bits,
         * warning below 2048 (RFC 8301 §3.2). Every call that receives key material runs inside
         * Guard; a Throwable from one is replaced by a fresh exception carrying only its message
         * (ConfigException, or UsageException for an unserialized passphrase Secret), and the
         * OpenSSL error queue is drained before each call and read only after a failure (§8.4, §7.6).
         *
         * @return list<string> warnings
         */
        private function loadRsa(?Secret $material, ?Secret $passphrase): array
        {
            $warnings = [];
            $deprecations = [];
            $created = [];
            try {
                if ($material !== null) {
                    $pem = self::source($material, $created, $warnings, $deprecations);
                    $label = self::pemLabel($pem);
                    if ($label === null) {
                        if (!\str_starts_with($pem->reveal(), "\x30")) {   // DER begins with an ASN.1 SEQUENCE
                            throw new ConfigException('the DKIM RSA key is neither PEM nor DER; for a key file, pass "file://" followed by its path');
                        }
                        // DER is loaded through PEM armour: PKCS#8 first, then PKCS#1 (and encrypted PKCS#8).
                        $labels = $passphrase === null ? ['PRIVATE KEY', 'RSA PRIVATE KEY'] : ['ENCRYPTED PRIVATE KEY', 'PRIVATE KEY', 'RSA PRIVATE KEY'];
                        $candidates = [];
                        foreach ($labels as $armour) {
                            $candidates[] = $created[] = Secret::of("-----BEGIN {$armour}-----\n"
                                . \implode("\n", \str_split(\base64_encode($pem->reveal()), 64)) . "\n-----END {$armour}-----\n");
                        }
                    } else {
                        self::refuseLabel($label, 'RSA', 'e.g. ssh-keygen -p -m PKCS8 -f <a copy of the key file>');
                        $encrypted = $label === 'ENCRYPTED PRIVATE KEY' || \str_contains($pem->reveal(), 'Proc-Type: 4,ENCRYPTED');
                        if ($encrypted && $passphrase === null) {
                            throw new ConfigException('the DKIM RSA key is encrypted: pass its passphrase (passphrase:)');
                        }
                        $candidates = [$pem];
                    }
                    $errors = [];
                    foreach ($candidates as $candidate) {
                        Guard::clearOpenssl();
                        try {
                            $loaded = Guard::run(static fn () => \openssl_pkey_get_private($candidate->reveal(), $passphrase?->reveal()), $warnings, $deprecations);
                        } catch (\Throwable $e) {
                            // Only class and message survive: the discarded trace may hold the key (§8.4).
                            // An unserialized passphrase Secret is misuse, as everywhere else (§8.1).
                            throw $e instanceof UsageException ? new UsageException($e->getMessage())
                                : new ConfigException(self::failure('could not load the DKIM RSA key', [$e::class . ': ' . $e->getMessage()]));
                        }
                        if ($loaded instanceof \OpenSSLAsymmetricKey) {
                            $this->rsaKey = $loaded;
                            break;
                        }
                        $errors = Guard::opensslErrors();
                    }
                    if ($this->rsaKey === null) {
                        throw new ConfigException(self::failure('could not load the DKIM RSA key'
                            . ($passphrase === null ? '' : ' (is the passphrase right?)'), [...$warnings, ...$errors]));
                    }
                }

                $key = $this->rsaKey;
                try {
                    $details = Guard::run(static fn () => \openssl_pkey_get_details($key), $warnings, $deprecations);
                } catch (\Throwable $e) {
                    throw new ConfigException(self::failure('could not inspect the DKIM RSA key', [$e::class . ': ' . $e->getMessage()]));
                }
                if (!\is_array($details) || ($details['type'] ?? null) !== \OPENSSL_KEYTYPE_RSA) {
                    $type = \is_array($details) ? self::keyType($details['type'] ?? null) : 'unreadable';
                    throw new ConfigException("the DKIM key is not an RSA key ({$type}): Dkim::rsa() signs rsa-sha256; an Ed25519 key goes to Dkim::ed25519()");
                }
                if (!isset($details['rsa']['d'])) {
                    throw new ConfigException('the DKIM RSA key is a public key: pass the private key (the public key goes in DNS, see dnsRecord())');
                }
                $bits = (int) $details['bits'];
                if ($bits < 1024) {
                    throw new ConfigException("the DKIM RSA key has {$bits} bits: DKIM requires at least 1024 (RFC 8301 §3.2); use 2048");
                }
                $this->publicKey = (string) $details['key'];
                $this->dnsKey = (string) \preg_replace('/-----[A-Z ]+-----|\s+/', '', $this->publicKey);
            } finally {
                foreach ($created as $secret) {
                    $secret->wipe();
                }
            }
            return [
                ...($bits < 2048 ? ["dkim-weak-key: RSA {$bits} bits < 2048 recommended"] : []),
                ...\array_map(static fn (string $d): string => "php-deprecation: {$d}", $deprecations),
            ];
        }

        /**
         * Loads the Ed25519 key (§7.8) and derives the keypair with sodium_crypto_sign_seed_keypair().
         * Intermediate buffers are wiped with sodium_memzero() (which zeroes only buffers nothing
         * else shares, so the caller's own Secret is never damaged).
         *
         * @return list<string> warnings
         */
        private function loadEd25519(Secret $material): array
        {
            $warnings = [];
            $deprecations = [];
            $created = [];
            $m = [];
            $der = $bytes = $seed = $keypair = $secret = null;
            try {
                $source = self::source($material, $created, $warnings, $deprecations);
                $length = \strlen($source->reveal());
                $label = self::pemLabel($source);
                if ($length === 32 || $length === 64) {
                    $bytes = $source->reveal();   // a raw seed, or seed + public key
                } elseif ($label !== null) {
                    self::refuseLabel($label, 'Ed25519', 'e.g. generate one with openssl genpkey -algorithm ed25519, or pass the 32-byte seed');
                    if ($label !== 'PRIVATE KEY'
                        || \preg_match('/-----BEGIN PRIVATE KEY-----([A-Za-z0-9+\/=\s]*)-----END PRIVATE KEY-----/', $source->reveal(), $m) !== 1) {
                        throw new ConfigException('the DKIM Ed25519 key PEM must be an unencrypted PKCS#8 "PRIVATE KEY"');
                    }
                    $der = \base64_decode($m[1], true);
                    if (!\is_string($der) || \strlen($der) !== 48 || !\str_starts_with($der, self::ED25519_PKCS8)) {
                        throw new ConfigException('the DKIM key is not an Ed25519 PKCS#8 key (RFC 8410: 48 bytes of DER, prefix 302e020100300506032b657004220420)');
                    }
                    $bytes = \substr($der, 16);
                } else {
                    $bytes = \base64_decode($source->reveal(), true);   // strict; ASCII whitespace is skipped
                    if (!\is_string($bytes) || (\strlen($bytes) !== 32 && \strlen($bytes) !== 64)) {
                        throw new ConfigException('the DKIM Ed25519 key must be a 32-byte seed, a 64-byte secret key (seed + public key),'
                            . ' base64 of either, a PKCS#8 PEM, or "file://" followed by the path of a file holding one of these');
                    }
                }
                $seed = \substr($bytes, 0, 32);
                try {
                    $keypair = Guard::run(static fn () => \sodium_crypto_sign_seed_keypair($seed), $warnings, $deprecations);
                    $secret = Guard::run(static fn () => \sodium_crypto_sign_secretkey($keypair), $warnings, $deprecations);
                    $this->publicKey = Guard::run(static fn () => \sodium_crypto_sign_publickey($keypair), $warnings, $deprecations);
                } catch (\Throwable $e) {
                    throw new ConfigException(self::failure('could not derive the DKIM Ed25519 keypair', [$e::class . ': ' . $e->getMessage()]));
                }
                if (\strlen($bytes) === 64 && !\hash_equals($this->publicKey, \substr($bytes, 32))) {
                    throw new ConfigException('the 64-byte DKIM Ed25519 secret key is inconsistent: its public half does not match its seed'
                        . ' (a hex-encoded seed is 64 characters too: decode it with hex2bin() first)');
                }
                $this->edKey = Secret::of($secret);
                $this->dnsKey = \base64_encode($this->publicKey);
            } finally {
                // Every intermediate copy of the key is wiped; a buffer still shared (a raw key that
                // is the caller's own string, the secret key now inside $this->edKey) is released only.
                foreach ($m as $i => $unused) {
                    \sodium_memzero($m[$i]);
                }
                if (\is_string($der)) {
                    \sodium_memzero($der);
                }
                if (\is_string($bytes)) {
                    \sodium_memzero($bytes);
                }
                if (\is_string($seed)) {
                    \sodium_memzero($seed);
                }
                if (\is_string($keypair)) {
                    \sodium_memzero($keypair);
                }
                if (\is_string($secret)) {
                    \sodium_memzero($secret);
                }
                foreach ($created as $each) {
                    $each->wipe();
                }
            }
            return \array_map(static fn (string $d): string => "php-deprecation: {$d}", $deprecations);
        }

        /**
         * The key bytes: $material itself, or the contents of the file a "file://" key names (read
         * into a Secret that the loader wipes afterwards).
         *
         * @param list<Secret> $created receives the Secret made here
         */
        private static function source(Secret $material, array &$created, array &$warnings, array &$deprecations): Secret
        {
            if (\strlen($material->reveal()) === 0) {
                throw new ConfigException('the DKIM key is empty');
            }
            if (!\str_starts_with($material->reveal(), 'file://')) {
                return $material;
            }
            $path = \substr($material->reveal(), 7);
            $shown = Text::sanitize($path, 200);
            try {
                $isFile = Guard::run(static fn () => \is_file($path), $warnings, $deprecations);
                $contents = $isFile ? Guard::run(static fn () => \file_get_contents($path), $warnings, $deprecations) : false;
            } catch (\Throwable $e) {
                $isFile = $contents = false;
                $warnings[] = $e::class . ': ' . $e->getMessage();
            }
            if (!$isFile) {
                throw new ConfigException(self::failure("the DKIM key file {$shown} does not exist or is not a file", $warnings));
            }
            if (!\is_string($contents)) {
                throw new ConfigException(self::failure("could not read the DKIM key file {$shown}", $warnings));
            }
            $secret = Secret::of($contents);
            $created[] = $secret;
            return $secret;
        }

        /** The label of the first PEM armour line ("RSA PRIVATE KEY", ...), or null when there is none. */
        private static function pemLabel(Secret $key): ?string
        {
            return \preg_match('/-----BEGIN ([A-Z0-9 ]{1,40})-----/', $key->reveal(), $m) === 1 ? $m[1] : null;
        }

        /** Refuses OpenSSH keys (with a conversion hint), public keys and certificates. */
        private static function refuseLabel(string $label, string $kind, string $hint): void
        {
            if ($label === 'OPENSSH PRIVATE KEY') {
                throw new ConfigException("the DKIM {$kind} key is in OpenSSH format, which cannot be read here: use a PKCS#8 PEM key instead, " . $hint);
            }
            if (\str_contains($label, 'PUBLIC KEY') || $label === 'CERTIFICATE') {
                throw new ConfigException("the DKIM {$kind} key is a {$label}: pass the private key (the public key goes in DNS, see dnsRecord())");
            }
        }

        /** A readable name for an OPENSSL_KEYTYPE_* value. */
        private static function keyType(mixed $type): string
        {
            return match ($type) {
                \OPENSSL_KEYTYPE_EC => 'an EC key',
                \OPENSSL_KEYTYPE_DSA => 'a DSA key',
                \OPENSSL_KEYTYPE_DH => 'a DH key',
                default => 'a key of another type',
            };
        }

        /**
         * "relaxed/simple" and the like as [relaxedHeader, relaxedBody]; a bare algorithm names the
         * header one and the body one is simple (RFC 6376 §3.5). Null when invalid.
         *
         * @return array{bool, bool}|null
         */
        private static function canonPair(string $c): ?array
        {
            $parts = \explode('/', Text::lower($c));
            if (\count($parts) === 1) {
                $parts[] = 'simple';
            }
            $known = ['simple' => false, 'relaxed' => true];
            return \count($parts) === 2 && isset($known[$parts[0]], $known[$parts[1]]) ? [$known[$parts[0]], $known[$parts[1]]] : null;
        }

        private function canonicalization(): string
        {
            return ($this->relaxedHeader ? 'relaxed' : 'simple') . '/' . ($this->relaxedBody ? 'relaxed' : 'simple');
        }

        private function assertUsable(): void
        {
            if ($this->poisoned) {
                throw new UsageException('this Dkim was unserialized and holds no key (§8.4)');
            }
        }

        /** An error message with the collected details (PHP warnings, OpenSSL errors), sanitised (§8.5). */
        private static function failure(string $what, array $details): string
        {
            $details = \array_values(\array_filter($details, static fn (string $d): bool => $d !== ''));
            return $what . ($details === [] ? '' : ': ' . Text::sanitize(\implode('; ', $details), 400));
        }

        // -----------------------------------------------------------------------------------------
        // Signing (§7.1, §7.4-§7.7)
        // -----------------------------------------------------------------------------------------

        /**
         * Splits a header section into fields (§7.1): CRLF-terminated lines, where a line starting
         * with SP or HTAB continues the previous field. The section is the library's own rendering,
         * so anything else (a bare CR or LF, a blank line, a line without a colon) is a library bug:
         * the signer never normalises, because the sent bytes would then differ from the signed ones.
         *
         * @return list<array{string, string}> [lower-cased name, the field without its final CRLF]
         */
        private static function fields(string $section): array
        {
            if ($section === '') {
                return [];
            }
            if (!\str_ends_with($section, "\r\n") || \str_starts_with($section, "\r\n") || \str_contains($section, "\r\n\r\n")
                || \preg_match('/\r(?!\n)|(?<!\r)\n/', $section) === 1) {
                throw new \LogicException('DKIM: the header section is not a list of CRLF-terminated fields (a tehsmtp bug)');
            }
            $fields = [];
            foreach (\explode("\r\n", \substr($section, 0, -2)) as $line) {
                if ($line[0] === ' ' || $line[0] === "\t") {
                    if ($fields === []) {
                        throw new \LogicException('DKIM: the header section starts with a continuation line (a tehsmtp bug)');
                    }
                    $fields[\array_key_last($fields)][1] .= "\r\n" . $line;
                    continue;
                }
                $colon = \strpos($line, ':');
                if ($colon === false || $colon === 0) {
                    throw new \LogicException('DKIM: a header line is not a field (a tehsmtp bug): ' . Text::sanitize($line, 80));
                }
                $fields[] = [Text::lower(\rtrim(\substr($line, 0, $colon), " \t")), $line];
            }
            return $fields;
        }

        /**
         * The h= list (§7.4): each sign-set name once per occurrence, then each oversign name once
         * more; an absent oversigned name contributes nothing to the hash (RFC 6376 §5.4.2).
         *
         * @param list<array{string, string}> $fields
         * @return list<string>
         * @throws ConfigException for the RFC 8058 §4 rule
         */
        private function signedNames(array $fields): array
        {
            $counts = \array_count_values(\array_column($fields, 0));
            if (!isset($counts['from'])) {
                throw new \LogicException('DKIM: the message has no From field (a tehsmtp bug)');
            }
            $names = [];
            foreach ($this->headers as $name) {
                for ($n = $counts[$name] ?? 0; $n > 0; $n--) {
                    $names[] = $name;
                }
            }
            \array_push($names, ...$this->oversign);
            if (isset($counts['list-unsubscribe-post'])
                && (!\in_array('list-unsubscribe', $names, true) || !\in_array('list-unsubscribe-post', $names, true))) {
                throw new ConfigException("signer d={$this->domain}; s={$this->selector} does not sign List-Unsubscribe/List-Unsubscribe-Post:"
                    . ' a one-click unsubscribe message needs both in the DKIM signature (RFC 8058 §4)');
            }
            return $names;
        }

        /**
         * Builds the template, signs it and emits the field; then verifies it (§7.5-§7.7).
         *
         * @param list<array{string, string}> $fields the fields of $section
         * @param list<string>                $names  h=
         */
        private function sign(string $section, array $fields, array $names, string $bodyHash, int $time): string
        {
            $expires = $this->expireAfter === null ? null : $time + $this->expireAfter;
            if ($time < 0 || $time > self::MAX_TIME || ($expires !== null && $expires > self::MAX_TIME)) {
                throw new ConfigException("Dkim: t= and x= must be 0 to " . self::MAX_TIME . " (RFC 6376 §3.5); the clock gave {$time}");
            }
            $template = $this->template($names, $bodyHash, $time, $expires);
            $input = self::hashInput($fields, $names, $template, $this->relaxedHeader);
            $field = self::emit($template, \base64_encode($this->signature($input)));
            if ($this->selfVerify) {
                $this->verify($field, $section, $bodyHash);
            }
            return $field;
        }

        /**
         * The unsigned DKIM-Signature field (§7.5): tags in the fixed order v a c d s t [x] h bh b,
         * never l=, i=, q= or z=; folded BEFORE hashing, with CRLF + TAB in place of the SP after
         * a ";" or after a ":" inside h=, so that no line exceeds 78 characters; ending exactly in "b=".
         *
         * @param list<string> $names
         */
        private function template(array $names, string $bodyHash, int $time, ?int $expires): string
        {
            $pieces = [];   // list<array{string $glue, string $text}>
            $tags = ['v=1', "a={$this->algorithm}", 'c=' . $this->canonicalization(), "d={$this->domain}", "s={$this->selector}", "t={$time}"];
            if ($expires !== null) {
                $tags[] = "x={$expires}";
            }
            foreach ($tags as $tag) {
                $pieces[] = [' ', "{$tag};"];
            }
            $last = \count($names) - 1;
            foreach ($names as $i => $name) {
                $pieces[] = $i === 0 ? [' ', 'h=' . $name . ($last === 0 ? ';' : ':')] : ['', $name . ($i === $last ? ';' : ':')];
            }
            $pieces[] = [' ', "bh={$bodyHash};"];
            $pieces[] = [' ', 'b='];

            $out = 'DKIM-Signature:';
            $line = \strlen($out);
            foreach ($pieces as [$glue, $text]) {
                if ($line + \strlen($glue) + \strlen($text) > self::LINE) {
                    $out .= "\r\n\t" . $text;
                    $line = 1 + \strlen($text);
                } else {
                    $out .= $glue . $text;
                    $line += \strlen($glue) + \strlen($text);
                }
            }
            return $out;
        }

        /**
         * The emitted field (§7.6): the template byte for byte, then the base64 b= value folded with
         * CRLF + TAB at 78 characters, then CRLF.
         */
        private static function emit(string $template, string $signature): string
        {
            $lastLine = \strrpos($template, "\n");
            $room = \max(0, self::LINE - (\strlen($template) - ($lastLine === false ? 0 : $lastLine + 1)));
            $field = $template . \substr($signature, 0, $room);
            $rest = \substr($signature, $room);
            if ($rest !== '') {
                $field .= "\r\n\t" . \implode("\r\n\t", \str_split($rest, self::LINE - 1));
            }
            return $field . "\r\n";
        }

        /**
         * The header hash input (§7.6, RFC 6376 §3.7): each field h= selects, canonicalised, WITH its
         * CRLF; then the canonicalised unsigned signature field WITHOUT a trailing CRLF.
         *
         * @param list<array{string, string}> $fields
         * @param list<string>                $names
         */
        private static function hashInput(array $fields, array $names, string $unsigned, bool $relaxed): string
        {
            $input = '';
            foreach (self::select($fields, $names) as $field) {
                $input .= DkimCanon::header($field, $relaxed) . "\r\n";
            }
            return $input . DkimCanon::header($unsigned, $relaxed);
        }

        /**
         * Bottom-up selection (RFC 6376 §5.4.2): the k-th mention of a name in h= takes the k-th
         * instance of that field counted from the BOTTOM of the header; a name listed more often
         * than the field occurs selects nothing (M17).
         *
         * @param list<array{string, string}> $fields
         * @param list<string>                $names
         * @return list<string>
         */
        private static function select(array $fields, array $names): array
        {
            $taken = [];
            $selected = [];
            foreach ($names as $name) {
                $k = $taken[$name] = ($taken[$name] ?? 0) + 1;
                for ($i = \count($fields) - 1; $i >= 0; $i--) {
                    if ($fields[$i][0] === $name && --$k === 0) {
                        $selected[] = $fields[$i][1];
                        break;
                    }
                }
            }
            return $selected;
        }

        /**
         * Signs the header hash input (§7.6). RSA: openssl_sign() with OPENSSL_ALGO_SHA256 always
         * passed (the default is SHA-1) and no pre-hash (M7-M9). Ed25519: the SHA-256 of the input,
         * which RFC 8463 §3 signs with PureEdDSA. A failure becomes a fresh ConfigException with only
         * the class and message of what failed (§8.4).
         */
        private function signature(string $input): string
        {
            $warnings = [];
            if ($this->algorithm === 'rsa-sha256') {
                $key = $this->rsaKey;
                $signature = '';
                Guard::clearOpenssl();
                try {
                    $ok = Guard::run(static function () use ($input, &$signature, $key): bool {
                        return \openssl_sign($input, $signature, $key, \OPENSSL_ALGO_SHA256);
                    }, $warnings);
                } catch (\Throwable $e) {
                    $ok = false;
                    $warnings[] = $e::class . ': ' . $e->getMessage();
                }
                if ($ok !== true) {
                    throw new ConfigException(self::failure("DKIM rsa-sha256 signing failed for d={$this->domain}; s={$this->selector}",
                        [...$warnings, ...Guard::opensslErrors()]));
                }
                return $signature;
            }
            $key = $this->edKey;
            $digest = \hash('sha256', $input, true);
            try {
                return Guard::run(static fn (): string => \sodium_crypto_sign_detached($digest, $key->reveal()), $warnings);
            } catch (\Throwable $e) {
                throw new ConfigException(self::failure("DKIM ed25519-sha256 signing failed for d={$this->domain}; s={$this->selector}",
                    [...$warnings, $e::class . ': ' . $e->getMessage()]));
            }
        }

        /**
         * Self-verification (§7.7): re-parses the emitted field from the final header bytes, checks
         * its tags, deletes the b= value as RFC 6376 §3.7 describes, recomputes the header hash from
         * the PARSED h= and c=, and verifies the signature with the public key derived at load time.
         * This catches any drift between the bytes hashed and the bytes emitted.
         *
         * @throws \LogicException on any mismatch (a library bug)
         */
        private function verify(string $field, string $section, string $bodyHash): void
        {
            $fields = self::fields($field . $section);
            [$name, $emitted] = $fields[0];
            if ($name !== 'dkim-signature' || \strlen($emitted) + 2 !== \strlen($field)) {
                throw $this->drift('the emitted bytes do not re-parse as one DKIM-Signature field');
            }
            $start = \strpos($emitted, ':') + 1;
            $tags = self::tags(\substr($emitted, $start));
            if ($tags === null || \array_key_last($tags) !== 'b') {
                throw $this->drift('its tag list does not parse, or b= is not the last tag');
            }
            $pair = self::canonPair($tags['c'][0] ?? 'simple/simple');
            $expected = ['v' => '1', 'a' => $this->algorithm, 'd' => $this->domain, 's' => $this->selector];
            foreach ($expected as $tag => $value) {
                if (($tags[$tag][0] ?? null) !== $value) {
                    throw $this->drift("{$tag}= is not {$value}");
                }
            }
            if ($pair !== [$this->relaxedHeader, $this->relaxedBody]) {
                throw $this->drift("c= is not {$this->canonicalization()}");
            }
            if (\preg_replace('/[ \t\r\n]+/', '', $tags['bh'][0] ?? '') !== $bodyHash) {
                throw $this->drift('bh= is not the body hash');
            }
            $names = self::headerList($tags['h'][0] ?? '');
            if ($names === [] || !\in_array('from', $names, true) || \in_array('dkim-signature', $names, true)) {
                throw $this->drift('h= is empty, lacks from, or lists dkim-signature');
            }
            [, $valueStart, $valueEnd] = $tags['b'];
            $unsigned = \substr($emitted, 0, $start + $valueStart) . \substr($emitted, $start + $valueEnd);
            $signature = \base64_decode((string) \preg_replace('/[ \t\r\n]+/', '', $tags['b'][0]), true);
            if (!\is_string($signature) || $signature === '') {
                throw $this->drift('b= is not base64');
            }
            $input = self::hashInput($fields, $names, $unsigned, $pair[0]);
            $warnings = [];
            $publicKey = $this->publicKey;
            Guard::clearOpenssl();
            try {
                if ($this->algorithm === 'rsa-sha256') {
                    $verified = Guard::run(static fn () => \openssl_verify($input, $signature, $publicKey, \OPENSSL_ALGO_SHA256), $warnings) === 1;
                } else {
                    $digest = \hash('sha256', $input, true);   // RFC 8463 §3: Ed25519 signs the SHA-256 of the input
                    $verified = Guard::run(static fn () => \sodium_crypto_sign_verify_detached($signature, $digest, $publicKey), $warnings) === true;
                }
            } catch (\Throwable $e) {
                throw $this->drift('the verification call failed: ' . Text::sanitize($e::class . ': ' . $e->getMessage(), 200));
            }
            if (!$verified) {
                Guard::clearOpenssl();
                throw $this->drift('the signature does not verify with the public key');
            }
        }

        private function drift(string $why): \LogicException
        {
            return new \LogicException("DKIM self-verification failed for d={$this->domain}; s={$this->selector}: {$why}."
                . ' The signature would not verify, so nothing was sent; this is a tehsmtp bug (§7.7)');
        }

        /**
         * Parses a tag-list (RFC 6376 §3.2): ";"-separated "name = value" specs with FWS around
         * each part, an optional trailing ";", names case-sensitive and unique.
         *
         * @return array<string, array{string, int, int}>|null name => [the value without surrounding
         *         FWS, start and end offset of the whole value region after "=" in $list]; null when invalid
         */
        private static function tags(string $list): ?array
        {
            $tags = [];
            $offset = 0;
            $specs = \explode(';', $list);
            foreach ($specs as $i => $spec) {
                $start = $offset;
                $offset += \strlen($spec) + 1;
                if (\trim($spec, " \t\r\n") === '' && $i === \count($specs) - 1 && $i > 0) {
                    break;   // the optional trailing ";"
                }
                if (\preg_match('/\A[ \t\r\n]*([A-Za-z][A-Za-z0-9_]*)[ \t\r\n]*=/', $spec, $m) !== 1 || isset($tags[$m[1]])) {
                    return null;
                }
                $tags[$m[1]] = [\trim(\substr($spec, \strlen($m[0])), " \t\r\n"), $start + \strlen($m[0]), $start + \strlen($spec)];
            }
            return $tags;
        }

        /** @return list<string> the h= names lower-cased; [] when the list is empty or has an empty name */
        private static function headerList(string $h): array
        {
            $names = [];
            foreach (\explode(':', $h) as $name) {
                $name = \trim($name, " \t\r\n");
                if ($name === '') {
                    return [];
                }
                $names[] = Text::lower($name);
            }
            return $names;
        }
    }

    /**
     * DKIM canonicalisation (RFC 6376 §3.4; §7.2, §7.3): header() for one field, and a streaming
     * body canonicaliser that hashes as it goes: push() the body in any chunks, then digest().
     *
     *     simple body:  trailing CRLF runs are dropped, one CRLF ends the body; "" becomes "\r\n"
     *     relaxed body: per line, trailing WSP deleted and inner WSP runs made one SP (a leading
     *                   run becomes one SP and is kept); trailing empty lines dropped; a non-empty
     *                   body ends in exactly one CRLF; an empty or all-blank body becomes ""
     *
     * Bytes only (M16): no /u regex, no mb_*; a bare CR or LF is an ordinary content byte. The
     * state carried between chunks is a trailing CR (which may begin a CRLF), a pending WSP run
     * and the count of held-back CRLFs, so the digest is the same however the body is split and
     * '' chunks change nothing (the chunk-invariance harness, §9.4). Memory stays O(chunk).
     *
     * @internal
     */
    final class DkimCanon
    {
        private ?\HashContext $hash;

        /** CRLFs seen but not yet hashed: dropped if only empty lines follow (both modes). */
        private int $crlfs = 0;

        /** The last byte pushed was a CR, which may begin a CRLF in the next chunk. */
        private bool $cr = false;

        /** relaxed: a WSP run is pending in the current line (one SP, if non-WSP content follows). */
        private bool $wsp = false;

        /** relaxed: content was hashed (an all-blank body canonicalises to ""). */
        private bool $content = false;

        public function __construct(private readonly bool $relaxed)
        {
            $this->hash = \hash_init('sha256');
        }

        /**
         * One header field canonicalised (RFC 6376 §3.4.1, §3.4.2). $field is exactly as in the
         * header section, folds included, WITHOUT its final CRLF; so is the result. relaxed:
         * lower-case the name (ASCII), unfold, turn each SP/HTAB run into one SP, delete WSP at the
         * end of the value and around the colon: "name:value". simple: the field unchanged.
         */
        public static function header(string $field, bool $relaxed): string
        {
            if (!$relaxed) {
                return $field;
            }
            $colon = \strpos($field, ':');
            if ($colon === false) {
                throw new \LogicException('DkimCanon::header(): not a header field (a tehsmtp bug)');
            }
            $value = \preg_replace('/[ \t]+/', ' ', \str_replace("\r\n", '', \substr($field, $colon + 1)))
                ?? throw new \LogicException('DkimCanon::header(): ' . \preg_last_error_msg());
            return Text::lower(\rtrim(\substr($field, 0, $colon), " \t")) . ':' . \trim($value, ' ');
        }

        /** Feeds the next chunk of the body; '' is a no-op. */
        public function push(string $chunk): void
        {
            if ($this->hash === null) {
                throw new \LogicException('DkimCanon::push() after digest()');
            }
            if ($chunk === '') {
                return;
            }
            if ($this->cr) {
                $chunk = "\r" . $chunk;
                $this->cr = false;
            }
            if ($chunk[-1] === "\r") {   // hold it back: the next chunk may start with its LF
                $this->cr = true;
                $chunk = \substr($chunk, 0, -1);
            }
            if ($chunk !== '') {
                $this->relaxed ? $this->relaxedChunk($chunk) : $this->simpleChunk($chunk);
            }
        }

        /** Ends the body and returns the raw SHA-256 of its canonical form (the bh= digest, before base64). */
        public function digest(): string
        {
            if ($this->hash === null) {
                throw new \LogicException('DkimCanon::digest() was already called');
            }
            $tail = '';
            if ($this->cr) {   // a CR at the very end is content, not a line break
                $tail = \str_repeat("\r\n", $this->crlfs) . ($this->wsp ? ' ' : '') . "\r";
                $this->content = true;
            }
            if (!$this->relaxed || $this->content) {
                $tail .= "\r\n";   // simple: always one CRLF (so "" gives "\r\n"); relaxed: only after content
            }
            \hash_update($this->hash, $tail);
            $digest = \hash_final($this->hash, true);
            $this->hash = null;
            return $digest;
        }

        /** simple: hashes everything but the trailing run of CRLF pairs, which is held back (§3.4.3). */
        private function simpleChunk(string $data): void
        {
            $end = \strlen($data);
            while ($end >= 2 && $data[$end - 1] === "\n" && $data[$end - 2] === "\r") {
                $end -= 2;
            }
            if ($end > 0) {
                \hash_update($this->hash, \str_repeat("\r\n", $this->crlfs) . \substr($data, 0, $end));
                $this->crlfs = 0;
            }
            $this->crlfs += \intdiv(\strlen($data) - $end, 2);
        }

        /**
         * relaxed (§3.4.4), token by token: CRLF (held back; ends any pending WSP, which is thereby
         * trailing and deleted), a WSP run (pending), or content (hashed after the held-back CRLFs
         * and, if a WSP run was pending, one SP). A lone CR is content.
         */
        private function relaxedChunk(string $data): void
        {
            if (\preg_match_all('/\r\n|[ \t]++|[^ \t\r]++|\r/', $data, $tokens) === false) {
                throw new \LogicException('DkimCanon: ' . \preg_last_error_msg());
            }
            $out = '';
            foreach ($tokens[0] as $token) {
                if ($token === "\r\n") {
                    $this->crlfs++;
                    $this->wsp = false;
                } elseif ($token[0] === ' ' || $token[0] === "\t") {
                    $this->wsp = true;
                } else {
                    $out .= \str_repeat("\r\n", $this->crlfs) . ($this->wsp ? ' ' : '') . $token;
                    $this->crlfs = 0;
                    $this->wsp = false;
                    $this->content = true;
                }
            }
            if ($out !== '') {
                \hash_update($this->hash, $out);
            }
        }
    }
}
