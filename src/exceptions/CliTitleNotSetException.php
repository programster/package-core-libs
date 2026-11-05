<?php

/*
 * Exception thrown when the title of the current process could not be set, such as when not running from the CLI.
 */

namespace Programster\CoreLibs\Exceptions;


class CliTitleNotSetException extends CoreLibsException
{
}
