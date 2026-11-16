<?php

/*
 * An exception thrown by the FailoverLogger when none of its loggers were able to log a message, because each one
 * either failed, or was skipped for being within its retry delay. Only the loggers that were actually tried have a
 * failure, so the failures are empty if every logger was skipped.
 */

declare(strict_types = 1);
namespace Programster\Log\Exceptions;


use Programster\Log\Collections\LoggerFailureCollection;

final class ExceptionAllLoggersFailed extends \Exception
{
    private readonly LoggerFailureCollection $m_failures;


    /**
     * @param string $message - the exception message.
     *
     * @param LoggerFailureCollection $failures - the failure of each logger that was tried, in the order they were
     * tried. Empty if every logger was skipped. The last one's exception is used as the previous exception.
     */
    public function __construct(string $message, LoggerFailureCollection $failures)
    {
        $previous = $failures->isEmpty() ? null : $failures->last()->getThrowable();
        parent::__construct($message, 0, $previous);
        $this->m_failures = $failures;
    }


    # Accessors
    public function getFailures() : LoggerFailureCollection { return $this->m_failures; }
}
