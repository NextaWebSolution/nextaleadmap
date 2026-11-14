<?php

declare(strict_types=1);

namespace App\Services\Update;

use RuntimeException;

/**
 * Release manifests are signed with Ed25519. The public key(s) ship inside the application
 * (config/update-public-key.txt) and can only change through a signed release, so a compromised or
 * spoofed update server cannot supply its own checksum: the checksum is trusted only because it is
 * covered by the signature.
 *
 * Signed message = canonical JSON of the manifest without "signature" (keys sorted, no escaping of / or unicode).
 */
final class ManifestVerifier
{
    public const REQUIRED = ['version', 'release_date', 'minimum_php', 'archive', 'checksum', 'signature'];

    /** @param list<string> $publicKeysB64 */
    public function __construct(private array $publicKeysB64)
    {
    }

    public static function fromFile(string $file): self
    {
        $keys = [];
        if (is_file($file)) {
            foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
                $line = trim($line);
                if ($line !== '' && $line[0] !== '#') {
                    $keys[] = $line;
                }
            }
        }
        return new self($keys);
    }

    public function configured(): bool
    {
        foreach ($this->publicKeysB64 as $k) {
            $bin = base64_decode($k, true);
            if ($bin !== false && strlen($bin) === SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
                return true;
            }
        }
        return false;
    }

    public static function canonical(array $manifest): string
    {
        unset($manifest['signature']);
        $sort = static function (array $a) use (&$sort): array {
            if (!array_is_list($a)) {
                ksort($a, SORT_STRING);
            }
            foreach ($a as $k => $v) {
                if (is_array($v)) {
                    $a[$k] = $sort($v);
                }
            }
            return $a;
        };
        return (string) json_encode($sort($manifest), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /** Validates structure and signature. Throws on any problem; returns the manifest on success. */
    public function verify(array $m): array
    {
        foreach (self::REQUIRED as $k) {
            if (!isset($m[$k]) || !is_string($m[$k]) || $m[$k] === '') {
                throw new RuntimeException("Manifest is missing \"$k\"");
            }
        }
        if (!preg_match('/^\d+\.\d+\.\d+(?:-[0-9A-Za-z.-]+)?$/', $m['version'])) {
            throw new RuntimeException('Manifest version is not semantic (x.y.z)');
        }
        if (!preg_match('/^[a-f0-9]{64}$/', strtolower($m['checksum']))) {
            throw new RuntimeException('Manifest checksum must be a SHA-256 hex digest');
        }
        if (!$this->configured()) {
            throw new RuntimeException('No update signing key is configured in config/update-public-key.txt. Updates are disabled.');
        }
        $sig = base64_decode($m['signature'], true);
        if ($sig === false || strlen($sig) !== SODIUM_CRYPTO_SIGN_BYTES) {
            throw new RuntimeException('Manifest signature is malformed');
        }
        $msg = self::canonical($m);
        foreach ($this->publicKeysB64 as $k) {
            $pk = base64_decode($k, true);
            if ($pk !== false && strlen($pk) === SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES && sodium_crypto_sign_verify_detached($sig, $msg, $pk)) {
                return $m;
            }
        }
        throw new RuntimeException('Manifest signature is INVALID. The update was rejected.');
    }

    /** Helper for tools/release.php: sign a manifest with a base64 secret key. */
    public static function sign(array $manifest, string $secretKeyB64): string
    {
        $sk = base64_decode($secretKeyB64, true);
        if ($sk === false || strlen($sk) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
            throw new RuntimeException('Invalid secret key');
        }
        return base64_encode(sodium_crypto_sign_detached(self::canonical($manifest), $sk));
    }
}
