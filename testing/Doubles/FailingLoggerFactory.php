<?php

/*
 * A test logger factory that always throws, to simulate a logger that cannot be constructed (e.g. failing to connect
 * to a database).
 */

declare(strict_types = 1);
namespace Programster\Log\Testing\Doubles;


use Programster\Log\Interfaces\InterfaceLoggerFactory;
use Psr\Log\LoggerInterface;

final class FailingLoggerFactory implements InterfaceLoggerFactory
{
    private readonly string $m_errorMessage;


    public function __construct(string $errorMessage = "Failed to construct logger")
    {
        $this->m_errorMessage = $errorMessage;
    }


    /**
     * Always throw.
     * @return LoggerInterface
     * @throws \RuntimeException - always, with the error message it was given.
     */
    public function fetchLogger() : LoggerInterface
    {
        throw new \RuntimeException($this->m_errorMessage);
    }
}
