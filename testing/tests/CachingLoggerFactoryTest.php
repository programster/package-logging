<?php

/*
 * Smoke tests for CachingLoggerFactory. These are run by testing/main.php, which provides $smoke.
 */

use Programster\Log\CachingLoggerFactory;
use Programster\Log\Exceptions\ExceptionInvalidLoggerCreator;
use Programster\Log\FailoverLogger;
use Programster\Log\RetryDelay;
use Programster\Log\Testing\Doubles\FixedLoggerFactory;
use Programster\Log\Testing\Doubles\RecordingLogger;
use Programster\Log\Testing\SmokeTest;
use Psr\Log\LoggerInterface;

/** @var SmokeTest $smoke */

$testCaches = function () : void {
    $numCreated = 0;

    $createLogger = function () use (&$numCreated) : LoggerInterface {
        $numCreated++;
        return new RecordingLogger();
    };

    $factory = new CachingLoggerFactory($createLogger);
    $first = $factory->fetchLogger();
    $second = $factory->fetchLogger();

    SmokeTest::assertSame(true, $first === $second, "whether the same logger is returned each time");
    SmokeTest::assertSame(1, $numCreated, "the number of loggers created");
};

$smoke->run("CachingLoggerFactory: creates the logger the first time, and then reuses it", $testCaches);

$testRetriesAfterThrowing = function () : void {
    $numAttempts = 0;
    $primaryLogger = new RecordingLogger();

    // Fails the first time (e.g. the database is down), then works.
    $createLogger = function () use (&$numAttempts, $primaryLogger) : RecordingLogger {
        $numAttempts++;

        if ($numAttempts === 1)
        {
            throw new \RuntimeException("db is down");
        }

        return $primaryLogger;
    };

    $primaryFactory = new CachingLoggerFactory($createLogger);
    $fallback = new RecordingLogger();
    $logger = FailoverLogger::create($primaryFactory, new FixedLoggerFactory($fallback));
    $logger = $logger->withRetryDelay(RetryDelay::createNone());
    $logger->info("first");
    $logger->info("second");
    $logger->info("third");

    $failureMessage = "FailoverLogger: logger factory " . CachingLoggerFactory::class
        . " failed to provide a logger: db is down";

    SmokeTest::assertSame(["first", $failureMessage], $fallback->getMessages(), "the fallback's messages");
    SmokeTest::assertSame(["second", "third"], $primaryLogger->getMessages(), "the primary's messages");
    SmokeTest::assertSame(2, $numAttempts, "the number of attempts to create the logger");
};

$smoke->run("CachingLoggerFactory: tries to create the logger again if it threw", $testRetriesAfterThrowing);

$testRejectsBadClosures = function () : void {
    $badClosures = [
        'no return type' => function () {
            return new RecordingLogger();
        },
        'a nullable return type' => function () : ?LoggerInterface {
            return new RecordingLogger();
        },
        'a return type that is not a logger' => function () : \stdClass {
            return new \stdClass();
        },
        'a union return type' => function () : RecordingLogger|\stdClass {
            return new RecordingLogger();
        },
        'a required parameter' => function (string $path) : LoggerInterface {
            return new RecordingLogger();
        },
    ];

    foreach ($badClosures as $name => $closure)
    {
        $createFactory = function () use ($closure) : void {
            new CachingLoggerFactory($closure);
        };

        $exception = SmokeTest::catchException(ExceptionInvalidLoggerCreator::class, $createFactory);
        SmokeTest::assertSame(true, $exception->getMessage() !== "", "whether there's a message, for {$name}");
    }
};

$smoke->run(
    "CachingLoggerFactory: rejects a closure that can't be relied on to return a logger",
    $testRejectsBadClosures
);

$testAcceptsOptionalParameter = function () : void {
    $createLogger = function (string $prefix = "") : RecordingLogger {
        return new RecordingLogger();
    };

    $factory = new CachingLoggerFactory($createLogger);
    SmokeTest::assertSame(true, $factory->fetchLogger() instanceof RecordingLogger, "the logger");
};

$smoke->run("CachingLoggerFactory: accepts a closure with only optional parameters", $testAcceptsOptionalParameter);
