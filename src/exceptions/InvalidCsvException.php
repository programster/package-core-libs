<?php

/*
 * Exception thrown when the contents of a CSV file are not what was expected, such as rows with a different number
 * of columns, or a non-numeric value in a column that should be numeric.
 */

namespace Programster\CoreLibs\Exceptions;


class InvalidCsvException extends CoreLibsException
{
}
