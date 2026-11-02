<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Checks the signed "ID token" that Google's sign-in button hands to the browser.
 *
 * The token is a JWT signed (RS256) with one of Google's published keys. Nothing in it is believed until the
 * signature checks out against those keys and the claims say it was made for this site, for this sign-in, and
 * is still fresh:
 *
 *   iss    Google
 *   aud    our own client ID (a token issued to some other site is refused)
 *   exp    not expired
 *   nonce  the one-time value we put on this visitor's sign-in page, so a token cannot be replayed or planted
 *   email  present, and email_verified is true
 *
 * Google's keys (https://www.googleapis.com/oauth2/v3/certs) are cached in storage/ for a few hours and fetched
 * again when a token names a key we do not have (Google rotates them), at most once a minute. If Google cannot be
 * reached, an older copy of the keys is still good for checking a signature.
 *
 * Needs the PHP openssl extension and some way to make an HTTPS request (curl, or allow_url_fopen).
 */
final class GoogleIdToken
{
    public const CERTS_URL = 'https://www.googleapis.com/oauth2/v3/certs';
    /** What a Google "web application" client ID looks like: 123456789-abc123.apps.googleusercontent.com */
    public const CLIENT_ID = '/^[A-Za-z0-9_-]{4,100}\.apps\.googleusercontent\.com$/D';

    private const ISSUERS = ['https://accounts.google.com', 'accounts.google.com'];
    private const CACHE_SECONDS = 21600; // 6 hours
    private const REFETCH_SECONDS = 60;  // an unknown key never triggers more than one fetch a minute
    private const SKEW = 60;             // seconds of clock difference tolerated
    private const MAX_TOKEN = 8192;

    /** @var (callable(string):?string)|null the way to download a URL; null = curl / streams. Replaced in tests. */
    private $fetch;

    /** @param (callable(string):?string)|null $fetch */
    public function __construct(private string $cacheFile, ?callable $fetch = null)
    {
        $this->fetch = $fetch;
    }

    public static function cacheFile(): string
    {
        return APP_ROOT . '/storage/google-certs.json';
    }

    /** Why this server cannot check Google tokens at all, or null if it can. */
    public static function problem(): ?string
    {
        if (!function_exists('openssl_verify')) {
            return 'The PHP openssl extension is needed to check Google sign-ins.';
        }
        if (!function_exists('curl_init') && !filter_var(ini_get('allow_url_fopen'), FILTER_VALIDATE_BOOLEAN)) {
            return 'This server cannot make HTTPS requests: enable the PHP curl extension (or allow_url_fopen).';
        }
        return null;
    }

    /**
     * @return array<string,mixed> the verified claims (sub, email, name, ...)
     * @throws \RuntimeException the reason, for the error log (never shown to the visitor)
     */
    public function verify(string $token, string $clientId, string $nonce, ?int $now = null): array
    {
        $now ??= time();
        if ($token === '' || strlen($token) > self::MAX_TOKEN) {
            throw new \RuntimeException('token missing or too long');
        }
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            throw new \RuntimeException('not a JWT');
        }
        $header = self::json($parts[0]);
        $claims = self::json($parts[1]);
        $signature = self::b64($parts[2]);
        if ($header === null || $claims === null || $signature === null) {
            throw new \RuntimeException('token is not well formed');
        }
        // Only RS256 is accepted: never "none", never an HMAC keyed with something public.
        if (($header['alg'] ?? null) !== 'RS256' || !is_string($header['kid'] ?? null) || $header['kid'] === '') {
            throw new \RuntimeException('unexpected algorithm or no key id');
        }

        $pem = $this->publicKey($header['kid'], $now);
        if ($pem === null) {
            throw new \RuntimeException('signing key ' . substr($header['kid'], 0, 40) . ' is not one of Google\'s');
        }
        if (openssl_verify($parts[0] . '.' . $parts[1], $signature, $pem, OPENSSL_ALGO_SHA256) !== 1) {
            throw new \RuntimeException('signature does not match');
        }

