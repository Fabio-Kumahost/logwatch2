<?php

declare(strict_types=1);

namespace App\Repository;

final class ErrorGroupRepository extends Repository
{
    public function findById(int $id): ?object
    {
        return $this->row('SELECT * FROM error_groups WHERE id = ?', [$id]);
    }

    public function findByFingerprint(string $fp): ?object
    {
        $g = $this->row('SELECT * FROM error_groups WHERE fingerprint = ?', [$fp]);
        if ($g !== null) {
            $g->occurrenceCount = (int) $g->occurrence_count;
            $g->serverIds = self::pgArray($g->server_ids);
            $g->sourceClass = $g->source_class;
        }
        return $g;
    }

    /** @return array{items: list<object>, total: int} */
    public function search(array $f, int $page, int $perPage): array
    {
        $where = ['1=1'];
        $params = [];
        if (!empty($f['status'])) {
            $where[] = 'g.status = ?::group_status';
            $params[] = $f['status'];
        }
        if (!empty($f['level'])) {
            $where[] = 'g.level >= ?::log_level';
            $params[] = $f['level'];
        }
        if (!empty($f['server'])) {
            $where[] = '(SELECT id FROM servers WHERE public_id = ?) = ANY(g.server_ids)';
            $params[] = $f['server'];
        }
        $cond = implode(' AND ', $where);

        $total = (int) $this->scalar("SELECT count(*) FROM error_groups g WHERE $cond", $params);
        $items = $this->rows(
            "SELECT g.id, g.service, g.level, g.title, g.status, g.recurring,
                    g.occurrence_count, g.first_seen, g.last_seen,
                    cardinality(g.server_ids) AS server_count,
                    a.summary AS ai_summary, a.severity AS ai_severity
             FROM error_groups g
             LEFT JOIN ai_analyses a ON a.fingerprint = g.fingerprint
             WHERE $cond
             ORDER BY g.last_seen DESC LIMIT ? OFFSET ?",
            [...$params, $perPage, ($page - 1) * $perPage]);

        return ['items' => $items, 'total' => $total];
    }

    /** Group detail incl. analysis, affected servers and recent occurrences. */
    public function detail(int $id): ?array
    {
        $group = $this->row(
            'SELECT g.*, a.summary, a.explanation, a.probable_causes, a.impact,
                    a.severity AS ai_severity, a.urgency, a.solution_steps, a.commands,
                    a.related_checks, a.provider, a.model, a.created_at AS analyzed_at
             FROM error_groups g
             LEFT JOIN ai_analyses a ON a.fingerprint = g.fingerprint
             WHERE g.id = ?', [$id]);
        if ($group === null) {
            return null;
        }
        $servers = $this->rows(
            'SELECT name, public_id AS uuid, status FROM servers WHERE id = ANY(?::bigint[]) ORDER BY name',
            [$group->server_ids]);
        $occurrences = $this->rows(
            'SELECT e.ts, e.raw, e.source_file, s.name AS server_name
             FROM log_entries e JOIN servers s ON s.id = e.server_id
             WHERE e.error_group_id = ? ORDER BY e.ts DESC LIMIT 20', [$id]);
        return ['group' => $group, 'servers' => $servers, 'occurrences' => $occurrences];
    }

    public function updateStatus(int $id, string $status): bool
    {
        return $this->exec('UPDATE error_groups SET status = ?::group_status WHERE id = ?',
            [$status, $id]) > 0;
    }

    /** Sample entry + a few lines around it from the same server/file (AI context). */
    public function representativeEntry(int $groupId): object
    {
        $entry = $this->row(
            'SELECT e.id, e.server_id, e.raw, e.ts, e.source_file, s.os_info
             FROM log_entries e JOIN servers s ON s.id = e.server_id
             WHERE e.error_group_id = ? ORDER BY e.id DESC LIMIT 1', [$groupId]);

        $context = $entry === null ? [] : array_map(
            static fn (object $r): string => (string) $r->raw,
            $this->rows(
                'SELECT raw FROM log_entries
                 WHERE server_id = ? AND source_file = ? AND id <> ?
                   AND ts BETWEEN ?::timestamptz - interval \'2 minutes\' AND ?::timestamptz + interval \'2 minutes\'
                 ORDER BY ts DESC LIMIT 5',
                [$entry->server_id, $entry->source_file, $entry->id, $entry->ts, $entry->ts]));

        return (object) [
            'raw' => $entry->raw ?? '',
            'contextLines' => $context,
            'osFamily' => $entry?->os_info ?: 'linux',
        ];
    }

    public function flagSeverityRaised(int $id, int $severity): void
    {
        $this->exec(
            "UPDATE error_groups SET level = 'critical'
             WHERE id = ? AND ? >= 5 AND level <> 'critical'", [$id, $severity]);
    }

    /** Mark groups recurring; returns the freshly flagged ones for notifications. */
    public function flagRecurring(int $perHour, int $perDay): array
    {
        return $this->rows(
            "UPDATE error_groups g SET recurring = true
             WHERE NOT recurring AND status = 'open' AND (
               (SELECT count(*) FROM log_entries e WHERE e.error_group_id = g.id
                  AND e.ts > now() - interval '1 hour') >= ?
               OR
               (SELECT count(*) FROM log_entries e WHERE e.error_group_id = g.id
                  AND e.ts > now() - interval '24 hours') >= ?)
             RETURNING g.id, g.server_ids[1] AS server_id", [$perHour, $perDay]);
    }

    public function purgeResolvedOlderThan(int $days): int
    {
        return $this->exec(
            "DELETE FROM error_groups
             WHERE status IN ('resolved', 'ignored')
               AND last_seen < now() - make_interval(days => ?)", [$days]);
    }

    /** Parses a PG bigint[] literal like {1,2,3} into a PHP int list. */
    public static function pgArray(?string $literal): array
    {
        if ($literal === null || $literal === '{}') {
            return [];
        }
        return array_map('intval', explode(',', trim($literal, '{}')));
    }
}
