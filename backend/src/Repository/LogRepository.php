<?php

declare(strict_types=1);

namespace App\Repository;

use App\Service\Ingest\Fingerprinter;

final class LogRepository extends Repository
{
    public function insertEntry(int $serverId, ?int $groupId, array $e): void
    {
        $this->exec(
            'INSERT INTO log_entries (server_id, error_group_id, ts, source_file, service, level, message, raw, fingerprint)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$serverId, $groupId, $e['ts'], $e['source_file'], $e['service'],
             $e['level'], $e['message'], $e['raw'], $e['fingerprint'] ?? null]);
    }

    /** @return array{0:int,1:bool} [group id, is new] */
    public function upsertErrorGroup(string $fingerprint, int $serverId, array $e): array
    {
        $row = $this->row(
            "INSERT INTO error_groups (fingerprint, service, source_class, level, title, occurrence_count, server_ids, first_seen, last_seen)
             VALUES (?, ?, ?, ?, ?, 1, ARRAY[?::bigint], ?, ?)
             ON CONFLICT (fingerprint) DO UPDATE SET
               occurrence_count = error_groups.occurrence_count + 1,
               last_seen = GREATEST(error_groups.last_seen, EXCLUDED.last_seen),
               level = GREATEST(error_groups.level, EXCLUDED.level),
               server_ids = CASE WHEN ? = ANY(error_groups.server_ids)
                                 THEN error_groups.server_ids
                                 ELSE array_append(error_groups.server_ids, ?) END
             RETURNING id, (xmax = 0) AS is_new",
            [
                $fingerprint, $e['service'], Fingerprinter::sourceClass($e['source_file']),
                $e['level'], mb_substr(Fingerprinter::normalize($e['message']), 0, 512),
                $serverId, $e['ts'], $e['ts'], $serverId, $serverId,
            ]);
        return [(int) $row->id, (bool) $row->is_new];
    }

    /**
     * Filterable raw stream. $f keys: server (uuid), level, service, from, to, q.
     * @return array{items: list<object>, total: int}
     */
    public function search(array $f, int $page, int $perPage): array
    {
        $where = ['1=1'];
        $params = [];
        if (!empty($f['server'])) {
            $where[] = 'server_id = (SELECT id FROM servers WHERE public_id = ?)';
            $params[] = $f['server'];
        }
        if (!empty($f['level'])) {
            $where[] = 'level >= ?::log_level';
            $params[] = $f['level'];
        }
        if (!empty($f['service'])) {
            $where[] = 'service = ?';
            $params[] = $f['service'];
        }
        if (!empty($f['from'])) {
            $where[] = 'ts >= ?';
            $params[] = $f['from'];
        }
        if (!empty($f['to'])) {
            $where[] = 'ts <= ?';
            $params[] = $f['to'];
        }
        if (!empty($f['q'])) {
            $where[] = "to_tsvector('simple', message) @@ plainto_tsquery('simple', ?)";
            $params[] = $f['q'];
        }
        $cond = implode(' AND ', $where);

        $total = (int) $this->scalar("SELECT count(*) FROM log_entries WHERE $cond", $params);
        $items = $this->rows(
            "SELECT e.id, e.ts, e.source_file, e.service, e.level, e.message, e.raw,
                    e.error_group_id, s.name AS server_name, s.public_id AS server_uuid
             FROM log_entries e JOIN servers s ON s.id = e.server_id
             WHERE $cond ORDER BY e.ts DESC LIMIT ? OFFSET ?",
            [...$params, $perPage, ($page - 1) * $perPage]);

        return ['items' => $items, 'total' => $total];
    }

    public function purgeOlderThan(int $days): int
    {
        return $this->exec(
            'DELETE FROM log_entries WHERE received_at < now() - make_interval(days => ?)', [$days]);
    }
}
