<?php

/*
 * Exception thrown when a filename or path that was expected to have a file extension doesn't have one, such as
 * "README" in Filesystem::getFileExtension().
 */

namespace Programster\CoreLibs\Exceptions;


class NoFileExtensionException extends CoreLibsException
{
}
