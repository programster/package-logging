<?php

/*
 * The stage at which one of a FailoverLogger's loggers failed: whilst its factory was fetching (e.g. constructing) it,
 * or whilst it was logging the message. The values are what the failure log's "failed_during" context holds.
 */

declare(strict_types = 1);
namespace Programster\Log;


enum FailureStage: string
{
    case FETCHING_LOGGER = "fetchLogger";
    case LOGGING = "log";
}
