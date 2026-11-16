<?php

/*
 * A test logger that always throws, to simulate a logger that is broken (e.g. a database that is down).
 */

declare(strict_types = 1);
namespace Programster\Log\Testing\Doubles;


use Psr\Log\AbstractLogger;

final class FailingLogger extends AbstractLogger
{
    private readonly string $m_errorMessage;


    public function __construct(string $errorMessage = "Logger is broken")
    {
        $this->m_errorMessage = $errorMessage;
    }


    /**
     * Always throw.
     * @param mixed $level - ignored.
     * @param string|\Stringable $message - ignored.
     * @param array<mixed> $context - ignored.
     * @return void
     * @throws \RuntimeException - always, with the error message it was given.
     */
    public function log(mixed $level, string|\Stringable $message, array $context = []) : void
    {
        throw new \RuntimeException($this->m_errorMessage);
    }
}
