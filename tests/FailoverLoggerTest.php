<?php

declare(strict_types = 1);
namespace Programster\Log\Tests;


use PHPUnit\Framework\TestCase;
use Programster\Log\Exceptions\ExceptionAllLoggersFailed;
use Programster\Log\Exceptions\ExceptionInvalidFallbackLoggers;
use Programster\Log\FailoverLogger;
use Programster\Log\Tests\Doubles\FailingLogger;
use Programster\Log\Tests\Doubles\RecordingFailureHandler;
use Programster\Log\Tests\Doubles\RecordingLogger;
use Psr\Log\AbstractLogger;
use Psr\Log\LogLevel;

final class FailoverLoggerTest extends TestCase
{
    public function testWorkingPrimaryLoggerIsTheOnlyLoggerUsed() : void
    {
        $primary = new RecordingLogger();
        $fallback = new RecordingLogger();
        $handler = new RecordingFailureHandler();

        $logger = new FailoverLogger($primary, $fallback, $handler);
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

        $logger = new FailoverLogger(new FailingLogger(), $fallback);
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
            new FailingLogger("primary down"),
            [new FailingLogger("first fallback down"), $secondFallback, $thirdFallback]
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
            new FailingLogger(),
            ['db' => new FailingLogger(), 'file' => $fallback]
        );

        $logger->info("hello");

        $this->assertSame("hello", $fallback->getLogs()[0]['message']);
    }


    public function testFailureLogContainsExceptionDetails() : void
    {
        $failingLogger = new FailingLogger("database is down");
        $fallback = new RecordingLogger();

        $logger = new FailoverLogger($failingLogger, $fallback);
        $logger->info("hello");

        $context = $fallback->getLogs()[1]['context'];
        $this->assertSame(0, $context['failed_logger_index']);
        $this->assertSame(FailingLogger::class, $context['failed_logger_class']);
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
            new FailingLogger("primary down"),
            [new FailingLogger("first fallback down"), new RecordingLogger()],
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

        $logger = new FailoverLogger(new FailingLogger(), $fallback, $handler);
        $logger->info("hello");

        $this->assertSame(2, $logCountWhenHandlerCalled, "Original log and failure log should exist before the handler runs.");
    }


    public function testThrowsWhenAllLoggersFail() : void
    {
        $handler = new RecordingFailureHandler();
        $logger = new FailoverLogger(new FailingLogger("first"), [new FailingLogger("second")], $handler);
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

        $logger = new FailoverLogger(new FailingLogger(), $fallback);
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
        $logger = new FailoverLogger(new FailingLogger(), $fallback, $handler);
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

        $logger = new FailoverLogger(new FailingLogger(), new RecordingLogger(), $handler);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage("Handler failed");
        $logger->info("hello");
    }


    public function testInvalidLevelIsRejectedBeforeTryingAnyLogger() : void
    {
        $primary = new RecordingLogger();
        $fallback = new RecordingLogger();
        $handler = new RecordingFailureHandler();
        $logger = new FailoverLogger($primary, $fallback, $handler);
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
        $logger = new FailoverLogger($primary, new RecordingLogger());

        $logger->log("WARNING", "a");
        $logger->log(\Programster\Log\LogLevel::ERROR, "b");
        $logger->log(7, "c");

        $this->assertSame(['warning', 'error', 'emergency'], array_column($primary->getLogs(), 'level'));
    }


    public function testEmptyArrayOfFallbackLoggersIsRejected() : void
    {
        $this->expectException(ExceptionInvalidFallbackLoggers::class);
        new FailoverLogger(new RecordingLogger(), []);
    }


    public function testArrayContainingNonLoggerIsRejected() : void
    {
        $this->expectException(ExceptionInvalidFallbackLoggers::class);
        $this->expectExceptionMessage("Fallback logger at key '1' is of type string");
        new FailoverLogger(new RecordingLogger(), [new RecordingLogger(), "not a logger"]);
    }
}
