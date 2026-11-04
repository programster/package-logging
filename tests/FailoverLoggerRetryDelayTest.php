<?php

/*
 * Tests for the FailoverLogger's retry delay, which skips a logger for a period after it fails.
 * These use real (short) delays, so leave generous margins either side of each timeout.
 */

declare(strict_types = 1);
namespace Programster\Log\Tests;


use PHPUnit\Framework\TestCase;
use Programster\Log\Exceptions\ExceptionAllLoggersFailed;
use Programster\Log\Exceptions\ExceptionInvalidRetryDelay;
use Programster\Log\FailoverLogger;
use Programster\Log\Tests\Doubles\FailingLogger;
use Programster\Log\Tests\Doubles\FixedLoggerFactory;
use Programster\Log\Tests\Doubles\RecordingFailureHandler;
use Programster\Log\Tests\Doubles\RecordingLogger;
use Programster\Log\Tests\Doubles\SwitchableLoggerFactory;

final class FailoverLoggerRetryDelayTest extends TestCase
{
    public function testFailedLoggerIsSkippedDuringItsRetryDelay() : void
    {
        $primary = new SwitchableLoggerFactory("primary", failing: true);
        $fallback = new SwitchableLoggerFactory("fallback");
        $logger = new FailoverLogger($primary, $fallback, retryDelaySeconds: 60);

        $logger->info("one");
        $primary->setFailing(false); // Even if it has recovered, it is not retried until the delay is up.
        $logger->info("two");
        $logger->info("three");

        $this->assertSame(1, $primary->getCallCount(), "Primary should only be tried once, as it is then in its timeout.");
        $this->assertSame(["one", "two", "three"], $fallback->getLoggedMessages());
    }


    public function testLoggerThatFailsWhilstLoggingIsAlsoSkipped() : void
    {
        // Fails in log() rather than in getLogger().
        $primary = new FixedLoggerFactory(new FailingLogger());
        $fallback = new RecordingLogger();
        $logger = new FailoverLogger($primary, new FixedLoggerFactory($fallback), retryDelaySeconds: 60);

        $logger->info("one");
        $logger->info("two");

        $this->assertSame(1, $primary->getCallCount());
        $this->assertSame(["one", "two"], array_values(array_filter(
            array_column($fallback->getLogs(), 'message'),
            fn(string $message) : bool => !str_starts_with($message, "FailoverLogger:")
        )));
    }


    public function testFailureIsOnlyLoggedWhenTheLoggerActuallyFails() : void
    {
        $handler = new RecordingFailureHandler();
        $fallback = new RecordingLogger();
        $logger = new FailoverLogger(new SwitchableLoggerFactory("primary", failing: true), new FixedLoggerFactory($fallback), $handler, 60);

        $logger->info("one");
        $logger->info("two");

        $this->assertSame(["one", "FailoverLogger: logger factory " . SwitchableLoggerFactory::class . " failed to provide a logger: primary is down", "two"], array_column($fallback->getLogs(), 'message'));
        $this->assertCount(1, $handler->getCalls(), "Skipping a logger in its timeout is not a failure.");
    }


    public function testLoggerIsRetriedOnceItsDelayHasPassed() : void
    {
        $primary = new SwitchableLoggerFactory("primary", failing: true);
        $fallback = new SwitchableLoggerFactory("fallback");
        $logger = new FailoverLogger($primary, $fallback, retryDelaySeconds: 0.2);

        $logger->info("one");
        $primary->setFailing(false);
        usleep(300_000);
        $logger->info("two");

        $this->assertSame(2, $primary->getCallCount());
        $this->assertSame(["two"], $primary->getLoggedMessages());
        $this->assertSame(["one"], $fallback->getLoggedMessages());
    }


    public function testEachLoggerHasItsOwnTimeout() : void
    {
        // The scenario: the primary fails, and logs go to the secondary. The secondary then fails whilst the
        // primary is still in its timeout, so logs go to the tertiary. The primary's timeout ends before the
        // secondary's, so the primary is tried again, and when it fails, logs go straight to the tertiary.
        $primary = new SwitchableLoggerFactory("primary", failing: true);
        $secondary = new SwitchableLoggerFactory("secondary");
        $tertiary = new SwitchableLoggerFactory("tertiary");
        $logger = new FailoverLogger($primary, [$secondary, $tertiary], retryDelaySeconds: 0.6);

        // t = 0: primary fails, so is unavailable until t = 0.6.
        $logger->info("one");

        // t = 0.2: secondary fails, so is unavailable until t = 0.8. Primary is skipped.
        usleep(200_000);
        $secondary->setFailing(true);
        $logger->info("two");

        // t = 0.65: primary's timeout is over, so it is tried, and fails again. Secondary is still in its
        // timeout, so is skipped.
        usleep(450_000);
        $secondary->setFailing(false);
        $logger->info("three");

        $this->assertSame(2, $primary->getCallCount(), "Primary should be tried at t = 0 and t = 0.65 only.");
        $this->assertSame(2, $secondary->getCallCount(), "Secondary should be tried at t = 0 and t = 0.2 only.");
        $this->assertSame(["one"], $secondary->getLoggedMessages());
        $this->assertSame(["two", "three"], $tertiary->getLoggedMessages());
    }


