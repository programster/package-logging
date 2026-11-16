<?php

/*
 * Smoke tests for the loggers that write to files: CsvFileLogger, JsonFileLogger and FileLogger. These are run by
 * testing/main.php, which provides $smoke.
 */

use Programster\Log\CsvFileLogger;
use Programster\Log\Exceptions\ExceptionFailedToEncodeLog;
use Programster\Log\Exceptions\ExceptionFailedToOpenFile;
use Programster\Log\Exceptions\ExceptionFailedToWriteFile;
use Programster\Log\Exceptions\ExceptionInvalidJsonLogFile;
use Programster\Log\Exceptions\ExceptionInvalidLogLevel;
use Programster\Log\FailoverLogger;
use Programster\Log\FileFormat;
use Programster\Log\FileLogger;
use Programster\Log\JsonFileLogger;
use Programster\Log\LogLevel;
use Programster\Log\Testing\Doubles\FixedLoggerFactory;
use Programster\Log\Testing\Doubles\RecordingLogger;
use Programster\Log\Testing\Doubles\TempDirectory;
use Programster\Log\Testing\SmokeTest;
use Psr\Log\LoggerInterface;

/** @var SmokeTest $smoke */

// Each level to log with, and the level string that should be written for it.
$validLevels = [
    'PSR-3 string' => [\Psr\Log\LogLevel::WARNING, 'warning'],
    'uppercase string' => ['INFO', 'info'],
    'mixed case string' => ['Error', 'error'],
    'LogLevel enum' => [LogLevel::CRITICAL, 'critical'],
    'integer 0 (debug)' => [0, 'debug'],
    'integer 3 (warning)' => [3, 'warning'],
    'integer 7 (emergency)' => [7, 'emergency'],
];

$invalidLevels = [
    'unknown string' => 'loud',
    'integer too high' => 8,
    'negative integer' => -1,
    'null' => null,
    'float' => 1.5,
    'object' => new \stdClass(),
];

/**
 * Read the rows from a CSV log file.
 * @param string $filepath - the path to the CSV log file.
 * @return list<list<string|null>> - one list of fields per row.
 */
$readCsvRows = function (string $filepath) : array {
    $rows = [];
    $handle = fopen($filepath, "r");

    if ($handle === false)
    {
        throw new \RuntimeException("Couldn't open {$filepath}.");
    }

    $row = fgetcsv($handle, escape: "\\");

    while ($row !== false)
    {
        $rows[] = $row;
        $row = fgetcsv($handle, escape: "\\");
    }

    fclose($handle);
    return $rows;
};

/**
 * Read and decode the logs from a JSON log file, throwing if the file is not valid JSON.
 * @param string $filepath - the path to the JSON log file.
 * @return array<mixed> - the decoded logs.
 */
$readJsonLogs = function (string $filepath) : array {
    $contents = file_get_contents($filepath);

    if ($contents === false)
    {
        throw new \RuntimeException("Couldn't read {$filepath}.");
    }

    $logs = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);

    if (!is_array($logs))
    {
        throw new \RuntimeException("{$filepath} doesn't hold a JSON array.");
    }

    return $logs;
};

/**
 * Wrap a test so that it runs in a new temporary directory, which is removed afterwards.
 * @param callable(string): void $test - the test, which is given the directory's path.
 * @return \Closure(): void - the test to run.
 */
$inTempDirectory = function (callable $test) : \Closure {
    return function () use ($test) : void {
        $directory = new TempDirectory();

        try
        {
            $test($directory->getPath());
        }
        finally
        {
            $directory->remove();
        }
    };
};

/**
 * Get the messages of every PHP warning (or other error) that the code raises, which a framework might convert into
 * an exception, whilst ignoring any exception it throws.
 * @param callable $code - the code to run.
 * @return list<string>
 */
$captureWarnings = function (callable $code) : array {
    $errors = [];

    $recordError = function (int $errno, string $errstr) use (&$errors) : bool {
        $errors[] = $errstr;
        return true;
    };

    set_error_handler($recordError);

    try
    {
        $code();
    }
    catch (\Exception)
    {
        // Only the warnings matter here.
    }
    finally
    {
        restore_error_handler();
    }

    return $errors;
};

