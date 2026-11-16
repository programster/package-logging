<?php

/*
 * A test logger that records every log it receives, so that tests can check what was logged.
 * Deliberately not readonly, as it has to record logs as they come in.
 */

declare(strict_types = 1);
namespace Programster\Log\Testing\Doubles;


use Psr\Log\AbstractLogger;

final class RecordingLogger extends AbstractLogger
{
    /** @var list<array{level: mixed, message: string, context: array<mixed>}> */
    private array $m_logs = [];


    /**
     * Record the log.
     * @param mixed $level - the level of the log, recorded as it was given.
     * @param string|\Stringable $message - the message, recorded as a string.
     * @param array<mixed> $context - the context.
     * @return void
     */
    public function log(mixed $level, string|\Stringable $message, array $context = []) : void
    {
        $this->m_logs[] = [
            'level' => $level,
            'message' => (string)$message,
            'context' => $context,
        ];
    }


    /**
     * Get the messages logged, leaving out the FailoverLogger's logs about other loggers failing.
     * @return list<string>
     */
    public function getOwnMessages() : array
    {
        $messages = [];

        foreach ($this->m_logs as $log)
        {
            if (!str_starts_with($log['message'], "FailoverLogger:"))
            {
                $messages[] = $log['message'];
            }
        }

        return $messages;
    }


    /**
     * Get every message logged, in order.
     * @return list<string>
     */
    public function getMessages() : array
    {
        return array_column($this->m_logs, 'message');
    }


    # Accessors
    /** @return list<array{level: mixed, message: string, context: array<mixed>}> */
    public function getLogs() : array { return $this->m_logs; }
}
