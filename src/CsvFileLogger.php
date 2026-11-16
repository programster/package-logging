<?php

/*
 * This class is a logger which logs to a file (using CSV formatting).
 *
 * The file is opened, locked, written to and closed for each log, rather than held open. This means that
 * if the file is moved away (e.g. by logrotate), the next log creates a new file at the path, rather than
 * carrying on writing into the moved file. The lock stops rows from different processes interleaving on
 * filesystems that don't guarantee appends are kept whole (e.g. NFS).
 */

declare(strict_types = 1);
namespace Programster\Log;


use Programster\Log\Exceptions\ExceptionFailedToLockFile;
use Programster\Log\Exceptions\ExceptionFailedToOpenFile;
use Programster\Log\Exceptions\ExceptionFailedToWriteFile;

final class CsvFileLogger extends AbstractLogger
{
    private readonly string $m_dateFormat;
    private readonly string $m_filepath;


    /**
     * Create a logger that logs to a CSV file.
     *
     * @param string $filepath - the path to the file you wish to log to.
     *
     * @param string $timeFormat - the format you want the time in. Defaults to Y-m-d H:i:s for easy sorting and
     * reading.
     *
     * @throws ExceptionFailedToOpenFile - if the file can't be opened (or created) for writing.
     */
    public function __construct(string $filepath, string $timeFormat="Y-m-d H:i:s")
    {
        $this->m_dateFormat = $timeFormat;
        $this->m_filepath = $filepath;

        // Open the file to check we can write to it (creating it if needed), so that a bad path is reported
        // straight away rather than on the first log. It is reopened for each log.
        $fileHandle = $this->openFile($this->m_filepath, "a", "CSV log file");
        fclose($fileHandle);
    }


    /**
     * Write the log to the file, as a row of the time, level, message and JSON encoded context.
     *
     * @param mixed $level - the level of the log, as a LogLevel enum, a case-insensitive PSR-3 level string (e.g.
     * "emergency"), or an integer from 0 (debug) to 7 (emergency). It is always written as the level string.
     *
     * @param string|\Stringable $message - the message of the log, e.g "failed to connect to db"
     *
     * @param array<mixed> $context - name value pairs providing context to the log, e.g. ["dbname" => "yolo"]
     *
     * @return void
     *
     * @throws Exceptions\ExceptionFailedToEncodeLog - if the context could not be JSON encoded
     * @throws Exceptions\ExceptionFailedToLockFile - if an exclusive lock could not be obtained on the file
     * @throws Exceptions\ExceptionFailedToOpenFile - if the file could not be opened
     * @throws Exceptions\ExceptionFailedToWriteFile - if writing failed, e.g. because the disk is full
     * @throws Exceptions\ExceptionInvalidLogLevel - if provided an invalid log level
     */
    public function log(mixed $level, string|\Stringable $message, array $context = []) : void
    {
        $logLevel = $this->convertLogLevelMixedVariable($level);
        $jsonContextString = $this->encodeJson($context, $message);

        $data = [
            date($this->m_dateFormat, time()),
            $logLevel->value,
            (string)$message,
            $jsonContextString,
        ];

        // "a" creates the file if it is missing (e.g. after log rotation), and always writes at the end.
        $fp = $this->openFile($this->m_filepath, "a", "CSV log file");

        try
        {
            if (flock($fp, LOCK_EX) === false)
            {
                $msg = "Failed to get an exclusive lock on CSV log file '{$this->m_filepath}'.";
                throw new ExceptionFailedToLockFile($msg);
            }

            $this->writeRow($fp, $data);
        }
        finally
        {
            flock($fp, LOCK_UN);
            fclose($fp);
        }
    }


    /**
     * Write a row to the CSV file.
     * @param resource $fp - the open, locked file handle.
     * @param list<string> $data - the fields of the row.
     * @return void
     * @throws ExceptionFailedToWriteFile - if writing failed, e.g. because the disk is full.
     */
    private function writeRow($fp, array $data) : void
    {
        $bytesWritten = false;

        $writeRow = function () use ($fp, $data, &$bytesWritten) : void {
            $bytesWritten = fputcsv(stream: $fp, fields: $data, escape: "\\");
        };

        $reason = $this->runCapturingFailureReason($writeRow);

        if ($bytesWritten === false)
        {
            throw new ExceptionFailedToWriteFile("Failed to write to CSV log file '{$this->m_filepath}': {$reason}");
        }
    }
}
