<?php

declare(strict_types=1);

namespace App\Service\Notify;

use App\Repository\NotificationRepository;
use Psr\Log\LoggerInterface;

/**
 * Executes 'notify.dispatch' jobs: match rules → rate-limit → send via adapter.
 * Every decision (sent / suppressed+reason / failed) lands in notifications_log,
 * which is both the audit trail and the data the rate limiter reads.
 */
final class Notifier
{
    private const HOURLY_CAP = 20;

    /** @param array<string, ChannelInterface> $adapters keyed by channel type */
    public function __construct(
        private readonly NotificationRepository $repo,
        private readonly array $adapters,
        private readonly LoggerInterface $log,
    ) {
    }

    /** @param array<string,string> $extra extra body lines (anomaly stats, digest text…) */
    public function dispatch(string $trigger, ?int $groupId, ?int $serverId, array $extra = []): void
    {
        foreach ($this->repo->activeRulesFor($trigger) as $rule) {
            if (!$this->repo->ruleMatchesFilters($rule, $groupId, $serverId)) {
                continue;
            }

            // Layer 1: per rule+group cooldown.
            $last = $this->repo->lastSentAt($rule->id, $groupId);
            if ($last !== null && time() - $last < $rule->cooldownSeconds) {
                $this->repo->logSuppressed($rule, $groupId, $serverId, 'cooldown');
                continue;
            }
            // Layer 2: per-channel hourly cap (one "muted" notice when first hit).
            $sentLastHour = $this->repo->countSentLastHour($rule->channelId);
            if ($sentLastHour >= self::HOURLY_CAP) {
                if ($sentLastHour === self::HOURLY_CAP) {
                    $this->sendRaw($rule, Notification::capReached());
                }
                $this->repo->logSuppressed($rule, $groupId, $serverId, 'hourly_cap');
                continue;
            }

            $notification = $this->repo->buildNotification($trigger, $rule, $groupId, $serverId, $extra);

            // Layer 3: dedupe identical payloads within 60s.
            if ($this->repo->recentlySentPayload($rule->channelId, $notification->payloadHash(), 60)) {
                $this->repo->logSuppressed($rule, $groupId, $serverId, 'dedupe');
                continue;
            }

            $this->sendRaw($rule, $notification, $groupId, $serverId);
        }
    }

    /** Direct send for the channel "Test" button — bypasses rules and limits. */
    public function sendTest(object $channel, Notification $n): DeliveryResult
    {
        $adapter = $this->adapters[$channel->type] ?? null;
        if ($adapter === null) {
            return new DeliveryResult(false, 'no adapter for type ' . $channel->type);
        }
        return $adapter->send($channel, $n);
    }

    private function sendRaw(object $rule, Notification $n, ?int $groupId = null, ?int $serverId = null): void
    {
        $channel = $this->repo->channel($rule->channelId);
        $adapter = $this->adapters[$channel->type] ?? null;
        if ($adapter === null || !$channel->isActive) {
            return;
        }
        $result = $adapter->send($channel, $n);
        $this->repo->logDelivery($rule, $groupId, $serverId, $result, $n->payloadHash());
        if (!$result->ok) {
            $this->log->warning('notification delivery failed', [
                'channel' => $channel->id, 'reason' => $result->reason,
            ]);
        }
    }
}
