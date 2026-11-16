<?php

/*
 * Smoke tests for RetryDelay, and the FailoverLogger's skipping of a logger for its retry delay after it fails.
 * These use real (short) delays, so leave generous margins either side of each timeout.
 * These are run by testing/main.php, which provides $smoke.
 */

use Programster\Log\Collections\LoggerFailureCollection;
use Programster\Log\Exceptions\ExceptionAllLoggersFailed;
use Programster\Log\Exceptions\ExceptionInvalidRetryDelay;
use Programster\Log\FailoverLogger;
use Programster\Log\RetryDelay;
use Programster\Log\Testing\Doubles\FailingLogger;
use Programster\Log\Testing\Doubles\FixedLoggerFactory;
use Programster\Log\Testing\Doubles\RecordingFailureHandler;
use Programster\Log\Testing\Doubles\RecordingLogger;
use Programster\Log\Testing\Doubles\SwitchableLoggerFactory;
use Programster\Log\Testing\SmokeTest;

/** @var SmokeTest $smoke */

/**
 * Get the message of each failure's exception, in order.
 * @param LoggerFailureCollection $failures - the failures.
 * @return list<string>
 */
$getFailureMessages = function (LoggerFailureCollection $failures) : array {
    $messages = [];

    foreach ($failures as $failure)
    {
        $messages[] = $failure->getThrowable()->getMessage();
    }

    return $messages;
};

$testCreators = function () : void {
    $delay = RetryDelay::createUsingSeconds(1.5);
    SmokeTest::assertSame(1.5, $delay->getSeconds(), "createUsingSeconds(1.5)");
    SmokeTest::assertSame(false, $delay->shouldRetrySkippedLoggersIfAllFail(), "the default for retrying");
    SmokeTest::assertSame(10.0, RetryDelay::createUsingSeconds(10)->getSeconds(), "createUsingSeconds(10)");
    $retrying = RetryDelay::createUsingSeconds(2, retrySkippedLoggersIfAllFail: true);
    SmokeTest::assertSame(true, $retrying->shouldRetrySkippedLoggersIfAllFail(), "retrySkippedLoggersIfAllFail");
    SmokeTest::assertSame(3.0, RetryDelay::createDefault()->getSeconds(), "createDefault()");
    SmokeTest::assertSame(false, RetryDelay::createDefault()->shouldRetrySkippedLoggersIfAllFail(), "the default");
    SmokeTest::assertSame(0.0, RetryDelay::createNone()->getSeconds(), "createNone()");
};

$smoke->run("RetryDelay: creators", $testCreators);

$testInvalidSeconds = function () : void {
    foreach ([0, -1, INF, NAN] as $seconds)
    {
        $createDelay = function () use ($seconds) : void {
            RetryDelay::createUsingSeconds($seconds);
        };

        SmokeTest::expectException(ExceptionInvalidRetryDelay::class, $createDelay, "RetryDelay::createNone()");
    }
};

$smoke->run("RetryDelay: rejects seconds that aren't finite and more than 0", $testInvalidSeconds);

$testSkipped = function () : void {
    $primary = new SwitchableLoggerFactory("primary", failing: true);
    $fallback = new SwitchableLoggerFactory("fallback");
    $logger = FailoverLogger::create($primary, $fallback)->withRetryDelay(RetryDelay::createUsingSeconds(60));

    $logger->info("one");
    $primary->setFailing(false); // Even if it has recovered, it is not retried until the delay is up.
    $logger->info("two");
    $logger->info("three");

    SmokeTest::assertSame(1, $primary->getCallCount(), "the calls to the primary, which is then in its timeout");
    SmokeTest::assertSame(["one", "two", "three"], $fallback->getLoggedMessages(), "the fallback's messages");
};

$smoke->run("retry delay: a failed logger is skipped during its retry delay", $testSkipped);

