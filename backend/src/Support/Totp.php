<?php

declare(strict_types=1);

namespace App\Support;

/**
 * RFC 6238 TOTP (SHA-1, 6 digits, 30s steps) — dependency-free.
 * Compatible with Google Authenticator, Aegis, 1Password, Bitwarden, …
 */
final class Totp
{
    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    private const PERIOD = 30;
    private const DIGITS = 6;

    /** 160-bit random secret, base32 (the format authenticator apps expect). */
    public static function generateSecret(): string
    {
        $raw = random_bytes(20);
        $bits = '';
        foreach (str_split($raw) as $byte) {
            $bits .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT);
        }
        $secret = '';
        foreach (str_split($bits, 5) as $chunk) {
            $secret .= self::ALPHABET[bindec(str_pad($chunk, 5, '0'))];
        }
        return $secret;
    }

    /** otpauth:// URI for QR codes / manual import in authenticator apps. */
    public static function provisioningUri(string $secret, string $username, string $issuer = 'Logwatch2'): string
    {
        return sprintf(
            'otpauth://totp/%s:%s?secret=%s&issuer=%s&algorithm=SHA1&digits=%d&period=%d',
            rawurlencode($issuer), rawurlencode($username), $secret, rawurlencode($issuer),
            self::DIGITS, self::PERIOD,
        );
    }

    /** Verifies a code, accepting ±1 time step of clock drift. Constant-time compare. */
    public static function verify(string $secret, string $code, ?int $now = null): bool
    {
        if (!preg_match('/^\d{6}$/', $code)) {
            return false;
        }
        $now ??= time();
        $key = self::base32Decode($secret);
        if ($key === null) {
            return false;
        }
        foreach ([-1, 0, 1] as $drift) {
            $counter = intdiv($now, self::PERIOD) + $drift;
            if (hash_equals(self::hotp($key, $counter), $code)) {
                return true;
            }
        }
        return false;
    }

    private static function hotp(string $key, int $counter): string
    {
        $binCounter = pack('N2', ($counter >> 32) & 0xFFFFFFFF, $counter & 0xFFFFFFFF);
        $hash = hash_hmac('sha1', $binCounter, $key, true);
        $offset = ord($hash[19]) & 0x0F;
        $value = ((ord($hash[$offset]) & 0x7F) << 24)
            | (ord($hash[$offset + 1]) << 16)
            | (ord($hash[$offset + 2]) << 8)
            | ord($hash[$offset + 3]);
        return str_pad((string) ($value % (10 ** self::DIGITS)), self::DIGITS, '0', STR_PAD_LEFT);
    }

    private static function base32Decode(string $b32): ?string
    {
        $b32 = strtoupper(rtrim($b32, '='));
        $bits = '';
        foreach (str_split($b32) as $char) {
            $pos = strpos(self::ALPHABET, $char);
            if ($pos === false) {
                return null;
            }
            $bits .= str_pad(decbin($pos), 5, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 8) as $chunk) {
            if (strlen($chunk) === 8) {
                $out .= chr(bindec($chunk));
            }
        }
        return $out;
    }
}
