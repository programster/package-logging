<?php

/*
 * A simple decorator logger that will prefix every message with whatever you specify.
 */

declare(strict_types = 1);
namespace Programster\Log;


final class PrefixLogger extends AbstractLogger
{
    private string $m_prefix;
    private \Psr\Log\LoggerInterface $m_subLogger;


    /**
     *
     * @param string $prefix - the prefix to add to every message.
     * @param \Psr\Log\LoggerInterface $logger - the logger to forwarde every request through.
     */
    public function __construct(string $prefix, \Psr\Log\LoggerInterface $logger)
    {
        $this->m_prefix = $prefix;
        $this->m_subLogger = $logger;
    }


    public function log($level, $message, array $context = array())
    {
        $message = $this->m_prefix . $message;
        $this->m_subLogger->log($level, $message, $context);
    }
}