$testLogFailureSkipped = function () : void {
    // Fails in log() rather than in fetchLogger().
    $primary = new FixedLoggerFactory(new FailingLogger());
    $fallback = new RecordingLogger();
    $logger = FailoverLogger::create($primary, new FixedLoggerFactory($fallback));
    $logger = $logger->withRetryDelay(RetryDelay::createUsingSeconds(60));
    $logger->info("one");
    $logger->info("two");

    SmokeTest::assertSame(1, $primary->getCallCount(), "the calls to the primary");
    SmokeTest::assertSame(["one", "two"], $fallback->getOwnMessages(), "the fallback's messages");
};

$smoke->run("retry delay: a logger that fails whilst logging is also skipped", $testLogFailureSkipped);

$testSkippingIsNotAFailure = function () : void {
    $handler = new RecordingFailureHandler();
    $fallback = new RecordingLogger();
    $primary = new SwitchableLoggerFactory("primary", failing: true);
    $logger = FailoverLogger::create($primary, new FixedLoggerFactory($fallback));
    $logger = $logger->withFailureHandler($handler)->withRetryDelay(RetryDelay::createUsingSeconds(60));
    $logger->info("one");
    $logger->info("two");

    $failureMessage = "FailoverLogger: logger factory " . SwitchableLoggerFactory::class
        . " failed to provide a logger: primary is down";

    SmokeTest::assertSame(["one", $failureMessage, "two"], $fallback->getMessages(), "the fallback's messages");
    SmokeTest::assertSame(1, count($handler->getCalls()), "the handler's calls, as skipping isn't a failure");
};

$smoke->run("retry delay: a failure is only reported when the logger actually fails", $testSkippingIsNotAFailure);

$testRetriedAfterDelay = function () : void {
    $primary = new SwitchableLoggerFactory("primary", failing: true);
    $fallback = new SwitchableLoggerFactory("fallback");
    $logger = FailoverLogger::create($primary, $fallback)->withRetryDelay(RetryDelay::createUsingSeconds(0.2));
    $logger->info("one");
    $primary->setFailing(false);
    usleep(300_000);
    $logger->info("two");

    SmokeTest::assertSame(2, $primary->getCallCount(), "the calls to the primary");
    SmokeTest::assertSame(["two"], $primary->getLoggedMessages(), "the primary's messages");
    SmokeTest::assertSame(["one"], $fallback->getLoggedMessages(), "the fallback's messages");
};

$smoke->run("retry delay: a logger is tried again once its delay has passed", $testRetriedAfterDelay);

$testOwnTimeouts = function () : void {
    // The primary fails, and logs go to the secondary. The secondary then fails whilst the primary is still in its
    // timeout, so logs go to the tertiary. The primary's timeout ends before the secondary's, so the primary is tried
    // again, and when it fails, logs go straight to the tertiary.
    $primary = new SwitchableLoggerFactory("primary", failing: true);
    $secondary = new SwitchableLoggerFactory("secondary");
    $tertiary = new SwitchableLoggerFactory("tertiary");
    $logger = FailoverLogger::create($primary, $secondary, $tertiary);
    $logger = $logger->withRetryDelay(RetryDelay::createUsingSeconds(0.6));

    // t = 0: primary fails, so is unavailable until t = 0.6.
    $logger->info("one");

    // t = 0.2: secondary fails, so is unavailable until t = 0.8. Primary is skipped.
    usleep(200_000);
    $secondary->setFailing(true);
    $logger->info("two");

    // t = 0.65: primary's timeout is over, so it is tried, and fails again. Secondary is still in its timeout.
    usleep(450_000);
    $secondary->setFailing(false);
    $logger->info("three");

    SmokeTest::assertSame(2, $primary->getCallCount(), "the calls to the primary, at t = 0 and t = 0.65");
    SmokeTest::assertSame(2, $secondary->getCallCount(), "the calls to the secondary, at t = 0 and t = 0.2");
    SmokeTest::assertSame(["one"], $secondary->getLoggedMessages(), "the secondary's messages");
    SmokeTest::assertSame(["two", "three"], $tertiary->getLoggedMessages(), "the tertiary's messages");
};

$smoke->run("retry delay: each logger has its own timeout", $testOwnTimeouts);

