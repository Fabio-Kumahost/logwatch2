<?php

declare(strict_types=1);

namespace App\Tests;

use App\Service\Privacy\Masker;
use PHPUnit\Framework\TestCase;

/**
 * Every masking pattern is pinned by a fixture that proves it masks,
 * plus cases proving normal log text is NOT over-masked.
 */
final class MaskerTest extends TestCase
{
    private Masker $masker;

    protected function setUp(): void
    {
        $this->masker = new Masker();
    }

    public function testMasksPasswordsInKeyValueForm(): void
    {
        $out = $this->masker->mask('login failed password=Sup3rS3cret! for user=bob');
        $this->assertStringNotContainsString('Sup3rS3cret!', $out);
        $this->assertStringContainsString('password=[PASSWORD]', $out);
    }

    public function testMasksBearerTokensAndJwts(): void
    {
        $jwt = 'eyJhbGciOiJIUzI1NiJ9.eyJzdWIiOiIxMjM0NTY3ODkwIn0.dozjgNryP4J3jVmNHl0w5N_XgL0n3I9PlFUP0THsR8U';
        $out = $this->masker->mask("auth header was: Bearer $jwt");
        $this->assertStringNotContainsString($jwt, $out);
    }

    public function testMasksUrlCredentials(): void
    {
        $out = $this->masker->mask('connecting to postgres://app:hunter2@db.internal:5432/prod');
        $this->assertStringNotContainsString('hunter2', $out);
        $this->assertStringContainsString('postgres://[CREDENTIALS]@', $out);
    }

    public function testMasksEmailsAndIpsWithStablePlaceholders(): void
    {
        $out = $this->masker->mask(
            'rejected mail from alice@example.com via 203.0.113.7; retry from 203.0.113.7');
        $this->assertStringNotContainsString('alice@example.com', $out);
        $this->assertStringNotContainsString('203.0.113.7', $out);
        // Same IP twice → same placeholder, exactly one numbered identity.
        $this->assertSame(2, substr_count($out, '[IP_1]'));
        $this->assertStringNotContainsString('[IP_2]', $out);
    }

    public function testMasksIpv6(): void
    {
        $out = $this->masker->mask('connection from 2001:db8::dead:beef dropped');
        $this->assertStringNotContainsString('2001:db8::dead:beef', $out);
    }

    public function testMasksHomeDirectoryUsernames(): void
    {
        $out = $this->masker->mask('permission denied: /home/fabio/.ssh/id_rsa');
        $this->assertStringNotContainsString('fabio', $out);
        $this->assertStringContainsString('/home/[USER]/', $out);
    }

    public function testPartialIpModeKeepsFirstOctet(): void
    {
        $out = (new Masker(partialIps: true))->mask('probe from 203.0.113.7');
        $this->assertStringContainsString('203.x.x.x', $out);
    }

    public function testDoesNotOverMaskNormalLogText(): void
    {
        $line = 'nginx: connect() failed (111: Connection refused) while connecting to upstream';
        $this->assertSame($line, $this->masker->mask($line));
    }

    public function testDoesNotMaskVersionNumbersOrPorts(): void
    {
        $line = 'started server v2.14.1 on port 8080 with 4 workers';
        $this->assertSame($line, $this->masker->mask($line));
    }

    public function testCustomOperatorPattern(): void
    {
        $m = new Masker(customPatterns: ['/\bACME-\d{6}\b/']);
        $out = $m->mask('order ACME-123456 failed to sync');
        $this->assertStringNotContainsString('ACME-123456', $out);
        $this->assertStringContainsString('[CUSTOM1_1]', $out);
    }
}
