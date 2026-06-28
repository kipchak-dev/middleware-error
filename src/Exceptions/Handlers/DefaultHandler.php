<?php

namespace Kipchak\Middleware\Error\Exceptions\Handlers;

use Psr\Http\Message\ResponseInterface;
use Slim\Exception\HttpBadRequestException;
use Slim\Exception\HttpException;
use Slim\Exception\HttpForbiddenException;
use Slim\Exception\HttpMethodNotAllowedException;
use Slim\Exception\HttpNotFoundException;
use Slim\Exception\HttpNotImplementedException;
use Slim\Exception\HttpUnauthorizedException;
use Slim\Handlers\ErrorHandler;

class DefaultHandler extends ErrorHandler
{
    public const BAD_REQUEST = 'BAD_REQUEST';
    public const INSUFFICIENT_PRIVILEGES = 'INSUFFICIENT_PRIVILEGES';
    public const NOT_ALLOWED = 'NOT_ALLOWED';
    public const NOT_IMPLEMENTED = 'NOT_IMPLEMENTED';
    public const RESOURCE_NOT_FOUND = 'RESOURCE_NOT_FOUND';
    public const SERVER_ERROR = 'SERVER_ERROR';
    public const UNAUTHENTICATED = 'UNAUTHENTICATED';

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
            if ($exception instanceof HttpNotFoundException) {
                $type = self::RESOURCE_NOT_FOUND;
            } elseif ($exception instanceof HttpMethodNotAllowedException) {
                $type = self::NOT_ALLOWED;
            } elseif ($exception instanceof HttpUnauthorizedException) {
                $type = self::UNAUTHENTICATED;
            } elseif ($exception instanceof HttpForbiddenException) {
                $type = self::INSUFFICIENT_PRIVILEGES;
            } elseif ($exception instanceof HttpBadRequestException) {
                $type = self::BAD_REQUEST;
            } elseif ($exception instanceof HttpNotImplementedException) {
                $type = self::NOT_IMPLEMENTED;
            }

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