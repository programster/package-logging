<?php

/*
 * This class is a logger which logs to a file (using CSV formatting).
 */

declare(strict_types = 1);
namespace Programster\Log;


use Programster\Log\Exceptions\ExceptionUnsupportedFileFormat;
use Psr\Log\LoggerInterface;

final class FileLogger extends AbstractLogger
{
    private readonly LoggerInterface $m_logger;


    /**
     * Create a logger that logs to a file.
     * @param string $filepath - the path to the file you wish to log to.
     * @param string $timeFormat - the format you want the time in. Defaults to Y-m-d H:i:s for easy sorting and reading.
     * @param \Programster\Log\FileFormat $fileFormat - the format you wish for the logs to be in. Defaults to CSV, but supports JSON.
     * @throws \Programster\Log\Exceptions\ExceptionFailedToOpenFile
     * @throws \Programster\Log\Exceptions\ExceptionUnsupportedFileFormat
     */
    public function __construct(
        string $filepath,
        string $timeFormat="Y-m-d H:i:s",
        FileFormat|string $fileFormat=FileFormat::CSV
    )
    {
        if (is_string($fileFormat))
        {
            $fileFormat = FileFormat::tryFrom(strtolower($fileFormat));

            if ($fileFormat === null)
            {
                throw new ExceptionUnsupportedFileFormat("Unrecognized file format '{$fileFormat->value}'.");
            }
        }

        $this->m_logger = match($fileFormat) {
            FileFormat::CSV => new CsvFileLogger($filepath, $timeFormat),
            FileFormat::JSON => new JsonFileLogger($filepath, $timeFormat),
            default => throw new ExceptionUnsupportedFileFormat("Unrecognized file format '{$fileFormat->value}'."),
        };
    }

    public function log($level, \Stringable|string $message, array $context = []): void
    {
        $this->m_logger->log($level, $message, $context);
    }
}
