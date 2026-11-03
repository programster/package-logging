<?php

/*
 * An exception thrown when a log (or its context) could not be encoded, e.g. JSON encoding a context
 * containing INF/NAN or a resource.
 */

declare(strict_types = 1);
namespace Programster\Log\Exceptions;


final class ExceptionFailedToEncodeLog extends \Exception
{

}
