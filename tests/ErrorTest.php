<?php

declare(strict_types=1);

namespace Kipchak\Middleware\Error\Tests;

use DI\Container;
use Kipchak\Core\Components\App;
use Kipchak\Driver\Config\Config;
use Kipchak\Driver\Logger\Logger;
use Kipchak\Middleware\Error\Error;
use Monolog\Handler\TestHandler;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

final class ErrorTest extends TestCase
{
    private TestHandler $logs;

    private function app(array $api): App
    {
        $container = new Container();
        Config::initialise($container);
        $container->set('drivers.config', ['kipchak.api' => $api + ['name' => 'test', 'debug' => false, 'logExceptions' => true, 'logExceptionDetails' => true]]);
        Logger::initialise($container);
        $this->logs = new TestHandler();
        Logger::get()->setHandlers([$this->logs]);

        $app = new App(new ResponseFactory(), $container);
        $app->addRoutingMiddleware();
        Error::initialise($app);
        $app->get('/boom', function () {
            throw new \RuntimeException('Lookup failed for jane@example.com');
        });

        return $app;
    }

    private static function boom(App $app): \Psr\Http\Message\ResponseInterface
    {
        return $app->handle((new ServerRequestFactory())->createServerRequest('GET', 'https://api.example.com/boom'));
    }

    public function testByDefaultExceptionsAreLoggedWithErrorLogAsBefore(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'error-log');
        $previous = ini_set('error_log', $file);
        try {
            $response = self::boom($this->app([]));
        } finally {
            ini_set('error_log', (string) $previous);
        }

        $this->assertSame(500, $response->getStatusCode());
        $this->assertStringContainsString('Lookup failed for jane@example.com', (string) file_get_contents($file));
        $this->assertSame([], $this->logs->getRecords(), 'Nothing goes to the Logger driver unless asked');
        unlink($file);
    }

    public function testExceptionsCanBeLoggedThroughTheLoggerDriver(): void
    {
        $response = self::boom($this->app(['logExceptionsTo' => 'logger']));

        $this->assertSame(500, $response->getStatusCode());
        $this->assertStringContainsString('An unexpected error occurred.', (string) $response->getBody(), 'The response is unchanged');
        $this->assertCount(1, $this->logs->getRecords());
        $record = $this->logs->getRecords()[0];
        $this->assertSame('ERROR', $record->level->getName());
        $this->assertStringContainsString('Lookup failed for jane@example.com', $record->message);
    }

    public function testExceptionLoggingCanStillBeSwitchedOff(): void
    {
        self::boom($this->app(['logExceptionsTo' => 'logger', 'logExceptions' => false]));

        $this->assertSame([], $this->logs->getRecords());
    }

    public function testFallsBackToErrorLogWithoutTheLoggerDriver(): void
    {
        $container = new Container();
        Config::initialise($container);
        $container->set('drivers.config', ['kipchak.api' => ['name' => 'test', 'logExceptions' => true, 'logExceptionsTo' => 'logger']]);
        $app = new App(new ResponseFactory(), $container);
        $app->addRoutingMiddleware();
        Error::initialise($app);
        $app->get('/boom', fn() => throw new \RuntimeException('x'));

        $file = tempnam(sys_get_temp_dir(), 'error-log');
        $previous = ini_set('error_log', $file);
        try {
            $this->assertSame(500, self::boom($app)->getStatusCode());
        } finally {
            ini_set('error_log', (string) $previous);
        }
        $this->assertStringContainsString('Slim Application Error', (string) file_get_contents($file));
        unlink($file);
    }
}
