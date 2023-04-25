<?php

/*
 * A logger that only logs if the minimum threshold is met.
 * This is useful for if one wants to filter out all debuglogs etc.
 */

declare(strict_types = 1);
namespace Programster\Log;


use Psr\Log\LoggerInterface;

final class MinLevelLogger Extends AbstractLogger
{
    private LogLevel $m_threshold;
    private LoggerInterface $m_subLogger;


    /**
     * Create the MinLevelLogger.
     * @param int $threshold - the threshold to pass. E.g.
     *                         0 = debug or higher (all).
     *                         1 = info or higher.
     *                         2 = warning or higher etc.
     * @param LoggerInterface $logger
     */
    public function __construct(int|LogLevel|string $threshold, LoggerInterface $logger)
    {
        $logLevelThresholdEnum = $this->convertLogLevelMixedVariable($threshold);
        $this->m_threshold = $logLevelThresholdEnum;
        $this->m_subLogger = $logger;
    }


    public function log($level, $message, array $context = array()) : void
    {
        $logLevelEnum = $this->convertLogLevelMixedVariable($level);

        if ($logLevelEnum->toInt() >= $this->m_threshold->toInt())
        {
            $this->m_subLogger->log($logLevelEnum->value, $message, $context);
        }
    }
}
