<?php

/*
 * Exception thrown when something needs a CSV file's header row, but the file doesn't have one,
 * such as CsvFile::removeHeader().
 */

namespace Programster\CoreLibs\Exceptions;


class NoCsvHeaderException extends CoreLibsException
{
}
