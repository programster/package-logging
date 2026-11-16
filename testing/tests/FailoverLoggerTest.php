<?php

/*
 * Smoke tests for the FailoverLogger's failing over between its loggers, and the failures it reports. The retry delay
 * is tested in RetryDelayTest.php. These are run by testing/main.php, which provides $smoke.
 */

use Programster\Log\Collections\LoggerFailureCollection;
use Programster\Log\Exceptions\ExceptionAllLoggersFailed;
use Programster\Log\Exceptions\ExceptionInvalidLogLevel;
use Programster\Log\FailoverLogger;
use Programster\Log\FailureStage;
use Programster\Log\Interfaces\InterfaceFailureHandler;
use Programster\Log\LoggerFailure;
use Programster\Log\LogLevel;
use Programster\Log\RetryDelay;
use Programster\Log\Testing\Doubles\FailingLogger;
use Programster\Log\Testing\Doubles\FailingLoggerFactory;
use Programster\Log\Testing\Doubles\FixedLoggerFactory;
use Programster\Log\Testing\Doubles\RecordingFailureHandler;
use Programster\Log\Testing\Doubles\RecordingLogger;
use Programster\Log\Testing\SmokeTest;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;

/** @var SmokeTest $smoke */

/**
 * Wrap a logger in a factory that always returns it.
 * @param LoggerInterface $logger - the logger to wrap.
 * @return FixedLoggerFactory
 */
$factory = function (LoggerInterface $logger) : FixedLoggerFactory {
    return new FixedLoggerFactory($logger);
};

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

$testPrimaryOnly = function () use ($factory) : void {
    $primary = new RecordingLogger();
    $fallback = new RecordingLogger();
    $handler = new RecordingFailureHandler();
    $logger = FailoverLogger::create($factory($primary), $factory($fallback))->withFailureHandler($handler);
    $logger->info("hello", ['key' => 'value']);

    $expected = [['level' => 'info', 'message' => "hello", 'context' => ['key' => 'value']]];
    SmokeTest::assertSame($expected, $primary->getLogs(), "the primary's logs");
    SmokeTest::assertSame([], $fallback->getLogs(), "the fallback's logs");
    SmokeTest::assertSame([], $handler->getCalls(), "the handler's calls, when nothing failed");
};

$smoke->run("FailoverLogger: a working primary logger is the only logger used", $testPrimaryOnly);

$testSingleFallback = function () use ($factory) : void {
    $fallback = new RecordingLogger();
    $logger = FailoverLogger::create($factory(new FailingLogger()), $factory($fallback));
    $logger->warning("hello", ['key' => 'value']);

    $logs = $fallback->getLogs();
    SmokeTest::assertSame(2, count($logs), "the number of logs: the original, then one failure log");
    $expected = ['level' => 'warning', 'message' => "hello", 'context' => ['key' => 'value']];
    SmokeTest::assertSame($expected, $logs[0], "the original log");
    SmokeTest::assertSame('error', $logs[1]['level'], "the failure log's level");
};

$smoke->run("FailoverLogger: falls back to the fallback logger", $testSingleFallback);

$testFallbacksInOrder = function () use ($factory) : void {
    $secondFallback = new RecordingLogger();
    $thirdFallback = new RecordingLogger();

    $logger = FailoverLogger::create(
        $factory(new FailingLogger("primary down")),
        $factory(new FailingLogger("first fallback down")),
        $factory($secondFallback),
        $factory($thirdFallback)
    );

    $logger->info("hello");

    $expected = [
        "hello",
        "FailoverLogger: logger " . FailingLogger::class . " failed to log a message: primary down",
        "FailoverLogger: logger " . FailingLogger::class . " failed to log a message: first fallback down",
    ];

    SmokeTest::assertSame($expected, $secondFallback->getMessages(), "the working logger's messages");
    SmokeTest::assertSame([], $thirdFallback->getLogs(), "the logs of the logger after the working one");
};

$smoke->run("FailoverLogger: tries the fallback loggers in order", $testFallbacksInOrder);

$testSpreadFallbacks = function () use ($factory) : void {
    $fallback = new RecordingLogger();
    $fallbacks = ['db' => $factory(new FailingLogger()), 'file' => $factory($fallback)];
    $logger = FailoverLogger::create($factory(new FailingLogger()), ...array_values($fallbacks));
    $logger->info("hello");

    SmokeTest::assertSame(["hello"], $fallback->getOwnMessages(), "the last fallback's messages");
};

$smoke->run("FailoverLogger: fallbacks can be spread from an array", $testSpreadFallbacks);

