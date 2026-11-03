<?php

/*
 * An exception thrown when the JsonFileLogger is asked to log to a file that already has content, but that
 * content is not a JSON array. The file is left untouched, rather than risk losing its data.
 */

declare(strict_types = 1);
namespace Programster\Log\Exceptions;


final class ExceptionInvalidJsonLogFile extends \Exception
{

}