$testCsvRows = function (string $dir) use ($readCsvRows) : void {
    $filepath = "{$dir}/logs.csv";
    $logger = new CsvFileLogger($filepath);
    $logger->info("first", ['key' => 'value']);
    $logger->error("second");

    $rows = $readCsvRows($filepath);
    SmokeTest::assertSame(2, count($rows), "the number of rows");
    SmokeTest::assertSame(['info', 'first', '{"key":"value"}'], array_slice($rows[0], 1), "the first row");
    SmokeTest::assertSame(['error', 'second', '[]'], array_slice($rows[1], 1), "the second row");
    SmokeTest::assertSame(1, preg_match('/^\d{4}-\d\d-\d\d \d\d:\d\d:\d\d$/', (string)$rows[0][0]), "the time");
};

$smoke->run("CsvFileLogger: writes each log as a row", $inTempDirectory($testCsvRows));

$testCsvTimeFormat = function (string $dir) use ($readCsvRows) : void {
    $filepath = "{$dir}/logs.csv";
    $logger = new CsvFileLogger($filepath, "Y");
    $logger->info("hello");

    SmokeTest::assertSame(date("Y"), $readCsvRows($filepath)[0][0], "the time");
};

$smoke->run("CsvFileLogger: writes the time in the format given", $inTempDirectory($testCsvTimeFormat));

$testCsvLevels = function (string $dir) use ($readCsvRows, $validLevels, $invalidLevels) : void {
    foreach ($validLevels as $name => [$level, $expectedLevel])
    {
        $filepath = "{$dir}/valid.csv";
        $logger = new CsvFileLogger($filepath);
        $logger->log($level, "hello");
        SmokeTest::assertSame($expectedLevel, $readCsvRows($filepath)[0][1], "the level written for a {$name}");
        unlink($filepath);
    }

    $filepath = "{$dir}/invalid.csv";
    $logger = new CsvFileLogger($filepath);

    foreach ($invalidLevels as $name => $level)
    {
        $logInvalid = function () use ($logger, $level) : void {
            $logger->log($level, "hello");
        };

        // PSR-3 requires an InvalidArgumentException for an invalid level.
        $exception = SmokeTest::catchException(\Psr\Log\InvalidArgumentException::class, $logInvalid);
        SmokeTest::assertSame(ExceptionInvalidLogLevel::class, get_class($exception), "the class, for a {$name}");
    }

    SmokeTest::assertSame([], $readCsvRows($filepath), "the rows after logging with invalid levels");
};

$smoke->run("CsvFileLogger: accepts every level format, and rejects invalid ones", $inTempDirectory($testCsvLevels));

$testCsvRotation = function (string $dir) use ($readCsvRows) : void {
    // E.g. logrotate renaming the file away whilst the application is running.
    $filepath = "{$dir}/logs.csv";
    $logger = new CsvFileLogger($filepath);
    $logger->info("before");
    rename($filepath, "{$filepath}.1");
    $logger->info("after");

    SmokeTest::assertSame(["after"], array_column($readCsvRows($filepath), 2), "the new file");
    SmokeTest::assertSame(["before"], array_column($readCsvRows("{$filepath}.1"), 2), "the rotated file");

    unlink($filepath);
    $logger->info("after deleting");
    SmokeTest::assertSame(["after deleting"], array_column($readCsvRows($filepath), 2), "the recreated file");
};

$smoke->run(
    "CsvFileLogger: writes to a new file after the file is rotated or deleted",
    $inTempDirectory($testCsvRotation)
);

