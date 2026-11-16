<?php

/*
 * An exception thrown by a MultiLogger when one or more of its loggers failed to log a message. It's only thrown
 * after the message has been given to every one of the loggers, so the others still logged it.
 */

declare(strict_types = 1);
namespace Programster\Log\Exceptions;


use Programster\Log\Collections\MultiLoggerFailureCollection;

final class ExceptionMultiLoggerFailed extends \Exception
{
    private readonly MultiLoggerFailureCollection $m_failures;
    private readonly int $m_numLoggers;


    /**
     * @param string $message - the exception message.
     *
     * @param MultiLoggerFailureCollection $failures - the failure of each logger that failed, in the order of the
     * loggers. It mustn't be empty. The first one's exception is used as the previous exception.
     *
     * @param int $numLoggers - how many loggers the MultiLogger has, including the ones that worked.
     *
     * @throws \LogicException - if $failures is empty, as then nothing failed, or there are more failures than
     * loggers.
     */
    public function __construct(string $message, MultiLoggerFailureCollection $failures, int $numLoggers)
    {
        if ($failures->isEmpty())
        {
            throw new \LogicException(self::class . " needs at least one failure, as it means a logger failed.");
        }

        if (count($failures) > $numLoggers)
        {
            $msg = "There can't be more failures (" . count($failures) . ") than loggers ({$numLoggers}).";
            throw new \LogicException($msg);
        }

        parent::__construct($message, 0, $failures->first()->getThrowable());
        $this->m_failures = $failures;
        $this->m_numLoggers = $numLoggers;
    }


    /**
     * Get whether every logger failed, so the message wasn't logged anywhere.
     * @return bool
     */
    public function didEveryLoggerFail() : bool
    {
        return count($this->m_failures) === $this->m_numLoggers;
    }


    # Accessors
    public function getFailures() : MultiLoggerFailureCollection { return $this->m_failures; }
    public function getNumLoggers() : int { return $this->m_numLoggers; }
}
