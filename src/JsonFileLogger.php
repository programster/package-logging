<?php

/*
 * This class is a logger which logs to a file (using CSV formatting).
 */

declare(strict_types = 1);
namespace Programster\Log;


use Programster\Log\Exceptions\ExceptionFailedToLockFile;
use Programster\Log\Exceptions\ExceptionFailedToOpenFile;

final class JsonFileLogger extends AbstractLogger
{
    protected string $m_dateFormat;


    /**
     * Create a logger that logs to a CSV file.
     * @param string $filepath - the path to the file you wish to log to.
     * @param string $timeFormat - the format you want the time in. Defaults to Y-m-d H:i:s for easy sorting and reading.
     * @throws ExceptionFailedToOpenFile - if fails to open the file.
     */
    public function __construct(private readonly string $filepath, string $timeFormat="Y-m-d H:i:s")
    {
        $this->m_dateFormat = $timeFormat;

        // use 'a' to check we can write and try to create file if doesn't already exist.
        $fileHandle = fopen($filepath, "a");

        if ($fileHandle === false)
        {
            throw new ExceptionFailedToOpenFile();
        }

        // immediately close the file. We only did it to check we had permissions.
        fclose($fileHandle);
    }


    /**
     * @param $level - the level of the log, may be a string (e.g. "emergency") or integer (0-7)
     * @param string|\Stringable $message - the message of the error, e.g "failed to connect to db"
     * @param array $context - - name value pairs providing context to error, e.g. "dbname => "yolo")
     * @return void
     * @throws \Programster\Log\Exceptions\ExceptionFailedToLockFile
     * @throws \Programster\Log\Exceptions\ExceptionFailedToOpenFile
     * @throws \Programster\Log\Exceptions\ExceptionInvalidLogLevel - if provided an invalid log level
     */
    public function log($level, string|\Stringable $message, array $context = array()) : void
    {
        $this->validateLogLevelString($level);

        $dataToAddToFile = [
            'when' => date($this->m_dateFormat, time()),
            'level' => $level,
            'message' => $message,
            'context' => $context,
        ];

        $jsonContextString = json_encode($dataToAddToFile, JSON_UNESCAPED_SLASHES);

        $this->updateJsonArrayFile($jsonContextString);
    }


    function updateJsonArrayFile(string $jsonString) : void
    {
        $fp = fopen($this->filepath, 'r+b');

        if (!$fp)
        {
            throw new ExceptionFailedToOpenFile();
        }

        if (flock($fp, LOCK_EX) === false)
        {
            fclose($fp);
            throw new ExceptionFailedToLockFile();
        }

        $filesize = filesize($this->filepath);

        // Empty or non-existent content just rewrite the file from scratch
        if ($filesize === 0)
        {
            fwrite($fp, "[\n" . $jsonString . "\n]\n");
            flock($fp, LOCK_UN);
            fclose($fp);
        }
        else
        {
            // Find the last two newlines by reading backwards
            $pos = $filesize - 1;
            $newlinesFound = 0;
            $lastNewlinePos  = null;
            $prevNewlinePos  = null;

            while ($pos >= 0 && $newlinesFound < 2)
            {
                fseek($fp, $pos);
                $char = fgetc($fp);

                if ($char === "\n")
                {
                    $newlinesFound++;

                    if ($newlinesFound === 1)
                    {
                        $lastNewlinePos = $pos;
                    }
                    else
                    {
                        $prevNewlinePos = $pos;
                    }
                }

                $pos--;
            }

            if ($newlinesFound < 2)
            {
                // File has 0 or 1 line → just rewrite everything
                ftruncate($fp, 0);
                rewind($fp);
                fwrite($fp, "[\n" . $jsonString . "\n]\n");
            }
            else
            {
                // Truncate right after the previous line (remove its trailing \n and everything after)
                ftruncate($fp, $prevNewlinePos);

                // Move to the new end and write:  ,\n  + JSON  + \n]
                fseek($fp, 0, SEEK_END);
                fwrite($fp, ",\n" . $jsonString . "\n]\n");
            }

            fflush($fp);
            flock($fp, LOCK_UN);
            fclose($fp);
        }
    }
}
