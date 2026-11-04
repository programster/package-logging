<?php

/*
 * A test logger factory that always returns the logger it was given, and records how many times it was
 * asked for it. Deliberately not readonly, as it has to count calls as they come in.
 */

declare(strict_types = 1);
namespace Programster\Log\Tests\Doubles;


use Programster\Log\Interfaces\InterfaceLoggerFactory;
use Psr\Log\LoggerInterface;

final class FixedLoggerFactory implements InterfaceLoggerFactory
{
    private readonly LoggerInterface $m_logger;
    private int $m_callCount = 0;


    public function __construct(LoggerInterface $logger)
    {
        $this->m_logger = $logger;
    }


    public function getLogger() : LoggerInterface
    {
        $this->m_callCount++;
        return $this->m_logger;
    }


    public function getCallCount() : int
    {
        return $this->m_callCount;
    }
}
