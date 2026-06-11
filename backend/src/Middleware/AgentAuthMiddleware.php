<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Repository\ServerRepository;
use App\Support\Json;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Response;

/**
 * Authenticates agents via "Authorization: Bearer lw2_…".
 * Tokens are random 256-bit values; only sha256(token) is stored, so the
 * lookup is by hash — constant-time comparison is inherent to the index hit,
 * and we avoid keeping plaintext anywhere.
 */
final class AgentAuthMiddleware implements MiddlewareInterface
{
    public function __construct(private readonly ServerRepository $servers)
    {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $header = $request->getHeaderLine('Authorization');
        if (!str_starts_with($header, 'Bearer ')) {
            return Json::error(new Response(), 401, 'unauthorized', 'missing bearer token');
        }

        $token = substr($header, 7);
        if (!preg_match('/^lw2_[A-Za-z0-9_-]{43}$/', $token)) {
            return Json::error(new Response(), 401, 'unauthorized', 'malformed token');
        }

        $server = $this->servers->findByTokenHash(hash('sha256', $token));
        if ($server === null) {
            return Json::error(new Response(), 401, 'unauthorized', 'unknown token');
        }

        return $handler->handle($request->withAttribute('server', $server));
    }
}