$testCsvConcurrency = function (string $dir) use ($readCsvRows) : void {
    $filepath = "{$dir}/logs.csv";
    $autoloadPath = dirname(__DIR__, 2) . "/vendor/autoload.php";
    $numProcesses = 4;
    $logsPerProcess = 50;

    // A general check that concurrent writers don't corrupt the file. Note that on a local Linux filesystem this
    // passes even without the lock, as the kernel keeps each append whole. The lock matters on filesystems that
    // don't guarantee that, such as NFS, which a test can't easily reproduce.
    $script = 'require $argv[1]; $l = new Programster\Log\CsvFileLogger($argv[2]); '
        . 'for ($i = 0; $i < (int)$argv[4]; $i++) { '
        . '$l->info("p{$argv[3]}-{$i}", ["padding" => str_repeat("x", 100000)]); }';

    $processes = [];

    for ($p = 0; $p < $numProcesses; $p++)
    {
        $command = [PHP_BINARY, '-r', $script, $autoloadPath, $filepath, (string)$p, (string)$logsPerProcess];
        $processes[] = proc_open($command, [], $pipes);
    }

    foreach ($processes as $process)
    {
        if ($process === false)
        {
            throw new \RuntimeException("Couldn't start a logging process.");
        }

        SmokeTest::assertSame(0, proc_close($process), "a logging process's exit code");
    }

    $rows = $readCsvRows($filepath);
    SmokeTest::assertSame($numProcesses * $logsPerProcess, count($rows), "the number of rows");

    foreach ($rows as $row)
    {
        SmokeTest::assertSame(4, count($row), "the number of fields in a row");
        $context = json_decode((string)$row[3], true, 512, JSON_THROW_ON_ERROR);
        $padding = is_array($context) ? $context['padding'] : null;
        SmokeTest::assertSame(str_repeat("x", 100000), $padding, "the padding in a row's context");
    }
};

$smoke->run("CsvFileLogger: concurrent processes don't interleave rows", $inTempDirectory($testCsvConcurrency));

$testCsvEncoding = function (string $dir) use ($readCsvRows) : void {
    $filepath = "{$dir}/logs.csv";
    $logger = new CsvFileLogger($filepath);
    $logger->info("hello", ['key' => "bad \xB1 context"]);
    $context = json_decode((string)$readCsvRows($filepath)[0][3], true, 512, JSON_THROW_ON_ERROR);
    $key = is_array($context) ? $context['key'] : null;
    SmokeTest::assertSame("bad \u{FFFD} context", $key, "the invalid UTF-8 in the context");

    $logUnencodable = function () use ($logger) : void {
        $logger->info("Payment failed", ['amount' => NAN]);
    };

    $exception = SmokeTest::catchException(ExceptionFailedToEncodeLog::class, $logUnencodable);
    $expectedMessage = "Failed to JSON encode log message 'Payment failed': Inf and NaN cannot be JSON encoded";
    SmokeTest::assertSame($expectedMessage, $exception->getMessage(), "the exception's message");
    SmokeTest::assertSame(true, $exception->getPrevious() instanceof \JsonException, "the previous exception");
    SmokeTest::assertSame(1, count($readCsvRows($filepath)), "the number of rows after the unencodable log");
};

$smoke->run(
    "CsvFileLogger: replaces invalid UTF-8, and rejects context that can't be encoded",
    $inTempDirectory($testCsvEncoding)
);

$testCsvOpenFailure = function (string $dir) use ($captureWarnings) : void {
    $filepath = "{$dir}/missing-dir/logs.csv";

    $createLogger = function () use ($filepath) : void {
        new CsvFileLogger($filepath);
    };

    $exception = SmokeTest::catchException(ExceptionFailedToOpenFile::class, $createLogger);
    $pattern = "#Failed to open CSV log file '" . preg_quote($filepath, '#') . "'.*No such file or directory#";
    SmokeTest::assertSame(1, preg_match($pattern, $exception->getMessage()), "the message: {$exception->getMessage()}");
    SmokeTest::assertSame([], $captureWarnings($createLogger), "the warnings that reached the error handler");
};

$smoke->run(
    "CsvFileLogger: a file that can't be opened is reported with its path and the reason",
    $inTempDirectory($testCsvOpenFailure)
);

$testJsonArray = function (string $dir) use ($readJsonLogs) : void {
    $filepath = "{$dir}/logs.json";
    $logger = new JsonFileLogger($filepath);
    $logger->info("first", ['key' => 'value']);
    $logger->error("second");

    $logs = $readJsonLogs($filepath);
    $expected = [['info', 'first', ['key' => 'value']], ['error', 'second', []]];
    $actual = [];

    foreach ($logs as $log)
    {
        $actual[] = is_array($log) ? [$log['level'], $log['message'], $log['context']] : $log;
    }

    SmokeTest::assertSame($expected, $actual, "the logs' levels, messages and contexts");
};

