<?php

declare(strict_types = 1);
namespace Programster\Log\Tests;


use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Programster\Log\Exceptions\ExceptionInvalidLogLevel;
use Programster\Log\LogLevel;
use Programster\Log\MinLevelLogger;
use Programster\Log\Tests\Doubles\RecordingLogger;

final class MinLevelLoggerTest extends TestCase
{
    #[DataProvider('provideThresholds')]
    public function testOnlyPassesLogsAtOrAboveThreshold(mixed $threshold) : void
    {
        $wrappedLogger = new RecordingLogger();
        $logger = new MinLevelLogger($threshold, $wrappedLogger);

        $logger->info("info");
        $logger->notice("notice");
        $logger->warning("warning");
        $logger->log(LogLevel::ERROR, "error");
        $logger->log(7, "emergency");

        $levels = array_column($wrappedLogger->getLogs(), 'level');
        $this->assertSame(['warning', 'error', 'emergency'], $levels);
    }


    /**
     * The same threshold (warning) in each supported format, matching the documented integer mapping.
     */
    public static function provideThresholds() : array
    {
        return [
            'PSR-3 string' => ['warning'],
            'uppercase string' => ['WARNING'],
            'LogLevel enum' => [LogLevel::WARNING],
            'integer 3' => [3],
        ];
    }


    #[DataProvider('provideInvalidLevels')]
    public function testRejectsInvalidThreshold(mixed $threshold) : void
    {
        $this->expectException(ExceptionInvalidLogLevel::class);
        new MinLevelLogger($threshold, new RecordingLogger());
    }


    public static function provideInvalidLevels() : array
    {
        // The constructor's native type allows only int|LogLevel|string, so only those invalid values apply.
        return [
            'unknown string' => ['loud'],
            'integer too high' => [8],
            'negative integer' => [-1],
        ];
    }
}
