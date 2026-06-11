<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\AnalysisRepository;
use App\Repository\ErrorGroupRepository;
use App\Service\AI\ProviderFactory;
use App\Service\Queue\Queue;
use App\Support\Config;
use App\Support\Json;
use App\Support\Validator;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class ErrorGroupController
{
    public function __construct(
        private readonly ErrorGroupRepository $groups,
        private readonly AnalysisRepository $analyses,
        private readonly ProviderFactory $providers,
        private readonly Queue $queue,
    ) {
    }

    public function index(Request $request, Response $response): Response
    {
        $q = $request->getQueryParams();
        $page = Validator::int($q['page'] ?? 1, 1, 100000, 1);
        $perPage = Validator::int($q['per_page'] ?? 50, 1, 200, 50);
        $filters = [
            'status' => Validator::enum($q['status'] ?? '', ['open', 'acknowledged', 'resolved', 'ignored'], ''),
            'level' => Validator::enum($q['level'] ?? '', ['warning', 'error', 'critical'], ''),
            'server' => Validator::uuid($q['server'] ?? null),
        ];
        $result = $this->groups->search($filters, $page, $perPage);
        return Json::data($response, $result['items'], 200,
            ['page' => $page, 'per_page' => $perPage, 'total' => $result['total']]);
    }

    public function show(Request $request, Response $response, array $args): Response
    {
        $detail = $this->groups->detail((int) $args['id']);
        if ($detail === null) {
            return Json::error($response, 404, 'not_found', 'unknown error group');
        }
        unset($detail['group']->server_ids);
        return Json::data($response, $detail);
    }

    public function updateStatus(Request $request, Response $response, array $args): Response
    {
        $status = Validator::enum(
            ((array) $request->getParsedBody())['status'] ?? '',
            ['open', 'acknowledged', 'resolved', 'ignored'], '');
        if ($status === '') {
            return Json::error($response, 422, 'validation_failed',
                'status must be open|acknowledged|resolved|ignored');
        }
        if (!$this->groups->updateStatus((int) $args['id'], $status)) {
            return Json::error($response, 404, 'not_found', 'unknown error group');
        }
        return Json::data($response, ['status' => $status]);
    }

    public function reanalyze(Request $request, Response $response, array $args): Response
    {
        $group = $this->groups->findById((int) $args['id']);
        if ($group === null) {
            return Json::error($response, 404, 'not_found', 'unknown error group');
        }
        if (!$this->providers->enabled()) {
            return Json::error($response, 409, 'conflict', 'AI analysis is disabled — configure it in settings');
        }
        if ($this->analyses->countToday() >= Config::envInt('AI_DAILY_BUDGET_REQUESTS', 500)) {
            return Json::error($response, 429, 'rate_limited', 'AI daily budget exhausted — resets at midnight UTC');
        }
        $this->queue->push('ai.analyze', ['fingerprint' => $group->fingerprint, 'force' => true]);
        return Json::data($response, ['queued' => true], 202);
    }
}
