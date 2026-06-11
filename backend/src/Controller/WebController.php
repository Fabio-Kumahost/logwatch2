<?php

declare(strict_types=1);

namespace App\Controller;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Twig\Environment;

/**
 * Server-rendered pages. Twig templates only get static context; all data
 * is fetched client-side from the JSON API (single source of truth).
 */
final class WebController
{
    public function __construct(private readonly Environment $twig)
    {
    }

    public function loginPage(Request $request, Response $response): Response
    {
        return $this->render($response, 'login.twig');
    }

    public function dashboard(Request $request, Response $response): Response
    {
        return $this->render($response, 'dashboard.twig', $this->userCtx($request));
    }

    public function logs(Request $request, Response $response): Response
    {
        return $this->render($response, 'logs.twig', $this->userCtx($request));
    }

    public function errorDetail(Request $request, Response $response, array $args): Response
    {
        return $this->render($response, 'error_detail.twig',
            $this->userCtx($request) + ['group_id' => (int) $args['id']]);
    }

    public function settings(Request $request, Response $response): Response
    {
        return $this->render($response, 'settings.twig', $this->userCtx($request));
    }

    private function userCtx(Request $request): array
    {
        $user = $request->getAttribute('user');
        return [
            'username' => $user->username,
            'role' => $user->role,
            'csrf' => $_SESSION['csrf'] ?? '',
        ];
    }

    private function render(Response $response, string $template, array $ctx = []): Response
    {
        $response->getBody()->write($this->twig->render($template, $ctx));
        return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
    }
}
