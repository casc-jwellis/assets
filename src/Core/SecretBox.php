<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Reversible encryption for a secret the app must be able to read back (an SMTP password), stored in the database.
 *
 * AES-256-GCM with a random 32-byte key kept in storage/secret.key: outside the web root, never committed (the
 * whole storage/ folder is git-ignored) and never part of a database dump, so a dump alone does not reveal the
 * secret. Back up storage/ together with the database, and treat that backup as sensitive. If the key is lost
 * the stored value cannot be read: decrypt() returns null and the secret must be entered again.
 *
 * Stored form: "v1:" . base64(iv . tag . ciphertext).
 */
final class SecretBox
{
    private const CIPHER = 'aes-256-gcm';
    private const PREFIX = 'v1:';

    public static function available(): bool
    {
        return function_exists('openssl_encrypt') && in_array(self::CIPHER, openssl_get_cipher_methods(), true);
    }

    /** @throws \RuntimeException if there is no OpenSSL or the key file cannot be created or read */
    public static function encrypt(string $plain): string
    {
        if (!self::available()) {
            throw new \RuntimeException('The PHP openssl extension is needed to store a password securely.');
        }
        $key = self::key(true) ?? throw new \RuntimeException('The key file storage/secret.key could not be created or read. Make the storage folder writable by the web server.');
        $iv = random_bytes(12);
        $tag = '';
        $cipher = openssl_encrypt($plain, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv, $tag, '', 16);
        if ($cipher === false) {
            throw new \RuntimeException('The password could not be encrypted.');
        }
        return self::PREFIX . base64_encode($iv . $tag . $cipher);
    }

    /** The plain value, or null if it is not in this format, the key is missing or wrong, or it was tampered with. */
    public static function decrypt(string $stored): ?string
    {
        if (!self::available() || !str_starts_with($stored, self::PREFIX)) {
            return null;
        }
        $raw = base64_decode(substr($stored, strlen(self::PREFIX)), true);
        $key = self::key(false);
        if ($raw === false || $key === null || strlen($raw) < 12 + 16 + 1) {
            return null;
        }
        $plain = openssl_decrypt(substr($raw, 28), self::CIPHER, $key, OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16));
        return $plain === false ? null : $plain;
    }

    /** The 32-byte key, created on first use when $create is true; null if there is none (or it cannot be made). */
    private static function key(bool $create): ?string
    {
        $dir = APP_ROOT . '/storage';
        $file = $dir . '/secret.key';
        if (is_file($file)) {
            $hex = trim((string) @file_get_contents($file));
            return preg_match('/^[0-9a-f]{64}$/', $hex) === 1 ? (string) hex2bin($hex) : null;
        }
        if (!$create) {
            return null;
        }
        if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) {
            return null;
        }
        $key = random_bytes(32);
        // LOCK_EX + a check afterwards: if two requests race, the one that wrote first wins and both use its key.
        if (@file_put_contents($file, bin2hex($key) . "\n", LOCK_EX) === false) {
            return null;
        }
        @chmod($file, 0600);
        $hex = trim((string) @file_get_contents($file));
        return preg_match('/^[0-9a-f]{64}$/', $hex) === 1 ? (string) hex2bin($hex) : null;
    }
}
