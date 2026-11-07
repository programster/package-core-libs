<?php

/*
 * A CSV file that has been checked to be well formed (see the constructor), along with its row and
 * column counts. This doesn't hold the file's data, only its path. Its own methods that change the
 * file (e.g. removeHeader()) keep its details up to date. But if the file is changed in any other
 * way, the details may be wrong, and methods that use the file may fail, so use reload() to check
 * it again. Renaming or moving the file with rename() or move() (from File) is fine, as the path is
 * updated.
 */

namespace Programster\CoreLibs\Models;


use Programster\CoreLibs\Enums\UseSystemTempDirEnum;
use Programster\CoreLibs\Enums\PreserveOwnershipEnum;
use Programster\CoreLibs\CsvLib;
use Programster\CoreLibs\Exceptions\FileDoesNotExistException;
use Programster\CoreLibs\Exceptions\FilesystemException;
use Programster\CoreLibs\Exceptions\InvalidCsvException;
use Programster\CoreLibs\Exceptions\BadArgumentException;
use Programster\CoreLibs\Exceptions\NoCsvHeaderException;
use Programster\CoreLibs\Exceptions\NonNumericCsvValuesException;

final class CsvFile extends File
{
    # These describe how to read the file, so never change.
    private readonly string $m_delimiter;
    private readonly string $m_enclosure;
    private readonly string $m_escape;

    # These are kept up to date as this class's methods change the file.
    private bool $m_hasHeader;
    private int $m_rowCount;
    private int $m_columnCount;


    /**
     * Load and check a CSV file. A file is valid if:
     *  - it has no blank lines before the last row of data. Blank lines at the end of the file are
     *    allowed, as editors often add them. A row of just delimiters (e.g. ",,,") is a row of
     *    empty values, not a blank line, so it is allowed.
     *  - every row has the same number of columns as the first row.
     *  - if $hasHeader is true, it has at least one row (the header).
     * An empty file (or one with only blank lines) is valid if it has no header, and has 0 rows and
     * 0 columns. A UTF-8 byte order mark at the start of the file is skipped.
     * @param string $filepath - the path to the CSV file.
     * @param bool $hasHeader - whether the first row is a header row, with the names of the
     * columns. This can't be worked out from the file, so it has to be given.
     * @param string $delimiter - optionally specify the delimiter if it isn't a comma.
     * @param string $enclosure - optionally specify the enclosure if it isn't a double quote.
     * @param string $escape - optionally specify the escape character to use when parsing the CSV.
     * @throws FileDoesNotExistException - if there is no file at $filepath.
     * @throws FilesystemException - if the file could not be opened for reading.
     * @throws InvalidCsvException - if the file has a blank line before the last row of data, a row
     * with a different number of columns to the first row, or no rows when $hasHeader is true.
     */
    public function __construct(
        string $filepath,
        bool $hasHeader,
        string $delimiter = ",",
        string $enclosure = '"',
        string $escape = "",
    )
    {
        parent::__construct($filepath);
        $this->m_hasHeader = $hasHeader;
        $this->m_delimiter = $delimiter;
        $this->m_enclosure = $enclosure;
        $this->m_escape = $escape;
        $this->validate();
    }


    /**
     * Check the file again and update the row and column counts, for when it may have been changed
     * by something other than this object (e.g. another program). If this throws, the counts are
     * left as they were.
     * @return void
     * @throws FilesystemException - if the file could no longer be opened for reading.
     * @throws InvalidCsvException - if the file is no longer valid (see the constructor).
     */
    public function reload(): void
    {
        $this->validate();
    }


    /**
     * Convert this CSV file into an array. An alias for CsvLib::convertCsvToArray(), using this
     * file's header setting, delimiter, enclosure and escape character.
     * @param array|null $keys - optionally the keys to use for each row. See
     * CsvLib::convertCsvToArray().
     * @return array - an array list of the rows.
     * @throws FilesystemException - if the file could not be opened for reading.
     * @throws InvalidCsvException - if a row doesn't have the same number of values as there are
     * keys.
     */
    public function toArray(?array $keys = null): array
    {
        return CsvLib::convertCsvToArray(
            $this->m_filepath,
            $this->m_hasHeader,
            $keys,
            $this->m_delimiter,
            $this->m_enclosure,
            $this->m_escape
        );
    }


