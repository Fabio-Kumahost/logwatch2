<?php

declare(strict_types=1);

namespace App\Support;

/** Typed access to environment configuration. Env always wins over DB settings. */
final class Config
{
    public static function env(string $key, ?string $default = null): ?string
    {
        $v = $_ENV[$key] ?? getenv($key);
        if ($v === false || $v === null || $v === '') {
            return $default;
        }
        return (string) $v;
    }

    public static function envInt(string $key, int $default): int
    {
        $v = self::env($key);
        return is_numeric($v) ? (int) $v : $default;
    }

    public static function envBool(string $key, bool $default = false): bool
    {
        $v = self::env($key);
        if ($v === null) {
            return $default;
        }
        return in_array(strtolower($v), ['1', 'true', 'yes', 'on'], true);
    }
}
