<?php

/*
 * A logger that tries each of its loggers in order until one of them successfully logs the message.
 * E.g. try logging to a database, and if that fails, fall back to logging to a file.
 *
 * Loggers are provided through logger factories (InterfaceLoggerFactory), which are asked for their
 * logger every time a message is logged. This means that a logger that fails to be constructed (e.g. it
 * cannot connect to the database) is failed over just like a logger that fails to log. The logger is not
 * cached by this class; a factory can cache it itself if it wishes.
 *
 * Once a logger succeeds, the original message is logged to it, followed by an error log for each
 * exception thrown by the factories/loggers that failed before it, so that you can find out why the
 * preferred logger(s) failed.
 *
 * Optionally, an InterfaceFailureHandler can be provided that is notified whenever one or more loggers
 * fail, so that you can take your own action, such as alerting someone.
 *
 * A logger that fails is skipped for a retry delay, which defaults to 3 seconds (0 disables it),
 * so that subsequent logs go straight to the next available logger, rather than waiting on the broken one
 * (e.g. a database connection timing out) every time. Each logger has its own timeout. A logger within its
 * timeout is not tried at all, even if every other logger fails.
 */

declare(strict_types = 1);
namespace Programster\Log;


use Programster\Log\Exceptions\ExceptionAllLoggersFailed;
use Programster\Log\Exceptions\ExceptionInvalidFallbackLoggers;
use Programster\Log\Exceptions\ExceptionInvalidRetryDelay;
use Programster\Log\Interfaces\InterfaceFailureHandler;
use Programster\Log\Interfaces\InterfaceLoggerFactory;
use Psr\Log\LoggerInterface;

final class FailoverLogger extends AbstractLogger
{
    /** @var InterfaceLoggerFactory[] */
    private readonly array $m_loggerFactories;

    private readonly ?InterfaceFailureHandler $m_failureHandler;

    private readonly float $m_retryDelaySeconds;

    /**
     * @var array<int, float> - for each logger that is currently in its timeout, the time (in seconds, from
     * getCurrentTime()) until which it is skipped, indexed by the position of its factory. Deliberately not
     * readonly, as it is updated whenever a logger fails or succeeds.
     */
    private array $m_unavailableUntil = [];


    /**
     * Create a logger that fails over between the loggers provided by the factories, trying them in the
     * order given.
     * @param InterfaceLoggerFactory $primaryLoggerFactory - the factory for the logger to try first.
     * @param InterfaceLoggerFactory|InterfaceLoggerFactory[] $fallbackLoggerFactories - the factory, or
     * ordered array of factories, for the logger(s) to try if the primary logger fails.
     * @param InterfaceFailureHandler|null $failureHandler - optional handler that is notified whenever one
     * or more loggers fail. See InterfaceFailureHandler::handleFailures() for when it is called.
     * @param int|float $retryDelaySeconds - how long a logger is skipped for after it fails, e.g. 3 means that
     * for 3 seconds after a logger fails, logs go straight to the next available logger. Fractions of a
     * second are allowed. Defaults to 3. Set to 0 to try every logger on every log, so that a failed logger
     * is tried again on the very next log.
     * @throws ExceptionInvalidFallbackLoggers - if $fallbackLoggerFactories is an empty array, or contains
     * anything that does not implement InterfaceLoggerFactory.
     * @throws ExceptionInvalidRetryDelay - if $retryDelaySeconds is negative.
     */
    public function __construct(
        InterfaceLoggerFactory $primaryLoggerFactory,
        InterfaceLoggerFactory|array $fallbackLoggerFactories,
        ?InterfaceFailureHandler $failureHandler = null,
        int|float $retryDelaySeconds = 3
    )
    {
        if ($retryDelaySeconds < 0)
        {
            throw new ExceptionInvalidRetryDelay("The retry delay must not be negative, but {$retryDelaySeconds} was given.");
        }

        if (is_array($fallbackLoggerFactories))
        {
            $this->validateFallbackLoggerFactories($fallbackLoggerFactories);
            $this->m_loggerFactories = [$primaryLoggerFactory, ...array_values($fallbackLoggerFactories)];
        }
        else
        {
            $this->m_loggerFactories = [$primaryLoggerFactory, $fallbackLoggerFactories];
        }

        $this->m_failureHandler = $failureHandler;
        $this->m_retryDelaySeconds = (float)$retryDelaySeconds;
    }


