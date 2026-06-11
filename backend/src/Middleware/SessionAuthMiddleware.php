<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Repository\UserRepository;
use App\Support\Config;
use App\Support\Json;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Response;

/**
 * Panel session auth. API routes get a 401 JSON envelope, web routes a
 * redirect to /login. The fresh user row is attached per request so role
 * changes and deactivation take effect immediately, not at next login.
 */
final class SessionAuthMiddleware implements MiddlewareInterface
{
    public function __construct(private readonly UserRepository $users)
    {
    }

    public static function startSession(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        session_set_cookie_params([
            'lifetime' => Config::envInt('SESSION_LIFETIME', 43200),
            'path' => '/',
            'httponly' => true,
            'secure' => str_starts_with(Config::env('APP_URL', '') ?? '', 'https://'),
            'samesite' => 'Lax',
        ]);
        session_name('lw2_session');
        session_start();
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        self::startSession();

        $uid = $_SESSION['uid'] ?? null;
        $user = is_int($uid) ? $this->users->findById($uid) : null;

        if ($user === null || !$user->is_active) {
            if (str_starts_with($request->getUri()->getPath(), '/api/')) {
                return Json::error(new Response(), 401, 'unauthorized', 'login required');
            }
            return (new Response())->withHeader('Location', '/login')->withStatus(302);
        }

        return $handler->handle($request->withAttribute('user', $user));
    }
}
