<?php

declare(strict_types=1);

namespace App\Service;

use App\Repository\Repository;
use App\Service\Queue\Queue;
use App\Support\Config;

/**
 * 📈 Statistical anomaly detection — no AI cost. Compares each server's
 * error rate in the last hour against its own 7-day hourly baseline and
 * raises an 'anomaly' event when it exceeds mean + 3σ (and a sane floor,
 * so quiet servers don't alarm on 0 → 2 errors).
 * Runs as the periodic job 'anomaly.scan'.
 */
final class AnomalyDetector extends Repository
{
    public function run(Queue $queue): void
    {
        $minErrors = Config::envInt('ANOMALY_MIN_ERRORS', 10);

        $rows = $this->rows(
            "WITH hourly AS (
               SELECT server_id, date_trunc('hour', ts) AS h, count(*) AS n
               FROM log_entries
               WHERE level >= 'error' AND ts > now() - interval '7 days'
                 AND ts < date_trunc('hour', now())
               GROUP BY server_id, h
             ), baseline AS (
               SELECT server_id, avg(n) AS mean, coalesce(stddev_samp(n), 0) AS sd
               FROM hourly GROUP BY server_id
             ), current AS (
               SELECT server_id, count(*) AS n
               FROM log_entries
               WHERE level >= 'error' AND ts > now() - interval '1 hour'
               GROUP BY server_id
             )
             SELECT c.server_id, c.n AS current_count,
                    round(b.mean, 1) AS mean, round(b.sd, 1) AS sd
             FROM current c JOIN baseline b USING (server_id)
             WHERE c.n >= ? AND c.n > b.mean + 3 * greatest(b.sd, 1)",
            [$minErrors]);

        foreach ($rows as $r) {
            $serverId = (int) $r->server_id;
            $already = $this->scalar(
                "SELECT exists(SELECT 1 FROM anomaly_events
                 WHERE server_id = ? AND kind = 'error_rate'
                   AND created_at > now() - interval '1 hour')", [$serverId]);
            if ($already) {
                continue;
            }

            $details = sprintf('%d errors in the last hour (baseline %s ± %s/h)',
                (int) $r->current_count, $r->mean, $r->sd);
            $this->exec(
                "INSERT INTO anomaly_events (server_id, kind, details) VALUES (?, 'error_rate', ?)",
                [$serverId, $details]);

            $queue->push('notify.dispatch', [
                'trigger' => 'anomaly',
                'server_id' => $serverId,
                'extra' => ['Detected' => $details,
                    'Hint' => 'open the log stream filtered to this server and the last hour'],
            ]);
        }
    }
}
