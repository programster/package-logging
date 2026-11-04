<?php

/*
 * A test logger factory that can be switched between failing (throwing from getLogger(), as if the logger
 * could not be constructed) and working (returning a RecordingLogger). It records how many times it was
 * asked for its logger. Deliberately not readonly, as its state changes during a test.
 */

declare(strict_types = 1);
namespace Programster\Log\Tests\Doubles;


use Programster\Log\Interfaces\InterfaceLoggerFactory;
use Psr\Log\LoggerInterface;

final class SwitchableLoggerFactory implements InterfaceLoggerFactory
{
    private readonly RecordingLogger $m_logger;
    private readonly string $m_name;
    private bool $m_failing;
    private int $m_callCount = 0;


    public function __construct(string $name, bool $failing = false)
    {
        $this->m_logger = new RecordingLogger();
        $this->m_name = $name;
        $this->m_failing = $failing;
    }


    public function getLogger() : LoggerInterface
    {
        $this->m_callCount++;

        if ($this->m_failing)
        {
            throw new \RuntimeException("{$this->m_name} is down");
        }

        return $this->m_logger;
    }


    public function setFailing(bool $failing) : void
    {
        $this->m_failing = $failing;
    }


    public function getCallCount() : int
    {
        return $this->m_callCount;
    }


    /**
     * Get the messages logged to this factory's logger (excluding failure logs about other loggers).
     * @return string[]
     */
    public function getLoggedMessages() : array
    {
        $logs = array_filter($this->m_logger->getLogs(), fn(array $log) : bool => !str_starts_with($log['message'], "FailoverLogger:"));
        return array_values(array_column($logs, 'message'));
    }
}