$testNeedsAFallback = function () use ($factory) : void {
    $createWithoutFallback = function () use ($factory) : void {
        // @phpstan-ignore arguments.count (deliberately given no fallback, to check it's rejected)
        FailoverLogger::create($factory(new RecordingLogger()));
    };

    $createWithRawLogger = function () : void {
        // @phpstan-ignore argument.type, argument.type (deliberately given loggers rather than factories)
        FailoverLogger::create(new RecordingLogger(), new RecordingLogger());
    };

    SmokeTest::expectException(\ArgumentCountError::class, $createWithoutFallback);
    SmokeTest::expectException(\TypeError::class, $createWithRawLogger, "InterfaceLoggerFactory");
};

$smoke->run("FailoverLogger: rejects being given no fallback, or loggers rather than factories", $testNeedsAFallback);

$testFailureContext = function () use ($factory) : void {
    $fallback = new RecordingLogger();
    $logger = FailoverLogger::create($factory(new FailingLogger("database is down")), $factory($fallback));
    $logger->info("hello");

    $context = $fallback->getLogs()[1]['context'];
    SmokeTest::assertSame(0, $context['failed_logger_index'], "failed_logger_index");
    SmokeTest::assertSame(FixedLoggerFactory::class, $context['failed_logger_factory_class'], "the factory class");
    SmokeTest::assertSame(FailingLogger::class, $context['failed_logger_class'], "failed_logger_class");
    SmokeTest::assertSame("log", $context['failed_during'], "failed_during");
    SmokeTest::assertSame(\RuntimeException::class, $context['exception_class'], "exception_class");
    SmokeTest::assertSame("database is down", $context['exception_message'], "exception_message");
    SmokeTest::assertSame(0, $context['exception_code'], "exception_code");
    SmokeTest::assertSame(true, is_string($context['exception_file']), "whether exception_file is a string");
    SmokeTest::assertSame(true, is_int($context['exception_line']), "whether exception_line is an int");
    SmokeTest::assertSame(true, is_string($context['exception_trace']), "whether exception_trace is a string");

    foreach ($context as $key => $value)
    {
        SmokeTest::assertSame(true, is_scalar($value), "whether {$key} is a scalar, so file loggers can write it");
    }
};

$smoke->run("FailoverLogger: the failure log's context has the exception's details", $testFailureContext);

$testHandlerGivenFailures = function () use ($factory, $getFailureMessages) : void {
    $handler = new RecordingFailureHandler();
    $primaryFactory = $factory(new FailingLogger("primary down"));

    $logger = FailoverLogger::create(
        $primaryFactory,
        new FailingLoggerFactory("first fallback down"),
        $factory(new RecordingLogger())
    );

    $logger = $logger->withFailureHandler($handler);
    $logger->info("hello");

    $calls = $handler->getCalls();
    SmokeTest::assertSame(1, count($calls), "the number of calls to the handler");
    SmokeTest::assertSame(true, $calls[0]['messageWasLogged'], "messageWasLogged");
    $messages = $getFailureMessages($calls[0]['failures']);
    SmokeTest::assertSame(["primary down", "first fallback down"], $messages, "the failures' messages");

    $first = $calls[0]['failures']->get(0);
    $second = $calls[0]['failures']->get(1);
    SmokeTest::assertSame([0, 1], [$first->getPosition(), $second->getPosition()], "the failures' positions");
    SmokeTest::assertSame(true, $first->getLoggerFactory() === $primaryFactory, "the first failure's factory");
    SmokeTest::assertSame(FailureStage::LOGGING, $first->getStage(), "the first failure's stage");
    SmokeTest::assertSame(FailureStage::FETCHING_LOGGER, $second->getStage(), "the second failure's stage");
};

$smoke->run("FailoverLogger: the failure handler is given each failure, in order", $testHandlerGivenFailures);

$testHandlerCalledAfterLogging = function () use ($factory) : void {
    $fallback = new RecordingLogger();

    $handler = new class($fallback) implements InterfaceFailureHandler {
        private readonly RecordingLogger $m_fallback;
        private int $m_logCount = -1;

        public function __construct(RecordingLogger $fallback)
        {
            $this->m_fallback = $fallback;
        }

        public function handleFailures(LoggerFailureCollection $failures, bool $messageWasLogged) : void
        {
            $this->m_logCount = count($this->m_fallback->getLogs());
        }

        public function getLogCount() : int { return $this->m_logCount; }
    };

    $logger = FailoverLogger::create($factory(new FailingLogger()), $factory($fallback))->withFailureHandler($handler);
    $logger->info("hello");

    SmokeTest::assertSame(2, $handler->getLogCount(), "the number of logs when the handler was called");
};

$smoke->run(
    "FailoverLogger: the failure handler is called after the failures have been logged",
    $testHandlerCalledAfterLogging
);

