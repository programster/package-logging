<?php

/*
 * A readonly collection of PSR-3 loggers.
 */

declare(strict_types = 1);
namespace Programster\Log\Collections;


use Programster\Collections\AbstractReadonlyCollection;
use Psr\Log\LoggerInterface;

/**
 * @extends AbstractReadonlyCollection<LoggerInterface>
 */
final readonly class LoggerCollection extends AbstractReadonlyCollection
{
    public function __construct(LoggerInterface ...$loggers)
    {
        parent::__construct(...$loggers);
    }
}
