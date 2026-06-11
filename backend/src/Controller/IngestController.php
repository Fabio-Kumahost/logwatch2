<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\LogRepository;
use App\Repository\ServerRepository;
use App\Service\Ingest\Fingerprinter;
use App\Service\Ingest\LevelClassifier;
use App\Service\Queue\Queue;
use App\Support\Json;
use App\Support\Validator;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Agent-facing endpoints. The authenticated server row is attached to the
 * request by AgentAuthMiddleware as attribute 'server'.
 */
final class IngestController
{
    private const MAX_MESSAGE_LEN = 16384;

    public function __construct(
        private readonly LogRepository $logs,
        private readonly ServerRepository $servers,
        private readonly LevelClassifier $classifier,
        private readonly Fingerprinter $fingerprinter,
        private readonly Queue $queue,
        private readonly int $maxBatch,
    ) {
    }

    public function ingest(Request $request, Response $response): Response
    {
        $server = $request->getAttribute('server');
        $body = (array) $request->getParsedBody();

        $entries = $body['entries'] ?? null;
        if (!is_array($entries) || $entries === [] || count($entries) > $this->maxBatch) {
            return Json::error($response, 422, 'validation_failed',
                sprintf('entries must contain 1..%d items', $this->maxBatch));
        }

        $accepted = 0;
        $rejected = [];
        foreach ($entries as $i => $entry) {
            $clean = $this->validateEntry($entry);
            if ($clean === null) {
                $rejected[] = ['index' => $i, 'reason' => 'invalid entry shape'];
                continue;
            }

            // Agent-supplied level is untrusted; classify from content,
            // keep the stricter of the two.
            $clean['level'] = LevelClassifier::stricter(
                $clean['level'],
                $this->classifier->classify($clean['message'], $clean['service']),
            );

            $groupId = null;
            if (LevelClassifier::atLeast($clean['level'], 'warning')) {
                $fp = $this->fingerprinter->fingerprint(
                    $clean['service'], $clean['source_file'], $clean['message']);
                $clean['fingerprint'] = $fp;
                [$groupId, $isNew] = $this->logs->upsertErrorGroup($fp, $server->id, $clean);
                if ($isNew) {
                    $this->queue->push('ai.analyze', ['fingerprint' => $fp]);
                    $this->queue->push('notify.dispatch', [
                        'trigger' => 'new_error', 'group_id' => $groupId, 'server_id' => $server->id,
                    ]);
                }
                if ($clean['level'] === 'critical') {
                    $this->queue->push('notify.dispatch', [
                        'trigger' => 'critical_error', 'group_id' => $groupId, 'server_id' => $server->id,
                    ]);
                }
            }

            $this->logs->insertEntry($server->id, $groupId, $clean);
            $accepted++;
        }

        $this->servers->touch($server->id, $body['agent_version'] ?? null);

        return Json::data($response, ['accepted' => $accepted, 'rejected' => $rejected], 202);
    }

    public function heartbeat(Request $request, Response $response): Response
    {
        $server = $request->getAttribute('server');
        $body = (array) $request->getParsedBody();

        $recovered = $this->servers->heartbeat(
            $server->id,
            Validator::str($body['agent_version'] ?? '', 32),
            Validator::str($body['hostname'] ?? '', 255),
        );
        if ($recovered) {
            $this->queue->push('notify.dispatch', [
                'trigger' => 'server_recovered', 'server_id' => $server->id,
            ]);
        }

        return Json::data($response, ['server_status' => 'online']);
    }

    /** @return array{ts:string,source_file:string,service:string,level:string,message:string,raw:string}|null */
    private function validateEntry(mixed $entry): ?array
    {
        if (!is_array($entry)) {
            return null;
        }
        $message = Validator::str($entry['message'] ?? '', self::MAX_MESSAGE_LEN);
        $source = Validator::str($entry['source_file'] ?? '', 512);
        if ($message === '' || $source === '') {
            return null;
        }
        $ts = Validator::timestamp($entry['ts'] ?? null); // rejects >24h future, falls back to now
        return [
            'ts' => $ts,
            'source_file' => $source,
            'service' => Validator::str($entry['service'] ?? 'unknown', 128) ?: 'unknown',
            'level' => Validator::enum($entry['level'] ?? 'info', LevelClassifier::LEVELS, 'info'),
            'message' => $message,
            'raw' => Validator::str($entry['raw'] ?? $message, self::MAX_MESSAGE_LEN * 2),
        ];
    }
}
