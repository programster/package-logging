<?php

/*
 * A logger that just logs to all the loggers it is made up of.
 * E.g. allows you to easily log to a database and email etc.
 */

declare(strict_types = 1);
namespace Programster\Log;


final class MultiLogger extends AbstractLogger
{
    private $m_loggers;


    public function __construct(\Psr\Log\LoggerInterface ...$loggers)
    {
        $this->m_loggers = $loggers;
    }


    public function log($level, $message, array $context = array())
    {
        foreach ($this->m_loggers as $logger)
        {
            $logger->log($level, $message, $context);
        }
    }
}

