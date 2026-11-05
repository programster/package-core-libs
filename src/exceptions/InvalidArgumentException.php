<?php

/*
 * Exception thrown when a method is given an argument that it can't work with, such as a key that doesn't exist, or
 * rows that don't all have the same number of values.
 */

namespace Programster\CoreLibs\Exceptions;


class InvalidArgumentException extends CoreLibsException
{
}
