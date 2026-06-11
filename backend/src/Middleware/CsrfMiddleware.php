<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Support\Json;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Response;

/**
 * Double-submit protection for the session-authenticated API: mutating
 * requests must carry the session's token in X-CSRF-Token. The SPA-ish UI
 * fetches it from GET /api/v1/auth/me. SameSite=Lax is the first layer;
 * this is the second.
 */
final class CsrfMiddleware implements MiddlewareInterface
{
    private const SAFE_METHODS = ['GET', 'HEAD', 'OPTIONS'];

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        SessionAuthMiddleware::startSession();
        if (!isset($_SESSION['csrf'])) {
            $_SESSION['csrf'] = bin2hex(random_bytes(32));
        }

        if (!in_array($request->getMethod(), self::SAFE_METHODS, true)) {
            $sent = $request->getHeaderLine('X-CSRF-Token');
            if ($sent === '' || !hash_equals($_SESSION['csrf'], $sent)) {
                return Json::error(new Response(), 403, 'forbidden', 'missing or invalid CSRF token');
            }
        }

        return $handler->handle($request->withAttribute('csrf', $_SESSION['csrf']));
    }
}
