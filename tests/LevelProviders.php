<?php

/*
 * Shared data provider sets of log levels, for testing that loggers accept every supported format of level
 * and reject invalid ones.
 */

declare(strict_types = 1);
namespace Programster\Log\Tests;


use Programster\Log\LogLevel;

final class LevelProviders
{
    /**
     * @return array<string, array{mixed, string}> - the level to pass, and the level string expected in the output.
     */
    public static function validLevels() : array
    {
        return [
            'PSR-3 string' => [\Psr\Log\LogLevel::WARNING, 'warning'],
            'uppercase string' => ['INFO', 'info'],
            'mixed case string' => ['Error', 'error'],
            'LogLevel enum' => [LogLevel::CRITICAL, 'critical'],
            'integer 0 (debug)' => [0, 'debug'],
            'integer 3 (warning)' => [3, 'warning'],
            'integer 7 (emergency)' => [7, 'emergency'],
        ];
    }


    /**
     * @return array<string, array{mixed}> - an invalid level to pass.
     */
    public static function invalidLevels() : array
    {
        return [
            'unknown string' => ['loud'],
            'integer too high' => [8],
            'negative integer' => [-1],
            'null' => [null],
            'float' => [1.5],
            'object' => [new \stdClass()],
        ];
    }
}
