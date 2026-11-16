PHP Logging Package
===================

[![Tests](https://github.com/programster/package-logging/actions/workflows/tests.yml/badge.svg?branch=master)](https://github.com/programster/package-logging/actions/workflows/tests.yml)

## Contents
- [About](#about)
- [Requirements](#requirements)
- [Installation](#installation)
- [Quick start](#quick-start)
- [Loggers](docs/loggers.md)
- [Failover logging](docs/failover-logging.md)
- [Upgrading](docs/upgrading.md)
- [Testing](docs/testing.md)
- [Design principles](#design-principles)
- [Related packages](#related-packages)
- [License](#license)

## About
A library of [PSR-3](https://www.php-fig.org/psr/psr-3/) loggers, most of which wrap other loggers, so they can be
combined:

| Logger | What it does |
|---|---|
| `CsvFileLogger` | Logs to a CSV file. |
| `JsonFileLogger` | Logs to a file holding a JSON array. |
| `FileLogger` | Logs to a file, as CSV (the default) or JSON. |
| `FailoverLogger` | Tries each of its loggers in order until one works, then logs why the ones before it failed. |
| `MultiLogger` | Logs to every one of its loggers. |
| `MinLevelLogger` | Only passes on logs at or above a level, e.g. warnings and above. |
| `PrefixLogger` | Adds a prefix to every message. |
| `AddedContextLogger` | Adds context to every log, e.g. `['script' => 'import']`, so you can filter by it later. |

There's also a `LogLevel` enum of the PSR-3 levels. The loggers accept a level as a `LogLevel`, a case-insensitive
PSR-3 level string (e.g. `"warning"`), or an integer from 0 (debug) to 7 (emergency).

## Requirements
- PHP 8.2, 8.3, 8.4 or 8.5.

## Installation
```bash
composer require programster/log
```

## Quick start
Log to a file:

```php
$logger = new FileLogger("logs.csv");
$logger->info("User logged in", ['user_id' => 5]);
```

Log to a JSON file, falling back to a CSV file if that fails. The `FailoverLogger` is given a factory for each logger,
rather than the logger itself, so that a logger that fails to be constructed (e.g. it can't connect to its database)
is failed over too:

```php
$createJsonLogger = function () : \Psr\Log\LoggerInterface {
    return new JsonFileLogger("logs.json");
};

$createCsvLogger = function () : \Psr\Log\LoggerInterface {
    return new CsvFileLogger("backup-logs.csv");
};

$jsonLoggerFactory = new CachingLoggerFactory($createJsonLogger);
$csvLoggerFactory = new CachingLoggerFactory($createCsvLogger);
$logger = FailoverLogger::create($jsonLoggerFactory, $csvLoggerFactory);
$logger->error("Payment failed", ['order_id' => 123]);
```

Only log warnings and above, with the name of the service at the start of every message:

```php
$logger = new MinLevelLogger(LogLevel::WARNING, new PrefixLogger("[billing] ", new FileLogger("logs.csv")));
$logger->debug("Not logged");
$logger->warning("Logged as: [billing] Card about to expire");
```

See [Loggers](docs/loggers.md) for each logger, and [Failover logging](docs/failover-logging.md) for everything the
`FailoverLogger` can do.

## Design principles
- **Mistakes are caught where they're made.** Wherever possible, a mistake can't be written at all: e.g. a
  `FailoverLogger` can't be created without a fallback, a `MultiLogger` without a logger, or a `FileLogger` with a
  misspelt format. Otherwise, it's rejected as the object is created, with an exception saying what was wrong, rather
  than failing later, or silently doing nothing.
- **Logs aren't lost quietly.** A file logger that can't write (e.g. because the disk is full) throws, rather than
  carrying on as if it had worked. Bad UTF-8 is replaced, rather than losing the whole log, and a JSON log file that
  isn't in the format expected is left alone, rather than being overwritten.
- **Warnings don't escape.** PHP's file functions report errors as warnings, which many frameworks turn into
  exceptions. The file loggers catch them, and throw the package's own exceptions instead, with the path and reason.
- **The safe behaviour is the default.** E.g. the `FailoverLogger` skips a broken logger for a few seconds by
  default, so a database timing out doesn't hold up every log.

## Related packages
These packages provide more loggers, which can be combined with the ones here:

- [PostgreSQL Logger](https://github.com/programster/package-pgsql-logger) - log to a PostgreSQL database.
- [MySQL Logger](https://github.com/programster/package-mysql-logger) - log to a MySQL database.
- [Email Logger](https://github.com/programster/package-email-logger) - send logs to any number of email addresses.

## License
MIT. See [LICENSE](LICENSE).
