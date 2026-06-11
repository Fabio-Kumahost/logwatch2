<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\AuditRepository;
use App\Repository\ServerRepository;
use App\Support\Json;
use App\Support\Validator;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class ServerController
{
    public function __construct(
        private readonly ServerRepository $servers,
        private readonly AuditRepository $audit,
    ) {
    }

    public function index(Request $request, Response $response): Response
    {
        return Json::data($response, $this->servers->listWithCounts());
    }

    public function show(Request $request, Response $response, array $args): Response
    {
        $uuid = Validator::uuid($args['uuid'] ?? null);
        $server = $uuid === null ? null : $this->servers->findByUuid($uuid);
        if ($server === null) {
            return Json::error($response, 404, 'not_found', 'unknown server');
        }
        unset($server->token_hash, $server->id);
        return Json::data($response, $server);
    }

    public function create(Request $request, Response $response): Response
    {
        $body = (array) $request->getParsedBody();
        $name = Validator::str($body['name'] ?? '', 128);
        if (!preg_match('/^[\w.-]{2,128}$/u', $name)) {
            return Json::error($response, 422, 'validation_failed',
                'name: 2-128 chars, letters/digits/._- only');
        }
        $tags = array_values(array_filter((array) ($body['tags'] ?? []), 'is_string'));

        try {
            $created = $this->servers->create($name, $tags);
        } catch (\PDOException) {
            return Json::error($response, 409, 'conflict', 'a server with this name exists');
        }
        $this->audit->log($request, 'server.create', ['name' => $name]);

        return Json::data($response, [
            'server' => $created['server'],
            'token' => $created['token'],
            'warning' => 'this token is shown exactly once — store it now',
        ], 201);
    }

    public function update(Request $request, Response $response, array $args): Response
    {
        $server = $this->resolve($args);
        if ($server === null) {
            return Json::error($response, 404, 'not_found', 'unknown server');
        }
        $body = (array) $request->getParsedBody();
        $name = Validator::str($body['name'] ?? $server->name, 128);
        $tags = isset($body['tags'])
            ? array_values(array_filter((array) $body['tags'], 'is_string')) : null;
        $this->servers->rename((int) $server->id, $name, $tags);
        $this->audit->log($request, 'server.update', ['id' => $server->public_id]);
        return Json::data($response, ['updated' => true]);
    }

    public function delete(Request $request, Response $response, array $args): Response
    {
        $server = $this->resolve($args);
        if ($server === null) {
            return Json::error($response, 404, 'not_found', 'unknown server');
        }
        $this->servers->delete((int) $server->id);
        $this->audit->log($request, 'server.delete', ['name' => $server->name]);
        return Json::data($response, ['deleted' => true, 'note' => 'all log data cascaded']);
    }

    public function rotateToken(Request $request, Response $response, array $args): Response
    {
        $server = $this->resolve($args);
        if ($server === null) {
            return Json::error($response, 404, 'not_found', 'unknown server');
        }
        $token = $this->servers->rotateToken((int) $server->id);
        $this->audit->log($request, 'server.token_rotate', ['name' => $server->name]);
        return Json::data($response, [
            'token' => $token,
            'warning' => 'old token is invalid immediately — update the agent config now',
        ]);
    }

    private function resolve(array $args): ?object
    {
        $uuid = Validator::uuid($args['uuid'] ?? null);
        return $uuid === null ? null : $this->servers->findByUuid($uuid);
    }
}
