<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\AnalysisRepository;
use App\Service\Queue\Queue;
use App\Support\Config;
use App\Support\Json;
use PDO;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class StatsController
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly AnalysisRepository $analyses,
        private readonly Queue $queue,
    ) {
    }

    /** Everything the dashboard polls every 15s, in one round trip. */
    public function dashboard(Request $request, Response $response): Response
    {
        $servers = $this->pdo->query(
            "SELECT public_id AS uuid, name, status,
                    to_char(last_seen_at, 'YYYY-MM-DD HH24:MI:SS') AS last_seen_human
             FROM servers ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

        $counts = $this->pdo->query(
            "SELECT
               (SELECT count(*) FROM servers) AS total,
               (SELECT count(*) FROM servers WHERE status <> 'offline') AS online,
               (SELECT count(*) FROM log_entries WHERE level = 'critical' AND ts > now() - interval '24 hours') AS critical_24h,
               (SELECT count(*) FROM log_entries WHERE level >= 'error' AND ts > now() - interval '24 hours') AS errors_24h")
            ->fetch(PDO::FETCH_ASSOC);
        $counts['ai_used'] = $this->analyses->countToday();
        $counts['ai_budget'] = Config::envInt('AI_DAILY_BUDGET_REQUESTS', 500);

        $recent = $this->pdo->query(
            "SELECT g.id, g.service, g.level, g.title, g.recurring, g.occurrence_count,
                    cardinality(g.server_ids) AS server_count, a.summary AS ai_summary,
                    to_char(g.last_seen, 'MM-DD HH24:MI') AS last_seen_human
             FROM error_groups g LEFT JOIN ai_analyses a ON a.fingerprint = g.fingerprint
             WHERE g.status = 'open' ORDER BY g.last_seen DESC LIMIT 15")
            ->fetchAll(PDO::FETCH_ASSOC);

        $anomalies = $this->pdo->query(
            "SELECT a.kind, a.details, s.name AS server_name,
                    to_char(a.created_at, 'MM-DD HH24:MI') AS at_human
             FROM anomaly_events a LEFT JOIN servers s ON s.id = a.server_id
             WHERE a.created_at > now() - interval '24 hours'
             ORDER BY a.created_at DESC LIMIT 10")->fetchAll(PDO::FETCH_ASSOC);

        return Json::data($response, [
            'servers' => $servers,
            'counts' => $counts,
            'recent_errors' => $recent,
            'anomalies' => $anomalies,
        ]);
    }

    /**
     * Prometheus exposition. Enabled only when METRICS_TOKEN is set;
     * scrape with: Authorization: Bearer $METRICS_TOKEN
     */
    public function metrics(Request $request, Response $response): Response
    {
        $token = Config::env('METRICS_TOKEN');
        if ($token === null) {
            return Json::error($response, 404, 'not_found', 'metrics are disabled (set METRICS_TOKEN)');
        }
        if (!hash_equals('Bearer ' . $token, $request->getHeaderLine('Authorization'))) {
            return Json::error($response, 401, 'unauthorized', 'bad metrics token');
        }

        $byStatus = $this->pdo->query(
            'SELECT status, count(*) AS n FROM servers GROUP BY status')->fetchAll(PDO::FETCH_KEY_PAIR);
        $openGroups = (int) $this->pdo->query(
            "SELECT count(*) FROM error_groups WHERE status = 'open'")->fetchColumn();
        $entries24h = (int) $this->pdo->query(
            "SELECT count(*) FROM log_entries WHERE received_at > now() - interval '24 hours'")->fetchColumn();

        $lines = [
            '# TYPE lw2_servers gauge',
        ];
        foreach (['online', 'offline', 'warning', 'critical'] as $s) {
            $lines[] = sprintf('lw2_servers{status="%s"} %d', $s, (int) ($byStatus[$s] ?? 0));
        }
        $lines[] = '# TYPE lw2_error_groups_open gauge';
        $lines[] = "lw2_error_groups_open $openGroups";
        $lines[] = '# TYPE lw2_log_entries_24h gauge';
        $lines[] = "lw2_log_entries_24h $entries24h";
        $lines[] = '# TYPE lw2_jobs_pending gauge';
        $lines[] = 'lw2_jobs_pending ' . $this->queue->pendingCount();
        $lines[] = '# TYPE lw2_ai_requests_today gauge';
        $lines[] = 'lw2_ai_requests_today ' . $this->analyses->countToday();

        $response->getBody()->write(implode("\n", $lines) . "\n");
        return $response->withHeader('Content-Type', 'text/plain; version=0.0.4');
    }
}
