<?php

declare(strict_types=1);

/**
 * Job worker — runs as the `worker` compose service (scalable: --scale worker=3).
 * Claims jobs with FOR UPDATE SKIP LOCKED; failures retry with exponential
 * backoff up to 5 attempts, then park for admin review. Periodic jobs re-arm
 * themselves after every run.
 */

use App\Service\AI\AiAnalyzer;
use App\Service\AnomalyDetector;
use App\Service\DigestService;
use App\Service\Notify\Notifier;
use App\Service\OfflineChecker;
use App\Service\Queue\Queue;
use App\Service\RecurrenceChecker;
use App\Service\RetentionCleaner;
use App\Service\SecurityRadar;
use App\Support\Config;

require __DIR__ . '/../vendor/autoload.php';
$container = require __DIR__ . '/../config/bootstrap.php';

$queue = $container->get(Queue::class);
$log = $container->get(Psr\Log\LoggerInterface::class);
$workerId = gethostname() . ':' . getmypid();

$running = true;
pcntl_async_signals(true);
foreach ([SIGTERM, SIGINT] as $sig) {
    pcntl_signal($sig, function () use (&$running, $log) {
        $log->info('worker: shutdown requested, finishing current job');
        $running = false;
    });
}

$log->info("worker $workerId started");

// Seed the periodic chains (no-ops when already scheduled by another worker).
$periodics = [
    'servers.offline_check' => 60,
    'groups.recurrence_check' => 300,
    'security.scan' => Config::envInt('RADAR_SCAN_INTERVAL', 120),
    'anomaly.scan' => 600,
    'retention.cleanup' => 3600,
    'jobs.reap_stale' => 600,
    'digest.weekly' => 604800,
];
foreach ($periodics as $type => $seconds) {
    $queue->ensurePeriodic($type, $seconds);
}

while ($running) {
    $job = $queue->claim($workerId);
    if ($job === null) {
        usleep(500_000);
        continue;
    }

    try {
        match ($job->type) {
            'ai.analyze' => $container->get(AiAnalyzer::class)->analyzeFingerprint(
                (string) $job->payload['fingerprint'],
                (bool) ($job->payload['force'] ?? false)),
            'notify.dispatch' => $container->get(Notifier::class)->dispatch(
                (string) $job->payload['trigger'],
                isset($job->payload['group_id']) ? (int) $job->payload['group_id'] : null,
                isset($job->payload['server_id']) ? (int) $job->payload['server_id'] : null,
                (array) ($job->payload['extra'] ?? [])),
            'servers.offline_check' => $container->get(OfflineChecker::class)->run(),
            'groups.recurrence_check' => $container->get(RecurrenceChecker::class)->run(),
            'security.scan' => $container->get(SecurityRadar::class)->run($queue),
            'anomaly.scan' => $container->get(AnomalyDetector::class)->run($queue),
            'retention.cleanup' => $container->get(RetentionCleaner::class)->run(),
            'digest.weekly' => $container->get(DigestService::class)->run($queue),
            'jobs.reap_stale' => $queue->reapStale(),
            default => $log->error("unknown job type {$job->type}"),
        };
        $queue->complete($job);

        // Periodic jobs re-arm their own chain.
        $interval = $job->payload['periodic'] ?? null;
        if (is_int($interval) && $interval > 0) {
            $queue->push($job->type, $job->payload, $interval);
        }
    } catch (Throwable $e) {
        $log->error("job {$job->id} ({$job->type}) failed: {$e->getMessage()}");
        $queue->fail($job, $e->getMessage()); // re-schedules with backoff or parks
    }
}