    public function testLoggersInTheirTimeoutAreNotTriedEvenIfAllOthersFail() : void
    {
        $primary = new SwitchableLoggerFactory("primary", failing: true);
        $fallback = new SwitchableLoggerFactory("fallback");
        $handler = new RecordingFailureHandler();
        $logger = new FailoverLogger($primary, $fallback, $handler, 60);
        $thrown = null;

        $logger->info("one");

        // The fallback now fails too, whilst the primary is in its timeout. The primary has recovered, but
        // is still skipped.
        $fallback->setFailing(true);
        $primary->setFailing(false);

        try
        {
            $logger->info("two");
        }
        catch (ExceptionAllLoggersFailed $exception)
        {
            $thrown = $exception;
        }

        $this->assertNotNull($thrown, "Expected ExceptionAllLoggersFailed to be thrown.");
        $this->assertSame(1, $primary->getCallCount(), "Primary should not be tried whilst in its timeout.");
        $this->assertSame([], $primary->getLoggedMessages());
        $this->assertSame("No logger was able to log the message, as 1 failed and 1 were skipped for being within their retry delay: two", $thrown->getMessage());
        $this->assertSame(["fallback is down"], array_map(fn(\Throwable $failure) : string => $failure->getMessage(), $thrown->getFailures()));

        $calls = $handler->getCalls();
        $this->assertCount(2, $calls);
        $this->assertFalse($calls[1]['messageWasLogged']);
        $this->assertSame("fallback is down", $calls[1]['failures'][0]->getMessage());
    }


    public function testThrowsWithoutTryingAnyLoggerWhenAllAreInTheirTimeout() : void
    {
        $primary = new SwitchableLoggerFactory("primary", failing: true);
        $fallback = new SwitchableLoggerFactory("fallback", failing: true);
        $handler = new RecordingFailureHandler();
        $logger = new FailoverLogger($primary, $fallback, $handler, 60);
        $thrown = null;

        try
        {
            $logger->info("one");
        }
        catch (ExceptionAllLoggersFailed)
        {
            // Both are now in their timeout.
        }

        try
        {
            $logger->info("two");
        }
        catch (ExceptionAllLoggersFailed $exception)
        {
            $thrown = $exception;
        }

        $this->assertNotNull($thrown, "Expected ExceptionAllLoggersFailed to be thrown.");
        $this->assertSame(1, $primary->getCallCount());
        $this->assertSame(1, $fallback->getCallCount());
        $this->assertSame("No logger was able to log the message, as 0 failed and 2 were skipped for being within their retry delay: two", $thrown->getMessage());
        $this->assertSame([], $thrown->getFailures());
        $this->assertNull($thrown->getPrevious());
        $this->assertCount(1, $handler->getCalls(), "The handler should not be called when nothing was tried, as nothing failed.");
    }


    public function testLoggersAreTriedAgainOnceAllTimeoutsHavePassed() : void
    {
        $primary = new SwitchableLoggerFactory("primary", failing: true);
        $fallback = new SwitchableLoggerFactory("fallback", failing: true);
        $logger = new FailoverLogger($primary, $fallback, retryDelaySeconds: 0.2);

        try
        {
            $logger->info("one");
        }
        catch (ExceptionAllLoggersFailed)
        {
            // Both are now in their timeout.
        }

        $primary->setFailing(false);
        usleep(300_000);
        $logger->info("two");

        $this->assertSame(["two"], $primary->getLoggedMessages());
    }


    public function testFailedLoggerIsSkippedByDefault() : void
    {
        $primary = new SwitchableLoggerFactory("primary", failing: true);
        $logger = new FailoverLogger($primary, new SwitchableLoggerFactory("fallback"));

        $logger->info("one");
        $primary->setFailing(false);
        $logger->info("two");

        $this->assertSame(1, $primary->getCallCount(), "The default retry delay of 3 seconds should skip the primary.");
    }


    public function testWithoutRetryDelayFailedLoggerIsTriedEveryTime() : void
    {
        $primary = new SwitchableLoggerFactory("primary", failing: true);
        $logger = new FailoverLogger($primary, new SwitchableLoggerFactory("fallback"), retryDelaySeconds: 0);

        $logger->info("one");
        $logger->info("two");
        $logger->info("three");

        $this->assertSame(3, $primary->getCallCount());
    }


    public function testNegativeRetryDelayIsRejected() : void
    {
        $this->expectException(ExceptionInvalidRetryDelay::class);
        new FailoverLogger(new SwitchableLoggerFactory("primary"), new SwitchableLoggerFactory("fallback"), retryDelaySeconds: -1);
    }
}
