# Error Middleware for Kipchak

This  Middleware package provides a standardized way to handle and log errors and exceptions in Kipchak APIs.

#### Composer Package 

`kipchak/middleware-error`

## How to use it?

Install it via composer: `composer require kipchak/middleware-error`.

Initialise it twice in `middlewares/middlewares.php`, as the first and the last line, with every other global
middleware between the two:

```php
use Kipchak\Middleware\Error\Error;

Error::initialise($app);   // innermost: turns route exceptions into JSON responses
// ... every other global middleware
Error::initialise($app);   // outermost: does the same for exceptions from the middleware above
```

Slim runs the middleware added last first, so the first is the innermost middleware and the last the outermost.
Without the last, an exception thrown by another middleware reaches the client as a plain-text 500.

Then ensure you have appropriate settings configured in the `kipchak.api.php` config file in the root of your project.

For a sample, please see: https://github.com/kipchak-dev/starter/blob/master/config/kipchak.api.php.

The settings it reads from `kipchak.api.php`:

* `logExceptions`: log exceptions.
* `logExceptionDetails`: include the details and stack trace in the log entry.
* `logExceptionsTo` (optional): `'error_log'` (the default) has Slim write exceptions with PHP's `error_log()`, as in
  earlier versions; `'logger'` sends them through the Logger driver at error level instead, so they share the API's
  log format and processors (PII Redaction's, for one). Without the Logger driver, `error_log()` is used.

Full documentation: <https://kipchak.dev/docs/mw-error>.

## What is a Kipchak Middleware?

Kipchak Middleware is a standard PHP PSR-15 middleware.

Middleware is a component that sits between the request and response of a web application, allowing for interception 
and modification of the request and response data. In the context of Kipchak, middleware can be used to handle errors, 
authentication, and other cross-cutting concerns that are common across one or more API endpoints.
