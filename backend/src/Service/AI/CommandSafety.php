<?php

declare(strict_types=1);

namespace App\Service\AI;

/** Blocks obviously destructive suggestions regardless of what the model says. */
final class CommandSafety
{
    private const DESTRUCTIVE = [
        '/\brm\s+(-[a-z]*r[a-z]*f|-[a-z]*f[a-z]*r)\b/i',
        '/\bdd\s+.*of=\/dev\//i',
        '/\bmkfs(\.\w+)?\b/i',
        '/:\(\)\s*\{.*\};\s*:/',          // fork bomb
        '/\bchmod\s+(-R\s+)?777\s+\//i',
        '/\b(shutdown|reboot|halt)\b/i',  // suggest as text, never as runnable command
        '/>\s*\/dev\/sd[a-z]/i',
    ];

    public static function isDestructive(string $command): bool
    {
        foreach (self::DESTRUCTIVE as $p) {
            if (preg_match($p, $command)) {
                return true;
            }
        }
        return false;
    }
}
