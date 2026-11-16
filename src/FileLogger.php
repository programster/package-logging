<?php

/*
 * A logger that logs to a file, in either CSV (the default) or JSON format, by using a CsvFileLogger or a
 * JsonFileLogger.
 */

declare(strict_types = 1);
namespace Programster\Log;


use Psr\Log\LoggerInterface;

final class FileLogger extends AbstractLogger
{
    private readonly LoggerInterface $m_logger;


    /**
     * Create a logger that logs to a file.
     *
     * @param string $filepath - the path to the file you wish to log to.
     *
     * @param string $timeFormat - the format you want the time in. Defaults to Y-m-d H:i:s for easy sorting and
     * reading.
     *
     * @param FileFormat $fileFormat - the format you wish for the logs to be in. Defaults to CSV.
     *
     * @throws Exceptions\ExceptionFailedToOpenFile - if the file can't be opened (or created) for writing.
     */
    public function __construct(
        string $filepath,
        string $timeFormat = "Y-m-d H:i:s",
        FileFormat $fileFormat = FileFormat::CSV
    )
    {
        $this->m_logger = match ($fileFormat) {
            FileFormat::CSV => new CsvFileLogger($filepath, $timeFormat),
            FileFormat::JSON => new JsonFileLogger($filepath, $timeFormat),
        };
    }


    /**
     * Log the message to the file. See CsvFileLogger::log() and JsonFileLogger::log() for what they throw.
     * @param mixed $level - the level of the log.
     * @param string|\Stringable $message - the message of the log.
     * @param array<mixed> $context - name value pairs providing context to the log.
     * @return void
     */
    public function log(mixed $level, string|\Stringable $message, array $context = []) : void
    {
        $this->m_logger->log($level, $message, $context);
    }
}