$smoke->run("JsonFileLogger: writes the logs as a JSON array", $inTempDirectory($testJsonArray));

$testJsonAppends = function (string $dir) use ($readJsonLogs) : void {
    $existingFiles = [
        'empty file' => ["", []],
        'whitespace only' => ["  \n\n", []],
        'empty array' => ["[]", []],
        'empty array with whitespace' => ["[ \n ]\n", []],
        'this logger\'s format' => ["[\n{\"message\":\"old1\"},\n{\"message\":\"old2\"}\n]\n", ["old1", "old2"]],
        'no trailing newline' => ["[\n{\"message\":\"old1\"},\n{\"message\":\"old2\"}\n]", ["old1", "old2"]],
        'single line' => ['[{"message":"old1"},{"message":"old2"}]', ["old1", "old2"]],
        'pretty printed' => ["[\n    {\n        \"message\": \"old1\"\n    }\n]\n\n", ["old1"]],
        'windows line endings' => ["[\r\n{\"message\":\"old1\"}\r\n]\r\n", ["old1"]],
    ];

    foreach ($existingFiles as $name => [$existingContent, $expectedMessages])
    {
        $filepath = "{$dir}/logs.json";
        file_put_contents($filepath, $existingContent);
        $logger = new JsonFileLogger($filepath);
        $logger->info("new");
        $logger->info("newer");

        $messages = array_column($readJsonLogs($filepath), 'message');
        SmokeTest::assertSame([...$expectedMessages, "new", "newer"], $messages, "the messages, for a {$name}");
        unlink($filepath);
    }
};

$smoke->run("JsonFileLogger: appends to an existing JSON array in any formatting", $inTempDirectory($testJsonAppends));

$testJsonRefuses = function (string $dir) : void {
    $nonArrays = [
        'plain text' => "some log line\n",
        'JSON object' => '{"message":"old"}',
        'truncated array' => "[\n{\"message\":\"old1\"},\n{\"message\":\"ol",
        'only an opening bracket' => "[",
        'only a closing bracket' => "]",
    ];

    foreach ($nonArrays as $name => $existingContent)
    {
        $filepath = "{$dir}/logs.json";
        file_put_contents($filepath, $existingContent);
        $logger = new JsonFileLogger($filepath);

        $logNew = function () use ($logger) : void {
            $logger->info("new");
        };

        $exception = SmokeTest::catchException(ExceptionInvalidJsonLogFile::class, $logNew);
        $hasPath = str_contains($exception->getMessage(), $filepath);
        SmokeTest::assertSame(true, $hasPath, "whether the message has the path, for {$name}");
        SmokeTest::assertSame($existingContent, file_get_contents($filepath), "the file's contents, for {$name}");
        unlink($filepath);
    }
};

$smoke->run("JsonFileLogger: refuses to change a file that isn't a JSON array", $inTempDirectory($testJsonRefuses));

$testJsonRotation = function (string $dir) use ($readJsonLogs) : void {
    // E.g. logrotate moving the file away.
    $filepath = "{$dir}/logs.json";
    $logger = new JsonFileLogger($filepath);
    $logger->info("before");
    rename($filepath, "{$filepath}.1");
    $logger->info("after");

    SmokeTest::assertSame(["after"], array_column($readJsonLogs($filepath), 'message'), "the new file");
    SmokeTest::assertSame(["before"], array_column($readJsonLogs("{$filepath}.1"), 'message'), "the rotated file");
};

$smoke->run("JsonFileLogger: writes to a new file after the file is rotated", $inTempDirectory($testJsonRotation));

