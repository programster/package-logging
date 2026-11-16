# Failover logging

- [Creating a FailoverLogger](#creating-a-failoverlogger)
- [Why logger factories?](#why-logger-factories)
- [Caching the logger](#caching-the-logger)
- [What's logged when a logger fails](#whats-logged-when-a-logger-fails)
- [Failure handlers](#failure-handlers)
- [The retry delay](#the-retry-delay)
- [Trying the skipped loggers as a last resort](#trying-the-skipped-loggers-as-a-last-resort)
- [When every logger fails](#when-every-logger-fails)

The `FailoverLogger` tries each of its loggers in order until one of them logs the message. E.g. log to a database,
and if that fails, fall back to a file, and if that fails too, to email. Once a logger works, it's also given an error
log for each logger that failed before it, so you can find out why they failed.

## Creating a FailoverLogger
`FailoverLogger::create()` takes a factory for the logger to try first, and one for each logger to fall back to, in
order. It needs at least one fallback, so a `FailoverLogger` that has nothing to fail over to can't be written:

```php
$createDatabaseLogger = function () : \Psr\Log\LoggerInterface {
    // Stands in for a logger that can't connect to its database.
    throw new \RuntimeException("Connection refused");
};

$createFileLogger = function () : \Psr\Log\LoggerInterface {
    return new JsonFileLogger("logs.json");
};

$createBackupFileLogger = function () : \Psr\Log\LoggerInterface {
    return new CsvFileLogger("backup-logs.csv");
};

$databaseLoggerFactory = new CachingLoggerFactory($createDatabaseLogger);
$fileLoggerFactory = new CachingLoggerFactory($createFileLogger);
$backupFileLoggerFactory = new CachingLoggerFactory($createBackupFileLogger);

$logger = FailoverLogger::create($databaseLoggerFactory, $fileLoggerFactory, $backupFileLoggerFactory);
$logger->error("Payment failed", ['order_id' => 123]);

// The database logger couldn't be created, so the log went to the file.
$logs = json_decode(file_get_contents("logs.json"), true);
assert($logs[0]['message'] === "Payment failed");
```

Its settings are changed with `withFailureHandler()` and `withRetryDelay()`, which each return a changed copy, rather
than changing the logger they're called on:

```php
$logger = FailoverLogger::create($databaseLoggerFactory, $fileLoggerFactory)
    ->withRetryDelay(RetryDelay::createUsingSeconds(10));
```

## Why logger factories?
Something can go wrong with a logger at two points: when calling its `log()` method, and when constructing it in the
first place. For example, a database logger may fail to connect to the database in its constructor. If the
`FailoverLogger` took loggers that had already been constructed, that exception would be thrown in your own code whilst
building the `FailoverLogger`, before it ever got the chance to fail over, and you would lose your fallback logging
entirely.

So it takes factories instead, which implement `InterfaceLoggerFactory`, and asks each one for its logger (with its
one method, `fetchLogger()`) only when it needs it. An exception thrown whilst fetching the logger is treated exactly like one thrown whilst logging: the next logger is
tried, and the exception is logged to whichever logger eventually works.

## Caching the logger
The `FailoverLogger` calls `fetchLogger()` every time it tries a logger, and doesn't keep the logger it gets back. A
`CachingLoggerFactory` creates its logger with the closure it's given, the first time it's needed, and then keeps it.
If the closure throws, nothing is kept, so the logger is created again the next time it's tried (e.g. once the
database is back up). Note that once a logger has been created, it's kept even if it later fails to log, so a logger
whose connection can drop should reconnect itself.

The closure must take no parameters, and must declare that it returns a `LoggerInterface` (or a class that implements
it), so that PHP checks it does. Anything else throws an `ExceptionInvalidLoggerCreator`:

```php
// Throws ExceptionInvalidLoggerCreator
$createLogger = function () {
    return new JsonFileLogger("logs.json");
};

new CachingLoggerFactory($createLogger);
```

For anything else, such as a logger that the rest of your application shares, implement `InterfaceLoggerFactory`
yourself:

```php
final class SharedLoggerFactory implements InterfaceLoggerFactory
{
    public function fetchLogger() : \Psr\Log\LoggerInterface
    {
        // E.g. get the application's logger from its service container.
        return new JsonFileLogger("shared-logs.json");
    }
}

$logger = FailoverLogger::create(new SharedLoggerFactory(), $fileLoggerFactory);
```

## What's logged when a logger fails
Once a logger works, it's given the original message, and then an error log for each logger that failed before it,
in order, e.g. `FailoverLogger: logger factory Programster\Log\CachingLoggerFactory failed to provide a logger:
Connection refused`. Its context holds the details, as scalars, so that file loggers can write them:

| Key | Value |
|---|---|
| `failed_logger_index` | The logger's position, from 0 for the primary logger. |
| `failed_logger_factory_class` | The class of the logger's factory. |
| `failed_logger_class` | The class of the logger, or `""` if its factory failed to provide one. |
| `failed_during` | `fetchLogger` if the factory failed, or `log` if the logger did. |
| `exception_class`, `exception_message`, `exception_code`, `exception_file`, `exception_line`, `exception_trace` | The exception's details. |

```php
$logs = json_decode(file_get_contents("logs.json"), true);
assert($logs[1]['context']['failed_during'] === "fetchLogger");
assert($logs[1]['context']['exception_message'] === "Connection refused");
```

If the working logger throws whilst logging these, it's ignored, as the original message was logged.

## Failure handlers
A failure handler is told whenever one or more loggers fail, so you can take your own action, such as alerting
someone. It's given a `LoggerFailureCollection`, with a `LoggerFailure` for each logger that failed, in the order they
were tried, and whether the message was eventually logged:

```php
$alertingHandler = new class implements InterfaceFailureHandler {
    public function handleFailures(LoggerFailureCollection $failures, bool $messageWasLogged) : void
    {
        foreach ($failures as $failure)
        {
            $position = $failure->getPosition();
            $stage = $failure->getStage()->value;
            $reason = $failure->getThrowable()->getMessage();
            echo "Logger {$position} failed during {$stage}: {$reason}" . PHP_EOL;
        }
    }
};

$logger = FailoverLogger::create($databaseLoggerFactory, $fileLoggerFactory)->withFailureHandler($alertingHandler);
$logger->warning("Disk space low"); // Prints "Logger 0 failed during fetchLogger: Connection refused".
```

It's called after the failures have been logged to the working logger, or, if no logger worked, before
`ExceptionAllLoggersFailed` is thrown. It isn't called when nothing failed, including when every logger was skipped
for being within its retry delay. Anything it throws is passed on to the code that was logging. Beware of logging to
the same `FailoverLogger` from within it, as that could recurse if the same loggers keep failing.

## The retry delay
Whenever a logger fails, it's skipped for the next 3 seconds by default, and logs go straight to the next logger. This
stops a slow failure, such as a database connection timing out, from holding up every log whilst the logger is down.

Each logger has its own timeout. For example, if the database logger fails, logs go to the file logger. If the file
logger then fails a second later, logs go to the email logger. Once the database logger's 3 seconds are up, it's tried
again, and if it still fails, logs go straight to the email logger, as the file logger is still in its own timeout.

To change the delay, give `withRetryDelay()` a `RetryDelay`:

- `RetryDelay::createUsingSeconds(10)` skips a failed logger for 10 seconds. Fractions of a second are allowed. It
  must be more than 0, so a mistake such as a negative delay throws an `ExceptionInvalidRetryDelay`.
- `RetryDelay::createNone()` tries every logger on every log, so a failed logger is tried again on the very next log.
- `RetryDelay::createDefault()` is the default, of 3 seconds.

```php
$logger = FailoverLogger::create($databaseLoggerFactory, $fileLoggerFactory)
    ->withRetryDelay(RetryDelay::createNone());
```

The timeouts are kept in the `FailoverLogger` object, so they last as long as it does. In a typical PHP-FPM web
request that's just the request, so each request tries the failed logger once, and then skips it for the rest of the
request (assuming the delay is longer than the request). The copies that `withFailureHandler()` and `withRetryDelay()`
return start with no loggers in their timeout.

## Trying the skipped loggers as a last resort
By default, a logger in its timeout isn't tried at all, even if every other logger fails, so the log is lost even if
that logger has recovered. That's because a broken logger can take a long time to fail (e.g. waiting for a connection
to time out), so trying the skipped loggers too can hold up your application for a long time.

If you'd rather wait than lose the log, pass `retrySkippedLoggersIfAllFail: true`. Then, once every logger that isn't
in its timeout has failed (or if every logger is in its timeout), the skipped loggers are tried as well, in order:

```php
$retryDelay = RetryDelay::createUsingSeconds(3, retrySkippedLoggersIfAllFail: true);
$logger = FailoverLogger::create($databaseLoggerFactory, $fileLoggerFactory)->withRetryDelay($retryDelay);
```

## When every logger fails
If no logger was able to log the message, because each one either failed or was skipped, an
`ExceptionAllLoggersFailed` is thrown. Its `getFailures()` gives a `LoggerFailureCollection` of the loggers that were
actually tried, so it's empty if every logger was skipped, and its previous exception is the last logger's:

```php
$createBrokenLogger = function () : \Psr\Log\LoggerInterface {
    throw new \RuntimeException("Disk full");
};

$logger = FailoverLogger::create($databaseLoggerFactory, new CachingLoggerFactory($createBrokenLogger));

try
{
    $logger->info("Lost");
}
catch (ExceptionAllLoggersFailed $exception)
{
    assert($exception->getMessage() === "All 2 loggers failed to log the message: Lost");
    assert(count($exception->getFailures()) === 2);
    assert($exception->getPrevious()?->getMessage() === "Disk full");
}
```
