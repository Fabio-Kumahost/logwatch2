<?php

declare(strict_types=1);

use App\Support\Config;
use DI\ContainerBuilder;
use Slim\Factory\AppFactory;

require __DIR__ . '/../vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(dirname(__DIR__, 2));
$dotenv->safeLoad();

$builder = new ContainerBuilder();
$builder->addDefinitions(__DIR__ . '/../config/container.php');
$container = $builder->build();

$app = AppFactory::createFromContainer($container);

$app->addBodyParsingMiddleware();
$app->addRoutingMiddleware();

// Security headers on every response.
$app->add(function ($request, $handler) {
    $response = $handler->handle($request);
    return $response
        ->withHeader('Content-Security-Policy', "default-src 'self'")
        ->withHeader('X-Content-Type-Options', 'nosniff')
        ->withHeader('X-Frame-Options', 'DENY')
        ->withHeader('Referrer-Policy', 'no-referrer');
});

$errorMiddleware = $app->addErrorMiddleware(
    displayErrorDetails: Config::env('APP_ENV', 'production') === 'development',
    logErrors: true,
    logErrorDetails: true,
);
$errorMiddleware->setDefaultErrorHandler(new App\Support\JsonErrorHandler(
    $app->getCallableResolver(),
    $app->getResponseFactory(),
    $container->get(Psr\Log\LoggerInterface::class),
));

(require __DIR__ . '/../src/routes.php')($app);

$app->run();
