<?php

declare(strict_types=1);

namespace Kipchak\Middleware\Error\Tests;

use DI\Container;
use Kipchak\Core\Components\App;
use Kipchak\Core\Components\Dto\InvalidInput;
use Kipchak\Driver\Config\Config;
use Kipchak\Driver\Logger\Logger;
use Kipchak\Middleware\Error\Error;
use Monolog\Handler\NullHandler;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Exception\HttpException;
use Slim\Exception\HttpNotFoundException;
use Slim\Exception\HttpTooManyRequestsException;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

/**
 * The status name in the error envelope for each HTTP exception a route throws.
 */
final class StatusTypeTest extends TestCase
{
    /**
     * @param callable(ServerRequestInterface): never $throw
     * @return array{int, array{code: int, status: string, data: string}}
     */
    private function respond(callable $throw): array
    {
        $container = new Container();
        Config::initialise($container);
        $container->set('drivers.config', ['kipchak.api' => ['name' => 'test', 'debug' => false, 'logExceptions' => false]]);
        Logger::initialise($container);
        Logger::get()->setHandlers([new NullHandler()]);

        $app = new App(new ResponseFactory(), $container);
        $app->addRoutingMiddleware();
        Error::initialise($app);
        $app->get('/thing', function (ServerRequestInterface $request) use ($throw) {
            $throw($request);
        });

        $response = $app->handle((new ServerRequestFactory())->createServerRequest('GET', 'https://api.example.com/thing'));

        return [$response->getStatusCode(), json_decode((string) $response->getBody(), true)];
    }

    public function testAnInvalidBodyCarriesItsErrorsByFieldBesideTheUnchangedData(): void
    {
        $errors = ['title' => ['Must be at least 1 character.'], 'notes.1.text' => ['This field is required.']];
        $message = 'The book is not valid: title: Must be at least 1 character. notes.1.text: This field is required.';

        [$status, $body] = $this->respond(static function (ServerRequestInterface $request) use ($message, $errors): never {
            throw new InvalidInput($request, $message, $errors);
        });

        self::assertSame(400, $status);
        self::assertSame(['code' => 400, 'status' => 'BAD_REQUEST', 'meta' => ['errors' => $errors], 'data' => $message], $body);
    }

    public function testControlInvalidInputWithNoFieldErrorsHasNoMeta(): void
    {
        [, $body] = $this->respond(static function (ServerRequestInterface $request): never {
            throw new InvalidInput($request, 'The body is not valid JSON.');
        });

        self::assertSame(['code' => 400, 'status' => 'BAD_REQUEST', 'data' => 'The body is not valid JSON.'], $body);
    }

    public function testControlAnotherErrorHasNoMeta(): void
    {
        [, $body] = $this->respond(static function (ServerRequestInterface $request): never {
            throw new HttpNotFoundException($request, 'No book has the ID 9.');
        });

        self::assertSame(['code' => 404, 'status' => 'RESOURCE_NOT_FOUND', 'data' => 'No book has the ID 9.'], $body);
    }

    public function testTooManyRequestsHasItsOwnStatus(): void
    {
        [$status, $body] = $this->respond(fn ($request) => throw new HttpTooManyRequestsException($request, 'Slow down.'));

        $this->assertSame(429, $status);
        $this->assertSame(['code' => 429, 'status' => 'TOO_MANY_REQUESTS', 'data' => 'Slow down.'], $body);
    }

    public function testAPlainHttpExceptionWith429IsTooManyRequestsToo(): void
    {
        [$status, $body] = $this->respond(fn ($request) => throw new HttpException($request, 'Quota exceeded.', 429));

        $this->assertSame(429, $status);
        $this->assertSame('TOO_MANY_REQUESTS', $body['status']);
        $this->assertSame('Quota exceeded.', $body['data']);
    }

    /**
     * @return array<string, array{0: callable, 1: int, 2: string}>
     */
    public static function namedExceptions(): array
    {
        return [
            'bad request' => [fn ($r) => new \Slim\Exception\HttpBadRequestException($r), 400, 'BAD_REQUEST'],
            'unauthorised' => [fn ($r) => new \Slim\Exception\HttpUnauthorizedException($r), 401, 'UNAUTHENTICATED'],
            'forbidden' => [fn ($r) => new \Slim\Exception\HttpForbiddenException($r), 403, 'INSUFFICIENT_PRIVILEGES'],
            'not found' => [fn ($r) => new HttpNotFoundException($r), 404, 'RESOURCE_NOT_FOUND'],
            'method not allowed' => [fn ($r) => new \Slim\Exception\HttpMethodNotAllowedException($r), 405, 'NOT_ALLOWED'],
            'not implemented' => [fn ($r) => new \Slim\Exception\HttpNotImplementedException($r), 501, 'NOT_IMPLEMENTED'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('namedExceptions')]
    public function testEachSlimExceptionKeepsItsName(callable $exception, int $code, string $status): void
    {
        [$actualCode, $body] = $this->respond(fn ($request) => throw $exception($request));

        $this->assertSame([$code, $status], [$actualCode, $body['status']]);
    }

    public function testAnotherClientErrorKeepsItsHttpName(): void
    {
        // Until 1.35 every 4xx without a Kipchak name was called SERVER_ERROR, as if the server had failed.
        [$status, $body] = $this->respond(fn ($request) => throw new HttpException($request, 'Already taken.', 409));
        $this->assertSame([409, 'CONFLICT'], [$status, $body['status']]);

        [$status, $body] = $this->respond(fn ($request) => throw new HttpException($request, 'Not valid.', 422));
        $this->assertSame([422, 'UNPROCESSABLE ENTITY'], [$status, $body['status']]);
    }

    public function testControlAnUnexpectedExceptionIsStillAServerError(): void
    {
        [$status, $body] = $this->respond(fn ($request) => throw new \RuntimeException('database password in here'));

        $this->assertSame([500, 'SERVER_ERROR', 'An unexpected error occurred.'], [$status, $body['status'], $body['data']]);
    }

    public function testOtherStatusesKeepTheirNames(): void
    {
        [$status, $body] = $this->respond(fn ($request) => throw new HttpNotFoundException($request, 'No such thing.'));
        $this->assertSame([404, 'RESOURCE_NOT_FOUND'], [$status, $body['status']]);

        // A status with no name of its own keeps the generic one.
        [$status, $body] = $this->respond(fn ($request) => throw new HttpException($request, 'Busy.', 503));
        $this->assertSame([503, 'SERVER_ERROR'], [$status, $body['status']]);
    }
}
