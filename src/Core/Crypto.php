<?php

declare(strict_types=1);

namespace App\Core;

use RuntimeException;

/** Authenticated encryption (XSalsa20-Poly1305) for credentials at rest, keyed by APP_KEY. */
final class Crypto
{
    private const PREFIX = 'v1:';

    private static function key(): string
    {
        $raw = (string) Env::get('APP_KEY', '');
        if (str_starts_with($raw, 'base64:')) {
            $raw = substr($raw, 7);
        }
        $key = base64_decode($raw, true);
        if ($key === false || strlen($key) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
            throw new RuntimeException('APP_KEY is missing or invalid (expected 32 bytes, base64).');
        }
        return $key;
    }

    public static function generateKey(): string
    {
        return 'base64:' . base64_encode(random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
    }

    public static function encrypt(string $plain): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $key = self::key();
        $c = sodium_crypto_secretbox($plain, $nonce, $key);
        sodium_memzero($key);
        return self::PREFIX . base64_encode($nonce . $c);
    }

    public static function decrypt(string $cipher): string
    {
        if (!str_starts_with($cipher, self::PREFIX)) {
            throw new RuntimeException('Unknown cipher format');
        }
        $bin = base64_decode(substr($cipher, strlen(self::PREFIX)), true);
        if ($bin === false || strlen($bin) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            throw new RuntimeException('Corrupted secret');
        }
        $nonce = substr($bin, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $key = self::key();
        $plain = sodium_crypto_secretbox_open(substr($bin, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), $nonce, $key);
        sodium_memzero($key);
        if ($plain === false) {
            throw new RuntimeException('Secret could not be decrypted (APP_KEY changed?)');
        }
        return $plain;
    }

    /** Hash for high-entropy tokens (API keys): fast lookup, constant-time compare elsewhere. */
    public static function tokenHash(string $token): string
    {
        return hash_hmac('sha256', $token, self::key());
    }

    public static function randomToken(int $bytes = 24): string
    {
        return rtrim(strtr(base64_encode(random_bytes($bytes)), '+/', 'Ab'), '=');
    }
}
