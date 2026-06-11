<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Secrets at rest (AI keys, webhook URLs, Gotify tokens) are sealed with
 * libsodium secretbox using APP_KEY. A database dump alone is useless
 * without the .env on the application host.
 */
final class Crypto
{
    private readonly string $key;

    public function __construct(string $appKeyBase64)
    {
        $key = base64_decode($appKeyBase64, true);
        if ($key === false || strlen($key) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
            throw new \RuntimeException(
                'APP_KEY must be 32 bytes base64 — generate with: openssl rand -base64 32');
        }
        $this->key = $key;
    }

    public function seal(string $plaintext): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        return base64_encode($nonce . sodium_crypto_secretbox($plaintext, $nonce, $this->key));
    }

    public function unseal(string $sealed): string
    {
        $raw = base64_decode($sealed, true);
        if ($raw === false || strlen($raw) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            throw new \RuntimeException('corrupt sealed value');
        }
        $nonce = substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $plain = sodium_crypto_secretbox_open(
            substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), $nonce, $this->key);
        if ($plain === false) {
            throw new \RuntimeException('unseal failed — wrong APP_KEY?');
        }
        return $plain;
    }

    /** Agent tokens: 32 random bytes, base64url, lw2_ prefix → 47 chars. */
    public static function newAgentToken(): string
    {
        return 'lw2_' . rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }
}