$testAllFail = function () use ($factory, $getFailureMessages) : void {
    $handler = new RecordingFailureHandler();
    $logger = FailoverLogger::create($factory(new FailingLogger("first")), new FailingLoggerFactory("second"));
    $logger = $logger->withFailureHandler($handler);

    $logHello = function () use ($logger) : void {
        $logger->info("hello");
    };

    $exception = SmokeTest::catchException(ExceptionAllLoggersFailed::class, $logHello);
    SmokeTest::assertSame("All 2 loggers failed to log the message: hello", $exception->getMessage(), "the message");
    SmokeTest::assertSame(["first", "second"], $getFailureMessages($exception->getFailures()), "the failures");
    $lastThrowable = $exception->getFailures()->last()->getThrowable();
    SmokeTest::assertSame(true, $exception->getPrevious() === $lastThrowable, "the previous exception");

    $calls = $handler->getCalls();
    SmokeTest::assertSame(1, count($calls), "the number of calls to the handler, before the exception");
    SmokeTest::assertSame(false, $calls[0]['messageWasLogged'], "messageWasLogged");
    SmokeTest::assertSame(2, count($calls[0]['failures']), "the number of failures given to the handler");
};

$smoke->run("FailoverLogger: throws when every logger fails", $testAllFail);

$testFailureLogSwallowed = function () use ($factory) : void {
    // A logger that accepts the original message, but throws when given the failure details.
    $fallback = new class extends AbstractLogger {
        /** @var list<string> */
        private array $m_messages = [];

        public function log(mixed $level, string|\Stringable $message, array $context = []) : void
        {
            if ($level === 'error')
            {
                throw new \RuntimeException("Cannot log errors");
            }

            $this->m_messages[] = (string)$message;
        }

        /** @return list<string> */
        public function getMessages() : array { return $this->m_messages; }
    };

    $handler = new RecordingFailureHandler();
    $logger = FailoverLogger::create($factory(new FailingLogger()), $factory($fallback))->withFailureHandler($handler);
    $logger->info("hello");

    SmokeTest::assertSame(["hello"], $fallback->getMessages(), "the fallback's messages");
    SmokeTest::assertSame(1, count($handler->getCalls()), "the number of calls to the handler");
    SmokeTest::assertSame(true, $handler->getCalls()[0]['messageWasLogged'], "messageWasLogged");
};

$smoke->run("FailoverLogger: an exception whilst logging the failures is swallowed", $testFailureLogSwallowed);

$testHandlerExceptionPassedOn = function () use ($factory) : void {
    $handler = new class implements InterfaceFailureHandler {
        public function handleFailures(LoggerFailureCollection $failures, bool $messageWasLogged) : void
        {
            throw new \LogicException("Handler failed");
        }
    };

    $logger = FailoverLogger::create($factory(new FailingLogger()), $factory(new RecordingLogger()));
    $logger = $logger->withFailureHandler($handler);

    $logHello = function () use ($logger) : void {
        $logger->info("hello");
    };

    SmokeTest::expectException(\LogicException::class, $logHello, "Handler failed");
};

$smoke->run("FailoverLogger: an exception from the failure handler is passed on", $testHandlerExceptionPassedOn);

$testInvalidLevel = function () use ($factory) : void {
    $primary = new RecordingLogger();
    $fallback = new RecordingLogger();
    $handler = new RecordingFailureHandler();
    $logger = FailoverLogger::create($factory($primary), $factory($fallback))->withFailureHandler($handler);

    $logLoud = function () use ($logger) : void {
        $logger->log("loud", "hello");
    };

    // PSR-3 requires an InvalidArgumentException for an invalid level.
    $exception = SmokeTest::catchException(\Psr\Log\InvalidArgumentException::class, $logLoud);
    SmokeTest::assertSame(ExceptionInvalidLogLevel::class, get_class($exception), "the exception's class");
    SmokeTest::assertSame([], $primary->getLogs(), "the primary's logs");
    SmokeTest::assertSame([], $fallback->getLogs(), "the fallback's logs");
    SmokeTest::assertSame([], $handler->getCalls(), "the handler's calls, as an invalid level isn't a failure");
};

$smoke->run("FailoverLogger: an invalid level is rejected before trying any logger", $testInvalidLevel);

$testLevelPassedOn = function () use ($factory) : void {
    $primary = new RecordingLogger();
    $logger = FailoverLogger::create($factory($primary), $factory(new RecordingLogger()));
    $logger->log("WARNING", "a");
    $logger->log(LogLevel::ERROR, "b");
    $logger->log(7, "c");

    $levels = array_column($primary->getLogs(), 'level');
    SmokeTest::assertSame(['warning', 'error', 'emergency'], $levels, "the levels passed on");
};

$smoke->run("FailoverLogger: the level is passed on as a lowercase string", $testLevelPassedOn);