$testSkippedEvenIfOthersFail = function () use ($getFailureMessages) : void {
    $primary = new SwitchableLoggerFactory("primary", failing: true);
    $fallback = new SwitchableLoggerFactory("fallback");
    $handler = new RecordingFailureHandler();
    $logger = FailoverLogger::create($primary, $fallback);
    $logger = $logger->withFailureHandler($handler)->withRetryDelay(RetryDelay::createUsingSeconds(60));
    $logger->info("one");

    // The fallback now fails too, whilst the primary is in its timeout. The primary has recovered, but is skipped.
    $fallback->setFailing(true);
    $primary->setFailing(false);

    $logTwo = function () use ($logger) : void {
        $logger->info("two");
    };

    $exception = SmokeTest::catchException(ExceptionAllLoggersFailed::class, $logTwo);
    SmokeTest::assertSame(1, $primary->getCallCount(), "the calls to the primary, whilst in its timeout");
    SmokeTest::assertSame([], $primary->getLoggedMessages(), "the primary's messages");

    $expectedMessage = "No logger was able to log the message, as 1 failed and 1 were skipped for being within their "
        . "retry delay: two";

    SmokeTest::assertSame($expectedMessage, $exception->getMessage(), "the exception's message");
    $failureMessages = $getFailureMessages($exception->getFailures());
    SmokeTest::assertSame(["fallback is down"], $failureMessages, "the exception's failures");

    $calls = $handler->getCalls();
    SmokeTest::assertSame(2, count($calls), "the number of calls to the handler");
    SmokeTest::assertSame(false, $calls[1]['messageWasLogged'], "messageWasLogged");
};

$smoke->run(
    "retry delay: by default, a logger in its timeout isn't tried, even if all the others fail",
    $testSkippedEvenIfOthersFail
);

$testAllInTimeout = function () : void {
    $primary = new SwitchableLoggerFactory("primary", failing: true);
    $fallback = new SwitchableLoggerFactory("fallback", failing: true);
    $handler = new RecordingFailureHandler();
    $logger = FailoverLogger::create($primary, $fallback);
    $logger = $logger->withFailureHandler($handler)->withRetryDelay(RetryDelay::createUsingSeconds(60));

    $logOne = function () use ($logger) : void {
        $logger->info("one");
    };

    $logTwo = function () use ($logger) : void {
        $logger->info("two");
    };

    // Both are then in their timeout.
    SmokeTest::expectException(ExceptionAllLoggersFailed::class, $logOne);
    $exception = SmokeTest::catchException(ExceptionAllLoggersFailed::class, $logTwo);

    SmokeTest::assertSame(1, $primary->getCallCount(), "the calls to the primary");
    SmokeTest::assertSame(1, $fallback->getCallCount(), "the calls to the fallback");

    $expectedMessage = "No logger was able to log the message, as 0 failed and 2 were skipped for being within their "
        . "retry delay: two";

    SmokeTest::assertSame($expectedMessage, $exception->getMessage(), "the exception's message");
    SmokeTest::assertSame(true, $exception->getFailures()->isEmpty(), "whether the exception has no failures");
    SmokeTest::assertSame(null, $exception->getPrevious(), "the previous exception");
    SmokeTest::assertSame(1, count($handler->getCalls()), "the handler's calls, as nothing failed the second time");
};

$smoke->run("retry delay: throws without trying any logger when all are in their timeout", $testAllInTimeout);

$testLastResortRecovered = function () use ($getFailureMessages) : void {
    $primary = new SwitchableLoggerFactory("primary", failing: true);
    $fallback = new SwitchableLoggerFactory("fallback");
    $handler = new RecordingFailureHandler();
    $retryDelay = RetryDelay::createUsingSeconds(60, retrySkippedLoggersIfAllFail: true);
    $logger = FailoverLogger::create($primary, $fallback)->withFailureHandler($handler)->withRetryDelay($retryDelay);
    $logger->info("one");

    // The fallback now fails too, whilst the primary is in its timeout. The primary has recovered, so as a last
    // resort, it logs the message.
    $fallback->setFailing(true);
    $primary->setFailing(false);
    $logger->info("two");

    SmokeTest::assertSame(["two"], $primary->getLoggedMessages(), "the primary's messages");
    SmokeTest::assertSame(2, $primary->getCallCount(), "the calls to the primary");
    $calls = $handler->getCalls();
    SmokeTest::assertSame(true, $calls[1]['messageWasLogged'], "messageWasLogged");
    SmokeTest::assertSame(["fallback is down"], $getFailureMessages($calls[1]['failures']), "the failures");

    // The primary worked, so it's no longer in its timeout, and is tried first again.
    $logger->info("three");
    SmokeTest::assertSame(["two", "three"], $primary->getLoggedMessages(), "the primary's messages, afterwards");
    SmokeTest::assertSame(2, $fallback->getCallCount(), "the calls to the fallback, which is in its timeout");
};