    /**
     * Convert this CSV file into a JSON file. An alias for CsvLib::convertCsvToJson(), using this
     * file's delimiter, enclosure and escape character. The file must have a header row, as its
     * values are used as the JSON keys.
     * @param string $jsonFilepath - the path to write the JSON to. An existing file is overwritten.
     * @param bool $compressed - if true, the JSON has no line endings or padding.
     * @param UseSystemTempDirEnum $useSystemTempDir - where to write the new contents before they
     * replace an existing JSON file (see UseSystemTempDirEnum). Defaults to AUTO, which picks the fastest place
     * that will work, which can be a hidden, private file next to it for a moment. Pass ALWAYS to
     * keep it in the system's temporary directory, e.g. for a directory served to the public.
     * @param PreserveOwnershipEnum $preserveOwnership - how hard to try to keep an existing JSON file's owner and
     * group when replacing it (see Filesystem::replaceWithFile()). Defaults to PREFERRED.
     * @return void
     * @throws NoCsvHeaderException - if this file doesn't have a header row.
     * @throws BadFilePermissionsException - if the JSON file (or, for a new file, its directory)
     * can't be written to.
     * @throws FilesystemException - if the CSV file could not be read, or the JSON file could not
     * be written.
     * @throws \JsonException
     */
    public function toJson(
        string $jsonFilepath,
        bool $compressed,
        UseSystemTempDirEnum $useSystemTempDir = UseSystemTempDirEnum::AUTO,
        PreserveOwnershipEnum $preserveOwnership = PreserveOwnershipEnum::PREFERRED
    ): void
    {
        # CsvLib::convertCsvToJson() always uses the first row as the keys, so would treat it as a header anyway.
        if (!$this->m_hasHeader)
        {
            throw new NoCsvHeaderException(
                "toJson: {$this->m_filepath} has no header row, which is needed for the JSON keys."
            );
        }

        CsvLib::convertCsvToJson(
            $this->m_filepath,
            $jsonFilepath,
            $compressed,
            $this->m_delimiter,
            $this->m_enclosure,
            $this->m_escape,
            $useSystemTempDir,
            $preserveOwnership
        );
    }


    /**
     * Run a callback on each row of this CSV file. An alias for CsvLib::csvWalk(), using this
     * file's header setting, delimiter, enclosure and escape character.
     * @param callable $callback - the callback to run on each row. See CsvLib::csvWalk().
     * @param array|null $headers - optionally the headers to use. See CsvLib::csvWalk().
     * @return void
     * @throws FilesystemException - if the file could not be opened for reading.
     * @throws InvalidCsvException - if a row doesn't have the same number of values as there are
     * headers.
     */
    public function walk(callable $callback, ?array $headers = null): void
    {
        CsvLib::csvWalk(
            $this->m_filepath,
            $callback,
            $this->m_hasHeader,
            $headers,
            $this->m_delimiter,
            $this->m_enclosure,
            $this->m_escape
        );
    }


    /**
     * Calculate the differences between this CSV file and another. An alias for
     * CsvLib::decimalDiff(), with this file as the first file, passing through whether each file
     * has a header row.
     * @param CsvFile|string $other - the CSV file to compare against, as a CsvFile or a path. A
     * CsvFile must use the same delimiter, enclosure and escape character as this one, but its own
     * header setting is used. A path is read with this file's settings, including whether it has a
     * header, so pass a CsvFile if the other file's header setting is different.
     * @return string - the path to the created diff CSV file.
     * @throws BadArgumentException - if $other is a CsvFile that uses a different delimiter,
     * enclosure or escape character.
     * @throws InvalidCsvException - if the two files don't have the same number of rows and
     * columns.
     * @throws FileDoesNotExistException - if there is no file at $other, or this file no longer
     * exists (e.g. it was deleted since this CsvFile was created).
     * @throws FilesystemException - if either CSV file could not be opened, or the diff file could
     * not be created.
     */
    public function decimalDiff(CsvFile|string $other): string
    {
        return CsvLib::decimalDiff(
            $this->m_filepath,
            $this->getOtherFilepath($other),
            $this->m_delimiter,
            $this->m_enclosure,
            $this->m_escape,
            $this->m_hasHeader,
            ($other instanceof CsvFile) ? $other->hasHeader() : $this->m_hasHeader
        );
    }


