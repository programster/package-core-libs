<?php

/*
 * Exception thrown when a directory that was expected to exist doesn't, such as the directory a
 * file or directory is being moved into. This is separate from FileDoesNotExistException, so the
 * two can be caught separately. To catch both, catch CoreLibsException, or use catch
 * (FileDoesNotExistException | DirectoryDoesNotExistException $e).
 */

namespace Programster\CoreLibs\Exceptions;


class DirectoryDoesNotExistException extends CoreLibsException
{
}
