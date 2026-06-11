<?php

declare(strict_types=1);

namespace App\Repository;

use App\Service\Notify\DeliveryResult;
use App\Service\Notify\Notification;
use App\Support\Config;
use App\Support\Crypto;
use PDO;

final class NotificationRepository extends Repository
{
    public function __construct(PDO $pdo, private readonly Crypto $crypto)
    {
        parent::__construct($pdo);
    }

    // ---- channels -------------------------------------------------------

    /** @return list<object> configs redacted for API output */
    public function listChannels(): array
    {
        $rows = $this->rows(
            'SELECT id, type, name, is_active, created_at FROM notification_channels ORDER BY name');
        foreach ($rows as $r) {
            $r->config_set = true; // secrets never leave the server
        }
        return $rows;
    }

    public function channel(int $id): ?object
    {
        return $this->row('SELECT * FROM notification_channels WHERE id = ?', [$id]);
    }

    public function createChannel(string $type, string $name, array $config): object
    {
        return $this->row(
            'INSERT INTO notification_channels (type, name, config) VALUES (?, ?, ?)
             RETURNING id, type, name, is_active',
            [$type, $name, $this->crypto->seal(json_encode($config, JSON_UNESCAPED_SLASHES))]);
    }

    public function updateChannel(int $id, ?string $name, ?bool $active, ?array $config): void
    {
        if ($name !== null) {
            $this->exec('UPDATE notification_channels SET name = ? WHERE id = ?', [$name, $id]);
        }
        if ($active !== null) {
            $this->exec('UPDATE notification_channels SET is_active = ? WHERE id = ?',
                [$active ? 'true' : 'false', $id]);
        }
        if ($config !== null) {
            $this->exec('UPDATE notification_channels SET config = ? WHERE id = ?',
                [$this->crypto->seal(json_encode($config, JSON_UNESCAPED_SLASHES)), $id]);
        }
    }

    public function deleteChannel(int $id): void
    {
        $this->exec('DELETE FROM notification_channels WHERE id = ?', [$id]);
    }

    // ---- rules ----------------------------------------------------------

    public function listRules(): array
    {
        return $this->rows(
            'SELECT r.*, c.name AS channel_name, c.type AS channel_type
             FROM notification_rules r JOIN notification_channels c ON c.id = r.channel_id
             ORDER BY r.id');
    }

    public function createRule(int $channelId, string $trigger, array $filters, int $cooldown): object
    {
        return $this->row(
            'INSERT INTO notification_rules (channel_id, trigger, filters, cooldown_seconds)
             VALUES (?, ?, ?, ?) RETURNING *',
            [$channelId, $trigger, json_encode($filters), $cooldown]);
    }

    public function updateRule(int $id, array $fields): void
    {
        if (isset($fields['filters'])) {
            $this->exec('UPDATE notification_rules SET filters = ? WHERE id = ?',
                [json_encode($fields['filters']), $id]);
        }
        if (isset($fields['cooldown_seconds'])) {
            $this->exec('UPDATE notification_rules SET cooldown_seconds = ? WHERE id = ?',
                [(int) $fields['cooldown_seconds'], $id]);
        }
        if (isset($fields['is_active'])) {
            $this->exec('UPDATE notification_rules SET is_active = ? WHERE id = ?',
                [$fields['is_active'] ? 'true' : 'false', $id]);
        }
    }

    public function deleteRule(int $id): void
    {
        $this->exec('DELETE FROM notification_rules WHERE id = ?', [$id]);
    }

    /** @return list<object> with ->id ->channelId ->cooldownSeconds ->filters */
    public function activeRulesFor(string $trigger): array
    {
        $rules = $this->rows(
            'SELECT id, channel_id, cooldown_seconds, filters FROM notification_rules
             WHERE trigger = ? AND is_active', [$trigger]);
        foreach ($rules as $r) {
            $r->channelId = (int) $r->channel_id;
            $r->cooldownSeconds = (int) $r->cooldown_seconds;
            $r->filterData = json_decode((string) $r->filters, true) ?: [];
        }
        return $rules;
    }

    public function ruleMatchesFilters(object $rule, ?int $groupId, ?int $serverId): bool
    {
        $f = $rule->filterData;
        if (!empty($f['server_ids']) && $serverId !== null
            && !in_array($serverId, array_map('intval', $f['server_ids']), true)) {
            return false;
        }
        if ($groupId !== null && (!empty($f['services']) || !empty($f['min_level']))) {
            $g = $this->row('SELECT service, level FROM error_groups WHERE id = ?', [$groupId]);
            if ($g === null) {
                return false;
            }
            if (!empty($f['services']) && !in_array($g->service, $f['services'], true)) {
                return false;
            }
            if (!empty($f['min_level'])) {
                $levels = ['debug', 'info', 'notice', 'warning', 'error', 'critical'];
                if (array_search($g->level, $levels, true) < array_search($f['min_level'], $levels, true)) {
                    return false;
                }
            }
        }
        return true;
    }

    // ---- rate limiting & history ---------------------------------------

    public function lastSentAt(int $ruleId, ?int $groupId): ?int
    {
        $v = $this->scalar(
            "SELECT extract(epoch FROM max(sent_at)) FROM notifications_log
             WHERE rule_id = ? AND error_group_id IS NOT DISTINCT FROM ? AND status = 'sent'",
            [$ruleId, $groupId]);
        return $v === null || $v === false ? null : (int) $v;
    }

    public function countSentLastHour(int $channelId): int
    {
        return (int) $this->scalar(
            "SELECT count(*) FROM notifications_log
             WHERE channel_id = ? AND status = 'sent' AND sent_at > now() - interval '1 hour'",
            [$channelId]);
    }

    public function recentlySentPayload(int $channelId, string $payloadHash, int $seconds): bool
    {
        return (bool) $this->scalar(
            "SELECT exists(SELECT 1 FROM notifications_log
             WHERE channel_id = ? AND payload_hash = ? AND status = 'sent'
               AND sent_at > now() - make_interval(secs => ?))",
            [$channelId, $payloadHash, $seconds]);
    }

    public function logSuppressed(object $rule, ?int $groupId, ?int $serverId, string $reason): void
    {
        $this->exec(
            "INSERT INTO notifications_log (rule_id, channel_id, error_group_id, server_id, status, reason)
             VALUES (?, ?, ?, ?, 'suppressed', ?)",
            [$rule->id, $rule->channelId, $groupId, $serverId, $reason]);
    }

    public function logDelivery(object $rule, ?int $groupId, ?int $serverId,
        DeliveryResult $result, string $payloadHash): void
    {
        $this->exec(
            'INSERT INTO notifications_log (rule_id, channel_id, error_group_id, server_id, status, reason, payload_hash)
             VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$rule->id, $rule->channelId, $groupId, $serverId,
             $result->ok ? 'sent' : 'failed', $result->reason ?: null, $payloadHash]);
    }