    /**
     * Check whether the numbers in this CSV file and another are the same, within the given
     * tolerances. An alias for CsvLib::diffTolerance(), with this file as the first file, passing
     * through whether each file has a header row.
     * @param CsvFile|string $other - the CSV file to compare against, as a CsvFile or a path. A
     * CsvFile must use the same delimiter, enclosure and escape character as this one, but its own
     * header setting is used. A path is read with this file's settings, including whether it has a
     * header, so pass a CsvFile if the other file's header setting is different.
     * @param array $tolerances - the columns to compare, as column number (from 1) / tolerance
     * pairs. See CsvLib::diffTolerance().
     * @return bool - true if every compared value is within its tolerance, false if not.
     * @throws BadArgumentException - if $other is a CsvFile that uses a different delimiter,
     * enclosure or escape character, or $tolerances isn't valid (see CsvLib::diffTolerance()).
     * @throws NonNumericCsvValuesException - if any of the listed columns hold non-numeric values,
     * in either file. getErrors() says where each one is. See CsvLib::diffTolerance().
     * @throws InvalidCsvException - if either file isn't valid, they don't have the same number of
     * rows of data, or either is missing one of the listed columns.
     * @throws FileDoesNotExistException - if there is no file at $other, or this file no longer
     * exists (e.g. it was deleted since this CsvFile was created).
     * @throws FilesystemException - if either CSV file could not be opened.
     */
    public function diffTolerance(CsvFile|string $other, array $tolerances): bool
    {
        return CsvLib::diffTolerance(
            $this->m_filepath,
            $this->getOtherFilepath($other),
            $tolerances,
            $this->m_delimiter,
            $this->m_enclosure,
            $this->m_escape,
            $this->m_hasHeader,
            ($other instanceof CsvFile) ? $other->hasHeader() : $this->m_hasHeader
        );
    }


    /**
     * Remove the blank lines at the end of this CSV file. An alias for
     * CsvLib::removeTrailingBlankLines(). The row and column counts don't change, as blank lines at
     * the end aren't counted as rows.
     * @return void
     * @throws FileDoesNotExistException - if the file no longer exists (e.g. it was deleted since
     * this CsvFile was created).
     * @throws BadFilePermissionsException - if the file can't be both read and written.
     * @throws FilesystemException - if the file could not be opened, read, or shortened.
     */
    public function removeTrailingBlankLines(): void
    {
        CsvLib::removeTrailingBlankLines($this->m_filepath);
    }


    /**
     * Remove the header row from this CSV file. Afterwards, hasHeader() is false, and there is one
     * fewer row. The file isn't checked again, as removing the first row can't make a valid file
     * invalid.
     * @param UseSystemTempDirEnum $useSystemTempDir - where to write the new contents before they
     * replace the file (see UseSystemTempDirEnum). Defaults to AUTO, which picks the fastest place
     * that will work, which can be a hidden, private file next to it for a moment. Pass ALWAYS to
     * keep it in the system's temporary directory, e.g. for a directory served to the public.
     * @param PreserveOwnershipEnum $preserveOwnership - how hard to try to keep the file's owner and
     * group when replacing it (see Filesystem::replaceWithFile()). Defaults to PREFERRED.
     * @return void
     * @throws NoCsvHeaderException - if this file doesn't have a header row.
     * @throws InvalidCsvException - if the file is no longer valid, e.g. because it was changed by
     * something else since this CsvFile was created.
     * @throws BadArgumentException - if the file no longer has a first row, because it was emptied
     * by something else since this CsvFile was created.
     * @throws FileDoesNotExistException - if the file no longer exists (e.g. it was deleted since
     * this CsvFile was created).
     * @throws BadFilePermissionsException - if the file can't be both read and written.
     * @throws FilesystemException - if the file could not be read, or could not be replaced.
     */
    public function removeHeader(
        UseSystemTempDirEnum $useSystemTempDir = UseSystemTempDirEnum::AUTO,
        PreserveOwnershipEnum $preserveOwnership = PreserveOwnershipEnum::PREFERRED
    ): void
    {
        if (!$this->m_hasHeader)
        {
            throw new NoCsvHeaderException("removeHeader: {$this->m_filepath} has no header row to remove.");
        }

        CsvLib::removeRows(
            $this->m_filepath,
            [0],
            true,
            $this->m_delimiter,
            $this->m_enclosure,
            $this->m_escape,
            $useSystemTempDir,
            $preserveOwnership
        );

        $this->m_hasHeader = false;
        $this->m_rowCount--;

        # Match what the constructor gives for a file with no rows.
        if ($this->m_rowCount === 0)
        {
            $this->m_columnCount = 0;
        }
    }


