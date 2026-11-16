<?php

/*
 * A logger that tries each of its loggers in order until one of them successfully logs the message.
 * E.g. try logging to a database, and if that fails, fall back to logging to a file.
 *
 * Loggers are provided through logger factories (InterfaceLoggerFactory), which are asked for their logger every time
 * it's tried. This means that a logger that fails to be constructed (e.g. it cannot connect to the database) is
 * failed over just like a logger that fails to log. The logger is not cached by this class: use a
 * CachingLoggerFactory for that.
 *
 * Once a logger succeeds, the original message is logged to it, followed by an error log for each logger that failed
 * before it, so that you can find out why the preferred logger(s) failed.
 *
 * A logger that fails is skipped for a retry delay (see RetryDelay), which defaults to 3 seconds, so that later logs
 * go straight to the next available logger, rather than waiting on the broken one (e.g. a database connection timing
 * out) every time. Each logger has its own timeout.
 *
 * Create one with FailoverLogger::create(), and use withFailureHandler() and withRetryDelay() to change its settings.
 *
 * Deliberately not readonly, as it keeps track of which loggers are within their retry delay.
 */

declare(strict_types = 1);
namespace Programster\Log;


use Programster\Log\Collections\LoggerFactoryCollection;
use Programster\Log\Collections\LoggerFailureCollection;
use Programster\Log\Exceptions\ExceptionAllLoggersFailed;
use Programster\Log\Interfaces\InterfaceFailureHandler;
use Programster\Log\Interfaces\InterfaceLoggerFactory;
use Psr\Log\LoggerInterface;

final class FailoverLogger extends AbstractLogger
{
    private readonly LoggerFactoryCollection $m_loggerFactories;

    // Null if no failure handler was given.
    private readonly ?InterfaceFailureHandler $m_failureHandler;

    private readonly RetryDelay $m_retryDelay;

    /**
     * @var array<int, float> - for each logger that is currently in its timeout, the time (in seconds, from
     * getCurrentTime()) until which it is skipped, indexed by its position. Deliberately not readonly, as it is
     * updated whenever a logger fails or succeeds.
     */
    private array $m_unavailableUntil = [];


    private function __construct(
        LoggerFactoryCollection $loggerFactories,
        ?InterfaceFailureHandler $failureHandler,
        RetryDelay $retryDelay
    )
    {
        $this->m_loggerFactories = $loggerFactories;
        $this->m_failureHandler = $failureHandler;
        $this->m_retryDelay = $retryDelay;
    }


    /**
     * Create a logger that fails over between the loggers provided by the factories, trying them in the order given.
     * It has no failure handler, and a retry delay of 3 seconds, which withFailureHandler() and withRetryDelay()
     * change.
     *
     * @param InterfaceLoggerFactory $primaryLoggerFactory - the factory for the logger to try first.
     *
     * @param InterfaceLoggerFactory $fallbackLoggerFactory - the factory for the logger to try if the primary fails.
     *
     * @param InterfaceLoggerFactory ...$moreFallbackLoggerFactories - the factories for any more loggers to try, in
     * order, if the ones before them fail.
     *
     * @return FailoverLogger
     */
    public static function create(
        InterfaceLoggerFactory $primaryLoggerFactory,
        InterfaceLoggerFactory $fallbackLoggerFactory,
        InterfaceLoggerFactory ...$moreFallbackLoggerFactories
    ) : FailoverLogger
    {
        $loggerFactories = new LoggerFactoryCollection(
            $primaryLoggerFactory,
            $fallbackLoggerFactory,
            ...$moreFallbackLoggerFactories
        );

        return new FailoverLogger($loggerFactories, null, RetryDelay::createDefault());
    }


    /**
     * Log the message to the first logger that is able to log it.
     * For each factory in turn, the logger is fetched from the factory and then logged to. An exception thrown by
     * either step counts as that logger failing, and the next factory is tried. Loggers that are within their retry
     * delay are skipped, unless the retry delay says to try them as a last resort when all the others fail.
     *
     * @param mixed $level - the level of the log, as a LogLevel enum, a case-insensitive PSR-3 level string (e.g.
     * "emergency"), or an integer from 0 (debug) to 7 (emergency). It is passed on as the level string.
     *
     * @param string|\Stringable $message - the message of the log, e.g "failed to connect to db"
     *
     * @param array<mixed> $context - name value pairs providing context to the log, e.g. ["dbname" => "yolo"]
     *
     * @return void
     *
     * @throws ExceptionAllLoggersFailed - if none of the loggers were able to log the message, because they either
     * failed or were skipped as they are within their retry delay.
     *
     * @throws Exceptions\ExceptionInvalidLogLevel - if the level is invalid. This is checked before trying any logger,
     * so that an invalid level is not mistaken for a failing logger.
     */
    public function log(mixed $level, string|\Stringable $message, array $context = []) : void
    {
        $levelString = $this->convertLogLevelMixedVariable($level)->value;
        $positionsToTry = $this->getPositionsToTry();
        $numToTry = count($positionsToTry);
        $failures = [];
        $workingLogger = null;
        $attempt = 0;

        while ($workingLogger === null && $attempt < $numToTry)
        {
            $position = $positionsToTry[$attempt];
            $loggerFactory = $this->m_loggerFactories->get($position);

            try
            {
                $logger = $loggerFactory->fetchLogger();

                try
                {
                    $logger->log($levelString, $message, $context);
                    $workingLogger = $logger;
                    unset($this->m_unavailableUntil[$position]);
                }
                catch (\Throwable $throwable)
                {
                    $failures[] = LoggerFailure::createLogFailure($position, $loggerFactory, $logger, $throwable);
                    $this->markUnavailable($position);
                }
            }
            catch (\Throwable $throwable)
            {
                $failures[] = LoggerFailure::createFetchFailure($position, $loggerFactory, $throwable);
                $this->markUnavailable($position);
            }

            $attempt++;
        }

        $failureCollection = new LoggerFailureCollection(...$failures);

        if ($workingLogger !== null)
        {
            $this->logFailures($workingLogger, $failureCollection);
        }

        if (!$failureCollection->isEmpty() && $this->m_failureHandler !== null)
        {
            $this->m_failureHandler->handleFailures($failureCollection, $workingLogger !== null);
        }

        if ($workingLogger === null)
        {
            $exceptionMessage = $this->describeAllFailed($failureCollection, $message);
            throw new ExceptionAllLoggersFailed($exceptionMessage, $failureCollection);
        }
    }