$testJsonLevels = function (string $dir) use ($readJsonLogs, $validLevels, $invalidLevels) : void {
    foreach ($validLevels as $name => [$level, $expectedLevel])
    {
        $filepath = "{$dir}/valid.json";
        $logger = new JsonFileLogger($filepath);
        $logger->log($level, "hello");
        $logs = $readJsonLogs($filepath);
        $writtenLevel = is_array($logs[0]) ? $logs[0]['level'] : null;
        SmokeTest::assertSame($expectedLevel, $writtenLevel, "the level written for a {$name}");
        unlink($filepath);
    }

    $filepath = "{$dir}/invalid.json";
    $logger = new JsonFileLogger($filepath);

    foreach ($invalidLevels as $name => $level)
    {
        $logInvalid = function () use ($logger, $level) : void {
            $logger->log($level, "hello");
        };

        // PSR-3 requires an InvalidArgumentException for an invalid level.
        $exception = SmokeTest::catchException(\Psr\Log\InvalidArgumentException::class, $logInvalid);
        SmokeTest::assertSame(ExceptionInvalidLogLevel::class, get_class($exception), "the class, for a {$name}");
    }

    SmokeTest::assertSame("", file_get_contents($filepath), "the file after logging with invalid levels");
};

$smoke->run("JsonFileLogger: accepts every level format, and rejects invalid ones", $inTempDirectory($testJsonLevels));

$testJsonEncoding = function (string $dir) use ($readJsonLogs) : void {
    $filepath = "{$dir}/logs.json";
    $logger = new JsonFileLogger($filepath);
    $logger->info("bad \xB1 message", ['key' => "bad \xB1 context"]);

    $message = new class implements \Stringable {
        public string $notTheMessage = "property";

        public function __toString() : string
        {
            return "the message";
        }
    };

    $logger->info($message);

    $logUnencodable = function () use ($logger) : void {
        $logger->info("Payment failed", ['amount' => INF]);
    };

    $exception = SmokeTest::catchException(ExceptionFailedToEncodeLog::class, $logUnencodable);
    $expectedMessage = "Failed to JSON encode log message 'Payment failed': Inf and NaN cannot be JSON encoded";
    SmokeTest::assertSame($expectedMessage, $exception->getMessage(), "the exception's message");
    SmokeTest::assertSame(true, $exception->getPrevious() instanceof \JsonException, "the previous exception");

    $logs = $readJsonLogs($filepath);
    $expected = [
        ['message' => "bad \u{FFFD} message", 'context' => ['key' => "bad \u{FFFD} context"]],
        ['message' => "the message", 'context' => []],
    ];

    $actual = [];

    foreach ($logs as $log)
    {
        $actual[] = is_array($log) ? ['message' => $log['message'], 'context' => $log['context']] : $log;
    }

    SmokeTest::assertSame($expected, $actual, "the logs, with nothing from the unencodable log");
};

$smoke->run(
    "JsonFileLogger: replaces invalid UTF-8, logs a Stringable as its string, and rejects what can't be encoded",
    $inTempDirectory($testJsonEncoding)
);

$testJsonOpenFailure = function (string $dir) use ($captureWarnings) : void {
    $filepath = "{$dir}/missing-dir/logs.json";

    $createLogger = function () use ($filepath) : void {
        new JsonFileLogger($filepath);
    };

    $exception = SmokeTest::catchException(ExceptionFailedToOpenFile::class, $createLogger);
    $pattern = "#Failed to open JSON log file '" . preg_quote($filepath, '#') . "'.*No such file or directory#";
    SmokeTest::assertSame(1, preg_match($pattern, $exception->getMessage()), "the message: {$exception->getMessage()}");
    SmokeTest::assertSame([], $captureWarnings($createLogger), "the warnings that reached the error handler");

    // The reason is still given when the application has its own error handler.
    $ignoreErrors = function (int $errno, string $errstr) : bool {
        return true;
    };

    set_error_handler($ignoreErrors);

    try
    {
        $exception = SmokeTest::catchException(ExceptionFailedToOpenFile::class, $createLogger);
        $hasReason = str_contains($exception->getMessage(), "No such file or directory");
        SmokeTest::assertSame(true, $hasReason, "whether the reason is given, with an application error handler");

        new JsonFileLogger("{$dir}/logs.json");
        $currentHandler = set_error_handler(null);
        restore_error_handler();
        SmokeTest::assertSame(true, $currentHandler === $ignoreErrors, "whether the application's handler is restored");
    }
    finally
    {
        restore_error_handler();
    }
};