    /**
     * Read through the file, checking it is valid (see the constructor), and set the row and column
     * counts. The counts are only set once the whole file has been checked, so if this throws, they
     * are left as they were.
     * @return void
     * @throws FilesystemException - if the file could not be opened for reading.
     * @throws InvalidCsvException - if the file has a blank line before the last row of data, a row
     * with a different number of columns to the first row, or no rows when it should have a header.
     */
    private function validate(): void
    {
        # Suppress the warning, as we throw an exception instead.
        $file = @fopen($this->m_filepath, 'r');

        if ($file === false)
        {
            throw new FilesystemException("Failed to open {$this->m_filepath} for reading.");
        }

        CsvLib::skipByteOrderMark($file);

        $columnCount = null;
        $blankRowNumber = null;
        $rowNumber = 0;
        $dataRowCount = 0;

        try
        {
            while (($row = fgetcsv($file, 0, $this->m_delimiter, $this->m_enclosure, $this->m_escape)) !== false)
            {
                # This counts rows rather than lines, as a quoted value can span several lines.
                $rowNumber++;

                if (CsvLib::isBlankRow($row))
                {
                    # Only remember the first one, as that's the one to report if there's more data after it.
                    $blankRowNumber = $blankRowNumber ?? $rowNumber;
                }
                elseif ($blankRowNumber !== null)
                {
                    throw new InvalidCsvException(
                        "{$this->m_filepath} has a blank line at row {$blankRowNumber}, before more data at row " .
                        "{$rowNumber}."
                    );
                }
                elseif ($columnCount !== null && count($row) !== $columnCount)
                {
                    throw new InvalidCsvException(
                        "{$this->m_filepath} has " . count($row) . " columns at row {$rowNumber}, but {$columnCount} " .
                        "in the first row."
                    );
                }
                else
                {
                    $columnCount = count($row);
                    $dataRowCount++;
                }
            }
        }
        finally
        {
            fclose($file);
        }

        if ($this->m_hasHeader && $dataRowCount === 0)
        {
            throw new InvalidCsvException("{$this->m_filepath} should have a header row, but has no rows.");
        }

        $this->m_rowCount = $dataRowCount;
        $this->m_columnCount = $columnCount ?? 0;
    }


    /**
     * Get the path of the other file passed to a method that compares two files. The CsvLib methods
     * that compare two files read both with the same delimiter, enclosure and escape character, so
     * a CsvFile must use the same ones as this file.
     * @param CsvFile|string $other - the other CSV file, as a CsvFile or a path.
     * @return string - the path to the other CSV file.
     * @throws BadArgumentException - if $other is a CsvFile that uses a different delimiter,
     * enclosure or escape character.
     */
    private function getOtherFilepath(CsvFile|string $other): string
    {
        if (
            $other instanceof CsvFile
            && (
                $other->getDelimiter() !== $this->m_delimiter
                || $other->getEnclosure() !== $this->m_enclosure
                || $other->getEscape() !== $this->m_escape
            )
        )
        {
            throw new BadArgumentException(
                "{$other->getFilepath()} must use the same delimiter, enclosure and escape character as " .
                "{$this->m_filepath} to compare them."
            );
        }

        return ($other instanceof CsvFile) ? $other->getFilepath() : $other;
    }


    # Accessors
    public function hasHeader() : bool { return $this->m_hasHeader; }
    public function getDelimiter() : string { return $this->m_delimiter; }
    public function getEnclosure() : string { return $this->m_enclosure; }
    public function getEscape() : string { return $this->m_escape; }
    public function getRowCount() : int { return $this->m_rowCount; }
    public function getColumnCount() : int { return $this->m_columnCount; }
}
