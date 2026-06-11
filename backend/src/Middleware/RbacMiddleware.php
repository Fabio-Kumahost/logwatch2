<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Support\Json;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Response;

/** Deny-by-default role gate; relies on SessionAuthMiddleware's 'user' attribute. */
final class RbacMiddleware implements MiddlewareInterface
{
    private function __construct(private readonly string $role)
    {
    }

    public static function requireRole(string $role): self
    {
        return new self($role);
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $user = $request->getAttribute('user');
        if ($user === null || $user->role !== $this->role) {
            return Json::error(new Response(), 403, 'forbidden', 'requires role: ' . $this->role);
        }
        return $handler->handle($request);
    }
}