    /** @return list<object> dispatch history for the UI */
    public function history(int $limit = 100): array
    {
        return $this->rows(
            'SELECT n.sent_at, n.status, n.reason, c.name AS channel, r.trigger,
                    g.title AS error_title, s.name AS server_name
             FROM notifications_log n
             LEFT JOIN notification_channels c ON c.id = n.channel_id
             LEFT JOIN notification_rules r ON r.id = n.rule_id
             LEFT JOIN error_groups g ON g.id = n.error_group_id
             LEFT JOIN servers s ON s.id = n.server_id
             ORDER BY n.sent_at DESC LIMIT ?', [$limit]);
    }

    // ---- payload construction -------------------------------------------

    private const EMOJI = [1 => 'ℹ️', 2 => '🔵', 3 => '⚠️', 4 => '🟠', 5 => '🔴'];

    /** @param array<string,string> $extra additional body lines (digest text, anomaly stats…) */
    public function buildNotification(string $trigger, object $rule,
        ?int $groupId, ?int $serverId, array $extra = []): Notification
    {
        $appUrl = rtrim(Config::env('APP_URL', 'http://localhost:8080') ?? '', '/');

        if ($groupId !== null) {
            $g = $this->row(
                'SELECT g.*, a.summary, a.severity AS ai_severity, cardinality(g.server_ids) AS server_count
                 FROM error_groups g LEFT JOIN ai_analyses a ON a.fingerprint = g.fingerprint
                 WHERE g.id = ?', [$groupId]);
            if ($g !== null) {
                $severity = (int) ($g->ai_severity ?? ($g->level === 'critical' ? 5 : 3));
                $title = self::EMOJI[$severity] . ' ' . ($g->summary ?: mb_substr($g->title, 0, 200));
                $lines = [
                    'Trigger: ' . str_replace('_', ' ', $trigger),
                    "Service: {$g->service} · Level: {$g->level}",
                    "Occurrences: {$g->occurrence_count} on {$g->server_count} server(s)",
                    'Last seen: ' . $g->last_seen,
                ];
                if ($g->summary === null) {
                    $lines[] = 'AI analysis pending — see panel for updates.';
                }
                foreach ($extra as $k => $v) {
                    $lines[] = "$k: $v";
                }
                return new Notification($title, implode("\n", $lines), $severity, "$appUrl/errors/{$g->id}");
            }
        }

        if ($serverId !== null) {
            $s = $this->row('SELECT name, public_id, last_seen_at, status FROM servers WHERE id = ?', [$serverId]);
            if ($s !== null) {
                [$emoji, $sev, $what] = match ($trigger) {
                    'server_offline' => ['🔌', 4, 'is OFFLINE'],
                    'server_recovered' => ['✅', 2, 'is back online'],
                    'auth_attack' => ['🛡️', 5, 'is under authentication attack'],
                    'anomaly' => ['📈', 3, 'shows an unusual error rate'],
                    default => ['ℹ️', 2, 'event'],
                };
                $lines = ["Server: {$s->name}", 'Last seen: ' . ($s->last_seen_at ?? 'never')];
                foreach ($extra as $k => $v) {
                    $lines[] = "$k: $v";
                }
                return new Notification("$emoji {$s->name} $what",
                    implode("\n", $lines), $sev, "$appUrl/?server={$s->public_id}");
            }
        }

        // Trigger without group/server context (e.g. weekly digest).
        $title = $extra['_title'] ?? ('Logwatch2: ' . str_replace('_', ' ', $trigger));
        unset($extra['_title']);
        return new Notification($title, implode("\n", $extra) ?: 'See panel.', 2, $appUrl);
    }
}
