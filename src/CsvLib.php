<?php

/*
 * A library for handling CSV files
 */

namespace Programster\CoreLibs;


use Programster\CoreLibs\Enums\UseSystemTempDirEnum;
use Programster\CoreLibs\Enums\PreserveOwnershipEnum;
use Programster\CoreLibs\Models\CsvCellError;
use Programster\CoreLibs\Models\CsvFile;
use Programster\CoreLibs\Exceptions\BadFilePermissionsException;
use Programster\CoreLibs\Exceptions\EmptyArrayException;
use Programster\CoreLibs\Exceptions\FileDoesNotExistException;
use Programster\CoreLibs\Exceptions\FilesystemException;
use Programster\CoreLibs\Exceptions\InvalidCsvException;
use Programster\CoreLibs\Exceptions\NonNumericCsvValuesException;
use Programster\CoreLibs\Exceptions\BadArgumentException;

final class CsvLib
{
    # The UTF-8 byte order mark, which some programs (e.g. Excel) put at the start of a CSV file, and which
    # convertArrayToCsv() adds by default.
    private const BYTE_ORDER_MARK = "\xEF\xBB\xBF";

    /**
     * Converts a CSV file into a JSON file: an array with an object for each row of data, keyed by
     * the header row (so the file must have one). Quoted values can contain line breaks. Blank lines
     * at the end are ignored.
     * This is written to handle very large CSV files: it reads one row at a time, and writes each
     * row's JSON object to the file as it goes, rather than loading the whole CSV into memory and
     * calling json_encode() on all of it at once (which could run the system out of memory). Only
     * one row is held in memory at a time, whether or not $compressed is set.
     * @param string $csvFilepath - location of the CSV file we wish to convert.
     * @param string $jsonFilepath - path to where we wish to store the result. If the file already
     * exists, then it will be overwritten. If not then it will be created. The JSON is written to a
     * temporary file first, and only copied here once complete, so if anything goes wrong, an
     * existing file is left as it was.
     * @param bool $compressed - if true then this will have no line endings or padding, if false
     * then the outputted json will be much easier to read by humans.
     * @param string $delimiter - optionally specify a delimiter other than the default comma.
     * @param string $enclosure - optionally specify the enclosure if it isn't double quote
     * @param string $escape - optionally specify the escape character to use when parsing the CSV.
     * @param UseSystemTempDirEnum $useSystemTempDir - where to write the new contents before they
     * replace an existing JSON file (see UseSystemTempDirEnum). Defaults to AUTO, which picks the fastest place
     * that will work, which can be a hidden, private file next to it for a moment. Pass ALWAYS to
     * keep it in the system's temporary directory, e.g. for a directory served to the public.
     * @param PreserveOwnershipEnum $preserveOwnership - how hard to try to keep an existing file's owner and
     * group when replacing it (see Filesystem::replaceWithFile()). Defaults to PREFERRED.
     * @return void
     * @throws FileDoesNotExistException - if there is no file at $csvFilepath.
     * @throws InvalidCsvException - if the CSV file isn't valid (see the CsvFile constructor), e.g.
     * it has a blank line in the middle.
     * @throws BadFilePermissionsException - if the JSON file (or, for a new file, its directory)
     * can't be written to.
     * @throws FilesystemException - if the CSV file could not be read, or the JSON file could not
     * be written.
     * @throws \JsonException - if a value can't be encoded as JSON (e.g. it isn't valid UTF-8).
     */
    public static function convertCsvToJson(
        string $csvFilepath,
        string $jsonFilepath,
        bool $compressed,
        string $delimiter = ",",
        string $enclosure = '"',
        string $escape = "",
        UseSystemTempDirEnum $useSystemTempDir = UseSystemTempDirEnum::AUTO,
        PreserveOwnershipEnum $preserveOwnership = PreserveOwnershipEnum::PREFERRED
    ): void
    {
        # Check the file first, so a blank line in the middle (which makes it invalid) is an error, rather than skipped.
        self::validateCsvFile($csvFilepath, $delimiter, $enclosure, $escape);

        $writeJson = function ($jsonFile) use ($csvFilepath, $compressed, $delimiter, $enclosure, $escape): void {
            # Suppress the warning, as we throw an exception instead.
            $csvHandle = @fopen($csvFilepath, "r");

            if ($csvHandle === false)
            {
                throw new FilesystemException("Failed to open CSV file for reading: {$csvFilepath}");
            }

            $jsonFlags = JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR | ($compressed ? 0 : JSON_PRETTY_PRINT);
            $separator = $compressed ? "," : "," . PHP_EOL;

            try
            {
                # Remove a byte order mark, so that it doesn't become part of the first header's name.
                self::skipByteOrderMark($csvHandle);
                $headers = fgetcsv($csvHandle, 0, $delimiter, $enclosure, $escape);
                $hasWrittenObject = false;
                self::writeToOutput($jsonFile, $compressed ? "[" : "[" . PHP_EOL);

                # Read with fgetcsv(), so a quoted value can contain line breaks. Blank lines can only be at the end,
                # as the file has been validated, so are skipped.
                while ($headers !== false && ($row = fgetcsv($csvHandle, 0, $delimiter, $enclosure, $escape)) !== false)
                {
                    if (!self::isBlankRow($row))
                    {
                        $object = new \stdClass();

                        foreach ($headers as $headerIndex => $header)
                        {
                            $object->$header = $row[$headerIndex];
                        }

                        $jsonString = json_encode($object, $jsonFlags);

                        if (!$compressed)
                        {
                            # Indent each object by four spaces, as it is inside the array.
                            $jsonString = "    " . str_replace(PHP_EOL, PHP_EOL . "    ", $jsonString);
                        }

                        self::writeToOutput($jsonFile, ($hasWrittenObject ? $separator : "") . $jsonString);
                        $hasWrittenObject = true;
                    }
                }

                self::writeToOutput($jsonFile, "]");
            }
            finally
            {
                fclose($csvHandle);
            }
        };

        # Write to a temporary file first, so that a failure part way through (e.g. a value that can't be encoded)
        # leaves an existing file as it was.
        self::writeOutputFile($jsonFilepath, $writeJson, $useSystemTempDir, $preserveOwnership);
    }


