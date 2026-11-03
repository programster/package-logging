<?php

declare(strict_types = 1);
namespace Programster\Log\Tests;


use PHPUnit\Framework\TestCase;
use Programster\Log\AddedContextLogger;
use Programster\Log\Exceptions\ExceptionContextRequired;
use Programster\Log\Tests\Doubles\RecordingLogger;
use Psr\Log\LogLevel;

final class AddedContextLoggerTest extends TestCase
{
    public function testAddsContextToLogsWithNoContext() : void
    {
        $wrappedLogger = new RecordingLogger();

        $logger = new AddedContextLogger($wrappedLogger, ['script' => 'import']);
        $logger->notice("hello");

        $this->assertSame(
            [['level' => LogLevel::NOTICE, 'message' => "hello", 'context' => ['script' => 'import']]],
            $wrappedLogger->getLogs()
        );
    }


    public function testMergesAddedContextWithProvidedContext() : void
    {
        $wrappedLogger = new RecordingLogger();

        $logger = new AddedContextLogger($wrappedLogger, ['script' => 'import']);
        $logger->info("hello", ['rows' => 5]);

        $this->assertSame(['script' => 'import', 'rows' => 5], $wrappedLogger->getLogs()[0]['context']);
    }


    public function testProvidedContextOverridesAddedContextForSameKey() : void
    {
        $wrappedLogger = new RecordingLogger();

        $logger = new AddedContextLogger($wrappedLogger, ['script' => 'import', 'stage' => 'added']);
        $logger->info("hello", ['stage' => 'provided']);

        $this->assertSame(['script' => 'import', 'stage' => 'provided'], $wrappedLogger->getLogs()[0]['context']);
    }


    public function testLogsOncePerCall() : void
    {
        $wrappedLogger = new RecordingLogger();

        $logger = new AddedContextLogger($wrappedLogger, ['script' => 'import']);
        $logger->info("first");
        $logger->error("second");

        $logs = $wrappedLogger->getLogs();
        $this->assertCount(2, $logs);
        $this->assertSame([LogLevel::INFO, "first"], [$logs[0]['level'], $logs[0]['message']]);
        $this->assertSame([LogLevel::ERROR, "second"], [$logs[1]['level'], $logs[1]['message']]);
    }


    public function testEmptyContextIsRejected() : void
    {
        $this->expectException(ExceptionContextRequired::class);
        new AddedContextLogger(new RecordingLogger(), []);
    }
}
