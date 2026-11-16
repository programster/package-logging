<?php

/*
 * A logger factory that creates its logger with the closure it's given, the first time the logger is needed, and
 * then keeps it for every later call. If the closure throws (e.g. the database is down), nothing is kept, so the
 * logger is created again the next time it's needed (e.g. once the database is back up).
 *
 * Note that once a logger has been created, it is kept even if it later fails to log. A logger whose connection can
 * drop should reconnect itself when it next logs.
 *
 * Deliberately not readonly, as it caches the logger once it has been created.
 */

declare(strict_types = 1);
namespace Programster\Log;


use Programster\Log\Exceptions\ExceptionInvalidLoggerCreator;
use Programster\Log\Interfaces\InterfaceLoggerFactory;
use Psr\Log\LoggerInterface;

final class CachingLoggerFactory implements InterfaceLoggerFactory
{
    /** @var \Closure(): LoggerInterface */
    private readonly \Closure $m_createLogger;

    // The logger, once it has been created.
    private ?LoggerInterface $m_logger = null;


    /**
     * Create a factory that creates its logger with $createLogger, the first time it's needed.
     *
     * @param \Closure(): LoggerInterface $createLogger - creates the logger, e.g.
     * function () : LoggerInterface { return new DatabaseLogger($connectionDetails); }. It must take no parameters,
     * and declare that it returns a LoggerInterface (or a class that implements it), so that PHP checks it does.
     *
     * @throws ExceptionInvalidLoggerCreator - if $createLogger has required parameters, or doesn't declare that it
     * returns a LoggerInterface.
     */
    public function __construct(\Closure $createLogger)
    {
        $reflection = new \ReflectionFunction($createLogger);
        $returnType = $reflection->getReturnType();

        $returnsLogger = (
               $returnType instanceof \ReflectionNamedType
            && !$returnType->allowsNull()
            && is_a($returnType->getName(), LoggerInterface::class, true)
        );

        if ($reflection->getNumberOfRequiredParameters() > 0)
        {
            $msg = "The closure given to " . self::class . " must take no parameters, as it is called with none.";
            throw new ExceptionInvalidLoggerCreator($msg);
        }

        if (!$returnsLogger)
        {
            $msg = "The closure given to " . self::class . " must declare that it returns a " . LoggerInterface::class
                . " (or a class that implements it), e.g. function () : LoggerInterface { ... }, so that PHP checks it "
                . "does.";

            throw new ExceptionInvalidLoggerCreator($msg);
        }

        $this->m_createLogger = $createLogger;
    }


    /**
     * Fetch the logger, creating it with the closure if it hasn't been created yet. Caches the logger once it has been
     * created, so the closure is only called again if it threw.
     * @return LoggerInterface
     * @throws \Throwable - whatever the closure throws, if it fails to create the logger.
     */
    public function fetchLogger() : LoggerInterface
    {
        if ($this->m_logger === null)
        {
            $this->m_logger = ($this->m_createLogger)();
        }

        return $this->m_logger;
    }
}
