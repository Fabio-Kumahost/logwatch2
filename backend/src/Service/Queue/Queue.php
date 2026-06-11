<?php

declare(strict_types=1);

namespace App\Service\Queue;

use PDO;

/**
 * DB-backed queue. claim() uses FOR UPDATE SKIP LOCKED so multiple workers
 * never double-process; failures retry with exponential backoff, then park.
 */
final class Queue
{
    private const MAX_ATTEMPTS = 5;

    public function __construct(private readonly PDO $pdo)
    {
    }

    public function push(string $type, array $payload = [], int $delaySeconds = 0): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO jobs (type, payload, run_at)
             VALUES (?, ?, now() + make_interval(secs => ?))');
        $stmt->execute([$type, json_encode($payload), $delaySeconds]);
    }

    /** Claims one due job, or null when the queue is idle. */
    public function claim(string $workerId): ?object
    {
        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare(
                'SELECT id, type, payload, attempts FROM jobs
                 WHERE run_at <= now() AND locked_at IS NULL AND failed_at IS NULL
                 ORDER BY id FOR UPDATE SKIP LOCKED LIMIT 1');
            $stmt->execute();
            $job = $stmt->fetch(PDO::FETCH_OBJ);
            if ($job === false) {
                $this->pdo->commit();
                return null;
            }
            $upd = $this->pdo->prepare(
                'UPDATE jobs SET locked_at = now(), locked_by = ?, attempts = attempts + 1 WHERE id = ?');
            $upd->execute([$workerId, $job->id]);
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }

        $job->payload = json_decode((string) $job->payload, true) ?: [];
        $job->attempts = (int) $job->attempts;
        return $job;
    }

    public function complete(object $job): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM jobs WHERE id = ?');
        $stmt->execute([$job->id]);
    }

    /** Retry with backoff (10s · 4^attempt), park after MAX_ATTEMPTS. */
    public function fail(object $job, string $error): void
    {
        if ($job->attempts >= self::MAX_ATTEMPTS) {
            $stmt = $this->pdo->prepare(
                'UPDATE jobs SET failed_at = now(), error = ?, locked_at = NULL WHERE id = ?');
            $stmt->execute([mb_substr($error, 0, 2000), $job->id]);
            return;
        }
        $delay = 10 * (4 ** $job->attempts);
        $stmt = $this->pdo->prepare(
            'UPDATE jobs SET locked_at = NULL, locked_by = NULL, error = ?,
                    run_at = now() + make_interval(secs => ?) WHERE id = ?');
        $stmt->execute([mb_substr($error, 0, 2000), $delay, $job->id]);
    }

    /**
     * Self-scheduling periodic jobs: seeds one instance of $type unless one
     * is already pending or running. After each run the worker re-arms the
     * chain by pushing the job again with its 'periodic' delay.
     */
    public function ensurePeriodic(string $type, int $everySeconds): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO jobs (type, payload, run_at)
             SELECT ?, ?, now() + make_interval(secs => ?)
             WHERE NOT EXISTS (SELECT 1 FROM jobs WHERE type = ? AND failed_at IS NULL)');
        $stmt->execute([$type, json_encode(['periodic' => $everySeconds]), $everySeconds, $type]);
    }

    /** Unlock jobs whose worker died mid-flight (lock older than 10 min). */
    public function reapStale(): int
    {
        return $this->pdo->exec(
            "UPDATE jobs SET locked_at = NULL, locked_by = NULL
             WHERE locked_at < now() - interval '10 minutes' AND failed_at IS NULL");
    }

    public function pendingCount(): int
    {
        return (int) $this->pdo->query(
            'SELECT count(*) FROM jobs WHERE failed_at IS NULL')->fetchColumn();
    }
}
