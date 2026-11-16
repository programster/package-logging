<?php

/*
 * Smoke tests for LogLevel, and the loggers that wrap another logger: AddedContextLogger, MinLevelLogger, MultiLogger
 * and PrefixLogger. These are run by testing/main.php, which provides $smoke.
 */

use Programster\Log\AddedContextLogger;
use Programster\Log\Collections\MultiLoggerFailureCollection;
use Programster\Log\Exceptions\ExceptionContextRequired;
use Programster\Log\Exceptions\ExceptionInvalidLogLevel;
use Programster\Log\Exceptions\ExceptionMultiLoggerFailed;
use Programster\Log\LogLevel;
use Programster\Log\MinLevelLogger;
use Programster\Log\MultiLogger;
use Programster\Log\MultiLoggerFailure;
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

$testAddedContextIntegerKeys = function () : void {
    $wrappedLogger = new RecordingLogger();
    $logger = new AddedContextLogger($wrappedLogger, ['added', 'script' => 'import']);
    $logger->info("hello", ['given']);

    $expected = ['given', 'script' => 'import'];
    SmokeTest::assertSame($expected, $wrappedLogger->getLogs()[0]['context'], "the context");
};

$smoke->run("AddedContextLogger: the log's own context overrides integer keys too", $testAddedContextIntegerKeys);

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
    $first = new RecordingLogger();
    $broken = new FailingLogger("broken");
    $last = new RecordingLogger();
    $logger = new MultiLogger($first, $broken, $last, new FailingLogger("also broken"));

    $logHello = function () use ($logger) : void {
        $logger->info("hello");
    };

    $exception = SmokeTest::catchException(ExceptionMultiLoggerFailed::class, $logHello);
    SmokeTest::assertSame(["hello"], $first->getMessages(), "the messages of the logger before the broken one");
    SmokeTest::assertSame(["hello"], $last->getMessages(), "the messages of the logger after the broken one");

    $expectedMessage = "2 of 4 loggers failed to log the message 'hello', the first because: broken";
    SmokeTest::assertSame($expectedMessage, $exception->getMessage(), "the exception's message");
    $failures = $exception->getFailures();
    SmokeTest::assertSame(2, count($failures), "the number of failures");
    SmokeTest::assertSame(1, $failures->get(0)->getPosition(), "the first failure's position");
    SmokeTest::assertSame(true, $failures->get(0)->getLogger() === $broken, "the first failure's logger");
    SmokeTest::assertSame("broken", $failures->get(0)->getThrowable()->getMessage(), "the first failure's exception");
    SmokeTest::assertSame(3, $failures->get(1)->getPosition(), "the second failure's position");
    SmokeTest::assertSame(true, $exception->getPrevious() === $failures->get(0)->getThrowable(), "the previous");
    SmokeTest::assertSame(4, $exception->getNumLoggers(), "getNumLoggers()");
    SmokeTest::assertSame(false, $exception->didEveryLoggerFail(), "didEveryLoggerFail()");
};

$smoke->run(
    "MultiLogger: logs to every logger it can, and then throws for the ones that failed",
    $testMultiLoggerFailure
);

$testMultiLoggerAllFail = function () : void {
    $logger = new MultiLogger(new FailingLogger("first"), new FailingLogger("second"));

    $logHello = function () use ($logger) : void {
        $logger->info("hello");
    };

    $exception = SmokeTest::catchException(ExceptionMultiLoggerFailed::class, $logHello);
    SmokeTest::assertSame(true, $exception->didEveryLoggerFail(), "didEveryLoggerFail()");
    SmokeTest::assertSame(2, count($exception->getFailures()), "the number of failures");
};

$smoke->run("MultiLogger: throws when every logger fails", $testMultiLoggerAllFail);

$testMultiLoggerLevels = function () : void {
    $wrappedLogger = new RecordingLogger();
    $logger = new MultiLogger($wrappedLogger);
    $logger->log("WARNING", "a");
    $logger->log(LogLevel::ERROR, "b");
    $logger->log(7, "c");
    $levels = array_column($wrappedLogger->getLogs(), 'level');
    SmokeTest::assertSame(['warning', 'error', 'emergency'], $levels, "the levels passed on");

    $logLoud = function () use ($logger) : void {
        $logger->log("loud", "d");
    };

    // PSR-3 requires an InvalidArgumentException for an invalid level, rather than every logger failing.
    $exception = SmokeTest::catchException(\Psr\Log\InvalidArgumentException::class, $logLoud);
    SmokeTest::assertSame(ExceptionInvalidLogLevel::class, get_class($exception), "the exception's class");
    SmokeTest::assertSame(3, count($wrappedLogger->getLogs()), "the number of logs, after the invalid level");
};

$smoke->run("MultiLogger: passes the level on as a string, and rejects an invalid one", $testMultiLoggerLevels);

$testMultiLoggerExceptionChecks = function () : void {
    $createWithNoFailures = function () : void {
        new ExceptionMultiLoggerFailed("message", new MultiLoggerFailureCollection(), 2);
    };

    $createWithTooManyFailures = function () : void {
        $failure = new MultiLoggerFailure(0, new RecordingLogger(), new \RuntimeException("broken"));
        new ExceptionMultiLoggerFailed("message", new MultiLoggerFailureCollection($failure, $failure), 1);
    };

    SmokeTest::expectException(\LogicException::class, $createWithNoFailures, "at least one failure");
    SmokeTest::expectException(\LogicException::class, $createWithTooManyFailures, "more failures");
};

$smoke->run(
    "ExceptionMultiLoggerFailed: rejects no failures, or more failures than loggers",
    $testMultiLoggerExceptionChecks
);

$testPrefix = function () : void {
    $wrappedLogger = new RecordingLogger();
    $logger = new PrefixLogger("[my-service] ", $wrappedLogger);
    $logger->warning("hello", ['key' => 'value']);

    $expected = [['level' => 'warning', 'message' => "[my-service] hello", 'context' => ['key' => 'value']]];
    SmokeTest::assertSame($expected, $wrappedLogger->getLogs(), "the logs");
};

$smoke->run("PrefixLogger: adds its prefix to every message", $testPrefix);
