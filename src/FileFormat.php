<?php

/*
 * The formats a FileLogger can write its logs in.
 */

declare(strict_types = 1);
namespace Programster\Log;


enum FileFormat: string
{
    case JSON = "json";
    case CSV = "csv";
}
