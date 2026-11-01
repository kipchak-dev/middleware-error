<?php

namespace Kipchak\Middleware\Error;

use Kipchak\Core\Interfaces;
use Kipchak\Driver\Config\Config;
use Kipchak\Middleware\Error\Exceptions\Handlers\DefaultHandler;
use Kipchak\Core\Components\App;
use Psr\Log\LoggerInterface;

class Error implements Interfaces\Middleware
{
    public static function initialise(App $app): void
    {
        $api = Config::get('kipchak.api');
        $logExceptions = isset($api['logExceptions']) && (bool) $api['logExceptions'];
        $logExceptionDetails = isset($api['logExceptionDetails']) && (bool) $api['logExceptionDetails'];
        $logger = self::logger($app, $api);

        // Add Application middleware
        $errorMiddleware = $app->addErrorMiddleware($logExceptions, $logExceptions, $logExceptionDetails, $logger);

        // Configure error middleware
        $errorMiddleware->setDefaultErrorHandler(
            new DefaultHandler(
                $app->getCallableResolver(),
                $app->getResponseFactory(),
                $logger
            )
        );
    }

    /**
     * With 'logExceptionsTo' => 'logger' in kipchak.api, exceptions are logged through the Logger driver, so they
     * share the API's log format and go through its processors (PII Redaction's, for one). Otherwise, as before,
     * Slim writes them with PHP's error_log().
     */
    private static function logger(App $app, array $api): ?LoggerInterface
    {
        if (($api['logExceptionsTo'] ?? 'error_log') !== 'logger') {
            return null;
        }

        $container = $app->getContainer();
        if ($container === null || !$container->has('drivers.logger')) {
            return null;
        }
        $logger = $container->get('drivers.logger');

        return $logger instanceof LoggerInterface ? $logger : null;
    }
}
