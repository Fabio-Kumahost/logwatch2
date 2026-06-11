<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\AuditRepository;
use App\Repository\NotificationRepository;
use App\Service\Notify\Notification;
use App\Service\Notify\Notifier;
use App\Support\Json;
use App\Support\Validator;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class NotificationController
{
    public const TRIGGERS = [
        'critical_error', 'server_offline', 'new_error', 'recurring_error',
        'auth_attack', 'anomaly', 'digest', 'server_recovered',
    ];

    public function __construct(
        private readonly NotificationRepository $repo,
        private readonly Notifier $notifier,
        private readonly AuditRepository $audit,
    ) {
    }

    public function channels(Request $request, Response $response): Response
    {
        if ($request->getMethod() === 'GET') {
            return Json::data($response, $this->repo->listChannels());
        }
        $b = (array) $request->getParsedBody();
        $type = Validator::enum($b['type'] ?? '', ['discord', 'gotify'], '');
        $name = Validator::str($b['name'] ?? '', 128);
        $config = $this->validateChannelConfig($type, (array) ($b['config'] ?? []));
        if ($type === '' || $name === '' || $config === null) {
            return Json::error($response, 422, 'validation_failed',
                'need type (discord|gotify), name, and a valid config for that type');
        }
        $channel = $this->repo->createChannel($type, $name, $config);
        $this->audit->log($request, 'notify.channel_create', ['type' => $type, 'name' => $name]);
        return Json::data($response, $channel, 201);
    }

    public function channel(Request $request, Response $response, array $args): Response
    {
        $id = (int) $args['id'];
        if ($this->repo->channel($id) === null) {
            return Json::error($response, 404, 'not_found', 'unknown channel');
        }
        if ($request->getMethod() === 'DELETE') {
            $this->repo->deleteChannel($id);
            $this->audit->log($request, 'notify.channel_delete', ['id' => $id]);
            return Json::data($response, ['deleted' => true]);
        }
        $b = (array) $request->getParsedBody();
        $config = null;
        if (isset($b['config'])) {
            $existing = $this->repo->channel($id);
            $config = $this->validateChannelConfig($existing->type, (array) $b['config']);
            if ($config === null) {
                return Json::error($response, 422, 'validation_failed', 'invalid config for channel type');
            }
        }
        $this->repo->updateChannel($id,
            isset($b['name']) ? Validator::str($b['name'], 128) : null,
            isset($b['is_active']) ? (bool) $b['is_active'] : null,
            $config);
        return Json::data($response, ['updated' => true]);
    }

    public function test(Request $request, Response $response, array $args): Response
    {
        $channel = $this->repo->channel((int) $args['id']);
        if ($channel === null) {
            return Json::error($response, 404, 'not_found', 'unknown channel');
        }
        $result = $this->notifier->sendTest($channel, new Notification(
            '🧪 Logwatch2 test notification',
            "If you can read this, the channel works.\nSent by: " .
                $request->getAttribute('user')->username, 2, ''));
        return $result->ok
            ? Json::data($response, ['sent' => true])
            : Json::error($response, 502, 'delivery_failed', $result->reason);
    }

    public function rules(Request $request, Response $response): Response
    {
        if ($request->getMethod() === 'GET') {
            return Json::data($response, $this->repo->listRules());
        }
        $b = (array) $request->getParsedBody();
        $trigger = Validator::enum($b['trigger'] ?? '', self::TRIGGERS, '');
        $channelId = Validator::int($b['channel_id'] ?? 0, 1, PHP_INT_MAX, 0);
        if ($trigger === '' || $channelId === 0 || $this->repo->channel($channelId) === null) {
            return Json::error($response, 422, 'validation_failed', 'need valid trigger and channel_id');
        }
        $rule = $this->repo->createRule($channelId, $trigger,
            (array) ($b['filters'] ?? []),
            Validator::int($b['cooldown_seconds'] ?? 900, 0, 86400, 900));
        $this->audit->log($request, 'notify.rule_create', ['trigger' => $trigger]);
        return Json::data($response, $rule, 201);
    }

    public function rule(Request $request, Response $response, array $args): Response
    {
        $id = (int) $args['id'];
        if ($request->getMethod() === 'DELETE') {
            $this->repo->deleteRule($id);
            return Json::data($response, ['deleted' => true]);
        }
        $this->repo->updateRule($id, (array) $request->getParsedBody());
        return Json::data($response, ['updated' => true]);
    }

    public function history(Request $request, Response $response): Response
    {
        return Json::data($response, $this->repo->history());
    }

    private function validateChannelConfig(string $type, array $config): ?array
    {
        if ($type === 'discord') {
            $url = Validator::str($config['webhook_url'] ?? '', 512);
            return preg_match('#^https://(discord\.com|discordapp\.com)/api/webhooks/#', $url)
                ? ['webhook_url' => $url] : null;
        }
        if ($type === 'gotify') {
            $url = Validator::str($config['server_url'] ?? '', 512);
            $token = Validator::str($config['app_token'] ?? '', 128);
            return (str_starts_with($url, 'https://') || str_starts_with($url, 'http://')) && $token !== ''
                ? ['server_url' => rtrim($url, '/'), 'app_token' => $token] : null;
        }
        return null;
    }
}
