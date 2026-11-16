<?php

/*
 * One of a MultiLogger's loggers failing to log a message: which logger it was, and what it threw. These are what
 * ExceptionMultiLoggerFailed holds.
 */

declare(strict_types = 1);
namespace Programster\Log;


use Psr\Log\LoggerInterface;

final readonly class MultiLoggerFailure
{
    private int $m_position;
    private LoggerInterface $m_logger;
    private \Throwable $m_throwable;


    /**
     * @param int $position - the position of the logger in the MultiLogger, from 0 for the first.
     * @param LoggerInterface $logger - the logger that failed.
     * @param \Throwable $throwable - what it threw.
     */
    public function __construct(int $position, LoggerInterface $logger, \Throwable $throwable)
    {
        $this->m_position = $position;
        $this->m_logger = $logger;
        $this->m_throwable = $throwable;
    }


    # Accessors
    public function getPosition() : int { return $this->m_position; }
    public function getLogger() : LoggerInterface { return $this->m_logger; }
    public function getThrowable() : \Throwable { return $this->m_throwable; }
}
