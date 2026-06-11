<?php

declare(strict_types=1);

namespace App\Repository;

use Psr\Http\Message\ServerRequestInterface as Request;

final class AuditRepository extends Repository
{
    /** Records who did what; user comes from the request's auth attribute. */
    public function log(Request $request, string $action, array $details = []): void
    {
        $user = $request->getAttribute('user');
        $this->exec(
            'INSERT INTO audit_log (user_id, action, details, ip) VALUES (?, ?, ?, ?::inet)',
            [
                $user?->id,
                $action,
                json_encode($details, JSON_UNESCAPED_SLASHES),
                $request->getServerParams()['REMOTE_ADDR'] ?? '0.0.0.0',
            ]);
    }

    /** @return list<object> */
    public function recent(int $limit = 100): array
    {
        return $this->rows(
            'SELECT a.created_at, a.action, a.details, a.ip, u.username
             FROM audit_log a LEFT JOIN users u ON u.id = a.user_id
             ORDER BY a.id DESC LIMIT ?', [$limit]);
    }
}
