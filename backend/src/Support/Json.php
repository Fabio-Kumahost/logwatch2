<?php

declare(strict_types=1);

namespace App\Support;

use Psr\Http\Message\ResponseInterface as Response;

/** Uniform JSON response envelope: {data:…} / {error:{code,message,details}}. */
final class Json
{
    public static function data(Response $response, mixed $data, int $status = 200, array $meta = []): Response
    {
        $body = ['data' => $data];
        if ($meta !== []) {
            $body['meta'] = $meta;
        }
        return self::write($response, $body, $status);
    }

    public static function error(Response $response, int $status, string $code,
        string $message, array $details = []): Response
    {
        $error = ['code' => $code, 'message' => $message];
        if ($details !== []) {
            $error['details'] = $details;
        }
        return self::write($response, ['error' => $error], $status);
    }

    private static function write(Response $response, array $body, int $status): Response
    {
        $response->getBody()->write(json_encode($body, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE));
        return $response->withHeader('Content-Type', 'application/json')->withStatus($status);
    }
}
