<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Support\Json;
use PDO;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Response;

/**
 * Fixed-window limiter backed by the rate_limits table — works across all
 * php-fpm workers and survives restarts. nginx provides the first layer;
 * this one is authoritative and returns Retry-After (agents honor it).
 */
final class RateLimitMiddleware implements MiddlewareInterface
{
    private function __construct(
        private readonly PDO $pdo,
        private readonly string $realm,
        private readonly int $limit,
        private readonly int $windowSeconds,
        private readonly \Closure $keyFn,
    ) {
    }

    /** Per agent token: 60 requests / 10s (batching keeps real agents far below). */
    public static function forAgents(PDO $pdo): self
    {
        return new self($pdo, 'agent', 60, 10,
            static fn (ServerRequestInterface $r): string =>
                substr(hash('sha256', $r->getHeaderLine('Authorization')), 0, 32));
    }

    /** Per client IP: 10 login attempts / 60s (app lockout is on top of this). */
    public static function forLogin(PDO $pdo): self
    {
        return new self($pdo, 'login', 10, 60,
            static fn (ServerRequestInterface $r): string =>
                (string) (($r->getServerParams()['REMOTE_ADDR'] ?? 'unknown')));
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $window = intdiv(time(), $this->windowSeconds);
        $key = sprintf('%s:%s:%d', $this->realm, ($this->keyFn)($request), $window);

        $stmt = $this->pdo->prepare(
            'INSERT INTO rate_limits (key, count) VALUES (?, 1)
             ON CONFLICT (key) DO UPDATE SET count = rate_limits.count + 1
             RETURNING count');
        $stmt->execute([$key]);
        $count = (int) $stmt->fetchColumn();

        if (random_int(1, 100) === 1) { // opportunistic cleanup of expired windows
            $this->pdo->prepare("DELETE FROM rate_limits WHERE created_at < now() - interval '1 hour'")
                ->execute();
        }

        if ($count > $this->limit) {
            $retryAfter = $this->windowSeconds - (time() % $this->windowSeconds);
            return Json::error(new Response(), 429, 'rate_limited', 'too many requests')
                ->withHeader('Retry-After', (string) $retryAfter);
        }

        return $handler->handle($request);
    }
}
