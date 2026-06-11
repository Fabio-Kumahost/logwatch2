<?php

declare(strict_types=1);

namespace App\Repository;

final class UserRepository extends Repository
{
    public function findByUsername(string $username): ?object
    {
        return $this->row('SELECT * FROM users WHERE username = ? AND is_active', [$username]);
    }

    public function findById(int $id): ?object
    {
        return $this->row('SELECT * FROM users WHERE id = ?', [$id]);
    }

    /** @return list<object> */
    public function list(): array
    {
        return $this->rows(
            'SELECT id, username, email, role, is_active, created_at, last_login_at,
                    totp_secret IS NOT NULL AS totp_enabled
             FROM users ORDER BY username');
    }

    public function create(string $username, string $passwordHash, string $role, ?string $email): ?object
    {
        return $this->row(
            'INSERT INTO users (username, password_hash, role, email) VALUES (?, ?, ?, ?)
             ON CONFLICT (username) DO NOTHING
             RETURNING id, username, role', [$username, $passwordHash, $role, $email]);
    }

    public function update(int $id, array $fields): void
    {
        $allowed = ['email', 'role', 'is_active', 'password_hash'];
        $sets = [];
        $params = [];
        foreach ($allowed as $col) {
            if (array_key_exists($col, $fields)) {
                $sets[] = "$col = ?";
                $params[] = $fields[$col];
            }
        }
        if ($sets === []) {
            return;
        }
        $params[] = $id;
        $this->exec('UPDATE users SET ' . implode(', ', $sets) . ' WHERE id = ?', $params);
    }

    public function delete(int $id): void
    {
        $this->exec('DELETE FROM users WHERE id = ?', [$id]);
    }

    public function setTotpSecret(int $id, ?string $sealedSecret): void
    {
        $this->exec('UPDATE users SET totp_secret = ? WHERE id = ?', [$sealedSecret, $id]);
    }

    public function recordLogin(string $username, string $ip, bool $success): void
    {
        $this->exec('INSERT INTO login_attempts (username, ip, success) VALUES (?, ?::inet, ?)',
            [$username, $ip, $success ? 'true' : 'false']);
        if ($success) {
            $this->exec('UPDATE users SET last_login_at = now() WHERE username = ?', [$username]);
        }
    }

    /** Lockout: too many failures for this user+ip in the last 15 minutes. */
    public function isLockedOut(string $username, string $ip, int $maxAttempts): bool
    {
        return (int) $this->scalar(
            "SELECT count(*) FROM login_attempts
             WHERE username = ? AND ip = ?::inet AND NOT success
               AND created_at > now() - interval '15 minutes'",
            [$username, $ip]) >= $maxAttempts;
    }

    public function countAdmins(): int
    {
        return (int) $this->scalar("SELECT count(*) FROM users WHERE role = 'admin' AND is_active");
    }
}
