<?php

/*
 * Exception thrown when something needs details of the current web request, but there isn't one,
 * such as when running from the CLI, or the web server didn't provide them.
 */

namespace Programster\CoreLibs\Exceptions;


class NoWebRequestException extends CoreLibsException
{
}
