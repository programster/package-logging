<?php

/*
 * An exception thrown when the FailoverLogger is given an invalid set of fallback loggers, such as an empty
 * array, or an array containing something that does not implement LoggerInterface.
 */

declare(strict_types = 1);
namespace Programster\Log\Exceptions;


final class ExceptionInvalidFallbackLoggers extends \Exception
{

}
