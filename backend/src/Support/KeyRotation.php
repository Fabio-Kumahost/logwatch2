<?php

declare(strict_types=1);

namespace App\Support;

use PDO;

/**
 * Re-seals every encrypted value (settings, notification channel configs,
 * TOTP secrets) from APP_KEY to APP_KEY_NEW. Run via: php bin/console rotate-app-key
 */
final class KeyRotation
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function run(): void
    {
        $newKey = Config::env('APP_KEY_NEW');
        if ($newKey === null) {
            echo "APP_KEY_NEW is not set — nothing to do.\n";
            return;
        }
        $old = new Crypto(Config::env('APP_KEY') ?? '');
        $new = new Crypto($newKey);
        $n = 0;

        $this->pdo->beginTransaction();
        try {
            foreach ($this->pdo->query("SELECT key, value FROM settings WHERE is_encrypted") as $row) {
                $stmt = $this->pdo->prepare('UPDATE settings SET value = ?, updated_at = now() WHERE key = ?');
                $stmt->execute([$new->seal($old->unseal($row['value'])), $row['key']]);
                $n++;
            }
            foreach ($this->pdo->query('SELECT id, config FROM notification_channels') as $row) {
                $stmt = $this->pdo->prepare('UPDATE notification_channels SET config = ? WHERE id = ?');
                $stmt->execute([$new->seal($old->unseal($row['config'])), $row['id']]);
                $n++;
            }
            foreach ($this->pdo->query('SELECT id, totp_secret FROM users WHERE totp_secret IS NOT NULL') as $row) {
                $stmt = $this->pdo->prepare('UPDATE users SET totp_secret = ? WHERE id = ?');
                $stmt->execute([$new->seal($old->unseal($row['totp_secret'])), $row['id']]);
                $n++;
            }
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }

        echo "$n value(s) re-sealed.\n";
        echo "Now set APP_KEY=<value of APP_KEY_NEW>, remove APP_KEY_NEW, and restart the stack.\n";
    }
}
