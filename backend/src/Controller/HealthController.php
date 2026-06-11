<?php

declare(strict_types=1);

namespace App\Controller;

use App\Support\Json;
use PDO;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/** Used by compose healthchecks, the installer's wait loop, and uptime probes. */
final class HealthController
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function __invoke(Request $request, Response $response): Response
    {
        try {
            $this->pdo->query('SELECT 1');
            return Json::data($response, ['status' => 'ok']);
        } catch (\Throwable) {
            return Json::error($response, 503, 'unavailable', 'database not reachable');
        }
    }
}
