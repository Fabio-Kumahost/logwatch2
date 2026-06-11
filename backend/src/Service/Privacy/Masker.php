<?php

declare(strict_types=1);

namespace App\Service\Privacy;

/**
 * Masks sensitive data before any text is sent to an AI provider.
 * Hard gate: AiAnalyzer refuses to call a provider with unmasked input.
 *
 * Identical values get stable numbered placeholders within one mask() call
 * ([IP_1] is the same address everywhere), so the model can still reason
 * about repeated actors. The value map never leaves this object.
 */
final class Masker
{
    /** @var array<string, string> value => placeholder, per mask() call */
    private array $map = [];
    private array $counters = [];

    /** @param list<string> $customPatterns operator-defined extra regexes */
    public function __construct(
        private readonly bool $partialIps = false,
        private readonly array $customPatterns = [],
    ) {
    }

    public function mask(string $text): string
    {
        $this->map = [];
        $this->counters = [];

        // Order matters: secrets before generic tokens, URLs before bare IPs.
        $text = $this->replace($text, '/\b(password|passwd|pwd|secret|api[_-]?key|token|auth)\s*[=:]\s*("[^"]*"|\'[^\']*\'|\S+)/i', 'PASSWORD', keepKey: true);
        $text = $this->replace($text, '/\b(Bearer|Basic)\s+[A-Za-z0-9+\/_\-.=]{8,}/', 'TOKEN');
        $text = $this->replace($text, '/\beyJ[A-Za-z0-9_-]{10,}\.[A-Za-z0-9_-]{10,}\.[A-Za-z0-9_-]{5,}\b/', 'TOKEN'); // JWT
        $text = $this->replace($text, '/\b(AKIA[0-9A-Z]{16}|ghp_[A-Za-z0-9]{36}|sk-[A-Za-z0-9_-]{20,})\b/', 'TOKEN');
        $text = $this->replace($text, '/\b[0-9a-f]{32,}\b/i', 'TOKEN'); // long hex blobs
        $text = $this->replace($text, '#(\w+://)([^/\s:@]+):([^/\s@]+)@#', 'CREDENTIALS', urlCreds: true);
        $text = $this->replace($text, '/\b[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}\b/', 'EMAIL');
        $text = $this->replace($text, '/\b(?:[0-9a-f]{2}:){5}[0-9a-f]{2}\b/i', 'MAC');
        $text = $this->maskIps($text);
        $text = preg_replace('#/home/([^/\s]+)#', '/home/[USER]', $text) ?? $text;

        foreach ($this->customPatterns as $i => $pattern) {
            $text = $this->replace($text, $pattern, 'CUSTOM' . ($i + 1));
        }

        return $text;
    }

    /** sha256 of the masked text — stored with each analysis as audit evidence. */
    public static function auditHash(string $maskedText): string
    {
        return hash('sha256', $maskedText);
    }

    private function maskIps(string $text): string
    {
        $ipv4 = '/\b((?:\d{1,3}\.){3}\d{1,3})\b/';
        $text = preg_replace_callback($ipv4, function (array $m): string {
            if ($this->partialIps) {
                return preg_replace('/^(\d{1,3})\..*/', '$1.x.x.x', $m[1]) ?? '[IP]';
            }
            return $this->placeholder($m[1], 'IP');
        }, $text) ?? $text;

        // IPv6 (pragmatic form: at least two colon groups, hex chars)
        $ipv6 = '/\b(?:[0-9a-f]{1,4}:){2,7}[0-9a-f:]{1,}\b/i';
        return preg_replace_callback($ipv6,
            fn (array $m): string => $this->placeholder($m[0], 'IP'), $text) ?? $text;
    }

    private function replace(string $text, string $pattern, string $label,
        bool $keepKey = false, bool $urlCreds = false): string
    {
        $result = preg_replace_callback($pattern, function (array $m) use ($label, $keepKey, $urlCreds): string {
            if ($urlCreds) {
                return $m[1] . '[CREDENTIALS]@';
            }
            if ($keepKey) {
                return $m[1] . '=[' . $label . ']';
            }
            return $this->placeholder($m[0], $label);
        }, $text);

        return $result ?? $text; // invalid custom pattern: fail open to original, logged upstream
    }

    private function placeholder(string $value, string $label): string
    {
        if (!isset($this->map[$value])) {
            $n = $this->counters[$label] = ($this->counters[$label] ?? 0) + 1;
            $this->map[$value] = sprintf('[%s_%d]', $label, $n);
        }
        return $this->map[$value];
    }
}
