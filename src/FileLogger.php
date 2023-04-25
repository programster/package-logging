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

    protected string $m_dateFormat;


    /**
     * Create a logger that logs to a CSV file.
     * @param string $filepath - the path to the file you wish to log to.
     * @param string $timeFormat - the format you want the time in. Defaults to Y-m-d H:i:s for easy sorting and reading.
     * @throws ExceptionFailedToOpenFile - if fails to open the file.
     */
    public function __construct(string $filepath, string $timeFormat="Y-m-d H:i:s")
    {
        $this->m_dateFormat = $timeFormat;
        $this->m_fileHandle = fopen($filepath, "a");

        if ($this->m_fileHandle === false)
        {
            throw new ExceptionFailedToOpenFile();
        }
    }


    /**
     * @param $level - the level of the log, may be a string (e.g. "emergency") or integer (0-7)
     * @param string|\Stringable $message - the message of the error, e.g "failed to connect to db"
     * @param array $context - - name value pairs providing context to error, e.g. "dbname => "yolo")
     * @return void
     * @throws Exceptions\ExceptionInvalidLogLevel - if provided an invalid log level
     */
    public function log($level, string|\Stringable $message, array $context = array()) : void
    {
        $this->validateLogLevelString($level);
        $jsonContextString = json_encode($context, JSON_UNESCAPED_SLASHES);

        $data = array(
            date($this->m_dateFormat, time()),
            $level,
            $message,
            $jsonContextString
        );

        fputcsv($this->m_fileHandle, $data);
    }
}
