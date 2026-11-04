Logger PHP Package
==================

This is a [PSR-3](https://www.php-fig.org/psr/psr-3/) compatible logger package that provides some
abstract loggers that allow:

* Minimum level logging (MinLevelLogger)
* Logging to multiple loggers (MultiLogger)
* Failover logging (FailoverLogger) - tries each logger in order until one succeeds, then also logs
  to that logger the exceptions thrown by any loggers that failed before it. Loggers are provided
  through `InterfaceLoggerFactory` objects, so that a logger that fails to be constructed is failed
  over too. An optional `InterfaceFailureHandler` can be provided, which is notified whenever any
  of the loggers fail. A logger that fails is skipped for a retry delay (3 seconds by default)
  before it is tried again.
* Prefix logging - add a prefix to all messages before logging to the underlying logger.
* CsvFileLogger - log to a CSV file.
* JsonFileLogger - log to a JSON file.
* File logger - wrapper that returns CsvFileLogger or JsonFileLogger depending on format specified.
* AddedContextLogger - just as the prefix logger adds a prefix to messages in the log, this adds 
  the provided context to the logs. Bear in mind that if you provide a context with associative 
  keys, and that logger gets called with context that has the same key, that key/value will be 
  overwritten.

This package provides a PHP enum for the log level to simplify things. Hopefully the PSR-3 package
will provide this itself at some point in the future.

You will likely want to take advantage of one of the following additional logging packages
that make use of this package.

* [PostgreSQL Logger](https://github.com/programster/package-pgsql-logger)- log to a PostgreSQL database. 
* [MySQL Logger](https://github.com/programster/package-mysql-logger) - log to a MySQL database.
* [Email Logger](https://github.com/programster/package-email-logger) - send logs to any number of email addresses.


## Install

```bash
composer require programster/log
```

## Running Tests

```bash
composer install
composer test
```

## Example Usage

```php
/**
 * Get a logger for logging all the things.
 * @return \Psr\Log\LoggerInterface
 */
public function getLogger() : \Psr\Log\LoggerInterface
{
    return new \Programster\Log\FileLogger(filepath: "/path/to/my/logs.csv");
}
```

### Failover Logging

Try logging to a primary logger, and fall back to the next logger(s) if it throws.

The `FailoverLogger` does not take loggers directly. Instead, it takes logger factories, which are
objects implementing `InterfaceLoggerFactory`:

```php
interface InterfaceLoggerFactory
{
    public function getLogger() : \Psr\Log\LoggerInterface;
}
```

#### Why logger factories?

Something can go wrong with a logger at two points: when calling its `log()` method, and when
constructing it in the first place. For example, a database logger may fail to connect to the
database in its constructor. If the `FailoverLogger` took already-constructed loggers, that exception
would be thrown in your own code whilst building the `FailoverLogger`, before it ever got the chance
to fail over, and you would lose your fallback logging entirely.

By taking factories, the `FailoverLogger` asks each factory for its logger at the point it needs it,
inside the same `try` as the `log()` call. An exception thrown whilst constructing the logger is
therefore treated exactly like an exception thrown whilst logging: the next logger is tried, and the
exception is logged to whichever logger eventually works, so that you can find out what went wrong.
The failure log's context includes `failed_during`, which is `getLogger` if the factory failed, or
`log` if the logger itself failed.

#### Caching the logger

The `FailoverLogger` calls `getLogger()` every time it logs a message, and deliberately does not
cache the logger it gets back. If constructing your logger is expensive (e.g. it opens a database
connection), cache it yourself in the factory, using a `static` variable that starts out as `null`:

```php
$databaseLoggerFactory = new class implements \Programster\Log\Interfaces\InterfaceLoggerFactory {
    public function getLogger() : \Psr\Log\LoggerInterface
    {
        static $logger = null;

        if ($logger === null)
        {
            // If this throws, $logger stays null, so construction will be tried again the
            // next time a message is logged (e.g. once the database is back up).
            $logger = new MyDatabaseLogger($databaseConnectionDetails);
        }

        return $logger;
    }
};
```

A nice side effect of this is that a logger that failed to be constructed is retried on the next
log, rather than being broken for the lifetime of the `FailoverLogger`.

Be aware that a `static` variable inside a method is shared by every instance of that class. That is
fine for an anonymous class like the one above, but if you write a named factory class that you
construct more than once with different settings (e.g. different file paths), every instance would
share the first logger created.

#### Retry delay

Whenever a logger fails, it is skipped for the next 3 seconds by default, and logs go straight to the
next logger. This stops a slow failure, such as a database connection timing out, from holding up
every log whilst the logger is down.

Each logger has its own timeout. For example, if the database logger fails, logs go to the file
logger. If the file logger then fails a second later, logs go to the email logger. Once the database
logger's 3 seconds are up, it is tried again, and if it still fails, logs go straight to the email
logger, as the file logger is still in its own timeout.

To change the delay, pass the number of seconds as the fourth parameter (fractions of a second are
allowed). If you want every logger to be tried on every log instead, so that a failed logger is tried
again on the very next log, set it to 0:

```php
$logger = new \Programster\Log\FailoverLogger(
    $databaseLoggerFactory,
    [$fileLoggerFactory, $emailLoggerFactory],
    retryDelaySeconds: 0
);
```

A logger in its timeout is not tried at all, even if every other logger fails. If none of the
loggers were able to log the message, because each one either failed or was skipped, an
`ExceptionAllLoggersFailed` is thrown. Its `getFailures()` only contains the exceptions from the
loggers that were actually tried, so it is empty if every logger was skipped.

The timeouts are stored in the `FailoverLogger` object, so they last as long as it does. In a typical
PHP-FPM web request that is just the request, so each request tries the failed logger once, and then
skips it for the rest of the request (assuming the delay is longer than the request).

#### Full example

The optional third parameter is a failure handler that is notified whenever any of the loggers fail.
An anonymous class is a quick way to create one:

```php
/**
 * Get a logger that logs to a JSON file, but falls back to a CSV file if that fails.
 * @return \Psr\Log\LoggerInterface
 */
public function getLogger() : \Psr\Log\LoggerInterface
{
    $jsonLoggerFactory = new class implements \Programster\Log\Interfaces\InterfaceLoggerFactory {
        public function getLogger() : \Psr\Log\LoggerInterface
        {
            static $logger = null;

            if ($logger === null)
            {
                $logger = new \Programster\Log\JsonFileLogger("/path/to/my/logs.json");
            }

            return $logger;
        }
    };

    $csvLoggerFactory = new class implements \Programster\Log\Interfaces\InterfaceLoggerFactory {
        public function getLogger() : \Psr\Log\LoggerInterface
        {
            static $logger = null;

            if ($logger === null)
            {
                $logger = new \Programster\Log\CsvFileLogger("/path/to/my/backup-logs.csv");
            }

            return $logger;
        }
    };

    $failureHandler = new class implements \Programster\Log\Interfaces\InterfaceFailureHandler {
        public function handleFailures(array $failures, bool $messageWasLogged) : void
        {
            echo count($failures) . " logger(s) failed. Message was logged: " . ($messageWasLogged ? "yes" : "no") . PHP_EOL;

            foreach ($failures as $failure)
            {
                echo " - " . $failure->getMessage() . PHP_EOL;
            }
        }
    };

    return new \Programster\Log\FailoverLogger($jsonLoggerFactory, $csvLoggerFactory, $failureHandler);
}
```

The second parameter can also be an array of logger factories, which are tried in order:

```php
$logger = new \Programster\Log\FailoverLogger(
    $primaryLoggerFactory,
    [$firstFallbackLoggerFactory, $secondFallbackLoggerFactory],
    $failureHandler
);
```

If every logger fails, an `ExceptionAllLoggersFailed` is thrown, which provides the individual
exceptions through `getFailures()`.
