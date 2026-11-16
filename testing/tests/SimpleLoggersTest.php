<?php

/*
 * Smoke tests for LogLevel, and the loggers that wrap another logger: AddedContextLogger, MinLevelLogger, MultiLogger
 * and PrefixLogger. These are run by testing/main.php, which provides $smoke.
 */

use Programster\Log\AddedContextLogger;
use Programster\Log\Exceptions\ExceptionContextRequired;
use Programster\Log\Exceptions\ExceptionInvalidLogLevel;
use Programster\Log\LogLevel;
use Programster\Log\MinLevelLogger;
use Programster\Log\MultiLogger;
use Programster\Log\PrefixLogger;
use Programster\Log\Testing\Doubles\FailingLogger;
use Programster\Log\Testing\Doubles\RecordingLogger;
use Programster\Log\Testing\SmokeTest;

/** @var SmokeTest $smoke */

$testLevelNumbers = function () : void {
    $levels = [];

    foreach (range(0, 7) as $number)
    {
        $level = LogLevel::createFromInt($number);
        SmokeTest::assertSame($number, $level->toInt(), "toInt() of createFromInt({$number})");
        $levels[] = $level->value;
    }

    $expected = ['debug', 'info', 'notice', 'warning', 'error', 'critical', 'alert', 'emergency'];
    SmokeTest::assertSame($expected, $levels, "the levels from 0 to 7");
};

$smoke->run("LogLevel: createFromInt() and toInt() go from 0 (debug) to 7 (emergency)", $testLevelNumbers);

$testInvalidLevelNumbers = function () : void {
    foreach ([-1, 8] as $number)
    {
        $createLevel = function () use ($number) : void {
            LogLevel::createFromInt($number);
        };

        // PSR-3 requires an InvalidArgumentException for an invalid level.
        $exception = SmokeTest::catchException(\Psr\Log\InvalidArgumentException::class, $createLevel);
        SmokeTest::assertSame(ExceptionInvalidLogLevel::class, get_class($exception), "the class, for {$number}");
    }
};

$smoke->run("LogLevel: createFromInt() rejects numbers outside 0 to 7", $testInvalidLevelNumbers);

$testLevelStrings = function () : void {
    SmokeTest::assertSame(LogLevel::WARNING, LogLevel::createFromString("warning"), "lowercase");
    SmokeTest::assertSame(LogLevel::WARNING, LogLevel::createFromString("WARNING"), "uppercase");
    SmokeTest::assertSame(LogLevel::ERROR, LogLevel::createFromString("Error"), "mixed case");

    $createLoud = function () : void {
        LogLevel::createFromString("loud");
    };

    SmokeTest::expectException(ExceptionInvalidLogLevel::class, $createLoud, "Value needs to be one of emergency");
};

$smoke->run("LogLevel: createFromString() ignores case, and rejects unknown levels", $testLevelStrings);

$testAddedContext = function () : void {
    $wrappedLogger = new RecordingLogger();
    $addedContext = ['script' => 'import', 'stage' => 'added'];
    $logger = new AddedContextLogger($wrappedLogger, $addedContext);
    $logger->notice("first");
    $logger->info("second", ['rows' => 5]);
    $logger->error("third", ['stage' => 'provided']);

    $expected = [
        ['level' => 'notice', 'message' => "first", 'context' => $addedContext],
        ['level' => 'info', 'message' => "second", 'context' => [...$addedContext, 'rows' => 5]],
        ['level' => 'error', 'message' => "third", 'context' => ['script' => 'import', 'stage' => 'provided']],
    ];

    SmokeTest::assertSame($expected, $wrappedLogger->getLogs(), "the logs, with the context merged");
};

$smoke->run(
    "AddedContextLogger: adds its context to every log, which the log's own context overrides",
    $testAddedContext
);

$testEmptyAddedContext = function () : void {
    $createLogger = function () : void {
        new AddedContextLogger(new RecordingLogger(), []);
    };

    SmokeTest::expectException(ExceptionContextRequired::class, $createLogger);
};

