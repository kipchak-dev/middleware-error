<?php

namespace Kipchak\Middleware\Error;

use Kipchak\Core\Interfaces;
use Kipchak\Driver\Config\Driver as Config;
use Kipchak\Middleware\Error\Exceptions\Handlers\DefaultHandler;
use Slim\App;

class Error implements Interfaces\Middleware
{
    public static function initialise(App $app): void
    {
        $api = Config::get('kipchak.api');
        $logExceptions = isset($api['logExceptions']) && (bool) $api['logExceptions'];
        $logExceptionDetails = isset($api['logExceptionDetails']) && (bool) $api['logExceptionDetails'];

        // Add Application middleware
        $errorMiddleware = $app->addErrorMiddleware($logExceptions, $logExceptions, $logExceptionDetails);

        // Configure error middleware
        $errorMiddleware->setDefaultErrorHandler(
            new DefaultHandler(
                $app->getCallableResolver(),
                $app->getResponseFactory()
            )
        );
    }
}