<?php

/*
 * A logger that only logs if the minimum threshold is met.
 * This is useful for if one wants to filter out all debuglogs etc.
 */

declare(strict_types = 1);
namespace Programster\Log;


final class MinLevelLogger Extends AbstractLogger
{
    private $m_threshold;
    private $m_subLogger;


    /**
     * Create the MinLevelLogger.
     * @param int $threshold - the threshold to pass. E.g.
     *                         0 = debug or higher (all).
     *                         1 = info or higher.
     *                         2 = warning or higher etc.
     * @param \iRAP\Logging\LoggerInterface $logger
     */
    public function __construct(int $threshold, \Psr\Log\LoggerInterface $logger)
    {
        $this->m_threshold = $threshold;
        $this->m_subLogger = $logger;
    }


    public function log($level, $message, array $context = array())
    {
        $this->validateLogLevel($level);

        if ($this->convertLogLevelToInteger($level) >= $this->m_threshold)
        {
            $this->m_subLogger->log($level, $message, $context);
        }
    }
}
