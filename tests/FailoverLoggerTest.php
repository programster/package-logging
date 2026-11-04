<?php

declare(strict_types = 1);
namespace Programster\Log\Tests;


use PHPUnit\Framework\TestCase;
use Programster\Log\Exceptions\ExceptionAllLoggersFailed;
use Programster\Log\Exceptions\ExceptionInvalidFallbackLoggers;
use Programster\Log\FailoverLogger;
use Programster\Log\Tests\Doubles\FailingLogger;
use Programster\Log\Tests\Doubles\FailingLoggerFactory;
use Programster\Log\Tests\Doubles\FixedLoggerFactory;
use Programster\Log\Tests\Doubles\RecordingFailureHandler;
use Programster\Log\Tests\Doubles\RecordingLogger;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;

final class FailoverLoggerTest extends TestCase
{
    public function testWorkingPrimaryLoggerIsTheOnlyLoggerUsed() : void
    {
        $primary = new RecordingLogger();
        $fallback = new RecordingLogger();
        $handler = new RecordingFailureHandler();

        $logger = new FailoverLogger(self::factory($primary), self::factory($fallback), $handler);
        $logger->info("hello", ['key' => 'value']);

        $this->assertSame(
            [['level' => LogLevel::INFO, 'message' => "hello", 'context' => ['key' => 'value']]],
            $primary->getLogs()
        );

        $this->assertSame([], $fallback->getLogs());
        $this->assertSame([], $handler->getCalls(), "Handler should not be called when nothing failed.");
    }


    public function testFallsBackToSingleFallbackLogger() : void
    {
        $fallback = new RecordingLogger();

        $logger = new FailoverLogger(self::factory(new FailingLogger()), self::factory($fallback));
        $logger->warning("hello", ['key' => 'value']);

        $logs = $fallback->getLogs();
        $this->assertCount(2, $logs, "Expected the original log, followed by one failure log.");
        $this->assertSame(['level' => LogLevel::WARNING, 'message' => "hello", 'context' => ['key' => 'value']], $logs[0]);
        $this->assertSame(LogLevel::ERROR, $logs[1]['level']);
    }


    public function testTriesArrayOfFallbackLoggersInOrder() : void
    {
        $secondFallback = new RecordingLogger();
        $thirdFallback = new RecordingLogger();

        $logger = new FailoverLogger(
            self::factory(new FailingLogger("primary down")),
            [self::factory(new FailingLogger("first fallback down")), self::factory($secondFallback), self::factory($thirdFallback)]
        );

        $logger->info("hello");

        $logs = $secondFallback->getLogs();
        $this->assertCount(3, $logs, "Expected the original log, followed by two failure logs.");
        $this->assertSame("hello", $logs[0]['message']);
        $this->assertStringContainsString("primary down", $logs[1]['message']);
        $this->assertStringContainsString("first fallback down", $logs[2]['message']);
        $this->assertSame([], $thirdFallback->getLogs(), "Loggers after the working one should not be used.");
    }


    public function testArrayOfFallbackLoggersWithNonSequentialKeysIsTriedInOrder() : void
    {
        $fallback = new RecordingLogger();

        $logger = new FailoverLogger(
            self::factory(new FailingLogger()),
            ['db' => self::factory(new FailingLogger()), 'file' => self::factory($fallback)]
        );

        $logger->info("hello");

        $this->assertSame("hello", $fallback->getLogs()[0]['message']);
    }


    public function testFailureLogContainsExceptionDetails() : void
    {
        $failingLogger = new FailingLogger("database is down");
        $fallback = new RecordingLogger();

        $logger = new FailoverLogger(self::factory($failingLogger), self::factory($fallback));
        $logger->info("hello");

        $context = $fallback->getLogs()[1]['context'];
        $this->assertSame(0, $context['failed_logger_index']);
        $this->assertSame(FixedLoggerFactory::class, $context['failed_logger_factory_class']);
        $this->assertSame(FailingLogger::class, $context['failed_logger_class']);
        $this->assertSame("log", $context['failed_during']);
        $this->assertSame(\RuntimeException::class, $context['exception_class']);
        $this->assertSame("database is down", $context['exception_message']);
        $this->assertIsString($context['exception_file']);
        $this->assertIsInt($context['exception_line']);
        $this->assertIsString($context['exception_trace']);

        foreach ($context as $key => $value)
        {
            $this->assertIsScalar($value, "Context value '{$key}' should be scalar, so file loggers can serialize it.");
        }
    }


