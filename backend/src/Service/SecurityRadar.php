<?php

declare(strict_types=1);

namespace App\Service;

use App\Repository\Repository;
use App\Service\Queue\Queue;
use App\Support\Config;

/**
 * 🛡️ Security Radar — detects authentication attacks from the log stream
 * without AI cost: counts failed-auth patterns per server in a sliding
 * window and raises an 'auth_attack' event when the threshold is crossed.
 * Runs as the periodic job 'security.scan'.
 */
final class SecurityRadar extends Repository
{
    private const PATTERNS =
        '(Failed password|Invalid user|authentication failure|Connection closed by authenticating user|maximum authentication attempts)';

    public function run(Queue $queue): void
    {
        $threshold = Config::envInt('RADAR_AUTH_THRESHOLD', 20);
        $windowMin = Config::envInt('RADAR_WINDOW_MINUTES', 10);

        $hits = $this->rows(
            "SELECT e.server_id, count(*) AS failures,
                    count(DISTINCT substring(e.message from 'from ([0-9a-fA-F:.]+)')) AS sources
             FROM log_entries e
             WHERE e.ts > now() - make_interval(mins => ?)
               AND e.service IN ('sshd', 'ssh', 'auth')
               AND e.message ~ ?
             GROUP BY e.server_id
             HAVING count(*) >= ?",
            [$windowMin, self::PATTERNS, $threshold]);

        foreach ($hits as $hit) {
            $serverId = (int) $hit->server_id;
            // One event per server per window — re-alerting is the rules' job.
            $already = $this->scalar(
                "SELECT exists(SELECT 1 FROM anomaly_events
                 WHERE server_id = ? AND kind = 'auth_attack'
                   AND created_at > now() - make_interval(mins => ?))",
                [$serverId, $windowMin]);
            if ($already) {
                continue;
            }

            $details = sprintf('%d failed auth attempts from %d source(s) in %d min',
                (int) $hit->failures, (int) $hit->sources, $windowMin);
            $this->exec(
                "INSERT INTO anomaly_events (server_id, kind, details) VALUES (?, 'auth_attack', ?)",
                [$serverId, $details]);

            $queue->push('notify.dispatch', [
                'trigger' => 'auth_attack',
                'server_id' => $serverId,
                'extra' => [
                    'Detected' => $details,
                    'Suggested' => 'check `journalctl -u ssh -n 100`, consider fail2ban / key-only auth',
                ],
            ]);
        }
    }
}
