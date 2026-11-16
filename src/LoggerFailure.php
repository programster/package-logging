<?php

/*
 * One of a FailoverLogger's loggers failing to log a message: which logger it was, at which stage it failed, and the
 * exception that it (or its factory) threw. These are what an InterfaceFailureHandler and ExceptionAllLoggersFailed
 * are given.
 */

declare(strict_types = 1);
namespace Programster\Log;


use Programster\Log\Interfaces\InterfaceLoggerFactory;
use Psr\Log\LoggerInterface;

final readonly class LoggerFailure
{
    private int $m_position;
    private InterfaceLoggerFactory $m_loggerFactory;
    private FailureStage $m_stage;

    // The class of the logger that failed, or "" if its factory failed to provide one.
    private string $m_loggerClass;

    private \Throwable $m_throwable;


    private function __construct(
        int $position,
        InterfaceLoggerFactory $loggerFactory,
        FailureStage $stage,
        string $loggerClass,
        \Throwable $throwable
    )
    {
        $this->m_position = $position;
        $this->m_loggerFactory = $loggerFactory;
        $this->m_stage = $stage;
        $this->m_loggerClass = $loggerClass;
        $this->m_throwable = $throwable;
    }


    /**
     * Create the failure of a logger factory to provide its logger, e.g. because the logger couldn't connect to its
     * database when it was constructed.
     *
     * @param int $position - the position of the logger in the FailoverLogger, from 0 for the primary logger.
     *
     * @param InterfaceLoggerFactory $loggerFactory - the factory that failed.
     *
     * @param \Throwable $throwable - what the factory threw.
     *
     * @return LoggerFailure
     */
    public static function createFetchFailure(
        int $position,
        InterfaceLoggerFactory $loggerFactory,
        \Throwable $throwable
    ) : LoggerFailure
    {
        return new LoggerFailure($position, $loggerFactory, FailureStage::FETCHING_LOGGER, "", $throwable);
    }


    /**
     * Create the failure of a logger to log a message, after its factory provided it.
     *
     * @param int $position - the position of the logger in the FailoverLogger, from 0 for the primary logger.
     *
     * @param InterfaceLoggerFactory $loggerFactory - the factory that provided the logger.
     *
     * @param LoggerInterface $logger - the logger that failed.
     *
     * @param \Throwable $throwable - what the logger threw.
     *
     * @return LoggerFailure
     */
    public static function createLogFailure(
        int $position,
        InterfaceLoggerFactory $loggerFactory,
        LoggerInterface $logger,
        \Throwable $throwable
    ) : LoggerFailure
    {
        return new LoggerFailure($position, $loggerFactory, FailureStage::LOGGING, get_class($logger), $throwable);
    }


    /**
     * Get a message describing the failure, for logging, e.g. "FailoverLogger: logger App\DbLogger failed to log a
     * message: connection refused".
     * @return string
     */
    public function getMessage() : string
    {
        $factoryClass = get_class($this->m_loggerFactory);
        $reason = $this->m_throwable->getMessage();
        $fetchFailure = "logger factory {$factoryClass} failed to provide a logger";
        $logFailure = "logger {$this->m_loggerClass} failed to log a message";

        $description = match ($this->m_stage) {
            FailureStage::FETCHING_LOGGER => $fetchFailure,
            FailureStage::LOGGING => $logFailure,
        };

        return "FailoverLogger: {$description}: {$reason}";
    }


    /**
     * Get the details of the failure as log context. Every value is a scalar rather than the throwable itself, so
     * that loggers which serialize context (e.g. to JSON or CSV) get meaningful output.
     * @return array<string, int|string>
     */
    public function getContext() : array
    {
        return [
            'failed_logger_index' => $this->m_position,
            'failed_logger_factory_class' => get_class($this->m_loggerFactory),
            'failed_logger_class' => $this->m_loggerClass,
            'failed_during' => $this->m_stage->value,
            'exception_class' => get_class($this->m_throwable),
            'exception_message' => $this->m_throwable->getMessage(),
            'exception_code' => $this->m_throwable->getCode(),
            'exception_file' => $this->m_throwable->getFile(),
            'exception_line' => $this->m_throwable->getLine(),
            'exception_trace' => $this->m_throwable->getTraceAsString(),
        ];
    }


    # Accessors
    public function getPosition() : int { return $this->m_position; }
    public function getLoggerFactory() : InterfaceLoggerFactory { return $this->m_loggerFactory; }
    public function getStage() : FailureStage { return $this->m_stage; }
    public function getThrowable() : \Throwable { return $this->m_throwable; }
}
