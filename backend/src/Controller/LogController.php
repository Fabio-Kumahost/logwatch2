<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\LogRepository;
use App\Service\Ingest\LevelClassifier;
use App\Support\Json;
use App\Support\Validator;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class LogController
{
    public function __construct(private readonly LogRepository $logs)
    {
    }

    public function index(Request $request, Response $response): Response
    {
        $q = $request->getQueryParams();
        $page = Validator::int($q['page'] ?? 1, 1, 100000, 1);
        $perPage = Validator::int($q['per_page'] ?? 50, 1, 200, 50);

        $filters = [
            'server' => Validator::uuid($q['server'] ?? null),
            'level' => Validator::enum($q['level'] ?? '', LevelClassifier::LEVELS, ''),
            'service' => Validator::str($q['service'] ?? '', 128),
            'from' => Validator::str($q['from'] ?? '', 32),
            'to' => Validator::str($q['to'] ?? '', 32),
            'q' => Validator::str($q['q'] ?? '', 200),
        ];

        $result = $this->logs->search($filters, $page, $perPage);
        return Json::data($response, $result['items'], 200,
            ['page' => $page, 'per_page' => $perPage, 'total' => $result['total']]);
    }
}