    /**
     * Log the message to the first logger that is able to log it.
     * For each factory in turn, the logger is fetched from the factory and then logged to. An exception
     * thrown by either step counts as that logger failing, and the next factory is tried. Loggers that are
     * within their retry delay are skipped.
     * @param mixed $level - the level of the log, as a LogLevel enum, a case-insensitive PSR-3 level string
     * (e.g. "emergency"), or an integer from 0 (debug) to 7 (emergency). It is passed on as the level string.
     * @param string|\Stringable $message - the message of the log, e.g "failed to connect to db"
     * @param array $context - name value pairs providing context to the log, e.g. "dbname => "yolo")
     * @return void
     * @throws ExceptionAllLoggersFailed - if none of the loggers were able to log the message, because they
     * either failed or were skipped as they are within their retry delay.
     * @throws \Programster\Log\Exceptions\ExceptionInvalidLogLevel - if the level is invalid. This is checked
     * before trying any logger, so that an invalid level is not mistaken for a failing logger.
     */
    public function log($level, string|\Stringable $message, array $context = array()) : void
    {
        $levelString = $this->convertLogLevelMixedVariable($level)->value;
        $failures = [];
        $failedLoggerClasses = [];
        $logged = false;
        $numLoggers = count($this->m_loggerFactories);
        $indexesToTry = $this->getIndexesToTry();
        $numToTry = count($indexesToTry);
        $position = 0;

        while ($logged === false && $position < $numToTry)
        {
            $index = $indexesToTry[$position];
            $logger = null;

            try
            {
                $logger = $this->m_loggerFactories[$index]->getLogger();
                $logger->log($levelString, $message, $context);
                $logged = true;
            }
            catch (\Throwable $throwable)
            {
                $failures[$index] = $throwable;

                // Null if the factory failed to provide a logger.
                $failedLoggerClasses[$index] = ($logger !== null) ? get_class($logger) : null;
                $this->markUnavailable($index);
            }

            if ($logged)
            {
                unset($this->m_unavailableUntil[$index]);
                $this->logFailures($logger, $failures, $failedLoggerClasses);
            }

            $position++;
        }

        if (count($failures) > 0 && $this->m_failureHandler !== null)
        {
            $this->m_failureHandler->handleFailures(array_values($failures), $logged);
        }

        if ($logged === false)
        {
            $numSkipped = $numLoggers - $numToTry;

            if ($numSkipped === 0)
            {
                $exceptionMessage = "All {$numLoggers} loggers failed to log the message: {$message}";
            }
            else
            {
                $numFailed = count($failures);
                $exceptionMessage = "No logger was able to log the message, as {$numFailed} failed and {$numSkipped} were skipped for being within their retry delay: {$message}";
            }

            throw new ExceptionAllLoggersFailed($exceptionMessage, $failures);
        }
    }


    /**
     * Get the positions of the loggers to try, in order of preference. These are the loggers that are not
     * within their retry delay.
     * @return int[] - the positions of the logger factories to try.
     */
    private function getIndexesToTry() : array
    {
        $now = $this->getCurrentTime();

        return array_values(array_filter(
            array_keys($this->m_loggerFactories),
            fn(int $index) : bool => ($this->m_unavailableUntil[$index] ?? 0.0) <= $now
        ));
    }


