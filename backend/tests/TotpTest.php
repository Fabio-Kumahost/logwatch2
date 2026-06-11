<?php

declare(strict_types=1);

namespace App\Tests;

use App\Support\Totp;
use PHPUnit\Framework\TestCase;

final class TotpTest extends TestCase
{
    /**
     * RFC 6238/4226 reference: ASCII secret "12345678901234567890",
     * base32 GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ. At t=59s (step 30) the
     * counter is 1 → HOTP(1) = 287082; drift -1 covers HOTP(0) = 755224.
     */
    private const RFC_SECRET = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';

    public function testVerifiesRfcVectorAtCurrentStep(): void
    {
        $this->assertTrue(Totp::verify(self::RFC_SECRET, '287082', now: 59));
    }

    public function testAcceptsOneStepOfClockDrift(): void
    {
        $this->assertTrue(Totp::verify(self::RFC_SECRET, '755224', now: 59));  // -1 step
        $this->assertTrue(Totp::verify(self::RFC_SECRET, '359152', now: 59));  // +1 step
    }

    public function testRejectsOutsideDriftWindow(): void
    {
        // HOTP(3) = 969429 — two steps ahead of t=59, must fail.
        $this->assertFalse(Totp::verify(self::RFC_SECRET, '969429', now: 59));
    }

    public function testRejectsMalformedCodes(): void
    {
        $this->assertFalse(Totp::verify(self::RFC_SECRET, '12345', now: 59));
        $this->assertFalse(Totp::verify(self::RFC_SECRET, 'abcdef', now: 59));
        $this->assertFalse(Totp::verify(self::RFC_SECRET, '', now: 59));
    }

    public function testRejectsInvalidSecret(): void
    {
        $this->assertFalse(Totp::verify('not!base32', '287082', now: 59));
    }

    public function testGeneratedSecretRoundTrips(): void
    {
        $secret = Totp::generateSecret();
        $this->assertMatchesRegularExpression('/^[A-Z2-7]{32}$/', $secret);

        $uri = Totp::provisioningUri($secret, 'alice');
        $this->assertStringContainsString('otpauth://totp/Logwatch2:alice', $uri);
        $this->assertStringContainsString('secret=' . $secret, $uri);

        // Independent reference implementation: a code computed here must
        // be accepted by Totp::verify for the same time step.
        $now = 1_750_000_000;
        $this->assertTrue(Totp::verify($secret, self::referenceCode($secret, intdiv($now, 30)), $now));
    }

    private static function referenceCode(string $base32, int $counter): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $bits = '';
        foreach (str_split($base32) as $char) {
            $bits .= str_pad(decbin((int) strpos($alphabet, $char)), 5, '0', STR_PAD_LEFT);
        }
        $key = '';
        foreach (str_split($bits, 8) as $chunk) {
            if (strlen($chunk) === 8) {
                $key .= chr((int) bindec($chunk));
            }
        }
        $hash = hash_hmac('sha1', pack('N2', 0, $counter), $key, true);
        $offset = ord($hash[19]) & 0x0F;
        $value = ((ord($hash[$offset]) & 0x7F) << 24) | (ord($hash[$offset + 1]) << 16)
            | (ord($hash[$offset + 2]) << 8) | ord($hash[$offset + 3]);
        return str_pad((string) ($value % 1_000_000), 6, '0', STR_PAD_LEFT);
    }
}