$smoke->run(
    "JsonFileLogger: a file that can't be opened is reported with its path and the reason",
    $inTempDirectory($testJsonOpenFailure)
);

$testJsonDirectoryRemoved = function (string $dir) : void {
    $subDir = "{$dir}/sub-dir";
    mkdir($subDir);
    $filepath = "{$subDir}/logs.json";
    $logger = new JsonFileLogger($filepath);
    unlink($filepath);
    rmdir($subDir);

    $logHello = function () use ($logger) : void {
        $logger->info("hello");
    };

    $exception = SmokeTest::catchException(ExceptionFailedToOpenFile::class, $logHello);
    $pattern = "#Failed to open JSON log file '" . preg_quote($filepath, '#') . "'.*No such file or directory#";
    SmokeTest::assertSame(1, preg_match($pattern, $exception->getMessage()), "the message: {$exception->getMessage()}");
};

$smoke->run(
    "JsonFileLogger: a file that can no longer be opened when logging is reported",
    $inTempDirectory($testJsonDirectoryRemoved)
);

$testContextObjects = function (string $dir) use ($readJsonLogs, $readCsvRows) : void {
    $previous = new \LogicException("bad config", 7);
    $exception = new \RuntimeException("db down", 3, $previous);

    $stringable = new class implements \Stringable {
        public string $notTheString = "property";

        public function __toString() : string
        {
            return "the string";
        }
    };

    $jsonSerializable = new class implements \Stringable, \JsonSerializable {
        public function __toString() : string
        {
            return "not used";
        }

        public function jsonSerialize() : mixed
        {
            return ['serialized' => true];
        }
    };

    $context = [
        'exception' => $exception,
        'stringable' => $stringable,
        'json_serializable' => $jsonSerializable,
        'nested' => ['exception' => $previous],
    ];

    $jsonPath = "{$dir}/logs.json";
    (new JsonFileLogger($jsonPath))->error("Failed", $context);
    $logs = $readJsonLogs($jsonPath);
    $jsonContext = is_array($logs[0]) ? $logs[0]['context'] : null;

    $csvPath = "{$dir}/logs.csv";
    (new CsvFileLogger($csvPath))->error("Failed", $context);
    $csvContext = json_decode((string)$readCsvRows($csvPath)[0][3], true, 512, JSON_THROW_ON_ERROR);

    foreach (['JSON' => $jsonContext, 'CSV' => $csvContext] as $format => $written)
    {
        if (!is_array($written) || !is_array($written['exception']) || !is_array($written['nested']))
        {
            throw new \RuntimeException("The {$format} context wasn't written as an array.");
        }

        $writtenException = $written['exception'];
        SmokeTest::assertSame(\RuntimeException::class, $writtenException['class'], "{$format}: the class");
        SmokeTest::assertSame("db down", $writtenException['message'], "{$format}: the message");
        SmokeTest::assertSame(3, $writtenException['code'], "{$format}: the code");
        SmokeTest::assertSame(__FILE__, $writtenException['file'], "{$format}: the file");
        SmokeTest::assertSame(true, is_int($writtenException['line']), "{$format}: whether the line is an int");
        SmokeTest::assertSame(true, is_string($writtenException['trace']), "{$format}: whether there's a trace");
        $writtenPrevious = is_array($writtenException['previous']) ? $writtenException['previous'] : [];
        SmokeTest::assertSame("bad config", $writtenPrevious['message'] ?? null, "{$format}: the previous exception");
        $hasNoPrevious = (array_key_exists('previous', $writtenPrevious) && $writtenPrevious['previous'] === null);
        SmokeTest::assertSame(true, $hasNoPrevious, "{$format}: whether the previous's previous is null");
        SmokeTest::assertSame("the string", $written['stringable'], "{$format}: the Stringable");
        SmokeTest::assertSame(['serialized' => true], $written['json_serializable'], "{$format}: the JsonSerializable");
        $nested = is_array($written['nested']['exception']) ? $written['nested']['exception'] : [];
        SmokeTest::assertSame("bad config", $nested['message'] ?? null, "{$format}: a nested exception");
    }
};

