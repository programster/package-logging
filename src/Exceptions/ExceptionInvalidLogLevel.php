<?php

/*
 * An exception indicating that an invalid loglevel was provided.
 * Extends the PSR-3 InvalidArgumentException, as PSR-3 requires loggers to throw that for unknown levels.
 */

declare(strict_types = 1);
namespace Programster\Log\Exceptions;


final class ExceptionInvalidLogLevel extends \Psr\Log\InvalidArgumentException
{

}

