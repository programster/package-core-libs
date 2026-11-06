<?php

/*
 * Exception thrown when the input (e.g. STDIN) ended before what was being read from it was
 * complete, such as a password being piped in without a newline, or the input being closed part way
 * through.
 */

namespace Programster\CoreLibs\Exceptions;


class InputEndedException extends CoreLibsException
{
}
