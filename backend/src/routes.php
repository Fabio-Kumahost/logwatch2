<?php

declare(strict_types=1);

use App\Controller;
use App\Middleware\AgentAuthMiddleware;
use App\Middleware\CsrfMiddleware;
use App\Middleware\RateLimitMiddleware;
use App\Middleware\RbacMiddleware;
use App\Middleware\SessionAuthMiddleware;
use Slim\App;
use Slim\Routing\RouteCollectorProxy;

return static function (App $app): void {
    $container = $app->getContainer();
    if ($container === null) {
        throw new RuntimeException('app must be created from a container');
    }
    $pdo = static fn (): \PDO => $container->get(\PDO::class);

    // Liveness/readiness for compose healthchecks and the installer.
    $app->get('/healthz', Controller\HealthController::class);

    // Prometheus metrics (enabled by setting METRICS_TOKEN, see docs/api.md).
    $app->get('/metrics', [Controller\StatsController::class, 'metrics']);

    // ---- Agent API: per-server bearer token ----
    $app->group('/api/v1', function (RouteCollectorProxy $g) {
        $g->post('/ingest/logs', [Controller\IngestController::class, 'ingest']);
        $g->post('/agent/heartbeat', [Controller\IngestController::class, 'heartbeat']);
    })->add(AgentAuthMiddleware::class)
      ->add(RateLimitMiddleware::forAgents($pdo()));

    // ---- Panel API: session + CSRF ----
    $app->post('/api/v1/auth/login', [Controller\AuthController::class, 'login'])
        ->add(RateLimitMiddleware::forLogin($pdo()));

    $app->group('/api/v1', function (RouteCollectorProxy $g) {
        $g->post('/auth/logout', [Controller\AuthController::class, 'logout']);
        $g->get('/auth/me', [Controller\AuthController::class, 'me']);
        $g->post('/auth/totp/setup', [Controller\AuthController::class, 'totpSetup']);
        $g->post('/auth/totp/confirm', [Controller\AuthController::class, 'totpConfirm']);
        $g->post('/auth/totp/disable', [Controller\AuthController::class, 'totpDisable']);

        $g->get('/servers', [Controller\ServerController::class, 'index']);
        $g->get('/servers/{uuid}', [Controller\ServerController::class, 'show']);
        $g->get('/logs', [Controller\LogController::class, 'index']);
        $g->get('/errors', [Controller\ErrorGroupController::class, 'index']);
        $g->get('/errors/{id:[0-9]+}', [Controller\ErrorGroupController::class, 'show']);
        $g->patch('/errors/{id:[0-9]+}', [Controller\ErrorGroupController::class, 'updateStatus']);
        $g->post('/errors/{id:[0-9]+}/analyze', [Controller\ErrorGroupController::class, 'reanalyze']);
        $g->get('/notify/log', [Controller\NotificationController::class, 'history']);
        $g->get('/stats/dashboard', [Controller\StatsController::class, 'dashboard']);

        // Admin-only group.
        $g->group('', function (RouteCollectorProxy $a) {
            $a->post('/servers', [Controller\ServerController::class, 'create']);
            $a->patch('/servers/{uuid}', [Controller\ServerController::class, 'update']);
            $a->delete('/servers/{uuid}', [Controller\ServerController::class, 'delete']);
            $a->post('/servers/{uuid}/token/rotate', [Controller\ServerController::class, 'rotateToken']);
            $a->map(['GET', 'POST'], '/notify/channels', [Controller\NotificationController::class, 'channels']);
            $a->map(['PATCH', 'DELETE'], '/notify/channels/{id:[0-9]+}', [Controller\NotificationController::class, 'channel']);
            $a->post('/notify/channels/{id:[0-9]+}/test', [Controller\NotificationController::class, 'test']);
            $a->map(['GET', 'POST'], '/notify/rules', [Controller\NotificationController::class, 'rules']);
            $a->map(['PATCH', 'DELETE'], '/notify/rules/{id:[0-9]+}', [Controller\NotificationController::class, 'rule']);
            $a->map(['GET', 'PUT'], '/settings/ai', [Controller\SettingsController::class, 'ai']);
            $a->post('/settings/mask-preview', [Controller\SettingsController::class, 'maskPreview']);
            $a->map(['GET', 'POST'], '/users', [Controller\UserController::class, 'collection']);
            $a->map(['PATCH', 'DELETE'], '/users/{id:[0-9]+}', [Controller\UserController::class, 'item']);
        })->add(RbacMiddleware::requireRole('admin'));
    })->add(CsrfMiddleware::class)
      ->add(SessionAuthMiddleware::class);

    // ---- Web UI (server-rendered Twig; same session auth) ----
    $app->get('/login', [Controller\WebController::class, 'loginPage']);
    $app->group('', function (RouteCollectorProxy $g) {
        $g->get('/', [Controller\WebController::class, 'dashboard']);
        $g->get('/logs', [Controller\WebController::class, 'logs']);
        $g->get('/errors/{id:[0-9]+}', [Controller\WebController::class, 'errorDetail']);
        $g->get('/settings', [Controller\WebController::class, 'settings']);
    })->add(SessionAuthMiddleware::class);
};
