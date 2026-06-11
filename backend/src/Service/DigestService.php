<?php

declare(strict_types=1);

namespace App\Service;

use App\Repository\Repository;
use App\Service\Queue\Queue;

/**
 * 📰 Weekly ops digest — one summary notification per week through any rule
 * with trigger 'digest'. Pure SQL stats; readable at a glance in
 * Discord/Gotify. Runs as the periodic job 'digest.weekly'.
 */
final class DigestService extends Repository
{
    public function run(Queue $queue): void
    {
        $s = $this->row(
            "SELECT
               (SELECT count(*) FROM servers) AS servers,
               (SELECT count(*) FROM servers WHERE status = 'offline') AS offline,
               (SELECT count(*) FROM log_entries WHERE received_at > now() - interval '7 days') AS entries,
               (SELECT count(*) FROM log_entries WHERE level >= 'error' AND received_at > now() - interval '7 days') AS errors,
               (SELECT count(*) FROM error_groups WHERE first_seen > now() - interval '7 days') AS new_groups,
               (SELECT count(*) FROM error_groups WHERE status = 'open') AS open_groups,
               (SELECT count(*) FROM anomaly_events WHERE created_at > now() - interval '7 days') AS anomalies,
               (SELECT count(*) FROM ai_analyses WHERE created_at > now() - interval '7 days') AS analyses");

        $top = $this->rows(
            "SELECT service, count(*) AS n FROM log_entries
             WHERE level >= 'error' AND received_at > now() - interval '7 days'
             GROUP BY service ORDER BY n DESC LIMIT 3");
        $noisiest = implode(', ',
            array_map(static fn (object $t): string => "{$t->service} ({$t->n})", $top)) ?: 'none';

        $queue->push('notify.dispatch', [
            'trigger' => 'digest',
            'extra' => [
                '_title' => '📰 Logwatch2 weekly digest',
                'Servers' => sprintf('%d total, %d offline', $s->servers, $s->offline),
                'Log volume (7d)' => sprintf('%s entries, %s errors', $s->entries, $s->errors),
                'Errors' => sprintf('%d new groups, %d still open', $s->new_groups, $s->open_groups),
                'Anomalies (7d)' => (string) $s->anomalies,
                'AI analyses (7d)' => (string) $s->analyses,
                'Noisiest services' => $noisiest,
            ],
        ]);
    }
}