    /**
     * Loops through the CSV file and puts the data into an array. You can specify whether the CSV
     * has a header and if keys is not provided then we will use these as indexes. If there is no
     * header and no keys, then an array list of rows is returned. If keys are provided then these
     * are always used as the indexes even if it has to override the header. WARNING - this can be a
     * memory hog.
     * @param string $filepath - the path to the file, including the name.
     * @param bool $hasHeader - specify whether the CSV file has a header which it will have to skip
     * for values, and may or may not be used for indexes of the rows depending on whether $keys is
     * provided.
     * @param ?array $keys - optional parameter to specify the indexes to use in the rows. If not
     * provided and there is a header, then we will use the header columns as indexes.
     * @param string $delimiter - optionally specify the delimiter if it isn't a comma
     * @param string $enclosure - optionally specify the enclosure if it isn't double quote
     * @param string $escape - optionally specify the escape character to use when parsing the CSV.
     *
     * @return array - an array list of indexed rows that the CSV has been converted into.
     * @throws FileDoesNotExistException - if there is no file at $filepath.
     * @throws FilesystemException - if the file could not be opened for reading.
     * @throws InvalidCsvException - if the file isn't valid (see the CsvFile constructor), e.g. it
     * has a blank line in the middle, or $keys doesn't have the same number of values as the rows.
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
        # Check the file first, so a blank line in the middle (which makes it invalid) is an error, rather than skipped.
        self::validateCsvFile($filepath, $delimiter, $enclosure, $escape);

        # Suppress the warning, as we throw an exception instead.
        $file = @fopen($filepath, 'r');

        if ($file === false)
        {
            throw new FilesystemException("Failed to open {$filepath} for reading.");
        }

        self::skipByteOrderMark($file);

        $output = [];
        $firstRow = true;

        # Close the file however this ends, including when a row throws an exception.
        try
        {
            while ($row = fgetcsv($file, 0, $delimiter, $enclosure, $escape))
            {
                # Skip blank lines, which can only be at the end, as the file has been validated (users may
                # accidentally leave some there, and LibreOffice Calc won't strip them either).
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
        }
        finally
        {
            fclose($file);
        }

        return $output;
    }


    /**
     * "Walk" a CSV file like array_walk, except that instead of an array, we walk the CSV file,
     * with each call to the callback calling the passed callable, with the array representing the
     * CSV row.
     * @param string $filepath - the path to the CSV file.
     * @param callable $callback - the callback to execute on each row of CSV data. Typically,
     * callback takes on two parameters. The array representing the row being the first, and the row
     * index being the second (starts from 0, but the data might start on row 1 if the CSV has
     * headers).
     * @param bool $hasHeaders - whether the CSV file's first row is the names for the columns.
     * Usually this is true. If the CSV file has headers, then the array passed to the callable will
     * be an associative array of name/value pairs with the name being the corresponding header.
     * @param null|array $headers - optionally manually specify the headers if you wish to set the
     * keys for the array that gets passed to the callable. If left as null, this is ignored. If set
     * and hasHeaders is set to true, this will override any headers in the file.
     * @param string $delimiter - delimiter to pass through to fgetcsv
     * @param string $enclosure - enclosure to pass through to fgetcsv
     * @param string $escape - escape value to pass through to fgetcsv
     * @throws FileDoesNotExistException - if there is no file at $filepath.
     * @throws FilesystemException - if the file could not be opened for reading.
     * @throws InvalidCsvException - if the file isn't valid (see the CsvFile constructor), e.g. it
     * has a blank line in the middle, or $headers doesn't have the same number of values as the
     * rows.
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
        # Check the file first, so a blank line in the middle (which makes it invalid) is an error, rather than skipped.
        self::validateCsvFile($filepath, $delimiter, $enclosure, $escape);

        # Suppress the warning, as we throw an exception instead.
        $fileHandle = @fopen($filepath, 'r');

        if ($fileHandle === false)
        {
            throw new FilesystemException("Failed to open {$filepath} for reading.");
        }

        self::skipByteOrderMark($fileHandle);

        $firstRow = true;
        $rowIndex = -1;

        # Close the file however this ends, including when the callback throws an exception.
        try
        {
            while ($row = fgetcsv($fileHandle, 0, $delimiter, $enclosure, $escape))
            {
                $rowIndex++;

                # Skip blank lines, which can only be at the end, as the file has been validated (users may
                # accidentally leave some there, and LibreOffice Calc won't strip them either).
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
        }
        finally
        {
            fclose($fileHandle);
        }
    }


    /**
     * Create a CSV file from the provided array of data. This is done in a memory efficient manner
     * of writing one line at a time to the file rather than building a massive string and dumping
     * the entire string to the file.
     * @param string $filepath - the path to the file that we will write the csv to, creating if
     * necessary. The CSV is written to a temporary file first, and only copied here once complete,
     * so if anything goes wrong (e.g. a bad row), an existing file is left as it was. An existing
     * file keeps its permissions and owner, and a new one gets the default permissions for a new
     * file.
     * @param array $rows - a collection of associative name/value pairs for the data to fill the
     * csv file. The values will be filled in in the order of the keys in the first row.
     * @param bool $addHeader - specify whether we should put a header row in the CSV file. If true,
     * we will use the keys of the first row as we expect all keys to match.
     * @param string $delimiter - optionally specify a delimiter if you don't wish to use the comma
     * @param string $enclosure - optionally specify the enclosure if you don't wish to use "
     * @param bool $addByteOrderMark - whether to add the byte order mark to the csv, so that Excel
     * handles UTF-8 characters.
     * @param string $escape - optionally set the escape sequence to use when passing to fputcsv.
     * @param UseSystemTempDirEnum $useSystemTempDir - where to write the new contents before they
     * replace an existing file (see UseSystemTempDirEnum). Defaults to AUTO, which picks the fastest place
     * that will work, which can be a hidden, private file next to it for a moment. Pass ALWAYS to
     * keep it in the system's temporary directory, e.g. for a directory served to the public.
     * @param PreserveOwnershipEnum $preserveOwnership - how hard to try to keep an existing file's owner and
     * group when replacing it (see Filesystem::replaceWithFile()). Defaults to PREFERRED.
     * @return void - all data written to the passed in filepath.
     * @throws EmptyArrayException - if there are no rows, or the first row has no columns.
     * @throws BadArgumentException - if the rows are not associative arrays, or don't all have the
     * same keys.
     * @throws BadFilePermissionsException - if the file (or, for a new file, its directory) can't
     * be written to.
     * @throws FilesystemException - if the file could not be written.
     */
    public static function convertArrayToCsv(
        string $filepath,
        array $rows,
        bool $addHeader,
        string $delimiter = ",",
        string $enclosure = '"',
        bool $addByteOrderMark = true,
        string $escape="",
        UseSystemTempDirEnum $useSystemTempDir = UseSystemTempDirEnum::AUTO,
        PreserveOwnershipEnum $preserveOwnership = PreserveOwnershipEnum::PREFERRED
    ): void
    {
        # Check the data before doing anything else.
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
            throw new BadArgumentException("convertArrayToCsv expects a list of associative arrays.");
        }

        $writeCsvRows = function ($fileHandle) use (
            $rows,
            $firstRow,
            $addHeader,
            $delimiter,
            $enclosure,
            $addByteOrderMark,
            $escape
        ): void {
            if ($addByteOrderMark)
            {
                self::writeToOutput($fileHandle, self::BYTE_ORDER_MARK);
            }

            $keys = array_keys($firstRow);

            if ($addHeader)
            {
                self::writeCsvRowToOutput($fileHandle, $keys, $delimiter, $enclosure, $escape);
            }

            foreach ($rows as $index => $row)
            {
                if (count($keys) != count($row))
                {
                    $msg = "Cannot convert array to CSV. Number of keys: " . count($keys) .
                           " is not the same as the number of values: " . count($row);

                    throw new BadArgumentException($msg);
                }

                $rowOfValues = [];

                foreach ($keys as $key)
                {
                    # Can't use isset() here, as a key may be set with a null value, which is written as empty.
                    if (!array_key_exists($key, $row))
                    {
                        throw new BadArgumentException("Row {$index} is missing the expected key {$key}.");
                    }

                    $rowOfValues[] = $row[$key];
                }

                self::writeCsvRowToOutput($fileHandle, $rowOfValues, $delimiter, $enclosure, $escape);
            }
        };

