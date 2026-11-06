<?php

/*
 * Exception thrown when a command or system file gives output that isn't in the expected format,
 * such as nproc not returning a number, or /proc/meminfo missing a field.
 */

namespace Programster\CoreLibs\Exceptions;


class UnexpectedOutputException extends CoreLibsException
{
}
