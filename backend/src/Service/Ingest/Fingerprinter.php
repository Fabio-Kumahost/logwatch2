<?php

declare(strict_types=1);

namespace App\Service\Ingest;

/**
 * Stable fingerprints for "the same error". Normalization strips everything
 * volatile so two occurrences with different IPs/timestamps/PIDs collide —
 * that collision is exactly what enables grouping and the AI cache.
 */
final class Fingerprinter
{
    public function fingerprint(string $service, string $sourceFile, string $message): string
    {
        return hash('sha256', implode("\0", [
            strtolower($service),
            self::sourceClass($sourceFile),
            self::normalize($message),
        ]));
    }

    /** error.log.1 / error.log.2.gz / error-20260611.log → error.log */
    public static function sourceClass(string $path): string
    {
        $p = preg_replace('/\.(\d+)(\.gz)?$/', '', $path) ?? $path;
        return preg_replace('/[-.]\d{8,}/', '', $p) ?? $p;
    }

    public static function normalize(string $message): string
    {
        $subs = [
            // ISO + syslog timestamps
            '/\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}:\d{2}(\.\d+)?(Z|[+-]\d{2}:?\d{2})?/' => '<TS>',
            '/\b[A-Z][a-z]{2}\s+\d{1,2} \d{2}:\d{2}:\d{2}\b/' => '<TS>',
            '/\b[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\b/i' => '<UUID>',
            '/\b(?:\d{1,3}\.){3}\d{1,3}(:\d+)?\b/' => '<IP>',
            '/\b(?:[0-9a-f]{1,4}:){2,7}[0-9a-f:]+\b/i' => '<IP>',
            '/\b0x[0-9a-f]+\b/i' => '<HEX>',
            '/\b[0-9a-f]{12,}\b/i' => '<HEX>',
            '/"[^"]*"/' => '<STR>',
            "/'[^']*'/" => '<STR>',
            '/\[\d+\]/' => '[<N>]',     // pids: sshd[4711]
            '/\b\d+\b/' => '<N>',
        ];
        $n = preg_replace(array_keys($subs), array_values($subs), $message) ?? $message;
        return trim(mb_substr(preg_replace('/\s+/', ' ', $n) ?? $n, 0, 512));
    }
}
