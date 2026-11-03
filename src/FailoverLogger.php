<?php

/*
 * A logger that tries each of its loggers in order until one of them successfully logs the message.
 * E.g. try logging to a database, and if that fails, fall back to logging to a file.
 *
 * Once a logger succeeds, the original message is logged to it, followed by an error log for each
 * exception thrown by the loggers that failed before it, so that you can find out why the
 * preferred logger(s) failed.
 *
 * Optionally, an InterfaceFailureHandler can be provided that is notified whenever one or more loggers
 * fail, so that you can take your own action, such as alerting someone.
 */

declare(strict_types = 1);
namespace Programster\Log;


use Programster\Log\Exceptions\ExceptionAllLoggersFailed;
use Programster\Log\Exceptions\ExceptionInvalidFallbackLoggers;
use Programster\Log\Interfaces\InterfaceFailureHandler;
use Psr\Log\LoggerInterface;

final class FailoverLogger extends AbstractLogger
{
    /** @var LoggerInterface[] */
    private readonly array $m_loggers;

    private readonly ?InterfaceFailureHandler $m_failureHandler;


    /**
     * Create a logger that fails over between the provided loggers, trying them in the order given.
     * @param LoggerInterface $primaryLogger - the logger to try first.
     * @param LoggerInterface|LoggerInterface[] $fallbackLoggers - the logger, or ordered array of loggers,
     * to try if the primary logger fails.
     * @param InterfaceFailureHandler|null $failureHandler - optional handler that is notified whenever one
     * or more loggers fail. See InterfaceFailureHandler::handleFailures() for when it is called.
     * @throws ExceptionInvalidFallbackLoggers - if $fallbackLoggers is an empty array, or contains
     * anything that does not implement LoggerInterface.
     */
    public function __construct(
        LoggerInterface $primaryLogger,
        LoggerInterface|array $fallbackLoggers,
        ?InterfaceFailureHandler $failureHandler = null
    )
    {
        if (is_array($fallbackLoggers))
        {
            $this->validateFallbackLoggers($fallbackLoggers);
            $this->m_loggers = [$primaryLogger, ...array_values($fallbackLoggers)];
        }
        else
        {
            $this->m_loggers = [$primaryLogger, $fallbackLoggers];
        }

        $this->m_failureHandler = $failureHandler;
    }


    /**
     * Log the message to the first logger that is able to log it.
     * @param mixed $level - the level of the log, as a LogLevel enum, a case-insensitive PSR-3 level string
     * (e.g. "emergency"), or an integer from 0 (debug) to 7 (emergency). It is passed on as the level string.
     * @param string|\Stringable $message - the message of the log, e.g "failed to connect to db"
     * @param array $context - name value pairs providing context to the log, e.g. "dbname => "yolo")
     * @return void
     * @throws ExceptionAllLoggersFailed - if none of the loggers were able to log the message.
     * @throws \Programster\Log\Exceptions\ExceptionInvalidLogLevel - if the level is invalid. This is checked
     * before trying any logger, so that an invalid level is not mistaken for a failing logger.
     */
    public function log($level, string|\Stringable $message, array $context = array()) : void
    {
        $levelString = $this->convertLogLevelMixedVariable($level)->value;
        $failures = [];
        $logged = false;
        $numLoggers = count($this->m_loggers);
        $index = 0;

        while ($logged === false && $index < $numLoggers)
        {
            $logger = $this->m_loggers[$index];

            try
            {
                $logger->log($levelString, $message, $context);
                $logged = true;
            }
            catch (\Throwable $throwable)
            {
                $failures[$index] = $throwable;
            }

            if ($logged)
            {
                $this->logFailures($logger, $failures);
            }

            $index++;
        }

        if (count($failures) > 0 && $this->m_failureHandler !== null)
        {
            $this->m_failureHandler->handleFailures(array_values($failures), $logged);
        }

        if ($logged === false)
        {
            throw new ExceptionAllLoggersFailed("All {$numLoggers} loggers failed to log the message: {$message}", $failures);
        }
    }


    /**
     * Check that the array of fallback loggers is non-empty, and that every element implements LoggerInterface.
     * @param array $fallbackLoggers - the array of fallback loggers to check.
     * @return void
     * @throws ExceptionInvalidFallbackLoggers - if the array is empty or contains an invalid element.
     */
    private function validateFallbackLoggers(array $fallbackLoggers) : void
    {
        if (count($fallbackLoggers) === 0)
        {
            throw new ExceptionInvalidFallbackLoggers("At least one fallback logger is required.");
        }

        foreach ($fallbackLoggers as $key => $fallbackLogger)
        {
            if (!($fallbackLogger instanceof LoggerInterface))
            {
                $type = get_debug_type($fallbackLogger);
                $msg = "Fallback logger at key '{$key}' is of type {$type}, which does not implement " . LoggerInterface::class;
                throw new ExceptionInvalidFallbackLoggers($msg);
            }
        }
    }


    /**
     * Log the exceptions thrown by the loggers that failed, to the logger that succeeded.
     * Any exception thrown whilst doing this is swallowed, as the original message has already been
     * logged successfully, and the caller should not be told that their log failed.
     * @param LoggerInterface $workingLogger - the logger that successfully logged the original message.
     * @param \Throwable[] $failures - the exceptions thrown, indexed by the position of the logger that threw them.
     * @return void
     */
    private function logFailures(LoggerInterface $workingLogger, array $failures) : void
    {
        foreach ($failures as $loggerIndex => $failure)
        {
            $failedLogger = $this->m_loggers[$loggerIndex];

            // Use scalar values rather than the throwable itself so that loggers that serialize
            // context (e.g. JSON/CSV) get meaningful output.
            $failureContext = [
                'failed_logger_index' => $loggerIndex,
                'failed_logger_class' => get_class($failedLogger),
                'exception_class' => get_class($failure),
                'exception_message' => $failure->getMessage(),
                'exception_code' => $failure->getCode(),
                'exception_file' => $failure->getFile(),
                'exception_line' => $failure->getLine(),
                'exception_trace' => $failure->getTraceAsString(),
            ];

            try
            {
                $workingLogger->log(
                    \Psr\Log\LogLevel::ERROR,
                    "FailoverLogger: logger " . get_class($failedLogger) . " failed to log a message: " . $failure->getMessage(),
                    $failureContext
                );
            }
            catch (\Throwable)
            {
                // Deliberately ignored - see the docblock.
            }
        }
    }
}
