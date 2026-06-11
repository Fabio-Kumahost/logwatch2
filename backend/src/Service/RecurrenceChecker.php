<?php

declare(strict_types=1);

namespace App\Service;

use App\Repository\ErrorGroupRepository;
use App\Service\Queue\Queue;
use App\Support\Config;

/**
 * Flags error groups as recurring when they cross occurrence thresholds
 * and emits 'recurring_error' notifications.
 * Runs as the periodic job 'groups.recurrence_check'.
 */
final class RecurrenceChecker
{
    public function __construct(
        private readonly ErrorGroupRepository $groups,
        private readonly Queue $queue,
    ) {
    }

    public function run(): void
    {
        $flagged = $this->groups->flagRecurring(
            Config::envInt('RECURRING_PER_HOUR', 10),
            Config::envInt('RECURRING_PER_DAY', 50),
        );
        foreach ($flagged as $g) {
            $this->queue->push('notify.dispatch', [
                'trigger' => 'recurring_error',
                'group_id' => (int) $g->id,
                'server_id' => $g->server_id === null ? null : (int) $g->server_id,
            ]);
        }
    }
}
