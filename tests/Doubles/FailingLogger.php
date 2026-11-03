<?php

/*
 * A test logger that always throws, to simulate a logger that is broken (e.g. a database that is down).
 */

declare(strict_types = 1);
namespace Programster\Log\Tests\Doubles;


use Psr\Log\AbstractLogger;

final class FailingLogger extends AbstractLogger
{
    private readonly string $m_errorMessage;


    public function __construct(string $errorMessage = "Logger is broken")
    {
        $this->m_errorMessage = $errorMessage;
    }


    public function log($level, string|\Stringable $message, array $context = []) : void
    {
        throw new \RuntimeException($this->m_errorMessage);
    }


    public function getErrorMessage() : string
    {
        return $this->m_errorMessage;
    }
}
