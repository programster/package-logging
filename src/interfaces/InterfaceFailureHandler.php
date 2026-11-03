<?php

/*
 * An interface for objects that the FailoverLogger notifies whenever one or more of its loggers fail,
 * so that you can take your own action, such as alerting someone.
 */

declare(strict_types = 1);
namespace Programster\Log\Interfaces;


interface InterfaceFailureHandler
{
    /**
     * Handle one or more of the FailoverLogger's loggers failing to log a message.
     * This is called after the failures have been logged to the working logger, or before
     * ExceptionAllLoggersFailed is thrown if no logger worked.
     * WARNING: Beware of logging to the same FailoverLogger from within this method, as that could
     * recurse if the same loggers keep failing.
     * @param \Throwable[] $failures - one exception per failed logger, in the order the loggers were tried
     * (e.g. two failures means the primary and first fallback failed).
     * @param bool $messageWasLogged - true if one of the fallback loggers eventually logged the message,
     * or false if every logger failed.
     * @return void
     */
    public function handleFailures(array $failures, bool $messageWasLogged) : void;
}
