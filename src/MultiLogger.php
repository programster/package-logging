<?php

/*
 * A logger that logs every message to all the loggers it is made up of, in order.
 * E.g. allows you to easily log to a database and email etc.
 *
 * If any of the loggers fail, the message is still given to all the others, and then an ExceptionMultiLoggerFailed
 * is thrown, holding each failure, so that one broken logger doesn't stop the rest from logging.
 */

declare(strict_types = 1);
namespace Programster\Log;


use Programster\Log\Collections\LoggerCollection;
use Programster\Log\Collections\MultiLoggerFailureCollection;
use Programster\Log\Exceptions\ExceptionMultiLoggerFailed;
use Psr\Log\LoggerInterface;

final class MultiLogger extends AbstractLogger
{
    private readonly LoggerCollection $m_loggers;


    /**
     * Create a logger that logs to all of the loggers given. At least one is required, as a MultiLogger with none
     * would silently throw every log away.
     * @param LoggerInterface $logger - the first logger to log to.
     * @param LoggerInterface ...$moreLoggers - any more loggers to log to, in order.
     */
    public function __construct(LoggerInterface $logger, LoggerInterface ...$moreLoggers)
    {
        $this->m_loggers = new LoggerCollection($logger, ...$moreLoggers);
    }


    /**
     * Log the message to every logger, in order. A logger that throws doesn't stop the message being given to the
     * loggers after it: once every logger has been tried, an exception is thrown if any of them failed.
     *
     * @param mixed $level - the level of the log, as a LogLevel enum, a case-insensitive PSR-3 level string (e.g.
     * "emergency"), or an integer from 0 (debug) to 7 (emergency). It is passed on as the level string.
     *
     * @param string|\Stringable $message - the message of the log.
     *
     * @param array<mixed> $context - name value pairs providing context to the log.
     *
     * @return void
     *
     * @throws ExceptionMultiLoggerFailed - if one or more of the loggers failed, after the rest have logged it.
     *
     * @throws Exceptions\ExceptionInvalidLogLevel - if the level is invalid. This is checked before giving the message
     * to any logger, so that an invalid level is reported as the PSR-3 exception, rather than as every logger failing.
     */
    public function log(mixed $level, string|\Stringable $message, array $context = []) : void
    {
        $levelString = $this->convertLogLevelMixedVariable($level)->value;
        $failures = [];

        foreach ($this->m_loggers as $position => $logger)
        {
            try
            {
                $logger->log($levelString, $message, $context);
            }
            catch (\Throwable $throwable)
            {
                $failures[] = new MultiLoggerFailure($position, $logger, $throwable);
            }
        }

        if (count($failures) > 0)
        {
            $numFailed = count($failures);
            $numLoggers = count($this->m_loggers);
            $firstReason = $failures[0]->getThrowable()->getMessage();

            $exceptionMessage = "{$numFailed} of {$numLoggers} loggers failed to log the message '{$message}', the "
                . "first because: {$firstReason}";

            throw new ExceptionMultiLoggerFailed(
                $exceptionMessage,
                new MultiLoggerFailureCollection(...$failures),
                $numLoggers
            );
        }
    }
}
