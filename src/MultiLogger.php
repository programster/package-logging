<?php

/*
 * A logger that logs every message to all the loggers it is made up of, in order.
 * E.g. allows you to easily log to a database and email etc.
 */

declare(strict_types = 1);
namespace Programster\Log;


use Programster\Log\Collections\LoggerCollection;
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
     * Log the message to every logger, in order. If one throws, the exception is passed on, and the loggers after it
     * are not logged to. Use a FailoverLogger for each one that should be allowed to fail.
     * @param mixed $level - the level of the log, which is passed on as it was given.
     * @param string|\Stringable $message - the message of the log.
     * @param array<mixed> $context - name value pairs providing context to the log.
     * @return void
     */
    public function log(mixed $level, string|\Stringable $message, array $context = []) : void
    {
        foreach ($this->m_loggers as $logger)
        {
            $logger->log($level, $message, $context);
        }
    }
}
