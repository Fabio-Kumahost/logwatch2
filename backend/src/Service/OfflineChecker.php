<?php

declare(strict_types=1);

namespace App\Service;

use App\Repository\ServerRepository;
use App\Service\Queue\Queue;
use App\Support\Config;

/**
 * Flags servers offline when heartbeats stop, refreshes derived
 * warning/critical status, and emits offline notifications.
 * Runs as the periodic job 'servers.offline_check'.
 */
final class OfflineChecker
{
    public function __construct(
        private readonly ServerRepository $servers,
        private readonly Queue $queue,
    ) {
    }

    public function run(): void
    {
        $wentOffline = $this->servers->markOffline(Config::envInt('AGENT_OFFLINE_AFTER', 180));
        foreach ($wentOffline as $server) {
            $this->queue->push('notify.dispatch', [
                'trigger' => 'server_offline',
                'server_id' => (int) $server->id,
            ]);
        }
        $this->servers->refreshDerivedStatus();
    }
}
