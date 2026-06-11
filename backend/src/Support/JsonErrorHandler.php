<?php

declare(strict_types=1);

namespace App\Support;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use Slim\Exception\HttpException;
use Slim\Handlers\ErrorHandler;
use Throwable;

/** All errors become the JSON envelope; internals never leak to clients. */
final class JsonErrorHandler extends ErrorHandler
{
    protected function respond(): ResponseInterface
    {
        $e = $this->exception;
        $response = $this->responseFactory->createResponse();

        if ($e instanceof HttpException) {
            $code = match ($e->getCode()) {
                401 => 'unauthorized', 403 => 'forbidden', 404 => 'not_found',
                405 => 'method_not_allowed', 429 => 'rate_limited', default => 'http_error',
            };
            return Json::error($response, $e->getCode(), $code, $e->getMessage());
        }

        $this->logger->error('unhandled exception', [
            'exception' => $e::class,
            'message' => $e->getMessage(),
            'file' => $e->getFile() . ':' . $e->getLine(),
        ]);

        $message = $this->displayErrorDetails
            ? $e->getMessage()
            : 'internal error — details are in the application log';
        return Json::error($response, 500, 'server_error', $message);
    }
}
