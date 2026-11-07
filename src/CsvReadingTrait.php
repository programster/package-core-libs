<?php

/*
 * Helpers for reading CSV files, shared by CsvLib and CsvFile. They are private to each class that
 * uses this trait, so aren't part of either class's public API.
 * @internal - only for use by this library.
 */

namespace Programster\CoreLibs;


trait CsvReadingTrait
{
    # The bytes a UTF-8 byte order mark is made of.
    private const string BYTE_ORDER_MARK = "\xEF\xBB\xBF";


    /**
     * Determine whether a row returned by fgetcsv/str_getcsv is a blank line, which
     * fgetcsv/str_getcsv return as [null]. Note that a line of just delimiters (e.g. ",,,") is NOT
     * a blank line, as it is a row of empty values, which may be legitimate data.
     * @param array $row - the row as returned by fgetcsv/str_getcsv.
     * @return bool - true if the row came from a blank line, false otherwise.
     */
    private static function isBlankRow(array $row): bool
    {
        return $row === [null];
    }


    /**
     * If a file starts with a UTF-8 byte order mark, move past it, so that it doesn't end up as
     * part of the first value read (e.g. making the first column's name "\xEF\xBB\xBFname" rather
     * than "name"). This must be called straight after opening the file, before anything has been
     * read from it.
     * @param resource $handle - the file handle, opened for reading. PHP can't declare a resource
     * type, so this is only documented here.
     * @return bool - true if the file started with a byte order mark (which has now been skipped),
     * false if not.
     */
    private static function skipByteOrderMark($handle): bool
    {
        $start = fread($handle, strlen(self::BYTE_ORDER_MARK));
        $hasByteOrderMark = ($start === self::BYTE_ORDER_MARK);

        if (!$hasByteOrderMark)
        {
            # Go back to the start, so the bytes we just read are read again as normal.
            rewind($handle);
        }

        return $hasByteOrderMark;
    }
}