    public function testHandlerIsGivenFailuresInOrderWhenMessageIsEventuallyLogged() : void
    {
        $handler = new RecordingFailureHandler();

        $logger = new FailoverLogger(
            self::factory(new FailingLogger("primary down")),
            [self::factory(new FailingLogger("first fallback down")), self::factory(new RecordingLogger())],
            $handler
        );

        $logger->info("hello");

        $calls = $handler->getCalls();
        $this->assertCount(1, $calls);
        $this->assertTrue($calls[0]['messageWasLogged']);
        $this->assertSame([0, 1], array_keys($calls[0]['failures']), "Failures should be a plain list.");
        $this->assertSame("primary down", $calls[0]['failures'][0]->getMessage());
        $this->assertSame("first fallback down", $calls[0]['failures'][1]->getMessage());
    }


    public function testHandlerIsCalledAfterFailuresHaveBeenLogged() : void
    {
        $fallback = new RecordingLogger();
        $logCountWhenHandlerCalled = null;

        $handler = new class($fallback, $logCountWhenHandlerCalled) implements \Programster\Log\Interfaces\InterfaceFailureHandler {
            private readonly RecordingLogger $m_fallback;
            private ?int $m_logCount;

            public function __construct(RecordingLogger $fallback, ?int &$logCount)
            {
                $this->m_fallback = $fallback;
                $this->m_logCount = &$logCount;
            }

            public function handleFailures(array $failures, bool $messageWasLogged) : void
            {
                $this->m_logCount = count($this->m_fallback->getLogs());
            }
        };

        $logger = new FailoverLogger(self::factory(new FailingLogger()), self::factory($fallback), $handler);
        $logger->info("hello");

        $this->assertSame(2, $logCountWhenHandlerCalled, "Original log and failure log should exist before the handler runs.");
    }


    public function testThrowsWhenAllLoggersFail() : void
    {
        $handler = new RecordingFailureHandler();
        $logger = new FailoverLogger(self::factory(new FailingLogger("first")), [self::factory(new FailingLogger("second"))], $handler);
        $thrown = null;

        try
        {
            $logger->info("hello");
        }
        catch (ExceptionAllLoggersFailed $exception)
        {
            $thrown = $exception;
        }

        $this->assertNotNull($thrown, "Expected ExceptionAllLoggersFailed to be thrown.");
        $this->assertStringContainsString("hello", $thrown->getMessage());
        $this->assertCount(2, $thrown->getFailures());
        $this->assertSame("first", $thrown->getFailures()[0]->getMessage());
        $this->assertSame("second", $thrown->getFailures()[1]->getMessage());
        $this->assertSame($thrown->getFailures()[1], $thrown->getPrevious());

        $calls = $handler->getCalls();
        $this->assertCount(1, $calls, "Handler should be called before the exception is thrown.");
        $this->assertFalse($calls[0]['messageWasLogged']);
        $this->assertCount(2, $calls[0]['failures']);
    }


    public function testWorksWithoutAFailureHandler() : void
    {
        $fallback = new RecordingLogger();

        $logger = new FailoverLogger(self::factory(new FailingLogger()), self::factory($fallback));
        $logger->info("hello");

        $this->assertCount(2, $fallback->getLogs());
    }


    public function testExceptionWhilstLoggingFailureDetailsIsSwallowed() : void
    {
        // A logger that accepts the original message, but throws when given the failure details.
        $fallback = new class extends AbstractLogger {
            private array $m_messages = [];

            public function log($level, string|\Stringable $message, array $context = []) : void
            {
                if ($level === LogLevel::ERROR)
                {
                    throw new \RuntimeException("Cannot log errors");
                }

                $this->m_messages[] = (string)$message;
            }

            public function getMessages() : array
            {
                return $this->m_messages;
            }
        };

        $handler = new RecordingFailureHandler();
        $logger = new FailoverLogger(self::factory(new FailingLogger()), self::factory($fallback), $handler);
        $logger->info("hello");

        $this->assertSame(["hello"], $fallback->getMessages());
        $this->assertCount(1, $handler->getCalls(), "Handler should still be called.");
        $this->assertTrue($handler->getCalls()[0]['messageWasLogged']);
    }


