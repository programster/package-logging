<?php

/*
 * The direction in which the JsonFileLogger scans its file for the next byte that isn't whitespace. Internal to the
 * package: not for use by applications, and may change in any release.
 */

declare(strict_types = 1);
namespace Programster\Log\Internal;


enum ScanDirection
{
    case FORWARDS;
    case BACKWARDS;


    /**
     * Get how far to move the position by for each byte scanned.
     * @return int - 1 to move forwards, or -1 to move backwards.
     */
    public function getStep() : int
    {
        return match ($this) {
            self::FORWARDS => 1,
            self::BACKWARDS => -1,
        };
    }
}
