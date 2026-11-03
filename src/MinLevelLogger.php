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
    private readonly LogLevel $m_threshold;
    private readonly LoggerInterface $m_subLogger;


    /**
     * Create the MinLevelLogger.
     * @param int|LogLevel|string $threshold - the minimum level to pass through, as a LogLevel enum, a
     *                         PSR-3 level string (e.g. "warning"), or an integer:
     *                         0 = debug or higher (all).
     *                         1 = info or higher.
     *                         2 = notice or higher.
     *                         3 = warning or higher.
     *                         4 = error or higher.
     *                         5 = critical or higher.
     *                         6 = alert or higher.
     *                         7 = emergency only.
     *                         Note that this is the reverse of syslog/RFC 5424 numbering.
     * @param LoggerInterface $logger
     * @throws \Programster\Log\Exceptions\ExceptionInvalidLogLevel - if the threshold is not a valid level.
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
