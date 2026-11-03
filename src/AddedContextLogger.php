<?php

/*
 * A logger that adds the provided context to every log, before passing it on to the logger it wraps.
 * E.g. tag every log from a script with ['script' => 'import'], so you can filter by it later.
 * Context provided at log time overrides the added context for any shared keys.
 */

declare(strict_types = 1);
namespace Programster\Log;


use Programster\Log\Exceptions\ExceptionContextRequired;
use Psr\Log\LoggerInterface;

final class AddedContextLogger extends AbstractLogger
{
    private readonly LoggerInterface $m_logger;
    private readonly array $m_addedContext;


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
        // Context provided at log time overrides the added context for any shared keys.
        $mergedContext = array_merge($this->m_addedContext, $context);
        $this->m_logger->log($level, $message, $mergedContext);
    }
}

