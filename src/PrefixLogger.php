<?php

/*
 * A simple decorator logger that will prefix every message with whatever you specify.
 */

declare(strict_types = 1);
namespace Programster\Log;


use Psr\Log\LoggerInterface;

final class PrefixLogger extends AbstractLogger
{
    private string $m_prefix;
    private LoggerInterface $m_subLogger;


    /**
     * Create a prefix logger that will prefix the message with anything  you specify. This can be useful for
     * adding the service name to the beginning of every message before emailing etc.
     * @param string $prefix - the prefix to add to every message.
     * @param LoggerInterface $logger - the logger to forwarde every request through.
     */
    public function __construct(string $prefix, LoggerInterface $logger)
    {
        $this->m_prefix = $prefix;
        $this->m_subLogger = $logger;
    }


    public function log($level, $message, array $context = array()) : void
    {
        $message = $this->m_prefix . $message;
        $this->m_subLogger->log($level, $message, $context);
    }
}

