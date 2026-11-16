<?php

/*
 * A simple decorator logger that will prefix every message with whatever you specify.
 */

declare(strict_types = 1);
namespace Programster\Log;


use Psr\Log\LoggerInterface;

final class PrefixLogger extends AbstractLogger
{
    private readonly string $m_prefix;
    private readonly LoggerInterface $m_subLogger;


    /**
     * Create a logger that adds a prefix to every message, before passing it on to another logger. This can be
     * useful for adding the service name to the beginning of every message before emailing etc.
     * @param string $prefix - the prefix to add to every message.
     * @param LoggerInterface $logger - the logger to pass every log on to.
     */
    public function __construct(string $prefix, LoggerInterface $logger)
    {
        $this->m_prefix = $prefix;
        $this->m_subLogger = $logger;
    }


    /**
     * Pass the log on to the logger, with the prefix added to the start of its message.
     * @param mixed $level - the level of the log, which is passed on as it was given.
     * @param string|\Stringable $message - the message of the log.
     * @param array<mixed> $context - name value pairs providing context to the log.
     * @return void
     */
    public function log(mixed $level, string|\Stringable $message, array $context = []) : void
    {
        $this->m_subLogger->log($level, $this->m_prefix . $message, $context);
    }
}
