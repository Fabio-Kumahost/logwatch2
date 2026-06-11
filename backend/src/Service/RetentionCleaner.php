<?php

declare(strict_types=1);

namespace App\Service;

use App\Repository\ErrorGroupRepository;
use App\Repository\LogRepository;
use App\Support\Config;
use Psr\Log\LoggerInterface;

/**
 * Data minimization: purges raw entries and resolved/ignored groups past
 * their retention windows. Runs as the periodic job 'retention.cleanup'.
 */
final class RetentionCleaner
{
    public function __construct(
        private readonly LogRepository $logs,
        private readonly ErrorGroupRepository $groups,
        private readonly LoggerInterface $log,
    ) {
    }

    public function run(): void
    {
        $entries = $this->logs->purgeOlderThan(Config::envInt('RETENTION_DAYS_LOGS', 30));
        $groups = $this->groups->purgeResolvedOlderThan(Config::envInt('RETENTION_DAYS_RESOLVED', 90));
        if ($entries > 0 || $groups > 0) {
            $this->log->info("retention: purged $entries entries, $groups resolved groups");
        }
    }
}
