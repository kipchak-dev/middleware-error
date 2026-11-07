<?php

declare(strict_types=1);

namespace Kipchak\Middleware\Error\Tests;

use DI\Container;
use Kipchak\Core\Components\App;
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

    public function testOtherStatusesKeepTheirNames(): void
    {
        [$status, $body] = $this->respond(fn ($request) => throw new HttpNotFoundException($request, 'No such thing.'));
        $this->assertSame([404, 'RESOURCE_NOT_FOUND'], [$status, $body['status']]);

        // A status with no name of its own keeps the generic one.
        [$status, $body] = $this->respond(fn ($request) => throw new HttpException($request, 'Busy.', 503));
        $this->assertSame([503, 'SERVER_ERROR'], [$status, $body['status']]);
    }
}