$smoke->run(
    "retry delay: with retrySkippedLoggersIfAllFail, a skipped logger is tried as a last resort",
    $testLastResortRecovered
);

$testLastResortAllFail = function () use ($getFailureMessages) : void {
    $primary = new SwitchableLoggerFactory("primary", failing: true);
    $fallback = new SwitchableLoggerFactory("fallback", failing: true);
    $retryDelay = RetryDelay::createUsingSeconds(60, retrySkippedLoggersIfAllFail: true);
    $logger = FailoverLogger::create($primary, $fallback)->withRetryDelay($retryDelay);

    $logOne = function () use ($logger) : void {
        $logger->info("one");
    };

    $logTwo = function () use ($logger) : void {
        $logger->info("two");
    };

    SmokeTest::expectException(ExceptionAllLoggersFailed::class, $logOne);
    $exception = SmokeTest::catchException(ExceptionAllLoggersFailed::class, $logTwo);

    SmokeTest::assertSame(2, $primary->getCallCount(), "the calls to the primary");
    SmokeTest::assertSame(2, $fallback->getCallCount(), "the calls to the fallback");
    $expectedMessage = "All 2 loggers failed to log the message: two";
    SmokeTest::assertSame($expectedMessage, $exception->getMessage(), "the exception's message");
    $failureMessages = $getFailureMessages($exception->getFailures());
    SmokeTest::assertSame(["primary is down", "fallback is down"], $failureMessages, "the failures, in order");
};

$smoke->run(
    "retry delay: with retrySkippedLoggersIfAllFail, every logger is tried before throwing",
    $testLastResortAllFail
);

$testLastResortOrder = function () : void {
    // The skipped loggers are only tried after every available logger has failed.
    $primary = new SwitchableLoggerFactory("primary", failing: true);
    $secondary = new SwitchableLoggerFactory("secondary");
    $retryDelay = RetryDelay::createUsingSeconds(60, retrySkippedLoggersIfAllFail: true);
    $logger = FailoverLogger::create($primary, $secondary)->withRetryDelay($retryDelay);
    $logger->info("one");
    $primary->setFailing(false);
    $logger->info("two");

    SmokeTest::assertSame(1, $primary->getCallCount(), "the calls to the primary, as the secondary worked");
    SmokeTest::assertSame(["one", "two"], $secondary->getLoggedMessages(), "the secondary's messages");
};

$smoke->run(
    "retry delay: with retrySkippedLoggersIfAllFail, a skipped logger isn't tried while another works",
    $testLastResortOrder
);

$testDefaultDelay = function () : void {
    $primary = new SwitchableLoggerFactory("primary", failing: true);
    $logger = FailoverLogger::create($primary, new SwitchableLoggerFactory("fallback"));
    $logger->info("one");
    $primary->setFailing(false);
    $logger->info("two");

    SmokeTest::assertSame(1, $primary->getCallCount(), "the calls to the primary, with the default delay of 3 seconds");
};

$smoke->run("retry delay: a failed logger is skipped by default", $testDefaultDelay);

$testNoDelay = function () : void {
    $primary = new SwitchableLoggerFactory("primary", failing: true);
    $logger = FailoverLogger::create($primary, new SwitchableLoggerFactory("fallback"));
    $logger = $logger->withRetryDelay(RetryDelay::createNone());
    $logger->info("one");
    $logger->info("two");
    $logger->info("three");

    SmokeTest::assertSame(3, $primary->getCallCount(), "the calls to the primary");
};

$smoke->run("retry delay: with no delay, a failed logger is tried on every log", $testNoDelay);