$smoke->run("AddedContextLogger: rejects empty context, as it would add nothing", $testEmptyAddedContext);

$testMinLevel = function () : void {
    $wrappedLogger = new RecordingLogger();
    $logger = new MinLevelLogger(LogLevel::WARNING, $wrappedLogger);
    $logger->info("info");
    $logger->notice("notice");
    $logger->log("WARNING", "warning");
    $logger->log(LogLevel::ERROR, "error");
    $logger->log(7, "emergency");

    $levels = array_column($wrappedLogger->getLogs(), 'level');
    SmokeTest::assertSame(['warning', 'error', 'emergency'], $levels, "the levels passed on, as strings");
};

$smoke->run("MinLevelLogger: only passes on logs at or above the threshold", $testMinLevel);

$testMinLevelInvalidLevel = function () : void {
    $wrappedLogger = new RecordingLogger();
    $logger = new MinLevelLogger(LogLevel::DEBUG, $wrappedLogger);

    $logLoud = function () use ($logger) : void {
        $logger->log("loud", "hello");
    };

    SmokeTest::expectException(ExceptionInvalidLogLevel::class, $logLoud);
    SmokeTest::assertSame([], $wrappedLogger->getLogs(), "the logs");
};

$smoke->run("MinLevelLogger: rejects an invalid level when logging", $testMinLevelInvalidLevel);

$testMultiLogger = function () : void {
    $first = new RecordingLogger();
    $second = new RecordingLogger();
    $logger = new MultiLogger($first, $second);
    $logger->info("hello", ['key' => 'value']);

    $expected = [['level' => 'info', 'message' => "hello", 'context' => ['key' => 'value']]];
    SmokeTest::assertSame($expected, $first->getLogs(), "the first logger's logs");
    SmokeTest::assertSame($expected, $second->getLogs(), "the second logger's logs");

    $spreadLogger = new MultiLogger(...[$first]);
    $spreadLogger->info("spread");
    SmokeTest::assertSame("spread", $first->getLogs()[1]['message'], "a logger given by spreading an array");
};

$smoke->run("MultiLogger: logs to every logger", $testMultiLogger);

$testMultiLoggerNeedsALogger = function () : void {
    $createEmpty = function () : void {
        // @phpstan-ignore arguments.count (deliberately given no loggers, to check it's rejected)
        new MultiLogger();
    };

    $spreadEmpty = function () : void {
        $loggers = [];
        // @phpstan-ignore arguments.count (deliberately given no loggers, to check it's rejected)
        new MultiLogger(...$loggers);
    };

    SmokeTest::expectException(\ArgumentCountError::class, $createEmpty);
    SmokeTest::expectException(\ArgumentCountError::class, $spreadEmpty);
};

$smoke->run(
    "MultiLogger: rejects being given no loggers, as it would throw every log away",
    $testMultiLoggerNeedsALogger
);

$testMultiLoggerFailure = function () : void {
    $after = new RecordingLogger();
    $logger = new MultiLogger(new FailingLogger("broken"), $after);

    $logHello = function () use ($logger) : void {
        $logger->info("hello");
    };

    SmokeTest::expectException(\RuntimeException::class, $logHello, "broken");
    SmokeTest::assertSame([], $after->getLogs(), "the logs of the logger after the one that failed");
};

$smoke->run("MultiLogger: passes on what a logger throws", $testMultiLoggerFailure);

$testPrefix = function () : void {
    $wrappedLogger = new RecordingLogger();
    $logger = new PrefixLogger("[my-service] ", $wrappedLogger);
    $logger->warning("hello", ['key' => 'value']);

    $expected = [['level' => 'warning', 'message' => "[my-service] hello", 'context' => ['key' => 'value']]];
    SmokeTest::assertSame($expected, $wrappedLogger->getLogs(), "the logs");
};

$smoke->run("PrefixLogger: adds its prefix to every message", $testPrefix);
