<?php

/*
 * A readonly collection of the failures of a FailoverLogger's loggers whilst logging one message, in the order the
 * loggers were tried.
 */

declare(strict_types = 1);
namespace Programster\Log\Collections;


use Programster\Collections\AbstractReadonlyCollection;
use Programster\Log\LoggerFailure;

/**
 * @extends AbstractReadonlyCollection<LoggerFailure>
 */
final readonly class LoggerFailureCollection extends AbstractReadonlyCollection
{
    public function __construct(LoggerFailure ...$failures)
    {
        parent::__construct(...$failures);
    }
}