        // ---- the signature is good: now is it for us, for now, for this visitor?
        if (!in_array($claims['iss'] ?? null, self::ISSUERS, true)) {
            throw new \RuntimeException('wrong issuer');
        }
        if (!is_string($claims['aud'] ?? null) || !hash_equals($clientId, $claims['aud'])) {
            throw new \RuntimeException('token was issued for a different client');
        }
        if (!is_int($claims['exp'] ?? null) || $claims['exp'] + self::SKEW < $now) {
            throw new \RuntimeException('token expired');
        }
        if (isset($claims['iat']) && (!is_int($claims['iat']) || $claims['iat'] - self::SKEW > $now)) {
            throw new \RuntimeException('token issued in the future');
        }
        if ($nonce === '' || !is_string($claims['nonce'] ?? null) || !hash_equals($nonce, $claims['nonce'])) {
            throw new \RuntimeException('nonce does not match this sign-in page');
        }
        if (!is_string($claims['sub'] ?? null) || $claims['sub'] === '') {
            throw new \RuntimeException('no subject');
        }
        if (!is_string($claims['email'] ?? null) || $claims['email'] === '') {
            throw new \RuntimeException('no email address in the token');
        }
        if (($claims['email_verified'] ?? false) !== true && ($claims['email_verified'] ?? null) !== 'true') {
            throw new \RuntimeException('Google has not verified this email address');
        }
        return $claims;
    }

    /** Download Google's keys now (used to prove the server can reach Google). @throws \RuntimeException */
    public function refresh(): void
    {
        $this->writeCache($this->download(), time());
    }

    // ------------------------------------------------------------------ keys

    private function publicKey(string $kid, int $now): ?string
    {
        $cache = $this->readCache();
        $age = $cache === null ? PHP_INT_MAX : $now - $cache['fetched'];
        $jwk = self::find($cache['keys'] ?? [], $kid);

        // An unknown key means Google has rotated: look again (but not more than once a minute).
        // A known key from an old copy is checked again too, in case it has been withdrawn.
        if ($age >= ($jwk === null ? self::REFETCH_SECONDS : self::CACHE_SECONDS)) {
            try {
                $keys = $this->download();
                $this->writeCache($keys, $now);
                $jwk = self::find($keys, $kid);
            } catch (\RuntimeException $e) {
                error_log('Google keys could not be fetched: ' . $e->getMessage());
                // If Google cannot be reached, the copy we have is still good enough to check a signature.
            }
        }
        return $jwk === null ? null : self::pem((string) $jwk['n'], (string) $jwk['e']);
    }

    /** @param list<array<string,mixed>> $keys */
    private static function find(array $keys, string $kid): ?array
    {
        foreach ($keys as $k) {
            if (is_array($k) && ($k['kid'] ?? null) === $kid && ($k['kty'] ?? null) === 'RSA'
                && is_string($k['n'] ?? null) && is_string($k['e'] ?? null)) {
                return $k;
            }
        }
        return null;
    }

    /** @return array{fetched:int, keys:list<array<string,mixed>>}|null */
    private function readCache(): ?array
    {
        $raw = is_file($this->cacheFile) ? @file_get_contents($this->cacheFile) : false;
        $data = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($data) || !is_int($data['fetched'] ?? null) || !is_array($data['keys'] ?? null)) {
            return null;
        }
        return ['fetched' => $data['fetched'], 'keys' => array_values($data['keys'])];
    }

    /** @param list<array<string,mixed>> $keys */
    private function writeCache(array $keys, int $now): void
    {
        $dir = dirname($this->cacheFile);
        if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) {
            return;
        }
        @file_put_contents($this->cacheFile, json_encode(['fetched' => $now, 'keys' => $keys]), LOCK_EX);
    }

    /** @return list<array<string,mixed>> @throws \RuntimeException */
    private function download(): array
    {
        $body = $this->fetch !== null ? ($this->fetch)(self::CERTS_URL) : self::get(self::CERTS_URL);
        $data = is_string($body) ? json_decode($body, true) : null;
        if (!is_array($data) || !is_array($data['keys'] ?? null) || $data['keys'] === []) {
            throw new \RuntimeException('Google did not return a list of keys');
        }
        return array_values($data['keys']);
    }

    /** A plain HTTPS GET with short time-outs and a size limit. Certificates are always verified. */
    private static function get(string $url): ?string
    {
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_TIMEOUT        => 10,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_PROTOCOLS      => CURLPROTO_HTTPS,
                CURLOPT_MAXFILESIZE    => 262144,
            ]);
            $body = curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $err = curl_error($ch);
            curl_close($ch);
            if (!is_string($body) || $code !== 200) {
                throw new \RuntimeException($err !== '' ? $err : 'HTTP ' . $code);
            }
            return $body;
        }
        $ctx = stream_context_create(['http' => ['timeout' => 10, 'follow_location' => 0, 'ignore_errors' => false],
                                      'ssl'  => ['verify_peer' => true, 'verify_peer_name' => true]]);
        $body = @file_get_contents($url, false, $ctx, 0, 262144);
        if (!is_string($body)) {
            throw new \RuntimeException('could not connect');
        }
        return $body;
    }

    // ------------------------------------------------------------------ encoding

    private static function b64(string $s): ?string
    {
        if ($s === '' || preg_match('/^[A-Za-z0-9_-]+$/', $s) !== 1) {
            return null;
        }
        $out = base64_decode(strtr($s, '-_', '+/') . str_repeat('=', (4 - strlen($s) % 4) % 4), true);
        return $out === false ? null : $out;
    }

    /** @return array<string,mixed>|null */
    private static function json(string $segment): ?array
    {
        $raw = self::b64($segment);
        $data = $raw === null ? null : json_decode($raw, true);
        return is_array($data) ? $data : null;
    }

    /** An RSA public key in PEM form from a JWK's modulus and exponent (base64url): the DER is built by hand. */
    public static function pem(string $n, string $e): ?string
    {
        $mod = self::b64($n);
        $exp = self::b64($e);
        if ($mod === null || $exp === null) {
            return null;
        }
        $int = static function (string $raw): string {
            $raw = ltrim($raw, "\0");
            if ($raw === '' || (ord($raw[0]) & 0x80) !== 0) {
                $raw = "\0" . $raw; // a leading 1 bit would make it negative
            }
            return self::der(0x02, $raw);
        };
        $rsaKey = self::der(0x30, $int($mod) . $int($exp));
        $algorithm = hex2bin('300d06092a864886f70d0101010500'); // rsaEncryption, no parameters
        $spki = self::der(0x30, $algorithm . self::der(0x03, "\0" . $rsaKey));
        return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($spki), 64, "\n") . "-----END PUBLIC KEY-----\n";
    }

    private static function der(int $tag, string $content): string
    {
        $len = strlen($content);
        if ($len < 128) {
            return chr($tag) . chr($len) . $content;
        }
        $bytes = ltrim(pack('N', $len), "\0");
        return chr($tag) . chr(0x80 | strlen($bytes)) . $bytes . $content;
    }
}
