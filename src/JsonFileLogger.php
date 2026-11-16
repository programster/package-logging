<?php

/*
 * This class is a logger which logs to a file (using JSON formatting). The file is kept as a valid JSON
 * array, with one object per log.
 */

declare(strict_types = 1);
namespace Programster\Log;


use Programster\Log\Exceptions\ExceptionFailedToLockFile;
use Programster\Log\Exceptions\ExceptionFailedToOpenFile;
use Programster\Log\Exceptions\ExceptionFailedToWriteFile;
use Programster\Log\Exceptions\ExceptionInvalidJsonLogFile;
use Programster\Log\Internal\ScanDirection;

final class JsonFileLogger extends AbstractLogger
{
    // The only characters JSON treats as whitespace (RFC 8259).
    private const JSON_WHITESPACE = [" ", "\t", "\n", "\r"];

    private readonly string $m_dateFormat;
    private readonly string $m_filepath;


    /**
     * Create a logger that logs to a JSON file.
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
        $this->m_filepath = $filepath;
        $this->m_dateFormat = $timeFormat;

        // use 'a' to check we can write and try to create file if doesn't already exist.
        $fileHandle = $this->openFile($this->m_filepath, "a", "JSON log file");

        // immediately close the file. We only did it to check we had permissions.
        fclose($fileHandle);
    }


    /**
     * Add the log to the file's JSON array, as an object with the time, level, message and context.
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
     * @throws Exceptions\ExceptionFailedToEncodeLog - if the log could not be JSON encoded
     * @throws Exceptions\ExceptionFailedToLockFile - if an exclusive lock could not be obtained on the file
     * @throws Exceptions\ExceptionFailedToOpenFile - if the file could not be opened
     * @throws Exceptions\ExceptionFailedToWriteFile - if writing failed, e.g. because the disk is full
     * @throws Exceptions\ExceptionInvalidJsonLogFile - if the file has content that is not a JSON array
     * @throws Exceptions\ExceptionInvalidLogLevel - if provided an invalid log level
     */
    public function log(mixed $level, string|\Stringable $message, array $context = []) : void
    {
        $logLevel = $this->convertLogLevelMixedVariable($level);

        $dataToAddToFile = [
            'when' => date($this->m_dateFormat, time()),
            'level' => $logLevel->value,
            // Cast, as json_encode() would otherwise encode a Stringable object's properties, not its string.
            'message' => (string)$message,
            'context' => $context,
        ];

        $json = $this->encodeJson($dataToAddToFile, $message);
        $this->updateJsonArrayFile($json);
    }


