# Upgrading

- [From 3.0.0 to 4.0.0](#from-300-to-400)
    - [Changes you need to make](#changes-you-need-to-make)
    - [Changes in behaviour](#changes-in-behaviour)
    - [New features](#new-features)

What changed in each release, and what, if anything, you need to change in your code when upgrading.

## From 3.0.0 to 4.0.0
The `FailoverLogger` now takes logger factories rather than loggers, so that a logger that fails to be constructed
(e.g. it can't connect to its database) is failed over too, rather than throwing in your own code before the
`FailoverLogger` exists. 4.0.0 also makes many more mistakes impossible to write, which changes several other
constructors.

### Changes you need to make
- **Create a `FailoverLogger` with `FailoverLogger::create()`, and give it a factory for each logger.** The constructor
  is now private. `create()` takes the primary logger's factory, then each fallback's, as separate arguments rather
  than an array (spread an array with `...$factories`). The failure handler and retry delay are set with
  `withFailureHandler()` and `withRetryDelay()`, which return a changed copy. The simplest factory is a
  `CachingLoggerFactory`, given a closure that creates the logger. So 3.0.0's
  `new FailoverLogger($databaseLogger, [$fileLogger], $failureHandler)` becomes:

  ```php
  $createDatabaseLogger = function () : \Psr\Log\LoggerInterface {
      return new JsonFileLogger("logs.json"); // E.g. new DatabaseLogger($connectionDetails).
  };

  $createFileLogger = function () : \Psr\Log\LoggerInterface {
      return new CsvFileLogger("logs.csv");
  };

  $databaseLoggerFactory = new CachingLoggerFactory($createDatabaseLogger);
  $fileLoggerFactory = new CachingLoggerFactory($createFileLogger);

  $failureHandler = new class implements InterfaceFailureHandler {
      public function handleFailures(LoggerFailureCollection $failures, bool $messageWasLogged) : void {}
  };

  $logger = FailoverLogger::create($databaseLoggerFactory, $fileLoggerFactory)->withFailureHandler($failureHandler);
  ```

- **Update your `InterfaceFailureHandler`s.** `handleFailures()` is now given a `LoggerFailureCollection` of
  `LoggerFailure`s, rather than an array of `\Throwable`s. Each one's `getThrowable()` gives the exception, and it also
  has the logger's position, its factory, and the stage at which it failed (`FailureStage::FETCHING_LOGGER` or
  `FailureStage::LOGGING`). `ExceptionAllLoggersFailed::getFailures()` returns the same collection.
- **The retry delay is a `RetryDelay`**, e.g. `->withRetryDelay(RetryDelay::createUsingSeconds(10))`, or
  `RetryDelay::createNone()` instead of a delay of 0.
- **`MinLevelLogger`'s threshold must be a `LogLevel`**, e.g. `new MinLevelLogger(LogLevel::WARNING, $logger)`,
  rather than a string or integer, so a misspelt level can't be written. The levels you log with can still be any of
  the three.
- **`FileLogger`'s format must be a `FileFormat`**, e.g. `FileFormat::JSON`, rather than a string. A string format
  that wasn't recognised used to crash with an `Error`, rather than throwing the exception it meant to.
  `ExceptionUnsupportedFileFormat` has been removed, as an unsupported format can no longer be given.
- **`LogLevel::fromInt()` is now `LogLevel::createFromInt()`**, and throws an `ExceptionInvalidLogLevel` for a number
  outside 0 to 7, rather than an `\UnhandledMatchError`.
- **`MultiLogger` needs at least one logger**, as one with none would silently throw every log away.
- **Classes that extend `AbstractLogger`:** `validateLogLevelString()`, `validateLogLevelInteger()` and
  `convertStringLogLevelToEnum()` have been removed. Use `convertLogLevelMixedVariable()`, or
  `LogLevel::createFromString()` and `LogLevel::createFromInt()`, instead.
- **`ExceptionInvalidFallbackLoggers` has been removed**, as an invalid set of fallbacks can no longer be given.

### Changes in behaviour
- **A logger that fails is skipped for 3 seconds by default**, so later logs go straight to the next logger, rather
  than waiting on the broken one every time. Use `RetryDelay::createNone()` to try every logger on every log, as 3.0.0
  did. See [the retry delay](failover-logging.md#the-retry-delay).
- **The failure logs say whether the factory or the logger failed**, in the `failed_during` context (`fetchLogger` or
  `log`), and the factory's class in `failed_logger_factory_class`. `failed_logger_class` is `""` when the factory
  failed to provide a logger.

- **Exceptions and other objects in the context are written properly by the file loggers.** An exception (e.g. in the
  `exception` key, as PSR-3 recommends) used to be written as `{}`, losing it entirely. It's now written as its class,
  message, code, file, line, trace and previous exception. A `Stringable` that isn't `JsonSerializable` is written as
  its string, rather than its public properties.
- **`MultiLogger` logs to every logger it can, and then throws.** A logger that throws used to stop the message
  being given to the loggers after it, and its exception was passed on as it was. Now the message is given to every
  logger, and then an `ExceptionMultiLoggerFailed` is thrown if any failed, holding each failure. Catch that, rather
  than the exceptions of the loggers themselves. It also now checks the level, throwing an `ExceptionInvalidLogLevel`
  for an invalid one before logging anything, and passes it on as the PSR-3 level string, rather than as it was
  given.
- **`AddedContextLogger` lets the log's own context override integer keys too.** It used to add the log's values
  after the added ones for integer keys, rather than replacing them.
- **`JsonFileLogger` rejects a file it can write to but not read when it's created**, with an
  `ExceptionFailedToOpenFile`, rather than failing on every log.

### New features
- **`CachingLoggerFactory`** creates its logger the first time it's needed, and keeps it, but tries again if creating
  it threw. See [Caching the logger](failover-logging.md#caching-the-logger).
- **Trying the skipped loggers as a last resort**, with `RetryDelay::createUsingSeconds(3,
  retrySkippedLoggersIfAllFail: true)`. See
  [Trying the skipped loggers as a last resort](failover-logging.md#trying-the-skipped-loggers-as-a-last-resort).
- **`LogLevel::createFromString()`** converts a case-insensitive PSR-3 level string into a `LogLevel`.
