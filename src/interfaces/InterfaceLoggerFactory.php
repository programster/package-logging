<?php

/*
 * An interface for objects that create (or fetch) a logger on demand.
 *
 * The FailoverLogger takes these instead of loggers, so that it can gracefully fail over to the next
 * logger if something goes wrong whilst constructing a logger (e.g. failing to connect to a database),
 * and not just if something goes wrong when calling the logger's log() method.
 *
 * The FailoverLogger calls getLogger() every time it tries to log, and does not cache the result. If
 * constructing the logger is expensive, implementations should cache it themselves (see the README).
 */

declare(strict_types = 1);
namespace Programster\Log\Interfaces;


use Psr\Log\LoggerInterface;

interface InterfaceLoggerFactory
{
    /**
     * Get the logger.
     * @return LoggerInterface - the logger.
     * @throws \Throwable - any exception may be thrown if the logger cannot be created. The FailoverLogger
     * treats this the same as the logger failing to log, and moves on to the next logger.
     */
    public function getLogger() : LoggerInterface;
}