    /**
     * Get a copy of this logger that notifies a failure handler whenever one or more of its loggers fail. The copy
     * starts with no loggers in their timeout.
     *
     * @param InterfaceFailureHandler $failureHandler - the handler to notify. See
     * InterfaceFailureHandler::handleFailures() for when it is called.
     *
     * @return FailoverLogger
     */
    public function withFailureHandler(InterfaceFailureHandler $failureHandler) : FailoverLogger
    {
        return new FailoverLogger($this->m_loggerFactories, $failureHandler, $this->m_retryDelay);
    }


    /**
     * Get a copy of this logger with a different retry delay, e.g. RetryDelay::createUsingSeconds(10), or
     * RetryDelay::createNone() to try every logger on every log. The copy starts with no loggers in their timeout.
     * @param RetryDelay $retryDelay - how long to skip a logger for after it fails.
     * @return FailoverLogger
     */
    public function withRetryDelay(RetryDelay $retryDelay) : FailoverLogger
    {
        return new FailoverLogger($this->m_loggerFactories, $this->m_failureHandler, $retryDelay);
    }


    /**
     * Get the positions of the loggers to try, in the order to try them: the loggers that are not within their retry
     * delay, followed by the ones that are, if the retry delay says to try them as a last resort.
     * @return list<int>
     */
    private function getPositionsToTry() : array
    {
        $now = $this->getCurrentTime();
        $available = [];
        $skipped = [];

        foreach (array_keys($this->m_loggerFactories->toArray()) as $position)
        {
            $unavailableUntil = $this->m_unavailableUntil[$position] ?? 0.0;

            if ($unavailableUntil <= $now)
            {
                $available[] = $position;
            }
            else
            {
                $skipped[] = $position;
            }
        }

        if ($this->m_retryDelay->shouldRetrySkippedLoggersIfAllFail())
        {
            $positions = [...$available, ...$skipped];
        }
        else
        {
            $positions = $available;
        }

        return $positions;
    }


    /**
     * Describe why no logger was able to log the message, for the exception message.
     * @param LoggerFailureCollection $failures - the failure of each logger that was tried.
     * @param string|\Stringable $message - the message that couldn't be logged.
     * @return string
     */
    private function describeAllFailed(LoggerFailureCollection $failures, string|\Stringable $message) : string
    {
        $numLoggers = count($this->m_loggerFactories);
        $numFailed = count($failures);
        $numSkipped = $numLoggers - $numFailed;

        if ($numSkipped === 0)
        {
            $description = "All {$numLoggers} loggers failed to log the message: {$message}";
        }
        else
        {
            $description = "No logger was able to log the message, as {$numFailed} failed and {$numSkipped} were "
                . "skipped for being within their retry delay: {$message}";
        }

        return $description;
    }


    /**
     * Mark a logger as unavailable for the retry delay, starting from now. Does nothing if there is no retry delay.
     * @param int $position - the position of the logger that failed.
     * @return void
     */
    private function markUnavailable(int $position) : void
    {
        if ($this->m_retryDelay->getSeconds() > 0)
        {
            $this->m_unavailableUntil[$position] = $this->getCurrentTime() + $this->m_retryDelay->getSeconds();
        }
    }


    /**
     * Get the current time in seconds, from a monotonic clock, so that changes to the system clock do not extend or
     * shorten a logger's retry delay. Only useful for comparing against other values from this method, as it is not
     * a Unix timestamp.
     * @return float - the current time in seconds.
     */
    private function getCurrentTime() : float
    {
        return hrtime(true) / 1e9;
    }


    /**
     * Log each failure of the loggers that failed, to the logger that succeeded.
     * Any exception thrown whilst doing this is swallowed, as the original message has already been logged
     * successfully, and the caller should not be told that their log failed.
     * @param LoggerInterface $workingLogger - the logger that successfully logged the original message.
     * @param LoggerFailureCollection $failures - the failure of each logger that failed before it.
     * @return void
     */
    private function logFailures(LoggerInterface $workingLogger, LoggerFailureCollection $failures) : void
    {
        foreach ($failures as $failure)
        {
            try
            {
                $workingLogger->log(LogLevel::ERROR->value, $failure->getMessage(), $failure->getContext());
            }
            catch (\Throwable)
            {
                // Deliberately ignored - see the docblock.
            }
        }
    }
}
