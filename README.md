Logger PHP Package
==================

This is a [PSR-3](https://www.php-fig.org/psr/psr-3/) compatible logger package that provides some
abstract loggers that allow:

* Minimum level logging (MinLevelLogger)
* Logging to multiple loggers (MultiLogger)
* Failover logging (FailoverLogger) - tries each logger in order until one succeeds, then also logs
  to that logger the exceptions thrown by any loggers that failed before it. An optional
  `InterfaceFailureHandler` can be provided, which is notified whenever any of the loggers fail.
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

Try logging to a primary logger, and fall back to the next logger(s) if it throws. The optional
third parameter is a failure handler that is notified whenever any of the loggers fail. An
anonymous class is a quick way to create one:

```php
/**
 * Get a logger that logs to a JSON file, but falls back to a CSV file if that fails.
 * @return \Psr\Log\LoggerInterface
 */
public function getLogger() : \Psr\Log\LoggerInterface
{
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

    return new \Programster\Log\FailoverLogger(
        new \Programster\Log\JsonFileLogger("/path/to/my/logs.json"),
        new \Programster\Log\CsvFileLogger("/path/to/my/backup-logs.csv"),
        $failureHandler
    );
}
```

The second parameter can also be an array of loggers, which are tried in order:

```php
$logger = new \Programster\Log\FailoverLogger(
    $primaryLogger,
    [$firstFallbackLogger, $secondFallbackLogger],
    $failureHandler
);
```

If every logger fails, an `ExceptionAllLoggersFailed` is thrown, which provides the individual
exceptions through `getFailures()`.