$smoke->run(
    "file loggers: write an exception in the context, a Stringable as its string, and a JsonSerializable as it says",
    $inTempDirectory($testContextObjects)
);

$testUnreadableJsonFile = function (string $dir) : void {
    $filepath = "{$dir}/write-only.json";
    touch($filepath);
    chmod($filepath, 0200);

    // Root can read any file, so this can only be tested when not running as root. The tests run as an ordinary user
    // in testing/run-all-php-versions.sh (and so in CI), so it's always tested there.
    if (!is_readable($filepath))
    {
        $createLogger = function () use ($filepath) : void {
            new JsonFileLogger($filepath);
        };

        $exception = SmokeTest::catchException(ExceptionFailedToOpenFile::class, $createLogger);
        $hasReason = str_contains($exception->getMessage(), "Permission denied");
        SmokeTest::assertSame(true, $hasReason, "whether the reason is given: {$exception->getMessage()}");
    }
};

$smoke->run(
    "JsonFileLogger: a file that can be written to but not read is rejected when the logger is created",
    $inTempDirectory($testUnreadableJsonFile)
);

$testFileLogger = function (string $dir) use ($readCsvRows, $readJsonLogs) : void {
    $csvPath = "{$dir}/logs.csv";
    $csvLogger = new FileLogger($csvPath);
    $csvLogger->info("csv");
    SmokeTest::assertSame(["csv"], array_column($readCsvRows($csvPath), 2), "the CSV file's messages, by default");

    $jsonPath = "{$dir}/logs.json";
    $jsonLogger = new FileLogger($jsonPath, "Y", FileFormat::JSON);
    $jsonLogger->info("json");
    $logs = $readJsonLogs($jsonPath);
    SmokeTest::assertSame(["json"], array_column($logs, 'message'), "the JSON file's messages");
    SmokeTest::assertSame([date("Y")], array_column($logs, 'when'), "the JSON file's times");
};

$smoke->run("FileLogger: logs as CSV by default, or as JSON", $inTempDirectory($testFileLogger));

// Linux's /dev/full accepts being opened, but fails every write with "No space left on device".
$fullDevice = "/dev/full";

if (is_writable($fullDevice))
{
    $createFileLoggers = [
        'JSON' => function () use ($fullDevice) : LoggerInterface {
            return new JsonFileLogger($fullDevice);
        },
        'CSV' => function () use ($fullDevice) : LoggerInterface {
            return new CsvFileLogger($fullDevice);
        },
    ];

    foreach ($createFileLoggers as $format => $createLogger)
    {
        $testFullDisk = function () use ($createLogger, $captureWarnings, $fullDevice) : void {
            $logger = $createLogger();

            $logHello = function () use ($logger) : void {
                $logger->info("hello");
            };

            $exception = SmokeTest::catchException(ExceptionFailedToWriteFile::class, $logHello);
            // The reason depends on the version of PHP: e.g. PHP 8.2 can't seek in /dev/full, whereas later versions
            // fail with "No space left on device". Either way, PHP's own reason should be given.
            $pattern = "#Failed to write to (JSON|CSV) log file '{$fullDevice}': (?!unknown reason).+#";
            SmokeTest::assertSame(1, preg_match($pattern, $exception->getMessage()), "the message");
            SmokeTest::assertSame([], $captureWarnings($logHello), "the warnings that reached the error handler");

            $fallback = new RecordingLogger();
            $loggerFactory = new FixedLoggerFactory($logger);
            $failoverLogger = FailoverLogger::create($loggerFactory, new FixedLoggerFactory($fallback));
            $failoverLogger->info("hello");
            $logs = $fallback->getLogs();
            SmokeTest::assertSame("hello", $logs[0]['message'], "the fallback's first message");
            $exceptionClass = $logs[1]['context']['exception_class'];
            SmokeTest::assertSame(ExceptionFailedToWriteFile::class, $exceptionClass, "the failure's exception");
        };

        $smoke->run("{$format} file logger: a full disk is reported, and failed over", $testFullDisk);
    }
}
