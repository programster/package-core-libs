<?php

/*
 * Exception thrown when something isn't supported on the operating system or environment being run
 * on, such as accessing the clipboard on an unsupported OS, or on Linux without a graphical
 * session.
 */

namespace Programster\CoreLibs\Exceptions;


class UnsupportedPlatformException extends CoreLibsException
{
}
