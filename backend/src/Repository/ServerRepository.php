<?php

declare(strict_types=1);

namespace App\Repository;

use App\Support\Crypto;

final class ServerRepository extends Repository
{
    public function findByTokenHash(string $hash): ?object
    {
        return $this->row('SELECT id, public_id, name, status FROM servers WHERE token_hash = ?', [$hash]);
    }

    public function findByUuid(string $uuid): ?object
    {
        return $this->row('SELECT * FROM servers WHERE public_id = ?', [$uuid]);
    }

    /** @return list<object> servers with open error counts for the dashboard/API */
    public function listWithCounts(): array
    {
        return $this->rows(
            "SELECT s.public_id AS uuid, s.name, s.hostname, s.status, s.agent_version,
                    s.tags, s.last_seen_at,
                    count(g.id) FILTER (WHERE g.status = 'open' AND g.level >= 'error') AS open_errors
             FROM servers s
             LEFT JOIN error_groups g ON s.id = ANY(g.server_ids)
             GROUP BY s.id ORDER BY s.name");
    }

    /** @return array{server: object, token: string} token is returned exactly once */
    public function create(string $name, array $tags = []): array
    {
        $token = Crypto::newAgentToken();
        $server = $this->row(
            'INSERT INTO servers (name, token_hash, tags) VALUES (?, ?, ?)
             RETURNING id, public_id, name, status, created_at',
            [$name, hash('sha256', $token), json_encode($tags)]);
        return ['server' => $server, 'token' => $token];
    }

    public function rename(int $id, string $name, ?array $tags): void
    {
        $this->exec('UPDATE servers SET name = ?, tags = COALESCE(?, tags) WHERE id = ?',
            [$name, $tags === null ? null : json_encode($tags), $id]);
    }

    public function delete(int $id): void
    {
        $this->exec('DELETE FROM servers WHERE id = ?', [$id]);
    }

    /** @return string the new token (old one is invalid immediately) */
    public function rotateToken(int $id): string
    {
        $token = Crypto::newAgentToken();
        $this->exec('UPDATE servers SET token_hash = ? WHERE id = ?', [hash('sha256', $token), $id]);
        return $token;
    }

    /** Ingest seen: refresh liveness without touching hostname. */
    public function touch(int $id, ?string $agentVersion): void
    {
        $this->exec(
            "UPDATE servers SET last_seen_at = now(),
                    status = CASE WHEN status = 'offline' THEN 'online'::server_status ELSE status END,
                    agent_version = COALESCE(NULLIF(?, ''), agent_version)
             WHERE id = ?", [$agentVersion ?? '', $id]);
    }

    /** @return bool true when this heartbeat revived an offline server */
    public function heartbeat(int $id, string $agentVersion, string $hostname): bool
    {
        // The scalar subquery in RETURNING reads the pre-update snapshot,
        // so we learn whether the server was offline before this beat.
        $was = $this->scalar(
            "UPDATE servers SET last_seen_at = now(),
                    status = CASE WHEN status = 'offline' THEN 'online'::server_status ELSE status END,
                    agent_version = NULLIF(?, ''), hostname = NULLIF(?, '')
             WHERE id = ?
             RETURNING (SELECT status FROM servers s2 WHERE s2.id = servers.id)",
            [$agentVersion, $hostname, $id]);
        return $was === 'offline';
    }

    /** @return list<object> servers that just went offline (status flipped by this call) */
    public function markOffline(int $offlineAfterSeconds): array
    {
        return $this->rows(
            "UPDATE servers SET status = 'offline'
             WHERE status <> 'offline'
               AND (last_seen_at IS NULL OR last_seen_at < now() - make_interval(secs => ?))
             RETURNING id, name", [$offlineAfterSeconds]);
    }

    /** Derive warning/critical from open error groups of the last 24h. */
    public function refreshDerivedStatus(): void
    {
        $this->exec(
            "UPDATE servers s SET status = sub.derived FROM (
               SELECT s2.id,
                      CASE WHEN bool_or(g.level = 'critical') THEN 'critical'::server_status
                           WHEN bool_or(g.level >= 'warning') THEN 'warning'::server_status
                           ELSE 'online'::server_status END AS derived
               FROM servers s2
               LEFT JOIN error_groups g ON s2.id = ANY(g.server_ids)
                    AND g.status = 'open' AND g.last_seen > now() - interval '24 hours'
               WHERE s2.status <> 'offline'
               GROUP BY s2.id) sub
             WHERE s.id = sub.id AND s.status <> sub.derived");
    }
}
