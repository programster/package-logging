<?php

/*
 * An interface for objects that the FailoverLogger notifies whenever one or more of its loggers fail, so that you can
 * take your own action, such as alerting someone.
 */

declare(strict_types = 1);
namespace Programster\Log\Interfaces;


use Programster\Log\Collections\LoggerFailureCollection;

interface InterfaceFailureHandler
{
    /**
     * Handle one or more of the FailoverLogger's loggers failing to log a message.
     * This is called after the failures have been logged to the working logger, or before ExceptionAllLoggersFailed
     * is thrown if no logger worked. It is not called if nothing failed, including when every logger was skipped for
     * being within its retry delay. Anything it throws is passed on to the code that was logging.
     *
     * WARNING: Beware of logging to the same FailoverLogger from within this method, as that could recurse if the
     * same loggers keep failing.
     *
     * @param LoggerFailureCollection $failures - one failure per logger that failed, in the order the loggers were
     * tried.
     *
     * @param bool $messageWasLogged - true if a later logger eventually logged the message, or false if every logger
     * failed or was skipped.
     *
     * @return void
     */
    public function handleFailures(LoggerFailureCollection $failures, bool $messageWasLogged) : void;
}
