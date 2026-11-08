# CSV files (`CsvLib` and `CsvFile`)

`Programster\CoreLibs\CsvLib` has static methods for reading, writing, changing and comparing CSV files.
`Programster\CoreLibs\Models\CsvFile` wraps one file, checking it once and keeping its settings, so you don't have to
pass them to every call.

Apart from `convertCsvToArray()`, every method works a row at a time, so a file of any size can be processed without
running out of memory.

- [What counts as a valid CSV file](#what-counts-as-a-valid-csv-file)
- [Delimiters, enclosures and escapes](#delimiters-enclosures-and-escapes)
- [Reading](#reading)
- [Writing](#writing)
- [Converting to JSON](#converting-to-json)
- [Tidying](#tidying)
- [Removing rows and columns](#removing-rows-and-columns)
- [Merging](#merging)
- [Comparing](#comparing)
- [The CsvFile class](#the-csvfile-class)

## What counts as a valid CSV file
Every method checks the file before it does anything, and throws an `InvalidCsvException` describing the first problem
if it isn't valid. A file is valid if:

- it has no blank lines before the last row of data. Blank lines at the end are fine, as editors and spreadsheet
  programs often add them. A row of just delimiters (e.g. `,,,`) is a row of empty values, not a blank line, so it is
  fine anywhere.
- every row has the same number of columns as the first.
- if it is said to have a header row, it has at least one row.

A UTF-8 byte order mark (BOM) at the start is skipped. A quoted value can contain line breaks, and is still one row.

```php
use Programster\CoreLibs\CsvLib;
use Programster\CoreLibs\Exceptions\InvalidCsvException;

try
{
    CsvLib::validateCsvFile("/data/import.csv");
}
catch (InvalidCsvException $e)
{
    // e.g. "/data/import.csv has a blank line at row 2, before more data at row 3."
    // or   "/data/import.csv has 3 columns at row 2, but 2 in the first row."
}
```

Because the file is checked first, a method that changes a file leaves an invalid one exactly as it was.

## Delimiters, enclosures and escapes
Every method takes optional `$delimiter` (`,` by default), `$enclosure` (`"`) and `$escape` parameters, in that order,
after its own parameters. They're passed through to PHP's `fgetcsv()` and `fputcsv()`.

`$escape` defaults to `""`, which turns PHP's non-standard escape character off, so a `"` inside a value is written as
`""`, as RFC 4180 and spreadsheet programs expect. Only pass `escape: "\\"` for files that use backslash escapes.

As several methods have other optional parameters before these, named arguments are the clearest way to give them:

```php
CsvLib::removeRows("/data/export.csv", [0], delimiter: ";");
```

## Reading
`convertCsvToArray()` reads the whole file into an array. Every value is a string.

```php
// With a header row, each row is keyed by it.
$rows = CsvLib::convertCsvToArray("/data/people.csv", true);
// [["id" => "1", "name" => "Alice"], ["id" => "2", "name" => "Bob"]]

// Without one, each row is a list.
$rows = CsvLib::convertCsvToArray("/data/people.csv", false);
// [["id", "name"], ["1", "Alice"], ["2", "Bob"]]

// Or give your own keys, which replace any header row.
$rows = CsvLib::convertCsvToArray("/data/people.csv", true, ["personId", "fullName"]);
```

For a big file, use `csvWalk()` instead, which calls a function with each row in turn, so only one row is in memory at a
time. It is given the row and its row number (counting a header row as row 0, so the data starts at 1):

```php
$importPerson = function (array $row, int $rowNumber) use ($db): void {
    $db->insert("people", ["id" => $row["id"], "name" => $row["name"]]);
};

CsvLib::csvWalk("/data/people.csv", $importPerson);
```

`csvWalk()` assumes a header row by default (`$hasHeaders`), and can take your own keys with `$headers`.

## Writing
`convertArrayToCsv()` writes a list of associative arrays to a file, a row at a time. With `$addHeader`, the first row's
keys become the header row.

```php
$rows = [
    ["id" => 1, "name" => "Alice"],
    ["id" => 2, "name" => "Bob"],
];

CsvLib::convertArrayToCsv("/data/people.csv", $rows, true);
```

Every row must have the same keys, or it throws a `BadArgumentException`. It writes to a temporary file first, so an
existing file is only replaced once the new one is complete (and is left as it was if anything goes wrong), and keeps
its owner and permissions (see [Changing files safely](safe-file-changes.md)).

It adds a UTF-8 byte order mark (BOM) by default, which Excel needs to read UTF-8 characters correctly. Turn it off
with `addByteOrderMark: false` for files read by other programs, some of which treat it as part of the first value.

## Converting to JSON
`convertCsvToJson()` writes a JSON file with an object for each row, keyed by the header row (so the file must have one).
It writes one row at a time, rather than building the whole thing in memory and calling `json_encode()` once, so it
works on files of any size.

```php
// $compressed true gives one line, false gives indented JSON for people to read.
CsvLib::convertCsvToJson("/data/people.csv", "/data/people.json", false);
```

It throws a `\JsonException` if a value can't be encoded as JSON, e.g. because it isn't valid UTF-8. See
`Filesystem::convertFileToUtf8()` in [Files and directories](filesystem.md#text-encoding) for converting the file first.

## Tidying

```php
// Remove the whitespace around every value, and any blank lines at the end of the file.
CsvLib::trim("/data/export.csv");

// Keep the blank lines at the end.
CsvLib::trim("/data/export.csv", removeTrailingBlankLines: false);

// Only remove the blank lines at the end. The last row keeps its line ending.
CsvLib::removeTrailingBlankLines("/data/export.csv");
```

## Removing rows and columns
Note that columns are numbered from 1, but rows from 0 (unless `$startFromZero` is false):

```php
// Remove the 2nd and 4th columns.
CsvLib::removeColumns("/data/export.csv", [2, 4]);

// Remove the header row, and the 5th row.
CsvLib::removeRows("/data/export.csv", [0, 4]);

// The same, counting from 1.
CsvLib::removeRows("/data/export.csv", [1, 5], false);
```

`removeRows()` counts CSV rows, not lines, so a quoted value spanning several lines is part of one row. The rows it keeps
are copied exactly as they were, with their quoting and line endings. A header row counts as a row.

`removeColumns()` throws a `BadArgumentException` if you ask it to remove every column, and an `InvalidCsvException` if
the file doesn't have one of them. `removeRows()` throws a `BadArgumentException` for a row that isn't there.

## Merging
`mergeFiles()` joins CSV files one after the other. With `$hasHeaders`, the first file's header row is kept, and the
others' are left out.

```php
CsvLib::mergeFiles(["/data/jan.csv", "/data/feb.csv"], "/data/q1.csv", true);
```

Every file must have the same number of columns (and, with `$hasHeaders`, the same header row). Blank lines at the end
of each file are left out, so they don't end up in the middle, and a file that doesn't end with a newline is handled.
The merged file can be one of the files being merged.

## Comparing
`diffTolerance()` checks whether the numbers in two files are the same, allowing each column to differ by a tolerance,
e.g. for floating point values whose last decimal place may differ. Only the columns you list are compared, numbered
from 1:

```php
// Column 1 may differ by up to 0.01, and column 3 must be equal.
$same = CsvLib::diffTolerance(
    "/data/expected.csv",
    "/data/actual.csv",
    [1 => 0.01, 3 => 0],
    file1HasHeader: true,
    file2HasHeader: true
);
```

A tolerance of 0 means the numbers must be equal (so `1.10` and `1.1` are). Both files must have the same number of rows
of data, and the listed columns must hold numbers in both. If they don't, it throws a `NonNumericCsvValuesException`,
whose `getErrors()` gives a `CsvCellError` for each value (up to 100), with its `filepath`, `rowNumber`, `columnNumber`
and `value`.

`decimalDiff()` writes a CSV file of the differences between two files (the first minus the second), and returns its path.
Where values aren't numbers, it puts the value in if they're the same, or both separated by `|` if they aren't:

```php
$diffPath = CsvLib::decimalDiff("/data/expected.csv", "/data/actual.csv", file1HasHeader: true, file2HasHeader: true);
```

Neither method can tell whether a file has a header row, so say which do. Without that, a header row is compared as data.

## The CsvFile class
`CsvFile` checks a file when it is created, and keeps its row and column counts, whether it has a header row, and its
delimiter, enclosure and escape character, so you don't have to pass them each time:

```php
use Programster\CoreLibs\Models\CsvFile;

$csv = new CsvFile("/data/people.csv", true);

$csv->getRowCount();      // including the header row
$csv->getColumnCount();
$csv->hasHeader();

$csv->trim();
$csv->removeColumns([3]);
$csv->removeHeader();     // hasHeader() is now false, and there is one fewer row
$csv->toJson("/data/people.json", true);

$rows = $csv->toArray();

$printRow = function (array $row, int $rowNumber): void {
    echo "{$rowNumber}: {$row["name"]}" . PHP_EOL;
};

$csv->walk($printRow);
```

The counts are kept up to date by its own methods. If something else changes the file, call `reload()` to check it
again. Each method checks the file again anyway, so an invalid file is never processed.

| `CsvFile` method | Does what `CsvLib` method does |
|---|---|
| `toArray()` | `convertCsvToArray()` |
| `toJson()` | `convertCsvToJson()` (throws a `NoCsvHeaderException` without a header row) |
| `walk()` | `csvWalk()` |
| `trim()` | `trim()` |
| `removeTrailingBlankLines()` | `removeTrailingBlankLines()` |
| `removeColumns()` | `removeColumns()` |
| `removeRows()`, `removeHeader()` | `removeRows()` |
| `decimalDiff($other)`, `diffTolerance($other, $tolerances)` | The same, with this file first. `$other` can be a path or another `CsvFile` |
