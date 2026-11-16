<?php

/*
 * A readonly collection of the failures of a MultiLogger's loggers whilst logging one message, in the order of the
 * loggers.
 */

declare(strict_types = 1);
namespace Programster\Log\Collections;


use Programster\Collections\AbstractReadonlyCollection;
use Programster\Log\MultiLoggerFailure;

/**
 * @extends AbstractReadonlyCollection<MultiLoggerFailure>
 */
final readonly class MultiLoggerFailureCollection extends AbstractReadonlyCollection
{
    public function __construct(MultiLoggerFailure ...$failures)
    {
        parent::__construct(...$failures);
    }
}
