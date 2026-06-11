<?php

declare(strict_types=1);

namespace App\Support;

/** Boundary validation helpers — every external value passes through here. */
final class Validator
{
    /** Trimmed string capped at $max chars; non-strings become ''. */
    public static function str(mixed $v, int $max): string
    {
        if (!is_string($v)) {
            return '';
        }
        $v = trim($v);
        // Strip control chars except tab/newline (log payloads keep structure, UI stays safe).
        $v = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $v) ?? '';
        return mb_substr($v, 0, $max);
    }

    /** @param list<string> $allowed */
    public static function enum(mixed $v, array $allowed, string $default): string
    {
        return is_string($v) && in_array($v, $allowed, true) ? $v : $default;
    }

    public static function int(mixed $v, int $min, int $max, int $default): int
    {
        if (!is_numeric($v)) {
            return $default;
        }
        $i = (int) $v;
        return ($i >= $min && $i <= $max) ? $i : $default;
    }

    /** RFC3339-ish timestamp → ISO string (UTC). Rejects >24h future, >365d past. */
    public static function timestamp(mixed $v): string
    {
        $now = time();
        if (is_string($v) && $v !== '') {
            $t = strtotime($v);
            if ($t !== false && $t <= $now + 86400 && $t >= $now - 31536000) {
                return gmdate('c', $t);
            }
        }
        return gmdate('c', $now);
    }

    public static function email(mixed $v): ?string
    {
        if (!is_string($v)) {
            return null;
        }
        $v = trim($v);
        return filter_var($v, FILTER_VALIDATE_EMAIL) !== false ? $v : null;
    }

    public static function uuid(mixed $v): ?string
    {
        if (is_string($v) && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $v)) {
            return strtolower($v);
        }
        return null;
    }
}
