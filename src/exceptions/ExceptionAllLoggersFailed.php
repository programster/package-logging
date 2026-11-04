<?php

/*
 * An exception thrown by the FailoverLogger when none of its loggers were able to log a message, because each
 * one either failed, or was skipped for being within its retry delay. Only the loggers that were actually
 * tried have a failure, so the list of failures is empty if every logger was skipped.
 */

declare(strict_types = 1);
namespace Programster\Log\Exceptions;


final class ExceptionAllLoggersFailed extends \Exception
{
    /** @var \Throwable[] */
    private readonly array $m_failures;


    /**
     * @param string $message - the exception message.
     * @param \Throwable[] $failures - the exceptions thrown by each logger that was tried, in the order they
     * were tried. Empty if every logger was skipped.
     */
    public function __construct(string $message, array $failures)
    {
        $previous = (count($failures) > 0) ? $failures[array_key_last($failures)] : null;
        parent::__construct($message, 0, $previous);
        $this->m_failures = array_values($failures);
    }


    /**
     * Get the exceptions thrown by each logger, in the order the loggers were tried.
     * @return \Throwable[]
     */
    public function getFailures() : array
    {
        return $this->m_failures;
    }
}