    public function testExceptionFromHandlerIsNotCaught() : void
    {
        $handler = new class implements \Programster\Log\Interfaces\InterfaceFailureHandler {
            public function handleFailures(array $failures, bool $messageWasLogged) : void
            {
                throw new \LogicException("Handler failed");
            }
        };

        $logger = new FailoverLogger(self::factory(new FailingLogger()), self::factory(new RecordingLogger()), $handler);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage("Handler failed");
        $logger->info("hello");
    }


    public function testInvalidLevelIsRejectedBeforeTryingAnyLogger() : void
    {
        $primary = new RecordingLogger();
        $fallback = new RecordingLogger();
        $handler = new RecordingFailureHandler();
        $logger = new FailoverLogger(self::factory($primary), self::factory($fallback), $handler);
        $thrown = null;

        try
        {
            $logger->log("loud", "hello");
        }
        catch (\Programster\Log\Exceptions\ExceptionInvalidLogLevel $exception)
        {
            $thrown = $exception;
        }

        $this->assertInstanceOf(\Psr\Log\InvalidArgumentException::class, $thrown, "PSR-3 requires this exception type.");
        $this->assertSame([], $primary->getLogs());
        $this->assertSame([], $fallback->getLogs());
        $this->assertSame([], $handler->getCalls(), "An invalid level is not a logger failure.");
    }


    public function testLevelIsPassedOnAsLowercaseString() : void
    {
        $primary = new RecordingLogger();
        $logger = new FailoverLogger(self::factory($primary), self::factory(new RecordingLogger()));

        $logger->log("WARNING", "a");
        $logger->log(\Programster\Log\LogLevel::ERROR, "b");
        $logger->log(7, "c");

        $this->assertSame(['warning', 'error', 'emergency'], array_column($primary->getLogs(), 'level'));
    }


    public function testEmptyArrayOfFallbackLoggersIsRejected() : void
    {
        $this->expectException(ExceptionInvalidFallbackLoggers::class);
        new FailoverLogger(self::factory(new RecordingLogger()), []);
    }


    public function testArrayContainingRawLoggerIsRejected() : void
    {
        $this->expectException(ExceptionInvalidFallbackLoggers::class);
        $this->expectExceptionMessage("Fallback logger factory at key '0' is of type " . RecordingLogger::class);
        new FailoverLogger(self::factory(new RecordingLogger()), [new RecordingLogger()]);
    }


    public function testFactoryThatFailsToProvideLoggerIsFailedOver() : void
    {
        $fallback = new RecordingLogger();
        $handler = new RecordingFailureHandler();

        $logger = new FailoverLogger(new FailingLoggerFactory("cannot connect to db"), self::factory($fallback), $handler);
        $logger->info("hello", ['key' => 'value']);

        $logs = $fallback->getLogs();
        $this->assertCount(2, $logs, "Expected the original log, followed by one failure log.");
        $this->assertSame(['level' => LogLevel::INFO, 'message' => "hello", 'context' => ['key' => 'value']], $logs[0]);
        $this->assertSame(LogLevel::ERROR, $logs[1]['level']);
        $this->assertStringContainsString("failed to provide a logger", $logs[1]['message']);
        $this->assertStringContainsString("cannot connect to db", $logs[1]['message']);

        $context = $logs[1]['context'];
        $this->assertSame(0, $context['failed_logger_index']);
        $this->assertSame(FailingLoggerFactory::class, $context['failed_logger_factory_class']);
        $this->assertSame("", $context['failed_logger_class']);
        $this->assertSame("getLogger", $context['failed_during']);
        $this->assertSame("cannot connect to db", $context['exception_message']);

        foreach ($context as $key => $value)
        {
            $this->assertIsScalar($value, "Context value '{$key}' should be scalar, so file loggers can serialize it.");
        }

        $calls = $handler->getCalls();
        $this->assertCount(1, $calls);
        $this->assertTrue($calls[0]['messageWasLogged']);
        $this->assertSame("cannot connect to db", $calls[0]['failures'][0]->getMessage());
    }


