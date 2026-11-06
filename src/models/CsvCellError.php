<?php

/*
 * A value in a CSV file that has a problem, such as a value that should be a number but isn't, and where it is. See
 * NonNumericCsvValuesException::getErrors(). This only holds those details, so its properties are public.
 */

namespace Programster\CoreLibs\Models;


final readonly class CsvCellError
{
    # The path of the CSV file the value is in.
    public string $filepath;

    # The row the value is on, starting from 1, and counting any header row, so it matches the row in the file.
    public int $rowNumber;

    # The column the value is in, starting from 1, in the same way as CsvLib::diffTolerance()'s $tolerances keys.
    public int $columnNumber;

    # The value itself, as read from the file, e.g. "N/A".
    public string $value;


    /**
     * Create the location of a value with a problem.
     * @param string $filepath - the path of the CSV file the value is in.
     * @param int $rowNumber - the row the value is on, starting from 1, and counting any header row.
     * @param int $columnNumber - the column the value is in, starting from 1.
     * @param string $value - the value itself, as read from the file.
     */
    public function __construct(string $filepath, int $rowNumber, int $columnNumber, string $value)
    {
        $this->filepath = $filepath;
        $this->rowNumber = $rowNumber;
        $this->columnNumber = $columnNumber;
        $this->value = $value;
    }
}
