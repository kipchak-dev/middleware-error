<?php

namespace Kipchak\Middleware\Error\Exceptions\Handlers;

use Kipchak\Core\Components\Http\ErrorStatus;
use Psr\Http\Message\ResponseInterface;
use Slim\Exception\HttpException;
use Slim\Handlers\ErrorHandler;

/**
 * Writes every error as {"code", "status", "data"}, with the status from core's ErrorStatus: the same names every
 * Kipchak middleware uses when it refuses a request itself. The constants are kept for code that refers to them.
 */
class DefaultHandler extends ErrorHandler
{
    public const BAD_REQUEST = ErrorStatus::BAD_REQUEST;
    public const INSUFFICIENT_PRIVILEGES = ErrorStatus::INSUFFICIENT_PRIVILEGES;
    public const NOT_ALLOWED = ErrorStatus::NOT_ALLOWED;
    public const NOT_IMPLEMENTED = ErrorStatus::NOT_IMPLEMENTED;
    public const RESOURCE_NOT_FOUND = ErrorStatus::RESOURCE_NOT_FOUND;
    public const SERVER_ERROR = ErrorStatus::SERVER_ERROR;
    public const TOO_MANY_REQUESTS = ErrorStatus::TOO_MANY_REQUESTS;
    public const UNAUTHENTICATED = ErrorStatus::UNAUTHENTICATED;

    protected function respond(): ResponseInterface
    {
        $exception = $this->exception;
        $statusCode = 500;
        $type = self::SERVER_ERROR;
        // Default to a generic description. Only Slim HttpException subclasses
        // — which carry intentional, user-facing error text (e.g. "Resource not
        // found", route validation messages) — get their message passed through.
        // Anything else (RuntimeException, DBAL/Stripe/Brevo SDK errors, etc.)
        // can leak credentials or internal detail via getMessage(), so we mask.
        $description = 'An unexpected error occurred.';

        if ($exception instanceof HttpException) {
            // Slim HttpExceptions set their code to the HTTP status code
            // (404, 403, 400, ...). Only trust it if it looks sane.
            if ($exception->getCode() >= 400) {
                $statusCode = $exception->getCode();
            }
            // Each Slim HTTP exception carries its status as its code (HttpUnauthorizedException 401, a plain
            // HttpException thrown with 429 by rate-limiting code, ...). A 4xx without a Kipchak name keeps its
            // HTTP name, so a 409 is CONFLICT rather than SERVER_ERROR.
            $type = ErrorStatus::for($statusCode);

            $description = $exception->getMessage();
        }

        $errorDisplay = [
            'code' => $statusCode,
            'status' => $type,
            'data' => $description,
        ];

        $payloadDisplay = json_encode($errorDisplay, JSON_PRETTY_PRINT);
        $response = $this->responseFactory->createResponse($statusCode);

        $response->getBody()->write($payloadDisplay);

        return $response->withHeader('Content-Type', 'application/json');

    }
}