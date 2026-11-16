<?php

/*
 * A readonly collection of logger factories, e.g. the ones a FailoverLogger tries, in order.
 */

declare(strict_types = 1);
namespace Programster\Log\Collections;


use Programster\Collections\AbstractReadonlyCollection;
use Programster\Log\Interfaces\InterfaceLoggerFactory;

/**
 * @extends AbstractReadonlyCollection<InterfaceLoggerFactory>
 */
final readonly class LoggerFactoryCollection extends AbstractReadonlyCollection
{
    public function __construct(InterfaceLoggerFactory ...$loggerFactories)
    {
        parent::__construct(...$loggerFactories);
    }
}