    /**
     * Mark a logger as unavailable for the retry delay, starting from now. Does nothing if there is no
     * retry delay.
     * @param int $index - the position of the logger factory that failed.
     * @return void
     */
    private function markUnavailable(int $index) : void
    {
        if ($this->m_retryDelaySeconds > 0)
        {
            $this->m_unavailableUntil[$index] = $this->getCurrentTime() + $this->m_retryDelaySeconds;
        }
    }


    /**
     * Get the current time in seconds, from a monotonic clock, so that changes to the system clock do not
     * extend or shorten a logger's retry delay. Only useful for comparing against other values from this
     * method, as it is not a Unix timestamp.
     * @return float - the current time in seconds.
     */
    private function getCurrentTime() : float
    {
        return hrtime(true) / 1e9;
    }


    /**
     * Check that the array of fallback logger factories is non-empty, and that every element implements
     * InterfaceLoggerFactory.
     * @param array $fallbackLoggerFactories - the array of fallback logger factories to check.
     * @return void
     * @throws ExceptionInvalidFallbackLoggers - if the array is empty or contains an invalid element.
     */
    private function validateFallbackLoggerFactories(array $fallbackLoggerFactories) : void
    {
        if (count($fallbackLoggerFactories) === 0)
        {
            throw new ExceptionInvalidFallbackLoggers("At least one fallback logger factory is required.");
        }

        foreach ($fallbackLoggerFactories as $key => $fallbackLoggerFactory)
        {
            if (!($fallbackLoggerFactory instanceof InterfaceLoggerFactory))
            {
                $type = get_debug_type($fallbackLoggerFactory);
                $msg = "Fallback logger factory at key '{$key}' is of type {$type}, which does not implement " . InterfaceLoggerFactory::class;
                throw new ExceptionInvalidFallbackLoggers($msg);
            }
        }
    }


    /**
     * Log the exceptions thrown by the factories/loggers that failed, to the logger that succeeded.
     * Any exception thrown whilst doing this is swallowed, as the original message has already been
     * logged successfully, and the caller should not be told that their log failed.
     * @param LoggerInterface $workingLogger - the logger that successfully logged the original message.
     * @param \Throwable[] $failures - the exceptions thrown, indexed by the position of the factory whose
     * logger failed.
     * @param array<int, string|null> $failedLoggerClasses - the class of each failed logger, indexed as per
     * $failures, or null where the factory itself failed to provide a logger.
     * @return void
     */
    private function logFailures(LoggerInterface $workingLogger, array $failures, array $failedLoggerClasses) : void
    {
        foreach ($failures as $loggerIndex => $failure)
        {
            $factoryClass = get_class($this->m_loggerFactories[$loggerIndex]);
            $failedLoggerClass = $failedLoggerClasses[$loggerIndex];

            if ($failedLoggerClass === null)
            {
                $failedDuring = "getLogger";
                $failureMessage = "FailoverLogger: logger factory {$factoryClass} failed to provide a logger: " . $failure->getMessage();
            }
            else
            {
                $failedDuring = "log";
                $failureMessage = "FailoverLogger: logger {$failedLoggerClass} failed to log a message: " . $failure->getMessage();
            }

            // Use scalar values rather than the throwable itself so that loggers that serialize
            // context (e.g. JSON/CSV) get meaningful output.
            $failureContext = [
                'failed_logger_index' => $loggerIndex,
                'failed_logger_factory_class' => $factoryClass,
                'failed_logger_class' => $failedLoggerClass ?? "",
                'failed_during' => $failedDuring,
                'exception_class' => get_class($failure),
                'exception_message' => $failure->getMessage(),
                'exception_code' => $failure->getCode(),
                'exception_file' => $failure->getFile(),
                'exception_line' => $failure->getLine(),
                'exception_trace' => $failure->getTraceAsString(),
            ];

            try
            {
                $workingLogger->log(\Psr\Log\LogLevel::ERROR, $failureMessage, $failureContext);
            }
            catch (\Throwable)
            {
                // Deliberately ignored - see the docblock.
            }
        }
    }
}
