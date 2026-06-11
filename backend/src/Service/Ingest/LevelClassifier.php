<?php

declare(strict_types=1);

namespace App\Service\Ingest;

/**
 * Regex baseline classification. Runs on every entry server-side because
 * agent input is untrusted; the AI may later RAISE severity, never lower it.
 */
final class LevelClassifier
{
    public const LEVELS = ['debug', 'info', 'notice', 'warning', 'error', 'critical'];

    private const RULES = [
        'critical' => [
            '/\b(kernel panic|oom-killer|out of memory|segfault|emerg(ency)?|fatal)\b/i',
            '/\b(disk full|no space left on device|read-only file system)\b/i',
            '/\b(data corruption|filesystem error)\b/i',
        ],
        'error' => [
            '/\b(error|err\b|failed|failure|exception|traceback|denied|refused)\b/i',
            '/\b(unauthorized|forbidden|invalid (user|password|token))\b/i',
            '/\b(timeout|timed out|unreachable|connection reset)\b/i',
            '/\bHTTP\/[12](\.\d)?"? 5\d\d\b/',
        ],
        'warning' => [
            '/\b(warn(ing)?|deprecated|retry(ing)?|slow query|high (load|memory))\b/i',
            '/\bcertificate (expires|expired|will expire)\b/i',
        ],
    ];

    public function classify(string $message, string $service): string
    {
        foreach (self::RULES as $level => $patterns) {
            foreach ($patterns as $p) {
                if (preg_match($p, $message)) {
                    return $level;
                }
            }
        }
        // auth.log: failed logins are at least warning even without keywords
        if ($service === 'sshd' && stripos($message, 'invalid') !== false) {
            return 'warning';
        }
        return 'info';
    }

    public static function atLeast(string $level, string $threshold): bool
    {
        return array_search($level, self::LEVELS, true)
            >= array_search($threshold, self::LEVELS, true);
    }

    public static function stricter(string $a, string $b): string
    {
        return self::atLeast($a, $b) ? $a : $b;
    }
}
