<?php

/*
 * A test logger that records every log it receives, so that tests can assert on what was logged.
 * Deliberately not readonly, as it has to record logs as they come in.
 */

declare(strict_types = 1);
namespace Programster\Log\Tests\Doubles;


use Psr\Log\AbstractLogger;

final class RecordingLogger extends AbstractLogger
{
    /** @var array<int, array{level: mixed, message: string, context: array}> */
    private array $m_logs = [];


    public function log($level, string|\Stringable $message, array $context = []) : void
    {
        $this->m_logs[] = [
            'level' => $level,
            'message' => (string)$message,
            'context' => $context,
        ];
    }


    /**
     * @return array<int, array{level: mixed, message: string, context: array}>
     */
    public function getLogs() : array
    {
        return $this->m_logs;
    }
}