$testFactoryFails = function () use ($factory, $getFailureMessages) : void {
    $fallback = new RecordingLogger();
    $handler = new RecordingFailureHandler();
    $logger = FailoverLogger::create(new FailingLoggerFactory("cannot connect to db"), $factory($fallback));
    $logger = $logger->withFailureHandler($handler);
    $logger->info("hello", ['key' => 'value']);

    $logs = $fallback->getLogs();
    SmokeTest::assertSame(2, count($logs), "the number of logs: the original, then one failure log");
    $expected = ['level' => 'info', 'message' => "hello", 'context' => ['key' => 'value']];
    SmokeTest::assertSame($expected, $logs[0], "the original log");

    $factoryClass = FailingLoggerFactory::class;
    $expectedMessage = "FailoverLogger: logger factory {$factoryClass} failed to provide a logger: "
        . "cannot connect to db";

    SmokeTest::assertSame($expectedMessage, $logs[1]['message'], "the failure log's message");

    $context = $logs[1]['context'];
    SmokeTest::assertSame(0, $context['failed_logger_index'], "failed_logger_index");
    SmokeTest::assertSame($factoryClass, $context['failed_logger_factory_class'], "failed_logger_factory_class");
    SmokeTest::assertSame("", $context['failed_logger_class'], "failed_logger_class");
    SmokeTest::assertSame("fetchLogger", $context['failed_during'], "failed_during");
    SmokeTest::assertSame("cannot connect to db", $context['exception_message'], "exception_message");

    $calls = $handler->getCalls();
    SmokeTest::assertSame(true, $calls[0]['messageWasLogged'], "messageWasLogged");
    $messages = $getFailureMessages($calls[0]['failures']);
    SmokeTest::assertSame(["cannot connect to db"], $messages, "the failures given to the handler");
};

$smoke->run("FailoverLogger: a factory that fails to provide a logger is failed over", $testFactoryFails);

$testFactoryAskedEveryLog = function () use ($factory) : void {
    $primaryFactory = $factory(new RecordingLogger());
    $fallbackFactory = $factory(new RecordingLogger());
    $logger = FailoverLogger::create($primaryFactory, $fallbackFactory);
    $logger->info("a");
    $logger->info("b");
    $logger->info("c");

    SmokeTest::assertSame(3, $primaryFactory->getCallCount(), "the calls to the primary factory");
    SmokeTest::assertSame(0, $fallbackFactory->getCallCount(), "the calls to the fallback factory");
};

$smoke->run(
    "FailoverLogger: asks the factory for the logger on every log, and only asks fallbacks when needed",
    $testFactoryAskedEveryLog
);

$testWithCopies = function () use ($factory) : void {
    $original = FailoverLogger::create(new FailingLoggerFactory("down"), $factory(new RecordingLogger()));
    $handler = new RecordingFailureHandler();
    $withHandler = $original->withFailureHandler($handler);
    $withNoDelay = $withHandler->withRetryDelay(RetryDelay::createNone());

    $original->info("original");
    SmokeTest::assertSame(0, count($handler->getCalls()), "the handler's calls, after logging to the original");
    SmokeTest::assertSame(true, $withHandler !== $original, "whether withFailureHandler() returns a copy");

    $withNoDelay->info("one");
    $withNoDelay->info("two");
    SmokeTest::assertSame(2, count($handler->getCalls()), "the handler's calls, which a copy keeps");
};

$smoke->run("FailoverLogger: withFailureHandler() and withRetryDelay() return changed copies", $testWithCopies);

$testLoggerFailure = function () : void {
    $factory = new FailingLoggerFactory();
    $throwable = new \RuntimeException("broken", 5);
    $fetchFailure = LoggerFailure::createFetchFailure(2, $factory, $throwable);
    $logFailure = LoggerFailure::createLogFailure(1, $factory, new RecordingLogger(), $throwable);

    SmokeTest::assertSame(2, $fetchFailure->getPosition(), "getPosition()");
    SmokeTest::assertSame(true, $fetchFailure->getLoggerFactory() === $factory, "getLoggerFactory()");
    SmokeTest::assertSame(true, $fetchFailure->getThrowable() === $throwable, "getThrowable()");
    SmokeTest::assertSame(FailureStage::FETCHING_LOGGER, $fetchFailure->getStage(), "a fetch failure's stage");
    SmokeTest::assertSame(FailureStage::LOGGING, $logFailure->getStage(), "a log failure's stage");
    SmokeTest::assertSame(5, $logFailure->getContext()['exception_code'], "the context's exception_code");
    $loggerClass = $logFailure->getContext()['failed_logger_class'];
    SmokeTest::assertSame(RecordingLogger::class, $loggerClass, "a log failure's failed_logger_class");
    $expectedMessage = "FailoverLogger: logger " . RecordingLogger::class . " failed to log a message: broken";
    SmokeTest::assertSame($expectedMessage, $logFailure->getMessage(), "a log failure's message");
};

$smoke->run("LoggerFailure: describes the failure", $testLoggerFailure);