    public function testMixOfFactoryAndLoggerFailuresAreAllLoggedInOrder() : void
    {
        $fallback = new RecordingLogger();

        $logger = new FailoverLogger(
            new FailingLoggerFactory("construction failed"),
            [self::factory(new FailingLogger("logging failed")), self::factory($fallback)]
        );

        $logger->info("hello");

        $logs = $fallback->getLogs();
        $this->assertCount(3, $logs);
        $this->assertSame("getLogger", $logs[1]['context']['failed_during']);
        $this->assertStringContainsString("construction failed", $logs[1]['message']);
        $this->assertSame("log", $logs[2]['context']['failed_during']);
        $this->assertStringContainsString("logging failed", $logs[2]['message']);
    }


    public function testThrowsWhenAllFactoriesFail() : void
    {
        $logger = new FailoverLogger(new FailingLoggerFactory("first"), new FailingLoggerFactory("second"));
        $thrown = null;

        try
        {
            $logger->info("hello");
        }
        catch (ExceptionAllLoggersFailed $exception)
        {
            $thrown = $exception;
        }

        $this->assertNotNull($thrown, "Expected ExceptionAllLoggersFailed to be thrown.");
        $this->assertCount(2, $thrown->getFailures());
        $this->assertSame("first", $thrown->getFailures()[0]->getMessage());
        $this->assertSame("second", $thrown->getFailures()[1]->getMessage());
    }


    public function testFactoryIsAskedForLoggerOnEveryLog() : void
    {
        $primaryFactory = self::factory(new RecordingLogger());
        $fallbackFactory = self::factory(new RecordingLogger());
        $logger = new FailoverLogger($primaryFactory, $fallbackFactory);

        $logger->info("a");
        $logger->info("b");
        $logger->info("c");

        $this->assertSame(3, $primaryFactory->getCallCount(), "The logger should not be cached by the FailoverLogger.");
        $this->assertSame(0, $fallbackFactory->getCallCount(), "Fallback factories should only be called when needed.");
    }


    public function testFactoryThatRecoversIsUsedAgain() : void
    {
        // A factory that fails the first time (e.g. db is down), then succeeds, caching the logger in a
        // static variable as shown in the README.
        $primaryLogger = new RecordingLogger();

        $primaryFactory = new class($primaryLogger) implements \Programster\Log\Interfaces\InterfaceLoggerFactory {
            private readonly RecordingLogger $m_logger;

            public function __construct(RecordingLogger $logger)
            {
                $this->m_logger = $logger;
            }

            public function getLogger() : LoggerInterface
            {
                static $attempts = 0;
                static $logger = null;

                if ($logger === null)
                {
                    $attempts++;

                    if ($attempts === 1)
                    {
                        throw new \RuntimeException("db is down");
                    }

                    $logger = $this->m_logger;
                }

                return $logger;
            }
        };

        $fallback = new RecordingLogger();
        // No retry delay, so the primary is tried again on the second log.
        $logger = new FailoverLogger($primaryFactory, self::factory($fallback), retryDelaySeconds: 0);

        $logger->info("first");
        $logger->info("second");

        $this->assertSame(["first", "FailoverLogger: logger factory " . get_class($primaryFactory) . " failed to provide a logger: db is down"], array_column($fallback->getLogs(), 'message'));
        $this->assertSame(["second"], array_column($primaryLogger->getLogs(), 'message'));
    }


    public function testArrayContainingNonLoggerIsRejected() : void
    {
        $this->expectException(ExceptionInvalidFallbackLoggers::class);
        $this->expectExceptionMessage("Fallback logger factory at key '1' is of type string");
        new FailoverLogger(self::factory(new RecordingLogger()), [self::factory(new RecordingLogger()), "not a logger"]);
    }


    /**
     * Wrap a logger in a factory that always returns it.
     * @param LoggerInterface $logger - the logger to wrap.
     * @return FixedLoggerFactory - the factory.
     */
    private static function factory(LoggerInterface $logger) : FixedLoggerFactory
    {
        return new FixedLoggerFactory($logger);
    }
}
