<?php

/*
 * A logger that just logs to all the loggers it is made up of.
 * E.g. allows you to easily log to a database and email etc.
 */

declare(strict_types = 1);
namespace Programster\Log;


use Programster\Log\Exceptions\ExceptionContextRequired;
use Psr\Log\LoggerInterface;

final class AddedContextLogger extends AbstractLogger
{
    private LoggerInterface $m_logger;
    private array $m_addedContext;


    public function __construct(LoggerInterface $logger, array $context)
    {
        if (empty($context))
        {
            throw new ExceptionContextRequired("Cannot create additional context logger with no context provided.");
        }

        $this->m_logger = $logger;
        $this->m_addedContext = $context;
    }


    public function log($level, $message, array $context = array()) : void
    {
        $context = $this->m_addedContext;

        if (!empty($context))
        {
            $context = array_merge($this->m_addedContext, $context);
        }

        foreach ($this->m_logger as $logger)
        {
            $logger->log($level, $message, $context);
        }
    }
}