        # Write to a temporary file first, so that a bad row part way through leaves an existing file as it was.
        self::writeOutputFile($filepath, $writeCsvRows, $useSystemTempDir, $preserveOwnership);
    }


    /**
     * Go through the CSV file and trim the whitespace from the start and end of every value. The
     * file is checked as described in the CsvFile constructor first, so a blank line in the middle
     * of the file makes it invalid, and nothing is changed. Blank lines at the end of the file are
     * removed, unless $removeTrailingBlankLines is false. This works row by row rather than reading
     * everything into memory. The new contents are written to a temporary file in the system's
     * temporary directory, then copied back into the original, which keeps its permissions and
     * owner (see Filesystem::replaceWithFile()). Only the file, not its directory, needs to be
     * writable.
     * @param string $filepath - the path to the CSV file we are trimming
     * @param string $delimiter - optionally specify the delimiter if it isn't a comma, e.g. ';'.
     * @param string $enclosure - optionally specify the enclosure if it isn't a double quote, e.g.
     * "'".
     * @param string $escape - the escape sequence to use when passing to fputcsv.
     * @param bool $removeTrailingBlankLines - whether to also remove the blank lines at the end of
     * the file, using removeTrailingBlankLines(). Defaults to true.
     * @param UseSystemTempDirEnum $useSystemTempDir - where to write the new contents before they
     * replace the file (see UseSystemTempDirEnum). Defaults to AUTO, which picks the fastest place
     * that will work, which can be a hidden, private file next to it for a moment. Pass ALWAYS to
     * keep it in the system's temporary directory, e.g. for a directory served to the public.
     * @param PreserveOwnershipEnum $preserveOwnership - how hard to try to keep the file's owner and
     * group when replacing it (see Filesystem::replaceWithFile()). Defaults to PREFERRED.
     * @return void
     * @throws FileDoesNotExistException - if there is no file at $filepath.
     * @throws BadFilePermissionsException - if the file can't be both read and written.
     * @throws InvalidCsvException - if the file isn't valid (see the CsvFile constructor), e.g. it
     * has a blank line in the middle. The file is left unchanged if this happens.
     * @throws FilesystemException - if the file could not be read, or its new contents could not be
     * written.
     */
    public static function trim(
        string $filepath,
        string $delimiter = ",",
        string $enclosure = '"',
        string $escape = "",
        bool $removeTrailingBlankLines = true,
        UseSystemTempDirEnum $useSystemTempDir = UseSystemTempDirEnum::AUTO,
        PreserveOwnershipEnum $preserveOwnership = PreserveOwnershipEnum::PREFERRED
    ): void
    {
        Filesystem::checkCanChangeFile($filepath);

        # Blank lines in the middle of the file make it invalid, so check for them before changing anything.
        self::validateCsvFile($filepath, $delimiter, $enclosure, $escape);

        # Suppress the warning, as we throw an exception instead.
        $fileHandle = @fopen($filepath, "r");

        if ($fileHandle === false)
        {
            throw new FilesystemException("trim: Failed to open {$filepath} for reading.");
        }

        $tmpFilepath = null;
        $tmpFile = false;

        # Only copy the new contents into the original once every row has been written, so a failure part way through
        # leaves the original untouched.
        try
        {
            $tmpFilepath = Filesystem::createReplacementFile($filepath, $useSystemTempDir, $preserveOwnership);
            $tmpFile = fopen($tmpFilepath, "w");

            if ($tmpFile === false)
            {
                throw new FilesystemException("trim: Failed to open a temporary file to write to.");
            }

            # Keep the byte order mark if the file had one, but don't let it become part of the first value.
            if (self::skipByteOrderMark($fileHandle))
            {
                fwrite($tmpFile, self::BYTE_ORDER_MARK);
            }

            while (($lineArray = fgetcsv($fileHandle, 0, $delimiter, $enclosure, $escape)) !== false)
            {
                foreach ($lineArray as $index => $value)
                {
                    // fgetcsv returns [null] for a blank line, which can only be at the end of the file here.
                    $lineArray[$index] = trim($value ?? "");
                }

                $written = fputcsv(
                    stream: $tmpFile,
                    fields: $lineArray,
                    separator: $delimiter,
                    enclosure: $enclosure,
                    escape: $escape
                );

                if ($written === false)
                {
                    throw new FilesystemException("trim: Failed to write to the temporary file.");
                }
            }
        }
        catch (\Throwable $e)
        {
            self::abandonReplacementFile($fileHandle, $tmpFile, $tmpFilepath);
            throw $e;
        }

        fclose($fileHandle);
        fclose($tmpFile);
        Filesystem::replaceWithFile($filepath, $tmpFilepath, $preserveOwnership);

        if ($removeTrailingBlankLines)
        {
            self::removeTrailingBlankLines($filepath);
        }
    }


    /**
     * Remove the blank lines at the end of a CSV file, which editors and spreadsheet programs often
     * add. The last row keeps its line ending (whichever of "\n", "\r\n" or "\r" it used), so the
     * file still ends with exactly one. A file with no blank lines at the end is left as it is, and
     * a file of only blank lines becomes empty. This changes the file in place, without reading it
     * all into memory, and doesn't change its permissions.
     * @param string $filepath - the path to the CSV file.
     * @return void
     * @throws FileDoesNotExistException - if there is no file at $filepath.
     * @throws BadFilePermissionsException - if the file can't be both read and written.
     * @throws FilesystemException - if the file could not be opened, read, or shortened.
     */
    public static function removeTrailingBlankLines(string $filepath): void
    {
        Filesystem::checkCanChangeFile($filepath);

        # Suppress the warning, as we throw an exception instead.
        $handle = @fopen($filepath, "r+");

        if ($handle === false)
        {
            throw new FilesystemException("removeTrailingBlankLines: Failed to open {$filepath} for writing.");
        }

        try
        {
            $stats = fstat($handle);

            if ($stats === false)
            {
                throw new FilesystemException("removeTrailingBlankLines: Failed to get the size of {$filepath}.");
            }

            # Read backwards from the end in chunks, until something other than a line ending is found.
            $chunkSize = 8192;
            $chunkEnd = $stats['size'];
            $contentEnd = null;

            while ($contentEnd === null && $chunkEnd > 0)
            {
                $chunkStart = max(0, $chunkEnd - $chunkSize);
                fseek($handle, $chunkStart);
                $chunk = fread($handle, $chunkEnd - $chunkStart);

                if ($chunk === false)
                {
                    throw new FilesystemException("removeTrailingBlankLines: Failed to read {$filepath}.");
                }

                $trimmedChunk = rtrim($chunk, "\r\n");

                if ($trimmedChunk !== "")
                {
                    $contentEnd = $chunkStart + strlen($trimmedChunk);
                }

                $chunkEnd = $chunkStart;
            }

            # Keep the last row's own line ending, if it has one.
            $newSize = 0;

            if ($contentEnd !== null)
            {
                fseek($handle, $contentEnd);
                $afterContent = (string)fread($handle, 2);

                if (str_starts_with($afterContent, "\r\n"))
                {
                    $newSize = $contentEnd + 2;
                }
                elseif ($afterContent !== "")
                {
                    $newSize = $contentEnd + 1;
                }
                else
                {
                    $newSize = $contentEnd;
                }
            }

            if ($newSize < $stats['size'] && !ftruncate($handle, $newSize))
            {
                throw new FilesystemException("removeTrailingBlankLines: Failed to shorten {$filepath}.");
            }
        }
        finally
        {
            fclose($handle);
        }
    }


    /**
     * Remove columns from a CSV file. The new contents are written to a temporary file in the
     * system's temporary directory, then copied back into the original, which keeps its permissions
     * and owner (see Filesystem::replaceWithFile()). Only the file, not its directory, needs to be
     * writable.
     * @param string $filepath - the path of the CSV file we are modifying.
     * @param array $columnIndexes - array of integers specifying the column indexes to remove,
     * starting at 1
     * @param string $delimiter - optionally specify the delimiter if it isn't a comma
     * @param string $enclosure - optionally specify the enclosure if it isn't double quote
     * @param string $escape - optionally set the escape sequence to use when passing to fputcsv.
     * @param UseSystemTempDirEnum $useSystemTempDir - where to write the new contents before they
     * replace the file (see UseSystemTempDirEnum). Defaults to AUTO, which picks the fastest place
     * that will work, which can be a hidden, private file next to it for a moment. Pass ALWAYS to
     * keep it in the system's temporary directory, e.g. for a directory served to the public.
     * @param PreserveOwnershipEnum $preserveOwnership - how hard to try to keep the file's owner and
     * group when replacing it (see Filesystem::replaceWithFile()). Defaults to PREFERRED.
     * @return void
     * @throws InvalidCsvException - if the file isn't valid (see the CsvFile constructor), e.g. it
     * has a blank line in the middle (blank lines at the very end are allowed, and are kept), or it
     * doesn't have one of the columns being removed. The file is left untouched if this happens.
     * @throws FileDoesNotExistException - if there is no file at $filepath.
     * @throws BadFilePermissionsException - if the file can't be both read and written.
     * @throws FilesystemException - if the file could not be read, or its new contents could not be
     * written.
     */
    public static function removeColumns(
        string $filepath,
        array $columnIndexes,
        string $delimiter=",",
        string $enclosure='"',
        string $escape="",
        UseSystemTempDirEnum $useSystemTempDir = UseSystemTempDirEnum::AUTO,
        PreserveOwnershipEnum $preserveOwnership = PreserveOwnershipEnum::PREFERRED
    ): void
    {
        Filesystem::checkCanChangeFile($filepath);

        # Check the file first, so a blank line in the middle (which makes it invalid) is an error, and every row has
        # the same number of columns, so the columns only need checking against the first.
        $columnCount = (new CsvFile($filepath, false, $delimiter, $enclosure, $escape))->getColumnCount();

        foreach ($columnIndexes as $columnHumanIndex)
        {
            if (!is_int($columnHumanIndex) || $columnHumanIndex < 1 || $columnHumanIndex > $columnCount)
            {
                throw new InvalidCsvException(
                    "removeColumns: {$filepath} does not have a column {$columnHumanIndex}, as it has {$columnCount}."
                );
            }
        }

        # Suppress the warning, as we throw an exception instead.
        $fileHandle = @fopen($filepath, "r");

        if ($fileHandle === false)
        {
            throw new FilesystemException("removeColumns: Failed to open {$filepath} for reading.");
        }

        $tmpFilepath = null;
        $tmpFile = false;
        $numTrailingBlankLines = 0;

        # Only copy the new contents into the original once every row has been processed, so throwing part way
        # through leaves the original untouched.
        try
        {
            $tmpFilepath = Filesystem::createReplacementFile($filepath, $useSystemTempDir, $preserveOwnership);
            $tmpFile = fopen($tmpFilepath, "w");

            if ($tmpFile === false)
            {
                throw new FilesystemException("removeColumns: Failed to open a temporary file to write to.");
            }

            # Keep the byte order mark if the file had one, but don't let it become part of the first value.
            if (self::skipByteOrderMark($fileHandle))
            {
                fwrite($tmpFile, self::BYTE_ORDER_MARK);
            }

            while (($lineArray = fgetcsv($fileHandle, 0, $delimiter, $enclosure, $escape)) !== false)
            {
                if (self::isBlankRow($lineArray))
                {
                    # The file has been validated, so blank lines can only be at the end.
                    $numTrailingBlankLines++;
                }
                else
                {
                    foreach ($columnIndexes as $columnHumanIndex)
                    {
                        unset($lineArray[$columnHumanIndex - 1]);
                    }

                    $written = fputcsv(
                        stream: $tmpFile,
                        fields: $lineArray,
                        separator: $delimiter,
                        enclosure: $enclosure,
                        escape: $escape
                    );

                    if ($written === false)
                    {
                        throw new FilesystemException("removeColumns: Failed to write to the temporary file.");
                    }
                }
            }

            # Keep any blank lines that were at the end of the file.
            fwrite($tmpFile, str_repeat("\n", $numTrailingBlankLines));
        }
        catch (\Throwable $e)
        {
            self::abandonReplacementFile($fileHandle, $tmpFile, $tmpFilepath);
            throw $e;
        }

        fclose($fileHandle);
        fclose($tmpFile);
        Filesystem::replaceWithFile($filepath, $tmpFilepath, $preserveOwnership);
    }


    /**
     * Remove rows from a CSV file. Rows are counted as CSV rows rather than lines, so a quoted
     * value that spans several lines is part of one row. The rows that are kept, and any blank
     * lines at the end of the file, are copied across exactly as they were (including their quoting
     * and line endings), as is any byte order mark. The file is checked as described in the CsvFile
     * constructor first, so it can't have blank lines in the middle. The new contents are written
     * to a temporary file in the system's temporary directory, then copied back into the original,
     * which keeps its permissions and owner (see Filesystem::replaceWithFile()). Only the file, not
     * its directory, needs to be writable.
     * @param string $filepath - the path of the CSV file we are modifying.
     * @param array $rowNumbers - the numbers of the rows to remove, e.g. [0, 4]. Any header row
     * counts as a row. Duplicates are allowed, and the order doesn't matter.
     * @param bool $startFromZero - whether the first row is row 0 (the default), or row 1 if false.
     * @param string $delimiter - optionally specify the delimiter if it isn't a comma
     * @param string $enclosure - optionally specify the enclosure if it isn't double quote
     * @param string $escape - optionally specify the escape character to use when parsing the CSV.
     * @param UseSystemTempDirEnum $useSystemTempDir - where to write the new contents before they
     * replace the file (see UseSystemTempDirEnum). Defaults to AUTO, which picks the fastest place
     * that will work, which can be a hidden, private file next to it for a moment. Pass ALWAYS to
     * keep it in the system's temporary directory, e.g. for a directory served to the public.
     * @param PreserveOwnershipEnum $preserveOwnership - how hard to try to keep the file's owner and
     * group when replacing it (see Filesystem::replaceWithFile()). Defaults to PREFERRED.
     * @return void
     * @throws BadArgumentException - if any of $rowNumbers isn't an int, or isn't the number of a
     * row in the file.
     * @throws InvalidCsvException - if the file isn't valid (see the CsvFile constructor).
     * @throws FileDoesNotExistException - if there is no file at $filepath.
     * @throws BadFilePermissionsException - if the file can't be both read and written.
     * @throws FilesystemException - if the file could not be read, or its new contents could not be
     * written.
     */
    public static function removeRows(
        string $filepath,
        array $rowNumbers,
        bool $startFromZero = true,
        string $delimiter = ",",
        string $enclosure = '"',
        string $escape = "",
        UseSystemTempDirEnum $useSystemTempDir = UseSystemTempDirEnum::AUTO,
        PreserveOwnershipEnum $preserveOwnership = PreserveOwnershipEnum::PREFERRED
    ): void
    {
        Filesystem::checkCanChangeFile($filepath);

        # Check the file first, so that the row numbers mean the same as in CsvFile::getRowCount(), and any blank lines
        # can only be at the end.
        $csvFile = new CsvFile($filepath, false, $delimiter, $enclosure, $escape);
        $rowCount = $csvFile->getRowCount();
        $firstRowNumber = $startFromZero ? 0 : 1;
        $lastRowNumber = $firstRowNumber + $rowCount - 1;
        $rowIndexesToRemove = [];

        foreach ($rowNumbers as $rowNumber)
        {
            # Throw rather than ignoring a number that's out of range, as that's usually a mix-up between counting
            # from 0 and from 1.
            if (!is_int($rowNumber) || $rowNumber < $firstRowNumber || $rowNumber > $lastRowNumber)
            {
                $validRange = ($rowCount === 0)
                    ? "it has no rows"
                    : "its rows are numbered {$firstRowNumber} to {$lastRowNumber}";

                throw new BadArgumentException(
                    "removeRows: " . var_export($rowNumber, true) . " is not a row number in {$filepath}, as " .
                    "{$validRange}."
                );
            }

            $rowIndexesToRemove[$rowNumber - $firstRowNumber] = true;
        }

        # Suppress the warning, as we throw an exception instead.
        $fileHandle = @fopen($filepath, "r");

        if ($fileHandle === false)
        {
            throw new FilesystemException("removeRows: Failed to open {$filepath} for reading.");
        }

        $tmpFilepath = null;
        $tmpFile = false;

        try
        {
            $tmpFilepath = Filesystem::createReplacementFile($filepath, $useSystemTempDir, $preserveOwnership);
            $tmpFile = fopen($tmpFilepath, "w");

            if ($tmpFile === false)
            {
                throw new FilesystemException("removeRows: Failed to open a temporary file to write to.");
            }

            # Keep the byte order mark even if the first row is removed.
            if (self::skipByteOrderMark($fileHandle))
            {
                fwrite($tmpFile, self::BYTE_ORDER_MARK);
            }

            for ($rowIndex = 0; $rowIndex < $rowCount; $rowIndex++)
            {
                # Note where the row starts and ends, so that a kept row's original bytes can be copied across.
                $rowStart = ftell($fileHandle);
                fgetcsv($fileHandle, 0, $delimiter, $enclosure, $escape);

                if (!isset($rowIndexesToRemove[$rowIndex]))
                {
                    $rowEnd = ftell($fileHandle);
                    fseek($fileHandle, $rowStart);

                    if (fwrite($tmpFile, (string)fread($fileHandle, $rowEnd - $rowStart)) === false)
                    {
                        throw new FilesystemException("removeRows: Failed to write to the temporary file.");
                    }
                }
            }

            # Keep anything after the last row (i.e. any blank lines at the end) exactly as it was.
            if (stream_copy_to_stream($fileHandle, $tmpFile) === false)
            {
                throw new FilesystemException("removeRows: Failed to write to the temporary file.");
            }
        }
        catch (\Throwable $e)
        {
            self::abandonReplacementFile($fileHandle, $tmpFile, $tmpFilepath);
            throw $e;
        }

        fclose($fileHandle);
        fclose($tmpFile);
        Filesystem::replaceWithFile($filepath, $tmpFilepath, $preserveOwnership);
    }


    /**
     * Check that a CSV file is well formed, throwing an exception describing the first problem
     * found. See the CsvFile constructor for what counts as valid. To also get the file's row and
     * column counts, create a CsvFile object instead.
     * @param string $filepath - the path to the CSV file to check.
     * @param string $delimiter - optionally specify the delimiter if it isn't a comma.
     * @param string $enclosure - optionally specify the enclosure if it isn't a double quote.
     * @param string $escape - optionally specify the escape character to use when parsing the CSV.
     * @return void
     * @throws FileDoesNotExistException - if there is no file at $filepath.
     * @throws FilesystemException - if the file could not be opened for reading.
     * @throws InvalidCsvException - if the file has a blank line before the last row of data, or a
     * row with a different number of columns to the first row.
     */
    public static function validateCsvFile(
        string $filepath,
        string $delimiter = ",",
        string $enclosure = '"',
        string $escape = "",
    ): void
    {
        # Whether there's a header only matters to the validation for an empty file, which is valid without one.
        new CsvFile($filepath, false, $delimiter, $enclosure, $escape);
    }


    /**
     * Calculate the mathematical differences between two CSV files. If this comes across string
     * values, it will check if they are the same and put the string in if they are, otherwise, it
     * will concatenate the two values with a | divider. Both files are checked as described in the
     * CsvFile constructor first, so neither may have blank lines in the middle, and every row in a
     * file must have the same number of columns. The two files must also have the same number of
     * rows and columns as each other. Blank lines at the end of either file are ignored. A header
     * row can't be detected automatically, so say which files have one. If both do, the output
     * starts with a header row made from them in the same way as for other text values (a | between
     * them if they differ, even if they look like numbers). If only one does, it's skipped, and the
     * output has no header row.
     * @param string $filepath1 - path to a CSV file to compare.
     * @param string $filepath2 - path to a CSV file to compare.
     * @param string $delimiter - optionally specify the delimiter if it isn't a comma
     * @param string $enclosure - optionally specify the enclosure if it isn't double quote
     * @param string $escape - the escape sequence to use when passing to fputcsv.
     * @param bool $file1HasHeader - whether the first row of $filepath1 is a header row. Defaults
     * to false.
     * @param bool $file2HasHeader - whether the first row of $filepath2 is a header row. Defaults
     * to false.
     * @return string - the path to the created diff CSV file.
     * @throws InvalidCsvException - if either file isn't valid (including having no rows when it
     * should have a header), or the two files don't have the same number of rows of data and
     * columns as each other.
     * @throws FileDoesNotExistException - if there is no file at either path.
     * @throws FilesystemException - if either CSV file could not be opened, or the diff file could
     * not be created.
     */
    public static function decimalDiff(
        string $filepath1,
        string $filepath2,
        string $delimiter=",",
        string $enclosure='"',
        string $escape="",
        bool $file1HasHeader = false,
        bool $file2HasHeader = false,
    ): string
    {
        # This checks both files are valid, so each file's rows all have the same number of columns, and there are no
        # blank lines before the end of the data. So the counts only need comparing once, here.
        $csvFile1 = new CsvFile($filepath1, $file1HasHeader, $delimiter, $enclosure, $escape);
        $csvFile2 = new CsvFile($filepath2, $file2HasHeader, $delimiter, $enclosure, $escape);
        $dataRowCount1 = $csvFile1->getRowCount() - ($file1HasHeader ? 1 : 0);
        $dataRowCount2 = $csvFile2->getRowCount() - ($file2HasHeader ? 1 : 0);

        if ($dataRowCount1 !== $dataRowCount2)
        {
            throw new InvalidCsvException(
                "decimalDiff: {$filepath1} has {$dataRowCount1} rows of data, but {$filepath2} has " .
                "{$dataRowCount2}. If only one of them has a header row, set \$file1HasHeader or \$file2HasHeader."
            );
        }

        if ($csvFile1->getColumnCount() !== $csvFile2->getColumnCount())
        {
            throw new InvalidCsvException(
                "decimalDiff: {$filepath1} has {$csvFile1->getColumnCount()} columns, but {$filepath2} has " .
                "{$csvFile2->getColumnCount()}."
            );
        }

        # The diff is a new temporary file, which is returned. Suppress the warning, as we throw an exception instead.
        $newFileName = @tempnam(sys_get_temp_dir(), "corelibs-diff-");

        if ($newFileName === false)
        {
            throw new FilesystemException("Failed to create a temporary file to store the diff in.");
        }

        $newFileHandle = false;
        $fileHandle1 = false;
        $fileHandle2 = false;

        try
        {
            $newFileHandle = @fopen($newFileName, "w");
            $fileHandle1 = @fopen($filepath1, "r");
            $fileHandle2 = @fopen($filepath2, "r");

            if ($newFileHandle === false || $fileHandle1 === false || $fileHandle2 === false)
            {
                throw new FilesystemException("decimalDiff: Failed to open the CSV files, or the diff file.");
            }

            # Skip any byte order marks, otherwise the first value would never match or be seen as a number.
            self::skipByteOrderMark($fileHandle1);
            self::skipByteOrderMark($fileHandle2);

            if ($file1HasHeader && $file2HasHeader)
            {
                $headerRow1 = fgetcsv($fileHandle1, 0, $delimiter, $enclosure, $escape);
                $headerRow2 = fgetcsv($fileHandle2, 0, $delimiter, $enclosure, $escape);
                $resultHeaderRow = [];

                # Don't subtract headers that look like numbers (e.g. years), as they're names, not values.
                foreach ($headerRow1 as $index => $header1)
                {
                    $header2 = $headerRow2[$index];
                    $resultHeaderRow[$index] = ($header1 === $header2) ? $header1 : $header1 . "|" . $header2;
                }

                self::writeCsvRowToOutput($newFileHandle, $resultHeaderRow, $delimiter, $enclosure, $escape);
            }
            elseif ($file1HasHeader)
            {
                # Only one file has a header, so there's nothing to compare it with. Skip it.
                fgetcsv($fileHandle1, 0, $delimiter, $enclosure, $escape);
            }
            elseif ($file2HasHeader)
            {
                fgetcsv($fileHandle2, 0, $delimiter, $enclosure, $escape);
            }

            # Read exactly the number of rows of data, which stops before any blank lines at the end of the files. This
            # also avoids checking feof(), which is only true after a read has failed, and so would add an extra empty
            # row at the end of the output.
            for ($rowIndex = 0; $rowIndex < $dataRowCount1; $rowIndex++)
            {
                $lineArray1 = fgetcsv($fileHandle1, 0, $delimiter, $enclosure, $escape);
                $lineArray2 = fgetcsv($fileHandle2, 0, $delimiter, $enclosure, $escape);
                $resultArray = [];

                foreach ($lineArray1 as $index => $value1)
                {
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

                self::writeCsvRowToOutput($newFileHandle, $resultArray, $delimiter, $enclosure, $escape);
            }

            # Closing flushes anything still buffered, which can fail (e.g. if the disk is full).
            $closed = fclose($newFileHandle);
            $newFileHandle = false;

            if (!$closed)
            {
                throw new FilesystemException("decimalDiff: Failed to finish writing the diff file.");
            }
        }
        catch (\Throwable $e)
        {
            # Don't leave a half-written diff file behind.
            if ($newFileHandle !== false)
            {
                fclose($newFileHandle);
                $newFileHandle = false;
            }

            @unlink($newFileName);
            throw $e;
        }
        finally
        {
            foreach ([$fileHandle1, $fileHandle2] as $handle)
            {
                if ($handle !== false)
                {
                    fclose($handle);
                }
            }
        }

        return $newFileName;
    }


    /**
     * Compare the numbers in two CSV files, allowing each value to differ by up to a given
     * tolerance. This can be useful for floating point values, where the last decimal place may be
     * different. Only the columns listed in $tolerances are compared, and they must hold numbers in
     * both files. Every other column is ignored, as this is only for comparing numbers. To compare
     * text, use a diff tool instead. Both files are checked completely before anything is compared:
     * first as described in the CsvFile constructor (e.g. no blank lines in the middle), then for
     * the same number of rows of data, then that they have all the listed columns, and then for any
     * non-numeric values in those columns. The non-numeric values are reported together in a
     * NonNumericCsvValuesException, whose getErrors() says where each one is. The search stops
     * after 100 of them, so a large file full of them doesn't take a long time or use a lot of
     * memory. A header row can't be detected automatically, so say which files have one. Header
     * rows aren't compared.
     *
     * @param string $filepath1 - the path to the file we are checking
     * @param string $filepath2 - the path to the file we are comparing it against
     * @param array $tolerances - the columns to compare, as column number / tolerance pairs, e.g.
     * [1 => 0.01, 3 => 0]. The column numbers start from 1, not 0. A tolerance of 0 means the
     * numbers must be equal (e.g. 1.10 and 1.1 are equal).
     * @param string $delimiter - optionally specify the delimiter to pass to fgetcsv (comma
     * default)
     * @param string $enclosure - optionally specify the enclosure to pass to fgetcsv (default: ")
     * @param string $escape - optionally specify the escape character to pass to fgetcsv.
     * @param bool $file1HasHeader - whether the first row of $filepath1 is a header row. Defaults
     * to false.
     * @param bool $file2HasHeader - whether the first row of $filepath2 is a header row. Defaults
     * to false.
     * @return bool - true if every compared value is within its tolerance, false if not.
     * @throws BadArgumentException - if $tolerances is empty, or has a column number that isn't an
     * int of 1 or more, or a tolerance that isn't a number of 0 or more.
     * @throws FileDoesNotExistException - if there is no file at either path.
     * @throws NonNumericCsvValuesException - if any of the listed columns hold non-numeric values,
     * in either file. This extends InvalidCsvException.
     * @throws InvalidCsvException - if either file isn't valid (see the CsvFile constructor), they
     * don't have the same number of rows of data, or either is missing one of the listed columns.
     * @throws FilesystemException - if either CSV file could not be opened.
     */
    public static function diffTolerance(
        string $filepath1,
        string $filepath2,
        array $tolerances,
        string $delimiter=",",
        string $enclosure='"',
        string $escape="",
        bool $file1HasHeader = false,
        bool $file2HasHeader = false,
    ): bool
    {
        # Without any columns to compare, every pair of files would count as the same, which is never useful.
        if (count($tolerances) === 0)
        {
            throw new BadArgumentException("diffTolerance: \$tolerances must list at least one column to compare.");
        }

        foreach ($tolerances as $columnNumber => $tolerance)
        {
            if (!is_int($columnNumber) || $columnNumber < 1 || !is_numeric($tolerance) || $tolerance < 0)
            {
                throw new BadArgumentException(
                    "diffTolerance: \$tolerances must be column number => tolerance pairs, with column numbers of 1 " .
                    "or more and tolerances of 0 or more, but has " . var_export($columnNumber, true) . " => " .
                    var_export($tolerance, true) . "."
                );
            }
        }

        # Check both files completely before comparing anything, so every problem is found up front.
        $csvFile1 = new CsvFile($filepath1, $file1HasHeader, $delimiter, $enclosure, $escape);
        $csvFile2 = new CsvFile($filepath2, $file2HasHeader, $delimiter, $enclosure, $escape);
        $dataRowCount1 = $csvFile1->getRowCount() - ($file1HasHeader ? 1 : 0);
        $dataRowCount2 = $csvFile2->getRowCount() - ($file2HasHeader ? 1 : 0);

        if ($dataRowCount1 !== $dataRowCount2)
        {
            throw new InvalidCsvException(
                "diffTolerance: {$filepath1} has {$dataRowCount1} rows of data, but {$filepath2} has " .
                "{$dataRowCount2}. If only one of them has a header row, set \$file1HasHeader or \$file2HasHeader."
            );
        }

        # Every row in a file has the same number of columns, so a missing column only needs checking once per file.
        $columnNumbers = array_keys($tolerances);
        $missingColumns = [];

        foreach ([$csvFile1, $csvFile2] as $csvFile)
        {
            foreach ($columnNumbers as $columnNumber)
            {
                if ($columnNumber > $csvFile->getColumnCount())
                {
                    $missingColumns[] = "{$csvFile->getFilepath()} has no column {$columnNumber}, as it has " .
                        "{$csvFile->getColumnCount()} column(s).";
                }
            }
        }

        if (count($missingColumns) > 0)
        {
            throw new InvalidCsvException(
                "diffTolerance: \$tolerances lists columns that aren't in the files:\n - " .
                implode("\n - ", $missingColumns)
            );
        }

        $maxErrors = 100;
        $errors = self::findNonNumericValues($csvFile1, $columnNumbers, $maxErrors);

        $errors = array_merge(
            $errors,
            self::findNonNumericValues($csvFile2, $columnNumbers, $maxErrors - count($errors))
        );

        if (count($errors) > 0)
        {
            if (count($errors) >= $maxErrors)
            {
                # The search stopped here, so there may be more than this. Listing them all would make the message
                # enormous, so point to getErrors() instead.
                $message = "diffTolerance: found {$maxErrors} or more (stopped counting at {$maxErrors}) non-numeric " .
                    "values in the columns being compared. Call getErrors() on this exception to get them.";
            }
            else
            {
                # List a few in the message, for logs. getErrors() has all of them.
                $maxErrorsListed = 10;

                $describeError = function (CsvCellError $error): string {
                    return "{$error->filepath} row {$error->rowNumber}, column {$error->columnNumber}: " .
                        var_export($error->value, true);
                };

                $listedErrors = array_map($describeError, array_slice($errors, 0, $maxErrorsListed));

                $message = "diffTolerance: the columns being compared must hold numbers in both files, but " .
                    count($errors) . " non-numeric value(s) were found:\n - " . implode("\n - ", $listedErrors);

                if (count($errors) > $maxErrorsListed)
                {
                    $message .= "\n - and " . (count($errors) - $maxErrorsListed) . " more. Call getErrors() on " .
                        "this exception to get them all.";
                }
            }

            throw new NonNumericCsvValuesException($message, $errors);
        }

        $hasFailed = false;
        $fileHandle1 = self::openForReadingData($csvFile1);
        $fileHandle2 = self::openForReadingData($csvFile2);

        # Every value being compared is now known to be a number. Stop at the first one that is out of tolerance.
        for ($rowIndex = 0; $rowIndex < $dataRowCount1 && $hasFailed === false; $rowIndex++)
        {
            $lineArray1 = fgetcsv($fileHandle1, 0, $delimiter, $enclosure, $escape);
            $lineArray2 = fgetcsv($fileHandle2, 0, $delimiter, $enclosure, $escape);

            foreach ($tolerances as $columnNumber => $tolerance)
            {
                # Compare the difference, as !== on the values would count 1.100 and 1.1 as different.
                if (abs($lineArray1[$columnNumber - 1] - $lineArray2[$columnNumber - 1]) > $tolerance)
                {
                    $hasFailed = true;
                }
            }
        }

        fclose($fileHandle1);
        fclose($fileHandle2);

        $passed = !$hasFailed;
        return $passed;
    }


    /**
     * Merges multiple csv files together in a memory efficient way. This will gracefully handle
     * cases where the files may or may not have ended with an endline. This will also gracefully
     * handle keeping the headers from the first file (if hasHeaders is set to true), and ignoring
     * headers in the subsequent files. Blank lines at the end of each file are left out, so they
     * don't end up in the middle of the merged file. WARNING - all files need to either have headers
     * or not, not both
     * @param array $filepaths - array of filepaths to csv files we wish to merge
     * @param string $mergedFilepath - where to write the merged file. This will overwrite any
     * existing file if there is one there already. The merged file is written to a temporary file
     * first, and only copied here once complete, so if anything goes wrong, an existing file is
     * left as it was. This can be one of the files in $filepaths.
     * @param bool $hasHeaders - specify if the files have headers or not.
     * @param string $delimiter - optionally specify the delimiter being used in the files
     * @param string $enclosure - optionally specify the enclosure being used in the files
     * @param string $escape - optionally specify the escape sequence to use when passing to
     * fputcsv.
     * @throws FileDoesNotExistException - if there is no file at one of $filepaths.
     * @throws InvalidCsvException - if one of the files isn't valid (see the CsvFile constructor),
     * e.g. it has a blank line in the middle, or the files don't have the same number of columns
     * (or, with $hasHeaders, the same header row). Empty files are skipped. Nothing is written if
     * this happens.
     * @throws BadFilePermissionsException - if the merged file (or, for a new file, its directory)
     * can't be written to.
     * @throws FilesystemException - if one of the files can't be read, or the merged file can't be
     * written.
     * @param UseSystemTempDirEnum $useSystemTempDir - where to write the new contents before they
     * replace an existing merged file (see UseSystemTempDirEnum). Defaults to AUTO, which picks the fastest place
     * that will work, which can be a hidden, private file next to it for a moment. Pass ALWAYS to
     * keep it in the system's temporary directory, e.g. for a directory served to the public.
     * @param PreserveOwnershipEnum $preserveOwnership - how hard to try to keep an existing file's owner and
     * group when replacing it (see Filesystem::replaceWithFile()). Defaults to PREFERRED.
     * @return void
     */
    public static function mergeFiles(
        array $filepaths,
        string $mergedFilepath,
        bool $hasHeaders,
        string $delimiter = ",",
        string $enclosure = '"',
        string $escape = "",
        UseSystemTempDirEnum $useSystemTempDir = UseSystemTempDirEnum::AUTO,
        PreserveOwnershipEnum $preserveOwnership = PreserveOwnershipEnum::PREFERRED
    ): void
    {
        # Check every file first, so a blank line in the middle of one (which makes it invalid), or files that don't
        # match, are an error, and nothing is written. An empty file has nothing to merge, so it can't mismatch.
        $first = null;

        foreach ($filepaths as $filepath)
        {
            $csvFile = new CsvFile($filepath, $hasHeaders, $delimiter, $enclosure, $escape);

            if ($csvFile->getColumnCount() > 0)
            {
                $header = $hasHeaders ? self::readFirstRow($csvFile) : null;
                $first ??= ['path' => $filepath, 'columns' => $csvFile->getColumnCount(), 'header' => $header];

                if ($csvFile->getColumnCount() !== $first['columns'])
                {
                    throw new InvalidCsvException(
                        "mergeFiles: {$filepath} has {$csvFile->getColumnCount()} columns, but {$first['path']} has " .
                        "{$first['columns']}, so they can't be merged."
                    );
                }

                if ($header !== $first['header'])
                {
                    throw new InvalidCsvException(
                        "mergeFiles: {$filepath} has a different header from {$first['path']}, so they can't be merged."
                    );
                }
            }
        }

        $writeMergedFile = function ($outputFileHandle) use (
            $filepaths,
            $hasHeaders,
            $delimiter,
            $enclosure,
            $escape
        ): void {
            $isFirstFile = true;

            foreach ($filepaths as $filepath)
            {
                # Suppress the warning, as we throw an exception instead.
                $readHandle = @fopen($filepath, "r");
                $isFirstLine = true;

                if ($readHandle === false)
                {
                    throw new FilesystemException("Unable to read file for merging: {$filepath}");
                }

                # Skip every file's byte order mark, so that none end up in the middle of the merged file. If the
                # first file had one, the merged file gets one at the start.
                if (self::skipByteOrderMark($readHandle) && $isFirstFile)
                {
                    self::writeToOutput($outputFileHandle, self::BYTE_ORDER_MARK);
                }

                while (($fields = fgetcsv($readHandle, 0, $delimiter, $enclosure, $escape)) !== false)
                {
                    # Only the first file's header row is kept. Blank lines at the end of a file are left out, as they
                    # would be in the middle of the merged file, making it invalid.
                    $copyLineAcross = (!$isFirstLine || !$hasHeaders || $isFirstFile) && !self::isBlankRow($fields);
                    $isFirstLine = false;

                    if ($copyLineAcross)
                    {
                        self::writeCsvRowToOutput($outputFileHandle, $fields, $delimiter, $enclosure, $escape);
                    }
                }

                $isFirstFile = false;
                fclose($readHandle);
            }
        };

        # Write to a temporary file first, so that a file that can't be read part way through leaves an existing
        # merged file as it was. This also means $mergedFilepath can be one of the files being merged.
        self::writeOutputFile($mergedFilepath, $writeMergedFile, $useSystemTempDir, $preserveOwnership);
    }


    /**
     * Determine whether a row returned by fgetcsv/str_getcsv is a blank line, which
     * fgetcsv/str_getcsv return as [null]. Note that a line of just delimiters (e.g. ",,,") is NOT
     * a blank line, as it is a row of empty values, which may be legitimate data.
     * @internal - this is only public so that CsvFile can use it.
     * @param array $row - the row as returned by fgetcsv/str_getcsv.
     * @return bool - true if the row came from a blank line, false otherwise.
     */
    public static function isBlankRow(array $row): bool
    {
        return $row === [null];
    }


    /**
     * If a file starts with a UTF-8 byte order mark, move past it, so that it doesn't end up as
     * part of the first value read (e.g. making the first column's name "\xEF\xBB\xBFname" rather
     * than "name"). This must be called straight after opening the file, before anything has been
     * read from it.
     * @internal - this is only public so that CsvFile can use it.
     * @param resource $handle - the file handle, opened for reading. PHP can't declare a resource
     * type, so this is only documented here.
     * @return bool - true if the file started with a byte order mark (which has now been skipped),
     * false if not.
     */
    public static function skipByteOrderMark($handle): bool
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


    /**
     * Write an output file safely: everything is written to a temporary file in the system's
     * temporary directory first, and only copied into $outputFilepath once $writer has finished. So
     * if anything goes wrong part way through (including $writer throwing), an existing file is
     * left as it was, and nothing half-written is left behind. An existing file keeps its
     * permissions, owner and links (see Filesystem::replaceWithFile()), and a new one gets the
     * default permissions for a new file.
     * @param string $outputFilepath - the file to write.
     * @param callable $writer - writes the contents, given the temporary file's handle, e.g.
     * function ($handle): void { ... }.
     * @param UseSystemTempDirEnum $useSystemTempDir - where to write it first.
     * @param PreserveOwnershipEnum $preserveOwnership - how hard to try to keep an existing file's
     * owner and group.
     * @return void
     * @throws BadFilePermissionsException - if $outputFilepath (or, for a new file, its directory)
     * can't be written to. This is checked before $writer is run.
     * @throws FilesystemException - if the contents could not be written.
     */
    private static function writeOutputFile(
        string $outputFilepath,
        callable $writer,
        UseSystemTempDirEnum $useSystemTempDir,
        PreserveOwnershipEnum $preserveOwnership
    ): void
    {
        # Check the output can be written before doing any work.
        if (file_exists($outputFilepath))
        {
            Filesystem::checkCanChangeFile($outputFilepath);
        }
        elseif (!is_writable(dirname($outputFilepath)))
        {
            throw new BadFilePermissionsException(
                "{$outputFilepath} can't be created, as " . dirname($outputFilepath) . " can't be written to."
            );
        }

        $tmpFilepath = Filesystem::createReplacementFile($outputFilepath, $useSystemTempDir, $preserveOwnership);
        $tmpHandle = @fopen($tmpFilepath, "w");

        if ($tmpHandle === false)
        {
            @unlink($tmpFilepath);
            throw new FilesystemException("Failed to open a temporary file to write {$outputFilepath} to.");
        }

        try
        {
            $writer($tmpHandle);
        }
        catch (\Throwable $e)
        {
            # Nothing has been written to the output yet, so it is untouched, and the temporary file can go.
            fclose($tmpHandle);
            @unlink($tmpFilepath);
            throw $e;
        }

        # Closing flushes anything still buffered, which can fail (e.g. if the disk is full).
        if (!fclose($tmpHandle))
        {
            @unlink($tmpFilepath);
            throw new FilesystemException("Failed to finish writing the contents of {$outputFilepath}.");
        }

        # Create a new output file first, so that it gets the default permissions for a new file,
        # rather than the temporary file's 0600.
        if (!file_exists($outputFilepath) && @touch($outputFilepath) === false)
        {
            @unlink($tmpFilepath);
            throw new FilesystemException("Failed to create {$outputFilepath}.");
        }

        Filesystem::replaceWithFile($outputFilepath, $tmpFilepath, $preserveOwnership);
    }


    /**
     * Write a string to an output file, throwing if it fails, e.g. because the disk is full.
     * @param resource $handle - the file handle to write to. PHP can't declare a resource type, so
     * this is only documented here.
     * @param string $data - the string to write.
     * @return void
     * @throws FilesystemException - if it could not be written.
     */
    private static function writeToOutput($handle, string $data): void
    {
        if (fwrite($handle, $data) === false)
        {
            throw new FilesystemException("Failed to write to the output file.");
        }
    }


    /**
     * Write a row to a CSV output file, throwing if it fails, e.g. because the disk is full.
     * @param resource $handle - the file handle to write to. PHP can't declare a resource type, so
     * this is only documented here.
     * @param array $fields - the values in the row.
     * @param string $delimiter - the delimiter to pass to fputcsv().
     * @param string $enclosure - the enclosure to pass to fputcsv().
     * @param string $escape - the escape character to pass to fputcsv().
     * @return void
     * @throws FilesystemException - if it could not be written.
     */
    private static function writeCsvRowToOutput(
        $handle,
        array $fields,
        string $delimiter,
        string $enclosure,
        string $escape
    ): void
    {
        $written = fputcsv(
            stream: $handle,
            fields: $fields,
            separator: $delimiter,
            enclosure: $enclosure,
            escape: $escape,
        );

        if ($written === false)
        {
            throw new FilesystemException("Failed to write a row to the output file.");
        }
    }


    /**
     * Find the values in the given columns of a CSV file that aren't numbers. The header row, if
     * there is one, isn't checked.
     * @param CsvFile $csvFile - the CSV file to check. It has already been validated, and has all
     * the columns.
     * @param array $columnNumbers - the numbers of the columns to check, starting from 1.
     * @param int $maxErrors - stop once this many have been found. If this is 0 or less, nothing is
     * checked.
     * @return array - a list of CsvCellError objects, one for each value found, which is empty if
     * there are none.
     * @throws FilesystemException - if the file could not be opened.
     */
    private static function findNonNumericValues(CsvFile $csvFile, array $columnNumbers, int $maxErrors): array
    {
        $errors = [];
        $dataRowCount = $csvFile->getRowCount() - ($csvFile->hasHeader() ? 1 : 0);

        if ($maxErrors > 0)
        {
            $handle = self::openForReadingData($csvFile);

            for ($rowIndex = 0; $rowIndex < $dataRowCount && count($errors) < $maxErrors; $rowIndex++)
            {
                $row = fgetcsv($handle, 0, $csvFile->getDelimiter(), $csvFile->getEnclosure(), $csvFile->getEscape());

                # Give the row's number in the file, counting the header row, so it's easy to find.
                $rowNumber = $rowIndex + 1 + ($csvFile->hasHeader() ? 1 : 0);

                foreach ($columnNumbers as $columnNumber)
                {
                    $value = $row[$columnNumber - 1];

                    if (count($errors) < $maxErrors && !is_numeric($value))
                    {
                        $errors[] = new CsvCellError($csvFile->getFilepath(), $rowNumber, $columnNumber, $value);
                    }
                }
            }

            fclose($handle);
        }

        return $errors;
    }


    /**
     * Read the first row of a validated CSV file, e.g. its header.
     * @param CsvFile $csvFile - the CSV file.
     * @return array<?string> - the values in its first row, or an empty array if it has none.
     * @throws FilesystemException - if the file could not be opened.
     */
    private static function readFirstRow(CsvFile $csvFile): array
    {
        # Suppress the warning, as we throw an exception instead.
        $handle = @fopen($csvFile->getFilepath(), "r");

        if ($handle === false)
        {
            throw new FilesystemException("Failed to open {$csvFile->getFilepath()} for reading.");
        }

        self::skipByteOrderMark($handle);
        $row = fgetcsv($handle, 0, $csvFile->getDelimiter(), $csvFile->getEnclosure(), $csvFile->getEscape());
        fclose($handle);
        return ($row === false) ? [] : $row;
    }


    /**
     * Open a validated CSV file for reading its rows of data, i.e. past any byte order mark and
     * header row.
     * @param CsvFile $csvFile - the CSV file to open.
     * @return resource - the open file handle, positioned at the first row of data. PHP can't
     * declare a resource return type, so this is only documented here.
     * @throws FilesystemException - if the file could not be opened.
     */
    private static function openForReadingData(CsvFile $csvFile)
    {
        # Suppress the warning, as we throw an exception instead.
        $handle = @fopen($csvFile->getFilepath(), "r");

        if ($handle === false)
        {
            throw new FilesystemException("Failed to open {$csvFile->getFilepath()} for reading.");
        }

        self::skipByteOrderMark($handle);

        if ($csvFile->hasHeader())
        {
            fgetcsv($handle, 0, $csvFile->getDelimiter(), $csvFile->getEnclosure(), $csvFile->getEscape());
        }

        return $handle;
    }


    /**
     * Clean up after something went wrong while writing a file's new contents to a temporary file,
     * before anything was copied into the original: close both files, and delete the temporary
     * file. The original is left as it was.
     * @param resource $fileHandle - the handle of the original file. PHP can't declare a resource
     * type, so this is only documented here.
     * @param resource|false $tmpFile - the handle of the temporary file, or false if it wasn't
     * opened.
     * @param string|null $tmpFilepath - the path of the temporary file, or null if it wasn't
     * created.
     * @return void
     */
    private static function abandonReplacementFile($fileHandle, $tmpFile, ?string $tmpFilepath): void
    {
        fclose($fileHandle);

        if ($tmpFile !== false)
        {
            fclose($tmpFile);
        }

        if ($tmpFilepath !== null)
        {
            @unlink($tmpFilepath);
        }
    }
}
