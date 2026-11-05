<?php

/*
 * A library for handling CSV files
 */

namespace Programster\CoreLibs;


use Programster\CoreLibs\Exceptions\EmptyArrayException;
use Programster\CoreLibs\Exceptions\FilesystemException;
use Programster\CoreLibs\Exceptions\InvalidCsvException;
use Programster\CoreLibs\Exceptions\InvalidArgumentException;

final class CsvLib
{
    /**
     * Converts a CSV file into a JSON file.
     * @param string $csvFilepath - location of the CSV file we wish to convert.
     * @param string $jsonFilepath - path to where we wish to store the result. If the file already
     *                               exists, then it will be overwritten. If not then it will be
     *                               created.
     * @param bool $compressed - if true then this will have no line endings or padding, if false
     *                           then the outputted json will be much easier to read by humans.
     * @param string $delimiter - optionally specify a delimiter other than the default comma.
     * @param string $enclosure - optionally specify the enclosure if it isn't double quote
     * @param string $escape - optionally specify the escape character to use when parsing the CSV.
     * @return void
     * @throws FilesystemException - if the CSV file could not be read, or the JSON file could not be written.
     * @throws \JsonException
     */
    public static function convertCsvToJson(
        string $csvFilepath,
        string $jsonFilepath,
        bool $compressed,
        string $delimiter = ",",
        string $enclosure = '"',
        string $escape = "",
    ): void
    {
        $lines = file($csvFilepath);

        if ($lines === false)
        {
            throw new FilesystemException("Failed to read CSV file: {$csvFilepath}");
        }

        $jsonFile = fopen($jsonFilepath, "w");

        if ($jsonFile === false)
        {
            throw new FilesystemException("Failed to open {$jsonFilepath} for writing.");
        }

        $headers = array();
        $hasWrittenObject = false;

        if ($compressed)
        {
            fwrite($jsonFile, "[");
        }
        else
        {
            fwrite($jsonFile, "[" . PHP_EOL);
        }


        foreach ($lines as $lineIndex => $line)
        {
            $data = str_getcsv($line, $delimiter, $enclosure, $escape);

            if ($lineIndex === 0)
            {
                # this is the header line
                $headers = $data;
            }
            elseif (self::isBlankRow($data))
            {
                // Skip empty lines (users may accidentally put one at the end
                // and LibreOffice Calc won't strip this either).
            }
            else
            {
                $obj = new \stdClass();

                foreach ($headers as $headerIndex => $header)
                {
                    $obj->$header = $data[$headerIndex];
                }

                if ($compressed)
                {
                    $jsonString = json_encode($obj, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
                }
                else
                {
                    $jsonString = json_encode($obj, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
                }

                if (!$compressed)
                {
                    $jsonStringLines = explode(PHP_EOL, $jsonString);

                    foreach ($jsonStringLines as $jsonStringLineIndex => $line)
                    {
                        $jsonStringLines[$jsonStringLineIndex] = "    " . $line;
                    }

                    $jsonString = implode(PHP_EOL, $jsonStringLines);
                }

                if ($hasWrittenObject)
                {
                    if ($compressed)
                    {
                        $jsonString = "," . $jsonString;
                    }
                    else
                    {
                        $jsonString = "," . PHP_EOL . $jsonString;
                    }

                }

                fwrite($jsonFile, $jsonString);
                $hasWrittenObject = true;
            }
        }

        fwrite($jsonFile, "]");
        fclose($jsonFile);
    }


    /**
     * Loops through the CSV file and puts the data into an array. You can specify whether
     * the CSV has a header and if keys is not provided then we will use these as indexes. If there
     * is no header and no keys, then an array list of rows is returned. If keys are provided then
     * these are always used as the indexes even if it has to override the header.
     * WARNING - this can be a memory hog.
     * @param string $filepath - the path to the file, including the name.
     * @param bool $hasHeader - specify whether the CSV file has a header which it will have to
     *                          skip for values, and may or may not be used for indexes of the
     *                          rows depending on whether $keys is provided.
     * @param ?array $keys - optional parameter to specify the indexes to use in the rows. If not
     *                      provided and there is a header, then we will use the header columns
     *                      as indexes.
     * @param string $delimiter - optionally specify the delimiter if it isn't a comma
     * @param string $enclosure - optionally specify the enclosure if it isn't double quote
     * @param string $escape - optionally specify the escape character to use when parsing the CSV.
     *
     * @return array - an array list of indexed rows that the CSV has been converted into.
     * @throws FilesystemException - if the file could not be opened for reading.
     * @throws InvalidCsvException - if a row doesn't have the same number of values as there are keys.
     */
    public static function convertCsvToArray(
        string $filepath,
        bool $hasHeader,
        ?array $keys = null,
        string $delimiter = ",",
        string $enclosure = '"',
        string $escape = "",
    ): array
    {
        $file = fopen($filepath, 'r');

        if ($file === false)
        {
            throw new FilesystemException("Failed to open {$filepath} for reading.");
        }

        $output = array();
        $firstRow = true;

        while ($row = fgetcsv($file, 0, $delimiter, $enclosure, $escape))
        {
            // Skip empty lines (users may accidentally put one at the end
            // and LibreOffice Calc won't strip this either).
            if (self::isBlankRow($row))
            {
                continue;
            }

            if ($firstRow)
            {
                $firstRow = false;

                if ($hasHeader)
                {
                    if ($keys === null)
                    {
                        $keys = $row;
                    }

                    continue;
                }
            }

            if ($keys !== null)
            {
                if (count($keys) !== count($row))
                {
                    $msg = "Cannot convert CSV to array. Number of keys: " . count($keys) .
                           " is not the same as the number of values: " . count($row);
                    throw new InvalidCsvException($msg);
                }
                $output[] = array_combine($keys, $row);
            }
            else
            {
                $output[] = $row;
            }
        }

        fclose($file);
        return $output;
    }


    /**
     * "Walk" a CSV file like array_walk, except that instead of an array, we walk the CSV file, with each call to
     * the callback calling the passed callable, with the array representing the CSV row.
     * @param string $filepath - the path to the CSV file.
     * @param callable $callback - the callback to execute on each row of CSV data. Typically, callback takes on two
     *                             parameters. The array representing the row being the first, and the row index
     *                             being the second (starts from 0, but the data might start on row 1 if the CSV has
     *                             headers).
     * @param bool $hasHeaders - whether the CSV file's first row is the names for the columns. Usually this is true.
     *                           If the CSV file has headers, then the array passed to the callable will be an
     *                           associative array of name/value pairs with the name being the corresponding header.
     * @param null|array $headers - optionally manually specify the headers if you wish to set the keys for the array
     *                              that gets passed to the callable. If left as null, this is ignored. If set and
     *                              hasHeaders is set to true, this will override any headers in the file.
     * @param string $delimiter - delimiter to pass through to fgetcsv
     * @param string $enclosure - enclosure to pass through to fgetcsv
     * @param string $escape - escape value to pass through to fgetcsv
     * @throws FilesystemException - if the file at $filepath does not exist or cannot be opened.
     * @throws InvalidCsvException - if a row doesn't have the same number of values as there are headers.
     * @return void
     */
    public static function csvWalk(
        string $filepath,
        callable $callback,
        bool $hasHeaders = true,
        ?array $headers = null,
        string $delimiter = ",",
        string $enclosure = "\"",
        string $escape = ""
    ): void
    {
        $fileHandle = fopen($filepath, 'r');

        if ($fileHandle === false)
        {
            throw new FilesystemException("Failed to open {$filepath} for reading.");
        }

        $firstRow = true;
        $rowIndex = -1;

        while ($row = fgetcsv($fileHandle, 0, $delimiter, $enclosure, $escape))
        {
            $rowIndex++;

            // Skip empty lines (users may accidentally put one at the end
            // and LibreOffice Calc won't strip this either).
            if (self::isBlankRow($row))
            {
                continue;
            }

            if ($firstRow)
            {
                $firstRow = false;

                if ($hasHeaders)
                {
                    if ($headers === null)
                    {
                        $headers = $row;
                    }

                    continue;
                }
            }

            if ($headers !== null)
            {
                if (count($headers) != count($row))
                {
                    $msg = "csvWalk: Cannot convert CSV row to array. Number of keys: " . count($headers) .
                           " is not the same as the number of values: " . count($row);
                    throw new InvalidCsvException($msg);
                }

                $arrayToRunCallbackOn = array_combine($headers, $row);
            }
            else
            {
                $arrayToRunCallbackOn = $row;
            }

            $callback($arrayToRunCallbackOn, $rowIndex);
        }

        fclose($fileHandle);
    }


    /**
     * Create a CSV file from the provided array of data. This is done in a memory efficient manner of writing one
     * line at a time to the file rather than building a massive string and dumping the entire string to the file.
     * @param string $filepath - the path to the file that we will write the csv to, creating if necessary.
     * @param array $rows - a collection of associative name/value pairs for the data to fill the csv file. The
     * values will be filled in in the order of the keys in the first row.
     * @param bool $addHeader - specify whether we should put a header row in the CSV file. If true,
     * we will use the keys of the first row as we expect all keys to match.
     * @param string $delimiter - optionally specify a delimiter if you don't wish to use the comma
     * @param string $enclosure - optionally specify the enclosure if you don't wish to use "
     * @param bool $addByteOrderMark - whether to add the byte order mark to the csv, so that Excel handles 
     * UTF-8 characters.
     * @param string $escape - optionally set the escape sequence to use when passing to fputcsv.
     * @return void - all data written to the passed in filepath.
     * @throws EmptyArrayException - if there are no rows, or the first row has no columns.
     * @throws InvalidArgumentException - if the rows are not associative arrays, or don't all have the same keys.
     * @throws FilesystemException - if the file could not be opened for writing.
     */
    public static function convertArrayToCsv(
        string $filepath,
        array $rows,
        bool $addHeader,
        string $delimiter = ",",
        string $enclosure = '"',
        bool $addByteOrderMark = true,
        string $escape="",
    ): void
    {
        # Check the data before opening the file, as opening it for writing empties any file already there.
        if (count($rows) === 0)
        {
            throw new EmptyArrayException("Cannot create CSV file with no data.");
        }

        $firstRow = ArrayLib::getFirstElement($rows);

        if (count($firstRow) === 0)
        {
            throw new EmptyArrayException("Cannot create CSV file, as the first row has no columns.");
        }

        if (ArrayLib::isAssoc($firstRow) === FALSE)
        {
            throw new InvalidArgumentException("convertArrayToCsv expects a list of associative arrays.");
        }

        $fileHandle = fopen($filepath, 'w');

        if ($fileHandle === FALSE)
        {
            throw new FilesystemException("Failed to open {$filepath} for writing.");
        }

        if ($addByteOrderMark)
        {
            $byteOrderMark = "\xEF\xBB\xBF";
            fwrite($fileHandle, $byteOrderMark);
        }

        $keys = array_keys($firstRow);

        if ($addHeader)
        {
            fputcsv(
                stream: $fileHandle,
                fields: $keys,
                separator: $delimiter,
                enclosure: $enclosure,
                escape: $escape
            );
        }

        foreach ($rows as $index => $row)
        {
            if (count($keys) != count($row))
            {
                $msg = "Cannot convert array to CSV. Number of keys: " . count($keys) . 
                       " is not the same as the number of values: " . count($row);

                throw new InvalidArgumentException($msg);
            }

            $rowOfValues = array();

            foreach ($keys as $key)
            {
                if (!isset($row[$key]))
                {
                    // key might be set, the value might just be null. Check for this.
                    $keys = array_keys($row);

                    if (in_array($key, $keys) === FALSE)
                    {
                        throw new InvalidArgumentException("Row {$index} is missing the expected key {$key}.");
                    }
                    else
                    {
                        $value = null;
                    }
                }
                else
                {
                    $value = $row[$key];
                }

                $rowOfValues[] = $value;
            }

            fputcsv(
                stream: $fileHandle,
                fields: $rowOfValues,
                separator: $delimiter,
                enclosure: $enclosure,
                escape: $escape,
            );
        }

        fclose($fileHandle);
    }


    /**
     * Go through the CSV file and trim the values.
     * This saves memory by working line by line rather than reading everything into memory
     * This will replace the existing file's contents, so if you need to keep that, make a copy
     * first.
     * @param string $filepath - the path to the CSV file we are trimming
     * @param string $delimiter - the delimiter/separator used in the CSV. E.g. ',' or ';'
     * @param string $escape - the escape sequence to use when passing to fputcsv.
     * @throws FilesystemException - if the CSV file could not be opened.
     * @return void
     */
    public static function trim(
        string $filepath, 
        string $delimiter, 
        string $escape="",
    ): void
    {
        $tmpFile = tmpfile();
        $uploadedFileHandle = fopen($filepath, "r");

        if ($uploadedFileHandle)
        {
            while (!feof($uploadedFileHandle))
            {
                $lineArray = fgetcsv($uploadedFileHandle, 0, $delimiter, escape: $escape);

                if (!empty($lineArray))
                {
                    foreach ($lineArray as $index => $value)
                    {
                        // fgetcsv returns [null] for a blank line.
                        $lineArray[$index] = trim($value ?? "");
                    }

                    fputcsv(
                        stream: $tmpFile,
                        fields: $lineArray,
                        separator: $delimiter,
                        escape: $escape
                    );
                }
            }

            fclose($uploadedFileHandle);

            $metaData = stream_get_meta_data($tmpFile);
            $tmpFileName = $metaData["uri"];
            rename($tmpFileName, $filepath); # replace the old upload file with new.
        }
        else
        {
            throw new FilesystemException("trim: Failed to open the CSV file for trimming.");
        }
    }


    /**
     * Remove columns from a CSV file.
     * @param string $filepath - the path of the CSV file we are modifying.
     * @param array $columnIndexes - array of integers specifying the column indexes to remove, starting at 1
     * @param string $delimiter - optionally specify the delimiter if it isn't a comma
     * @param string $enclosure - optionally specify the enclosure if it isn't double quote
     * @param string $escape - optionally set the escape sequence to use when passing to fputcsv.
     * @return void
     * @throws InvalidCsvException - if the file contains a blank line before the end of the data (blank lines at the
     * very end of the file are allowed and are kept), or a row doesn't have one of the columns being removed. The
     * original file is left untouched if this happens.
     * @throws FilesystemException - if the CSV file could not be opened.
     */
    public static function removeColumns(
        string $filepath,
        array $columnIndexes,
        string $delimiter=",",
        string $enclosure='"',
        string $escape=""
    ): void
    {
        $tmpFile = tmpfile();
        $fileHandle = fopen($filepath, "r");
        $lineNumber = 1;

        // Blank lines are only allowed at the very end of the file, but we can't know whether a blank line is at the
        // end until we've read past it, so keep track of them until we either hit more data (error) or the end of the
        // file (write them back out so they are kept).
        $numPendingBlankLines = 0;
        $firstPendingBlankLineNumber = 0;

        if ($fileHandle)
        {
            while (!feof($fileHandle))
            {
                $lineArray = fgetcsv($fileHandle, 0, $delimiter, $enclosure, $escape);

                if (is_array($lineArray) && self::isBlankRow($lineArray))
                {
                    if ($numPendingBlankLines === 0)
                    {
                        $firstPendingBlankLineNumber = $lineNumber;
                    }

                    $numPendingBlankLines++;
                }
                elseif (!empty($lineArray))
                {
                    if ($numPendingBlankLines > 0)
                    {
                        // We write to a temporary file and only replace the original once every line has been
                        // processed, so throwing here leaves the original file untouched.
                        fclose($fileHandle);
                        fclose($tmpFile);

                        throw new InvalidCsvException(
                            "removeColumns: source CSV file has a blank line on line: " . $firstPendingBlankLineNumber
                        );
                    }

                    foreach ($columnIndexes as $columnHumanIndex)
                    {
                        $columnIndex = $columnHumanIndex - 1;

                        // Can't use isset() here, as a field could legitimately be null.
                        if (!array_key_exists($columnIndex, $lineArray))
                        {
                            $msg = "removeColumns: source CSV file does not have " .
                                   "column: " . $columnHumanIndex . " on line: " . $lineNumber;

                            fclose($fileHandle);
                            fclose($tmpFile);
                            throw new InvalidCsvException($msg);
                        }

                        unset($lineArray[$columnIndex]);
                    }

                    fputcsv(
                        stream: $tmpFile,
                        fields: $lineArray,
                        separator: $delimiter,
                        enclosure: $enclosure,
                        escape: $escape
                    );
                }

                $lineNumber++;
            }

            fclose($fileHandle);

            // Keep any blank lines that were at the end of the file.
            fwrite($tmpFile, str_repeat("\n", $numPendingBlankLines));

            $metaData = stream_get_meta_data($tmpFile);
            $tmpFileName = $metaData["uri"];
            rename($tmpFileName, $filepath); # replace the old upload file with new.
        }
        else
        {
            throw new FilesystemException("removeColumns: Failed to open the CSV file for removing columns.");
        }
    }


    /**
     * Remove rows from a CSV file.
     * @param string $filepath - the path of the CSV file we are modifying.
     * @param array $rowIndexes - array of integers specifying the row numbers to remove, starting at 1
     * @param string $delimiter - optionally specify the delimiter if it isn't a comma
     * @param string $enclosure - optionally specify the enclosure if it isn't double quote
     * @return void
     * @throws FilesystemException - if the CSV file could not be opened.
     */
    public static function removeRows(
        string $filepath, 
        array $rowIndexes, 
        string $delimiter=",", 
        string $enclosure='"',
        string $escape = "",
    ): void
    {
        $tmpFile = tmpfile();
        $fileHandle = fopen($filepath, "r");
        $lineNumber = 1;

        if ($fileHandle)
        {
            while (!feof($fileHandle))
            {
                $lineArray = fgetcsv($fileHandle, 0, $delimiter, $enclosure, $escape);

                if ($lineArray)
                {
                    if (!in_array($lineNumber, $rowIndexes))
                    {
                        fputcsv(
                            stream: $tmpFile,
                            fields: $lineArray,
                            separator: $delimiter,
                            enclosure: $enclosure,
                            escape: $escape
                        );
                    }
                }

                $lineNumber++;
            }

            fclose($fileHandle);
            $metaData = stream_get_meta_data($tmpFile);
            $tmpFileName = $metaData["uri"];
            rename($tmpFileName, $filepath); # replace the old upload file with new.
        }
        else
        {
            throw new FilesystemException("removeRows: Failed to open the CSV file for removing rows.");
        }
    }


    /**
     * Calculate the mathematical differences between two CSV files. If this comes across string
     * values, it will check if they are the same and put the string in if they are, otherwise,
     * it will concatenate the two values with a | divider.
     * WARNING - this expects the two files to have the same number of columns and rows.
     * @param string $filepath1 - path to a CSV file to compare.
     * @param string $filepath2 - path to a CSV file to compare.
     * @param string $delimiter - optionally specify the delimiter if it isn't a comma
     * @param string $enclosure - optionally specify the enclosure if it isn't double quote
     * @param string $escape - the escape sequence to use when passing to fputcsv.
     * @return string - the path to the created diff CSV file.
     * @throws InvalidCsvException - if the rows of the two files don't have the same number of columns.
     * @throws FilesystemException - if either CSV file could not be opened, or the diff file could not be created.
     */
    public static function decimalDiff(
        string $filepath1,
        string $filepath2,
        string $delimiter=",",
        string $enclosure='"',
        string $escape="",
    ): string
    {
        $newFileName = tempnam(sys_get_temp_dir(), "");

        if ($newFileName === false)
        {
            throw new FilesystemException("Failed to create a temporary file to store the diff in.");
        }

        $newFileHandle = fopen($newFileName, "w");

        if (!$newFileHandle)
        {
            throw new FilesystemException("Cannot create file to store diff in.");
        }

        $fileHandle1 = fopen($filepath1, "r");
        $fileHandle2 = fopen($filepath2, "r");

        if ($fileHandle1 && $fileHandle2)
        {
            while (!feof($fileHandle1))
            {
                $lineArray1 = fgetcsv($fileHandle1, 0, $delimiter, $enclosure, $escape);
                $lineArray2 = fgetcsv($fileHandle2, 0, $delimiter, $enclosure, $escape);
                $resultArray = array();

                if ($lineArray1)
                {
                    foreach ($lineArray1 as $index => $value1)
                    {
                        // Can't use isset() here, as fgetcsv returns [null] for blank lines.
                        if (!is_array($lineArray2) || !array_key_exists($index, $lineArray2))
                        {
                            throw new InvalidCsvException("decimalDiff: rows do not have the same column count.");
                        }

                        $value2 = $lineArray2[$index];

                        if (is_numeric($value1) && is_numeric($value2))
                        {
                            $resultArray[$index] = $value1 - $value2;
                        }
                        else
                        {
                            if ($value1 === $value2)
                            {
                                $resultArray[$index] = $value1;
                            }
                            else
                            {
                                $resultArray[$index] = $value1 . "|" . $value2;
                            }
                        }
                    }
                }

                fputcsv(
                    stream: $newFileHandle,
                    fields: $resultArray,
                    separator: $delimiter,
                    enclosure: $enclosure,
                    escape: $escape
                );
            }

            fclose($fileHandle1);
            fclose($fileHandle2);
            fclose($newFileHandle);
        }
        else
        {
            throw new FilesystemException("decimalDiff: Failed to open CSV file for processing.");
        }

        return $newFileName;
    }


    /**
     * Perform a diff on two CSV files with a tolerance of values being different by the amount
     * specified. This can be useful in situations with floating point values where the last decimal
     * place may be different.
     *
     * WARNING: if your CSV file has headers, you will need to remove them with a call to
     * CsvLib::removeRows
     *
     * @param string $filepath1 - the path to the file we are checking
     * @param array $tolerances - array of column index / tolerance pairs. For this function,
     *                            the column indexes start from 1, not 0.
     * @param bool $compareOtherColumns - optionally specify false to tell this function to ignore
     *                                    all the other columns and only compare the ones specified
     *                                    in the tolerances array.
     * @param string $delimiter - optionally specify the delimiter to pass to fgetcsv (comma default)
     * @param string $enclosure - optionally specify the enclosure to pass to fgetcsv (default: ")
     * @param string $escape - optionally specify the escape character to pass to fgetcsv.
     * @return bool - true if the files are the same, false if different.
     * @throws InvalidCsvException - if the rows of the two files don't have the same number of columns, or a column
     *                               with a tolerance contains a non-numeric value.
     * @throws FilesystemException - if either CSV file could not be opened.
     */
    public static function diffTolerance(
        string $filepath1,
        string $filepath2,
        array $tolerances,
        bool $compareOtherColumns = true,
        string $delimiter=",",
        string $enclosure='"',
        string $escape="",
    ): bool
    {
        $hasFailed = false;
        $humanRowNumber = 1;
        $fileHandle1 = fopen($filepath1, "r");
        $fileHandle2 = fopen($filepath2, "r");

        if (!$fileHandle1 || !$fileHandle2)
        {
            throw new FilesystemException("diffTolerance: Failed to open CSV files for comparison.");
        }

        while (!feof($fileHandle1) && $hasFailed === FALSE)
        {
            $lineArray1 = fgetcsv($fileHandle1, 0, $delimiter, $enclosure, $escape);
            $lineArray2 = fgetcsv($fileHandle2, 0, $delimiter, $enclosure, $escape);

            if ($lineArray1)
            {
                foreach ($lineArray1 as $index => $value1)
                {
                    $humanColumnNumber = $index + 1;

                    // Can't use isset() here, as fgetcsv returns [null] for blank lines.
                    if (!is_array($lineArray2) || !array_key_exists($index, $lineArray2))
                    {
                        throw new InvalidCsvException("diffTolerance: rows do not have the same column count.");
                    }

                    $value2 = $lineArray2[$index];

                    if ($compareOtherColumns || (isset($tolerances[$humanColumnNumber])))
                    {
                        if (is_numeric($value1) && is_numeric($value2))
                        {
                            $numericDifference = abs($value1 - $value2);

                            if (isset($tolerances[$humanColumnNumber]))
                            {
                                if ($numericDifference > $tolerances[$humanColumnNumber])
                                {
                                    $hasFailed = TRUE;
                                }
                            }
                            else
                            {
                                if ($numericDifference > 0) // can't use !== on the values as 1.100 !== 1.1
                                {
                                    $hasFailed = TRUE;
                                }
                            }
                        }
                        else
                        {
                            // comparing non numeric values...
                            if (isset($tolerances[$humanColumnNumber]))
                            {
                                $msg = "diffTolerance: Trying to perform numeric tolerance " .
                                       "diff on non-numeric column. Column: $humanColumnNumber " .
                                       "Row: $humanRowNumber";

                                throw new InvalidCsvException($msg);
                            }
                        }
                    }
                    else
                    {
                        // Skip this column as we don't wish to check it.
                    }
                }
            }

            $humanRowNumber++;
        }

        fclose($fileHandle1);
        fclose($fileHandle2);

        $passed = !$hasFailed;
        return $passed;
    }


    /**
     * Merges multiple csv files together in a memory efficient way.
     * This will gracefully handle cases where the files may or may not have
     * ended with an endline.
     * This will also gracefully handle keeping the headers from the first file
     * (if hasHeaders is set to true), and ignoring headers in the subsequent files.
     * WARNING - all files need to either have headers or not, not both
     * @param array $filepaths - array of filepaths to csv files we wish to merge
     * @param string $mergedFilepath - where to write the merged file. This will overwrite
     *                                 any existing file if there is one there already.
     * @param bool $hasHeaders - specify if the files have headers or not.
     * @param string $delimiter - optionally specify the delimiter being used in the files
     * @param string $enclosure - optionally specify the enclosure being used in the files
     * @param string $escape - optionally specify the escape sequence to use when passing to fputcsv.
     * @throws FilesystemException - if the merged file can't be opened for writing, or one of the files can't be read.
     * @return void
     */
    public static function mergeFiles(
        array $filepaths,
        string $mergedFilepath,
        bool $hasHeaders,
        string $delimiter = ",",
        string $enclosure = '"',
        string $escape = "",
    ): void
    {
        $isFirstFile = true;
        $outputFileHandle = fopen($mergedFilepath, "w");

        if ($outputFileHandle === false)
        {
            throw new FilesystemException("Unable to open the merged file for writing: {$mergedFilepath}");
        }

        foreach ($filepaths as $filepath)
        {
            $readHandle = fopen($filepath, "r");
            $isFirstLine = true;

            if ($readHandle === false)
            {
                fclose($outputFileHandle);
                throw new FilesystemException("Unable to read file for merging: {$filepath}");
            }

            while (($fields = fgetcsv($readHandle, 0, $delimiter, $enclosure, $escape)) !== FALSE)
            {
                $copyLineAcross = false;

                if ($isFirstLine)
                {
                    if ($hasHeaders && $isFirstFile)
                    {
                        $copyLineAcross = true;
                    }
                    elseif ($hasHeaders === FALSE)
                    {
                        $copyLineAcross = true;
                    }

                    $isFirstLine = false;
                }
                else
                {
                    $copyLineAcross = true;
                }

                if ($copyLineAcross)
                {
                    fputcsv(
                        stream: $outputFileHandle,
                        fields: $fields,
                        separator: $delimiter,
                        enclosure: $enclosure,
                        escape: $escape,
                    );
                }
            }

            $isFirstFile = false;
            fclose($readHandle);
        }

        fclose($outputFileHandle);
    }


    /**
     * Determine whether a row returned by fgetcsv/str_getcsv is a blank line, which fgetcsv/str_getcsv return as
     * [null]. Note that a line of just delimiters (e.g. ",,,") is NOT a blank line, as it is a row of empty values,
     * which may be legitimate data.
     * @param array $row - the row as returned by fgetcsv/str_getcsv.
     * @return bool - true if the row came from a blank line, false otherwise.
     */
    private static function isBlankRow(array $row): bool
    {
        return $row === [null];
    }
}
