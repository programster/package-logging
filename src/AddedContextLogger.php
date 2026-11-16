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

    /** @var array<mixed> */
    private readonly array $m_addedContext;


    /**
     * Create a logger that adds context to every log.
     * @param LoggerInterface $logger - the logger to pass every log on to.
     * @param array<mixed> $context - the context to add to every log, e.g. ['script' => 'import'].
     * @throws ExceptionContextRequired - if $context is empty, as it would then add nothing.
     */
    public function __construct(LoggerInterface $logger, array $context)
    {
        if (count($context) === 0)
        {
            throw new ExceptionContextRequired("Cannot create additional context logger with no context provided.");
        }

        $this->m_logger = $logger;
        $this->m_addedContext = $context;
    }


    /**
     * Pass the log on to the logger, with the added context merged into its context.
     * @param mixed $level - the level of the log, which is passed on as it was given.
     * @param string|\Stringable $message - the message of the log.
     * @param array<mixed> $context - context for this log, which overrides the added context for any shared keys.
     * @return void
     */
    public function log(mixed $level, string|\Stringable $message, array $context = []) : void
    {
        // array_replace() rather than array_merge(), which would add to integer keys rather than replacing them.
        $mergedContext = array_replace($this->m_addedContext, $context);
        $this->m_logger->log($level, $message, $mergedContext);
    }
}
