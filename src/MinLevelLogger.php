<?php

/*
 * A logger that only passes on logs that are at or above a minimum level.
 * This is useful for if one wants to filter out all debug logs etc.
 */

declare(strict_types = 1);
namespace Programster\Log;


use Psr\Log\LoggerInterface;

final class MinLevelLogger extends AbstractLogger
{
    private readonly LogLevel $m_threshold;
    private readonly LoggerInterface $m_subLogger;


    /**
     * Create a logger that only passes on logs that are at or above a minimum level.
     * @param LogLevel $threshold - the minimum level to pass on, e.g. LogLevel::WARNING for warning or higher.
     * @param LoggerInterface $logger - the logger to pass the logs on to.
     */
    public function __construct(LogLevel $threshold, LoggerInterface $logger)
    {
        $this->m_threshold = $threshold;
        $this->m_subLogger = $logger;
    }


    /**
     * Pass the log on to the logger, if its level is at or above the threshold.
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
     * @throws Exceptions\ExceptionInvalidLogLevel - if the level is invalid.
     */
    public function log(mixed $level, string|\Stringable $message, array $context = []) : void
    {
        $logLevel = $this->convertLogLevelMixedVariable($level);

        if ($logLevel->toInt() >= $this->m_threshold->toInt())
        {
            $this->m_subLogger->log($logLevel->value, $message, $context);
        }
    }
}