    /**
     * Append a JSON-encoded log to the file, keeping the file a valid JSON array. Rather than rewriting the
     * whole file, this finds the array's closing "]" (ignoring any whitespace around it), and writes the new
     * entry followed by a new closing "]" in its place. This works for files in any JSON array formatting,
     * not just the format this logger writes.
     * @param string $jsonString - the JSON-encoded log to append.
     * @return void
     * @throws ExceptionFailedToOpenFile - if the file could not be opened.
     * @throws ExceptionFailedToLockFile - if an exclusive lock could not be obtained on the file.
     * @throws ExceptionFailedToWriteFile - if writing failed, e.g. because the disk is full.
     * @throws ExceptionInvalidJsonLogFile - if the file has content that is not a JSON array. The file is
     * left untouched in this case, rather than risk losing its data.
     */
    private function updateJsonArrayFile(string $jsonString) : void
    {
        // c+b opens for reading and writing, creates the file if it is missing (e.g. after log rotation), and
        // never empties it.
        $fp = $this->openFile($this->m_filepath, 'c+b', "JSON log file");

        try
        {
            if (flock($fp, LOCK_EX) === false)
            {
                $msg = "Failed to get an exclusive lock on JSON log file '{$this->m_filepath}'.";
                throw new ExceptionFailedToLockFile($msg);
            }

            // Use the open handle's size, rather than filesize(), which can be stale due to PHP's stat cache.
            $stat = fstat($fp);

            if ($stat === false)
            {
                throw new ExceptionFailedToOpenFile("Failed to get the size of JSON log file '{$this->m_filepath}'.");
            }

            $filesize = $stat['size'];
            $lastCharPos = $this->findNonWhitespace($fp, $filesize - 1, ScanDirection::BACKWARDS);

            if ($lastCharPos === -1)
            {
                // The file is empty (or only whitespace), so start a new array.
                $this->writeAt($fp, 0, "[\n" . $jsonString . "\n]\n");
            }
            else
            {
                $firstCharPos = $this->findNonWhitespace($fp, 0, ScanDirection::FORWARDS);

                $isArray = (
                       $this->readByteAt($fp, $firstCharPos) === "["
                    && $this->readByteAt($fp, $lastCharPos) === "]"
                );

                if (!$isArray)
                {
                    $msg = "JSON log file '{$this->m_filepath}' does not contain a JSON array (it must start with "
                        . "'[' and end with ']'), so it has not been modified, to avoid losing its data. Please fix "
                        . "or move the file.";

                    throw new ExceptionInvalidJsonLogFile($msg);
                }

                // The end of the last entry, or the opening "[" if the array is empty.
                $lastEntryEndPos = $this->findNonWhitespace($fp, $lastCharPos - 1, ScanDirection::BACKWARDS);
                $separator = ($lastEntryEndPos === $firstCharPos) ? "\n" : ",\n";
                $this->writeAt($fp, $lastEntryEndPos + 1, $separator . $jsonString . "\n]\n");
            }
        }
        finally
        {
            flock($fp, LOCK_UN);
            fclose($fp);
        }
    }


    /**
     * Write content at a position in the file, replacing everything from that position onwards.
     * The content is written before cutting off what was left after it, so that the file is only left
     * broken if the write itself fails part way through.
     * @param resource $fp - the open file handle.
     * @param int<0, max> $position - the byte position to write at.
     * @param string $content - the content to write.
     * @return void
     * @throws ExceptionFailedToWriteFile - if any step fails, e.g. because the disk is full.
     */
    private function writeAt($fp, int $position, string $content) : void
    {
        $succeeded = false;

        // Each step only runs if the previous one succeeded.
        $write = function () use ($fp, $position, $content, &$succeeded) : void {
            $succeeded = (
                   fseek($fp, $position) === 0
                && fwrite($fp, $content) === strlen($content)
                && ftruncate($fp, $position + strlen($content))
                && fflush($fp)
            );
        };

        $reason = $this->runCapturingFailureReason($write);

        if ($succeeded === false)
        {
            throw new ExceptionFailedToWriteFile("Failed to write to JSON log file '{$this->m_filepath}': {$reason}");
        }
    }


    /**
     * Find the position of the first non-whitespace byte, scanning from a position in a given direction.
     * @param resource $fp - the open file handle.
     * @param int $start - the byte position to start scanning from.
     * @param ScanDirection $direction - which way to scan.
     * @return int<-1, max> - the position found, or -1 if the start or end of the file was reached first.
     */
    private function findNonWhitespace($fp, int $start, ScanDirection $direction) : int
    {
        $result = -1;
        $position = $start;
        $endOfFileReached = false;

        while ($result === -1 && $endOfFileReached === false && $position >= 0)
        {
            $byte = $this->readByteAt($fp, $position);

            if ($byte === "")
            {
                $endOfFileReached = true;
            }
            elseif (!in_array($byte, self::JSON_WHITESPACE, true))
            {
                $result = $position;
            }

            $position += $direction->getStep();
        }

        return $result;
    }


    /**
     * Read a single byte from the file.
     * @param resource $fp - the open file handle.
     * @param int $position - the byte position to read.
     * @return string - the byte, or an empty string if the position is past the end of the file.
     */
    private function readByteAt($fp, int $position) : string
    {
        fseek($fp, $position);
        $byte = fgetc($fp);
        return ($byte === false) ? "" : $byte;
    }
}
