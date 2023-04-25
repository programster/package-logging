<?php

/*
 * This class is a logger which logs to a file (using CSV formatting).
 */

declare(strict_types = 1);
namespace Programster\Log;


use Programster\Log\Exceptions\ExceptionFailedToOpenFile;

final class FileLogger extends AbstractLogger
{
    protected $m_fileHandle;


    /**
     * Creates a logger that logs to a file.
     */
    public function __construct(string $filepath)
    {
        $this->m_fileHandle = fopen($filepath, "a");

        if ($this->m_fileHandle === false)
        {
            throw new ExceptionFailedToOpenFile();
        }
    }


    /**
     * Logs with an arbitrary level.
     *
     * @param string $level - the level of the logl
     * @param string $message -  the message of the error, e.g "failed to connect to db"
     * @param array $context - name value pairs providing context to error, e.g. "dbname => "yolo")
     *
     * @return void
     */
    public function log($level, string|\Stringable $message, array $context = array()) : void
    {
        $this->validateLogLevelString($level);
        $jsonContextString = json_encode($context, JSON_UNESCAPED_SLASHES);

        $data = array(
            microtime(true),
            $level,
            $message,
            $jsonContextString
        );

        fputcsv($this->m_fileHandle, $data);
    }
}
