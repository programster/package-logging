# Loggers

- [Log levels](#log-levels)
- [CsvFileLogger](#csvfilelogger)
- [JsonFileLogger](#jsonfilelogger)
- [FileLogger](#filelogger)
- [MultiLogger](#multilogger)
- [MinLevelLogger](#minlevellogger)
- [PrefixLogger](#prefixlogger)
- [AddedContextLogger](#addedcontextlogger)
- [FailoverLogger](#failoverlogger)

Every logger implements PSR-3's `LoggerInterface`, so it can be used anywhere a PSR-3 logger can, and the loggers that
wrap another logger can wrap any PSR-3 logger, including ones from other packages.

## Log levels
The `LogLevel` enum has a case for each PSR-3 level. The loggers accept a level as a `LogLevel`, a case-insensitive
PSR-3 level string, or an integer from 0 (debug) to 7 (emergency), which is the reverse of syslog's numbering:

```php
$logger = new FileLogger("logs.csv");
$logger->log(LogLevel::WARNING, "As an enum");
$logger->log("warning", "As a string");
$logger->log("WARNING", "In any case");
$logger->log(3, "As a number");
```

An invalid level throws an `ExceptionInvalidLogLevel`, which is the `\Psr\Log\InvalidArgumentException` that PSR-3
requires:

```php
// Throws ExceptionInvalidLogLevel
$logger->log("loud", "Not a level");
```

`LogLevel::createFromInt()` and `LogLevel::createFromString()` convert a number or string into a `LogLevel`, and
`toInt()` gives a level's number, so levels can be compared:

```php
assert(LogLevel::createFromString("Error") === LogLevel::ERROR);
assert(LogLevel::createFromInt(7) === LogLevel::EMERGENCY);
assert(LogLevel::ERROR->toInt() > LogLevel::WARNING->toInt());
```

## CsvFileLogger
Logs to a CSV file, one row per log, holding the time, level, message and the context as JSON. The time is written in
the format given (by default `Y-m-d H:i:s`, for easy sorting and reading):

```php
$logger = new CsvFileLogger("logs.csv", "Y-m-d\TH:i:sP");
$logger->info("User logged in", ['user_id' => 5]);
```

The file is created if it doesn't exist. It's checked when the logger is created, so a path that can't be written to
throws an `ExceptionFailedToOpenFile` there, with the reason, rather than on the first log:

```php
// Throws ExceptionFailedToOpenFile
new CsvFileLogger("missing-directory/logs.csv");
```

The file is opened, locked, written to and closed for each log, rather than kept open. So if the file is moved away
(e.g. by logrotate), the next log creates a new file at the path, rather than writing into the moved one, and rows from
different processes can't be mixed together.

A log that can't be written throws, rather than being lost silently:

- `ExceptionFailedToWriteFile` if writing failed, e.g. because the disk is full.
- `ExceptionFailedToLockFile` if the file couldn't be locked.
- `ExceptionFailedToEncodeLog` if the context can't be encoded as JSON, e.g. it holds `NAN`. Bad UTF-8 is replaced
  with the Unicode replacement character (U+FFFD) instead, rather than losing the whole log.

Objects in the context that JSON would write as just their public properties are converted first, so nothing that
matters about them is lost:

- An exception, e.g. in the `exception` key as PSR-3 recommends, is written as its class, message, code, file, line,
  trace and previous exception (written the same way, or `null`).
- A `Stringable` is written as its string, unless it's also `JsonSerializable`, as that says how it should be written.

```php
$logger = new JsonFileLogger("exceptions.json");
$logger->error("Payment failed", ['exception' => new \RuntimeException("Card declined")]);

$logs = json_decode(file_get_contents("exceptions.json"), true);
assert($logs[0]['context']['exception']['message'] === "Card declined");
```

## JsonFileLogger
Logs to a file holding a JSON array, with an object per log, so the file can always be read as JSON:

```php
$logger = new JsonFileLogger("logs.json");
$logger->info("User logged in", ['user_id' => 5]);
$logger->error("Payment failed");

$logs = json_decode(file_get_contents("logs.json"), true);
assert($logs[1]['message'] === "Payment failed");
assert(array_keys($logs[0]) === ['when', 'level', 'message', 'context']);
```

Each log is added to the end of the array in place, without rewriting the file, and works with a JSON array in any
formatting (e.g. pretty printed, or all on one line). A file that isn't a JSON array is never changed, to avoid losing
what's in it: logging to it throws an `ExceptionInvalidJsonLogFile` instead. Like the `CsvFileLogger`, it copes with
the file being rotated, converts objects in the context, and throws rather than losing a log it can't write. A file
that can be written to but not read can't be logged to, as each log reads the end of the array, so it's rejected when
the logger is created.

## FileLogger
Logs to a file as CSV (the default) or JSON, using a `CsvFileLogger` or `JsonFileLogger`:

```php
$csvLogger = new FileLogger("logs.csv");
$jsonLogger = new FileLogger("logs.json", fileFormat: FileFormat::JSON);
```

## MultiLogger
Logs to every one of its loggers, in order. It needs at least one:

```php
$logger = new MultiLogger(new FileLogger("logs.csv"), new FileLogger("logs.json", fileFormat: FileFormat::JSON));
$logger->info("Logged to both");
```

If one of its loggers throws, the exception is passed on, and the loggers after it aren't logged to. To keep logging
to the others when one fails, wrap it in a [FailoverLogger](failover-logging.md).

## MinLevelLogger
Only passes on logs at or above a level, e.g. to leave out the debug logs in production:

```php
$fileLogger = new JsonFileLogger("min-level.json");
$logger = new MinLevelLogger(LogLevel::WARNING, $fileLogger);
$logger->debug("Left out");
$logger->warning("Logged");
$logger->critical("Logged");

$messages = array_column(json_decode(file_get_contents("min-level.json"), true), 'message');
assert($messages === ["Logged", "Logged"]);
```

## PrefixLogger
Adds a prefix to the start of every message, e.g. the name of the service, before sending logs on by email:

```php
$logger = new PrefixLogger("[billing] ", new FileLogger("logs.csv"));
$logger->error("Card declined"); // Logged as "[billing] Card declined".
```

## AddedContextLogger
Adds context to every log, so you can filter by it later, e.g. to tag every log from a script. Context given when
logging is merged with it, and wins for any keys that are in both:

```php
$fileLogger = new JsonFileLogger("added-context.json");
$logger = new AddedContextLogger($fileLogger, ['script' => 'import']);
$logger->info("Imported", ['rows' => 5]);

$logs = json_decode(file_get_contents("added-context.json"), true);
assert($logs[0]['context'] === ['script' => 'import', 'rows' => 5]);
```

It needs some context to add, so an empty array throws an `ExceptionContextRequired`:

```php
// Throws ExceptionContextRequired
new AddedContextLogger($fileLogger, []);
```

## FailoverLogger
Tries each of its loggers in order until one works. See [Failover logging](failover-logging.md).
