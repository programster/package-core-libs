<?php

/*
 * Smoke tests for CsvLib. These are run by testing/main.php, which provides $smoke and $dir.
 */

use Programster\CoreLibs\Enums\UseSystemTempDirEnum;
use Programster\CoreLibs\CsvLib;
use Programster\CoreLibs\Exceptions\BadArgumentException;
use Programster\CoreLibs\Exceptions\BadFilePermissionsException;
use Programster\CoreLibs\Exceptions\FileDoesNotExistException;
use Programster\CoreLibs\Exceptions\FilesystemException;
use Programster\CoreLibs\Exceptions\InvalidCsvException;
use Programster\CoreLibs\Exceptions\NoCsvHeaderException;
use Programster\CoreLibs\Exceptions\NonNumericCsvValuesException;
use Programster\CoreLibs\Filesystem;
use Programster\CoreLibs\Models\CsvCellError;
use Programster\CoreLibs\Models\CsvFile;
use Programster\CoreLibs\Testing\SmokeTest;

/** @var \Programster\CoreLibs\Testing\SmokeTest $smoke */
/** @var string $dir - a temporary directory for the tests to use, which is deleted afterwards. */

$smoke->run("CSV methods", function () use ($dir) {
    $inputRows = [["a" => 1, "b" => "x,y"], ["a" => 2, "b" => ""]];
    CsvLib::convertArrayToCsv("{$dir}/a.csv", $inputRows, true, addByteOrderMark: false);
    $expectedRows = [["a" => "1", "b" => "x,y"], ["a" => "2", "b" => ""]];
    assert(CsvLib::convertCsvToArray("{$dir}/a.csv", true) === $expectedRows);
    CsvLib::csvWalk("{$dir}/a.csv", fn(array $row) => null);
    CsvLib::convertCsvToJson("{$dir}/a.csv", "{$dir}/a.json", true);
    assert(json_decode(file_get_contents("{$dir}/a.json"), true) === $expectedRows);
    CsvLib::trim("{$dir}/a.csv");

    # trim() uses the enclosure given, so a value enclosed in ' keeps its delimiter, and is written back the same way.
    file_put_contents("{$dir}/single-quoted.csv", "' a ; b ' ; c \n");
    CsvLib::trim("{$dir}/single-quoted.csv", ";", "'");
    assert(file_get_contents("{$dir}/single-quoted.csv") === "'a ; b';c\n");

    # trim() removes blank lines at the end unless told not to.
    file_put_contents("{$dir}/trim-blank-lines.csv", " a \n b \n\n\n");
    CsvLib::trim("{$dir}/trim-blank-lines.csv");
    assert(file_get_contents("{$dir}/trim-blank-lines.csv") === "a\nb\n");
    file_put_contents("{$dir}/trim-blank-lines.csv", " a \n b \n\n\n");
    CsvLib::trim("{$dir}/trim-blank-lines.csv", removeTrailingBlankLines: false);
    assert(file_get_contents("{$dir}/trim-blank-lines.csv") === "a\nb\n\n\n");

    # A row of just delimiters is a row of empty values, not a blank line, so it's valid and kept.
    file_put_contents("{$dir}/trim-blank-lines.csv", " a , b \n , \n c , d \n");
    CsvLib::trim("{$dir}/trim-blank-lines.csv");
    assert(file_get_contents("{$dir}/trim-blank-lines.csv") === "a,b\n,\nc,d\n");

    # A blank line in the middle makes the file invalid, so trim() throws and leaves it unchanged.
    file_put_contents("{$dir}/trim-blank-lines.csv", " a \n\n b \n");
    SmokeTest::expectException(
        InvalidCsvException::class,
        fn() => CsvLib::trim("{$dir}/trim-blank-lines.csv"),
        "blank line at row 2"
    );
    assert(file_get_contents("{$dir}/trim-blank-lines.csv") === " a \n\n b \n");
    copy("{$dir}/a.csv", "{$dir}/b.csv");
    CsvLib::removeColumns("{$dir}/b.csv", [2]);
    CsvLib::removeRows("{$dir}/b.csv", [1]);
    CsvLib::decimalDiff("{$dir}/a.csv", "{$dir}/a.csv");

    # The output has one row per input row, with no extra empty row at the end.
    file_put_contents("{$dir}/diff1.csv", "5,x\n3,y\n");
    file_put_contents("{$dir}/diff2.csv", "2,x\n1,z\n");
    assert(file_get_contents(CsvLib::decimalDiff("{$dir}/diff1.csv", "{$dir}/diff2.csv")) === "3,x\n2,y|z\n");

    # Blank lines at the end of either file don't count as rows, and aren't added to the output.
    file_put_contents("{$dir}/diff2-blank-end.csv", "2,x\n1,z\n\n\n");
    file_put_contents("{$dir}/diff2-blank-end-crlf.csv", "2,x\r\n1,z\r\n\r\n");
    $trailingBlankPairs = [
        ["{$dir}/diff1.csv", "{$dir}/diff2-blank-end.csv"],
        ["{$dir}/diff2-blank-end.csv", "{$dir}/diff1.csv"],
        ["{$dir}/diff1.csv", "{$dir}/diff2-blank-end-crlf.csv"],
    ];

    foreach ($trailingBlankPairs as [$file1, $file2])
    {
        $expected = ($file1 === "{$dir}/diff1.csv") ? "3,x\n2,y|z\n" : "-3,x\n-2,z|y\n";
        assert(file_get_contents(CsvLib::decimalDiff($file1, $file2)) === $expected);
    }

    $blankEndBoth = "{$dir}/diff2-blank-end.csv";
    assert(file_get_contents(CsvLib::decimalDiff($blankEndBoth, $blankEndBoth)) === "0,x\n0,z\n");

    # Blank lines in the middle of a file are rejected, even if both files have them in the same place.
    file_put_contents("{$dir}/diff-blank-middle.csv", "2,x\n\n1,z\n");
    SmokeTest::expectException(
        InvalidCsvException::class,
        fn() => CsvLib::decimalDiff("{$dir}/diff1.csv", "{$dir}/diff-blank-middle.csv"),
        "blank line at row 2"
    );
    SmokeTest::expectException(
        InvalidCsvException::class,
        fn() => CsvLib::decimalDiff("{$dir}/diff-blank-middle.csv", "{$dir}/diff-blank-middle.csv"),
        "blank line at row 2"
    );

    # Files with different numbers of rows can't be compared, whichever one is longer.
    file_put_contents("{$dir}/diff3.csv", "2,x\n");
    SmokeTest::expectException(
        InvalidCsvException::class,
        fn() => CsvLib::decimalDiff("{$dir}/diff1.csv", "{$dir}/diff3.csv"),
        "has 2 rows of data, but {$dir}/diff3.csv has 1"
    );
    SmokeTest::expectException(
        InvalidCsvException::class,
        fn() => CsvLib::decimalDiff("{$dir}/diff3.csv", "{$dir}/diff1.csv"),
        "has 1 rows of data, but {$dir}/diff1.csv has 2"
    );

    # Files with different numbers of columns can't be compared, whichever one is wider.
    file_put_contents("{$dir}/diff-wide.csv", "2,x,a\n1,z,b\n");
    SmokeTest::expectException(
        InvalidCsvException::class,
        fn() => CsvLib::decimalDiff("{$dir}/diff1.csv", "{$dir}/diff-wide.csv"),
        "has 2 columns"
    );
    SmokeTest::expectException(
        InvalidCsvException::class,
        fn() => CsvLib::decimalDiff("{$dir}/diff-wide.csv", "{$dir}/diff1.csv"),
        "has 3 columns"
    );

    # Header rows: if both files have one, the output has a header row (not subtracted, even if the headers look
    # like numbers). If only one does, it's skipped.
    file_put_contents("{$dir}/head1.csv", "2024,b\n5,x\n3,y\n");
    file_put_contents("{$dir}/head2.csv", "2023,b\n2,x\n1,z\n");
    $headerDiff = CsvLib::decimalDiff(
        "{$dir}/head1.csv",
        "{$dir}/head2.csv",
        file1HasHeader: true,
        file2HasHeader: true
    );
    assert(file_get_contents($headerDiff) === "2024|2023,b\n3,x\n2,y|z\n");
    $headerDiff = CsvLib::decimalDiff("{$dir}/head1.csv", "{$dir}/diff2.csv", file1HasHeader: true);
    assert(file_get_contents($headerDiff) === "3,x\n2,y|z\n");
    $headerDiff = CsvLib::decimalDiff("{$dir}/diff1.csv", "{$dir}/head2.csv", file2HasHeader: true);
    assert(file_get_contents($headerDiff) === "3,x\n2,y|z\n");
    SmokeTest::expectException(
        InvalidCsvException::class,
        fn() => CsvLib::decimalDiff("{$dir}/head1.csv", "{$dir}/diff2.csv"),
        "If only one of them has a header row"
    );

    # diffTolerance() doesn't compare header rows, so a header can't fail a numeric tolerance.
    file_put_contents("{$dir}/tol1.csv", "price,qty\n1.00,2\n");
    file_put_contents("{$dir}/tol2.csv", "cost,qty\n1.04,2\n");
    file_put_contents("{$dir}/tol2-no-header.csv", "1.04,2\n");
    $tolWithHeaders = fn(float $tolerance) => CsvLib::diffTolerance(
        "{$dir}/tol1.csv",
        "{$dir}/tol2.csv",
        [1 => $tolerance],
        file1HasHeader: true,
        file2HasHeader: true
    );
    assert($tolWithHeaders(0.05));
    assert(!$tolWithHeaders(0.01));
    assert(CsvLib::diffTolerance("{$dir}/tol1.csv", "{$dir}/tol2-no-header.csv", [1 => 0.05], file1HasHeader: true));
    SmokeTest::expectException(
        InvalidCsvException::class,
        fn() => CsvLib::diffTolerance("{$dir}/tol1.csv", "{$dir}/tol2.csv", [1 => 0.05]),
        "{$dir}/tol1.csv row 1, column 1: 'price'"
    );

    # Only the columns listed in $tolerances are compared, so a different text column is ignored.
    file_put_contents("{$dir}/tol-text1.csv", "1.00,abc,10\n2.00,def,20\n\n");
    file_put_contents("{$dir}/tol-text2.csv", "1.01,xyz,10\n2.00,uvw,21\n");
    assert(CsvLib::diffTolerance("{$dir}/tol-text1.csv", "{$dir}/tol-text2.csv", [1 => 0.05]));
    assert(!CsvLib::diffTolerance("{$dir}/tol-text1.csv", "{$dir}/tol-text2.csv", [1 => 0.05, 3 => 0]));
    assert(CsvLib::diffTolerance("{$dir}/tol-text1.csv", "{$dir}/tol-text2.csv", [1 => 0.05, 3 => 1]));

    # A tolerance of 0 means equal as numbers, so 1.10 and 1.1 match.
    file_put_contents("{$dir}/tol-zero1.csv", "1.10\n");
    file_put_contents("{$dir}/tol-zero2.csv", "1.1\n");
    assert(CsvLib::diffTolerance("{$dir}/tol-zero1.csv", "{$dir}/tol-zero2.csv", [1 => 0]));

    # Every non-numeric value in the listed columns, in both files, is reported in one exception, before anything is
    # compared. getErrors() gives each one's file, row (counting any header) and column (from 1).
    file_put_contents("{$dir}/tol-bad1.csv", "h1,h2\n1,abc\nN/A,2\n");
    file_put_contents("{$dir}/tol-bad2.csv", "1,2\n5,\n");
    $badErrors = null;

    try
    {
        CsvLib::diffTolerance("{$dir}/tol-bad1.csv", "{$dir}/tol-bad2.csv", [1 => 0, 2 => 0], file1HasHeader: true);
    }
    catch (NonNumericCsvValuesException $e)
    {
        $badErrors = array_map(
            fn(CsvCellError $error) => [$error->filepath, $error->rowNumber, $error->columnNumber, $error->value],
            $e->getErrors()
        );
        assert(str_contains($e->getMessage(), "3 non-numeric value(s) were found"));
        assert(str_contains($e->getMessage(), "{$dir}/tol-bad1.csv row 3, column 1: 'N/A'"));
    }

    assert($badErrors === [
        ["{$dir}/tol-bad1.csv", 2, 2, "abc"],
        ["{$dir}/tol-bad1.csv", 3, 1, "N/A"],
        ["{$dir}/tol-bad2.csv", 2, 2, ""],
    ]);

    # It still extends InvalidCsvException, so existing catch blocks still work.
    SmokeTest::expectException(
        InvalidCsvException::class,
        fn() => CsvLib::diffTolerance("{$dir}/tol-bad1.csv", "{$dir}/tol-bad2.csv", [1 => 0], file1HasHeader: true)
    );

    # A listed column that a file doesn't have is reported before looking for non-numeric values.
    SmokeTest::expectException(
        InvalidCsvException::class,
        fn() => CsvLib::diffTolerance("{$dir}/tol-bad1.csv", "{$dir}/tol-bad2.csv", [3 => 0], file1HasHeader: true),
        "{$dir}/tol-bad2.csv has no column 3, as it has 2 column(s)."
    );

    # Under 100, the message lists the first 10, and says how many more getErrors() has.
    file_put_contents("{$dir}/tol-some.csv", str_repeat("x\n", 15));
    SmokeTest::expectException(
        NonNumericCsvValuesException::class,
        fn() => CsvLib::diffTolerance("{$dir}/tol-some.csv", "{$dir}/tol-some.csv", [1 => 0]),
        "and 20 more. Call getErrors() on this exception to get them all."
    );

    # The search stops at 100 non-numeric values, across both files.
    file_put_contents("{$dir}/tol-many.csv", str_repeat("x,y\n", 60));
    $manyErrors = [];

    try
    {
        CsvLib::diffTolerance("{$dir}/tol-many.csv", "{$dir}/tol-many.csv", [1 => 0, 2 => 0]);
    }
    catch (NonNumericCsvValuesException $e)
    {
        $manyErrors = $e->getErrors();
        assert($e->getMessage() === "diffTolerance: found 100 or more (stopped counting at 100) non-numeric values " .
            "in the columns being compared. Call getErrors() on this exception to get them.");
    }

    assert(count($manyErrors) === 100 && $manyErrors[99]->rowNumber === 50 && $manyErrors[99]->columnNumber === 2);

    # Files with different numbers of rows of data, or invalid files, can't be compared.
    SmokeTest::expectException(
        InvalidCsvException::class,
        fn() => CsvLib::diffTolerance("{$dir}/tol-text1.csv", "{$dir}/tol-zero1.csv", [1 => 0]),
        "has 2 rows of data, but {$dir}/tol-zero1.csv has 1"
    );
    file_put_contents("{$dir}/tol-blank-middle.csv", "1\n\n2\n");
    SmokeTest::expectException(
        InvalidCsvException::class,
        fn() => CsvLib::diffTolerance("{$dir}/tol-blank-middle.csv", "{$dir}/tol-blank-middle.csv", [1 => 0]),
        "blank line at row 2"
    );

    # $tolerances must list at least one column, with valid column numbers and tolerances.
    $badTolerances = [[], [0 => 1], ["price" => 1], [1 => -0.5], [1 => "abc"]];

    foreach ($badTolerances as $tolerances)
    {
        SmokeTest::expectException(
            BadArgumentException::class,
            fn() => CsvLib::diffTolerance("{$dir}/tol-zero1.csv", "{$dir}/tol-zero2.csv", $tolerances)
        );
    }

    # Validation allows blank lines at the end, rows of just delimiters, values spanning several lines, and empty
    # files. Blank lines at the end don't count as rows.
    file_put_contents("{$dir}/valid.csv", "a,b\n,\n\"line1\nline2\",x\n3,4\n\n\n");
    CsvLib::validateCsvFile("{$dir}/valid.csv");
    $validCsv = new CsvFile("{$dir}/valid.csv", false);
    assert($validCsv->getRowCount() === 4 && $validCsv->getColumnCount() === 2);
    file_put_contents("{$dir}/empty.csv", "");
    CsvLib::validateCsvFile("{$dir}/empty.csv");
    $emptyCsv = new CsvFile("{$dir}/empty.csv", false);
    assert($emptyCsv->getRowCount() === 0 && $emptyCsv->getColumnCount() === 0);
    SmokeTest::expectException(
        InvalidCsvException::class,
        fn() => new CsvFile("{$dir}/empty.csv", true),
        "should have a header row, but has no rows"
    );
    SmokeTest::expectException(
        InvalidCsvException::class,
        fn() => CsvLib::decimalDiff("{$dir}/empty.csv", "{$dir}/diff1.csv"),
        "has 0 rows of data, but {$dir}/diff1.csv has 2"
    );

    # validateCsvFile() rejects blank lines before more data, and rows with a different number of columns. Rows are
    # counted rather than lines, as a value can span several lines.
    file_put_contents("{$dir}/invalid-blank.csv", "a,b\n\"line1\nline2\",x\n\n\n3,4\n");
    SmokeTest::expectException(
        InvalidCsvException::class,
        fn() => CsvLib::validateCsvFile("{$dir}/invalid-blank.csv"),
        "blank line at row 3, before more data at row 5"
    );
    file_put_contents("{$dir}/invalid-columns.csv", "a,b\n1,2\n3,4,5\n");
    SmokeTest::expectException(
        InvalidCsvException::class,
        fn() => CsvLib::validateCsvFile("{$dir}/invalid-columns.csv"),
        "has 3 columns at row 3, but 2 in the first row"
    );
    SmokeTest::expectException(FileDoesNotExistException::class, fn() => CsvLib::validateCsvFile("{$dir}/nope.csv"));
    SmokeTest::expectException(
        InvalidCsvException::class,
        fn() => new CsvFile("{$dir}/invalid-columns.csv", true),
        "has 3 columns at row 3"
    );
    SmokeTest::expectException(FileDoesNotExistException::class, fn() => new CsvFile("{$dir}/nope.csv", true));

    # removeTrailingBlankLines() keeps the last row's own line ending, and nothing else after it.
    $trailingCases = [
        "a,b\n1,2\n\n\n" => "a,b\n1,2\n",
        "a,b\r\n1,2\r\n\r\n\r\n" => "a,b\r\n1,2\r\n",
        "a,b\r1,2\r\r" => "a,b\r1,2\r",
        "a,b\n1,2\n" => "a,b\n1,2\n",
        "a,b\n1,2" => "a,b\n1,2",
        "a,\"x\n\n\"\n\n" => "a,\"x\n\n\"\n",
        "\n\n\n" => "",
        "" => "",
        "a,b\n" . str_repeat("\n", 20000) => "a,b\n",
    ];

    foreach ($trailingCases as $before => $after)
    {
        file_put_contents("{$dir}/trailing.csv", $before);
        CsvLib::removeTrailingBlankLines("{$dir}/trailing.csv");
        clearstatcache();
        assert(file_get_contents("{$dir}/trailing.csv") === $after);
    }

    file_put_contents("{$dir}/trailing.csv", "a,b\n1,2\n\n");
    chmod("{$dir}/trailing.csv", 0640);
    $trailingCsv = new CsvFile("{$dir}/trailing.csv", true);
    $trailingCsv->removeTrailingBlankLines();
    assert(file_get_contents("{$dir}/trailing.csv") === "a,b\n1,2\n");
    assert($trailingCsv->getRowCount() === 2 && $trailingCsv->getColumnCount() === 2);

    # reload() updates the counts. If the file is no longer valid, it throws and leaves the counts as they were.
    file_put_contents("{$dir}/reload.csv", "a,b\n1,2\n");
    $reloadCsv = new CsvFile("{$dir}/reload.csv", true);
    file_put_contents("{$dir}/reload.csv", "a,b,c\n1,2,3\n4,5,6\n");
    $reloadCsv->reload();
    assert($reloadCsv->getRowCount() === 3 && $reloadCsv->getColumnCount() === 3);
    file_put_contents("{$dir}/reload.csv", "a,b\n\n1,2\n");
    SmokeTest::expectException(InvalidCsvException::class, fn() => $reloadCsv->reload(), "blank line at row 2");
    assert($reloadCsv->getRowCount() === 3 && $reloadCsv->getColumnCount() === 3);

    # Outside code can't change a CsvFile's details directly.
    SmokeTest::expectException(\Error::class, function () use ($reloadCsv) { $reloadCsv->m_rowCount = 99; });
    clearstatcache();
    SmokeTest::assertPermissions("{$dir}/trailing.csv", 0640);
    SmokeTest::expectException(
        FileDoesNotExistException::class,
        fn() => CsvLib::removeTrailingBlankLines("{$dir}/nope.csv")
    );

    # removeRows() counts from 0 by default, or from 1, and ignores duplicates and order.
    $rowsFile = "{$dir}/rows.csv";
    $rowsCases = [
        [[0, 2], true],
        [[1, 3], false],
        [[2, 0, 2], true],
    ];

    foreach ($rowsCases as [$rowNumbers, $startFromZero])
    {
        file_put_contents($rowsFile, "h\n1\n2\n3\n");
        CsvLib::removeRows($rowsFile, $rowNumbers, $startFromZero);
        assert(file_get_contents($rowsFile) === "1\n3\n");
    }

    # Kept rows are copied exactly, including unneeded quotes, line endings, values spanning several lines, and blank
    # lines at the end.
    file_put_contents($rowsFile, "a,\"b\"\r\n\"multi\nline\",x\r\n1,2\r\n\r\n");
    CsvLib::removeRows($rowsFile, [2]);
    assert(file_get_contents($rowsFile) === "a,\"b\"\r\n\"multi\nline\",x\r\n\r\n");

    # Row numbers that don't exist throw, and leave the file as it was.
    file_put_contents($rowsFile, "h\n1\n");
    $badRowNumbers = [
        [[2], true, "rows are numbered 0 to 1"],
        [[-1], true, "rows are numbered 0 to 1"],
        [[0], false, "rows are numbered 1 to 2"],
        [["1"], true, "'1' is not a row number"],
    ];

    foreach ($badRowNumbers as [$rowNumbers, $startFromZero, $message])
    {
        SmokeTest::expectException(
            BadArgumentException::class,
            fn() => CsvLib::removeRows($rowsFile, $rowNumbers, $startFromZero),
            $message
        );
    }

    assert(file_get_contents($rowsFile) === "h\n1\n");
    SmokeTest::expectException(
        BadArgumentException::class,
        fn() => CsvLib::removeRows("{$dir}/empty.csv", [0]),
        "it has no rows"
    );

    # An invalid file is rejected and left as it was. A successful change keeps the file's permissions, and leaves no
    # temporary file behind.
    mkdir("{$dir}/rows-dir");
    file_put_contents("{$dir}/rows-dir/bad.csv", "a\n\nb\n");
    SmokeTest::expectException(InvalidCsvException::class, fn() => CsvLib::removeRows("{$dir}/rows-dir/bad.csv", [0]));
    assert(file_get_contents("{$dir}/rows-dir/bad.csv") === "a\n\nb\n");
    file_put_contents("{$dir}/rows-dir/good.csv", "a\nb\n");
    chmod("{$dir}/rows-dir/good.csv", 0640);
    CsvLib::removeRows("{$dir}/rows-dir/good.csv", [0]);
    clearstatcache();
    assert(file_get_contents("{$dir}/rows-dir/good.csv") === "b\n");
    SmokeTest::assertPermissions("{$dir}/rows-dir/good.csv", 0640);
    assert(array_values(array_diff(scandir("{$dir}/rows-dir"), [".", ".."])) === ["bad.csv", "good.csv"]);

    # CsvFile::removeHeader() updates the CsvFile to have no header and one fewer row, and throws if there isn't one.
    file_put_contents("{$dir}/with-header.csv", "a,b\n1,2\n3,4\n");
    $withHeader = new CsvFile("{$dir}/with-header.csv", true);
    $withHeader->removeHeader();
    assert(file_get_contents("{$dir}/with-header.csv") === "1,2\n3,4\n");
    assert(!$withHeader->hasHeader() && $withHeader->getRowCount() === 2 && $withHeader->getColumnCount() === 2);
    SmokeTest::expectException(
        NoCsvHeaderException::class,
        fn() => $withHeader->removeHeader(),
        "has no header row to remove"
    );
    assert(file_get_contents("{$dir}/with-header.csv") === "1,2\n3,4\n");

    # Removing a header that's the only row gives the same counts as checking the file again would.
    file_put_contents("{$dir}/only-header.csv", "a,b\n");
    $onlyHeader = new CsvFile("{$dir}/only-header.csv", true);
    $onlyHeader->removeHeader();
    assert(file_get_contents("{$dir}/only-header.csv") === "");
    assert($onlyHeader->getRowCount() === 0 && $onlyHeader->getColumnCount() === 0);
    $onlyHeader->reload();
    assert($onlyHeader->getRowCount() === 0 && $onlyHeader->getColumnCount() === 0);

    # CsvFile's aliases pass its own header setting, delimiter etc. to the CsvLib methods.
    file_put_contents("{$dir}/semi1.csv", "a;b\n5;x\n");
    file_put_contents("{$dir}/semi2.csv", "a;b\n2;x\n");
    $semi1 = new CsvFile("{$dir}/semi1.csv", true, ";");
    $semi2 = new CsvFile("{$dir}/semi2.csv", true, ";");
    SmokeTest::assertSamePath($semi1->getFilepath(), "{$dir}/semi1.csv");
    assert($semi1->getDelimiter() === ";" && $semi1->hasHeader());
    assert($semi1->toArray() === [["a" => "5", "b" => "x"]]);
    $semi1NoHeader = new CsvFile("{$dir}/semi1.csv", false, ";");
    assert(!$semi1NoHeader->hasHeader() && $semi1NoHeader->toArray() === [["a", "b"], ["5", "x"]]);
    SmokeTest::expectException(
        NoCsvHeaderException::class,
        fn() => $semi1NoHeader->toJson("{$dir}/semi1.json", true),
        "has no header row"
    );
    $semi1->toJson("{$dir}/semi1.json", true);
    assert(file_get_contents("{$dir}/semi1.json") === '[{"a":"5","b":"x"}]');
    $walkedRows = [];
    $semi1->walk(function (array $row) use (&$walkedRows) { $walkedRows[] = $row; });
    assert($walkedRows === [["a" => "5", "b" => "x"]]);
    assert(file_get_contents($semi1->decimalDiff($semi2)) === "a;b\n3;x\n");
    assert(file_get_contents($semi1->decimalDiff("{$dir}/semi2.csv")) === "a;b\n3;x\n");
    assert($semi1->diffTolerance($semi1, [1 => 0]));
    assert($semi1->diffTolerance("{$dir}/semi1.csv", [1 => 0]));

    # A CsvFile passed as $other brings its own header setting.
    file_put_contents("{$dir}/semi2-no-header.csv", "2;x\n");
    $semi2NoHeader = new CsvFile("{$dir}/semi2-no-header.csv", false, ";");
    assert(file_get_contents($semi1->decimalDiff($semi2NoHeader)) === "3;x\n");
    assert(file_get_contents($semi2NoHeader->decimalDiff($semi1)) === "-3;x\n");
    assert($semi1->diffTolerance($semi2NoHeader, [1 => 3]));
    SmokeTest::expectException(
        BadArgumentException::class,
        fn() => $semi1->decimalDiff(new CsvFile("{$dir}/diff1.csv", false)),
        "must use the same delimiter"
    );
    assert(CsvLib::diffTolerance("{$dir}/a.csv", "{$dir}/a.csv", [1 => 0], file1HasHeader: true, file2HasHeader: true));
    CsvLib::mergeFiles(["{$dir}/a.csv", "{$dir}/a.csv"], "{$dir}/merged.csv", true);
});

$smoke->run("CSV byte order marks", function () use ($dir) {
    $bom = "\xEF\xBB\xBF";

    # convertArrayToCsv() adds a byte order mark by default, so reading the file back must not include it in the
    # first column's name.
    CsvLib::convertArrayToCsv("{$dir}/bom.csv", [["name" => "x", "age" => 1]], true);
    assert(str_starts_with(file_get_contents("{$dir}/bom.csv"), $bom));
    assert(CsvLib::convertCsvToArray("{$dir}/bom.csv", true) === [["name" => "x", "age" => "1"]]);
    $walkedKeys = [];
    CsvLib::csvWalk("{$dir}/bom.csv", function (array $row) use (&$walkedKeys) { $walkedKeys = array_keys($row); });
    assert($walkedKeys === ["name", "age"]);
    CsvLib::convertCsvToJson("{$dir}/bom.csv", "{$dir}/bom.json", true);
    assert(file_get_contents("{$dir}/bom.json") === '[{"name":"x","age":"1"}]');

    # A file with a byte order mark must compare as equal to the same data without one.
    file_put_contents("{$dir}/with-bom.csv", "{$bom}1,2\n");
    file_put_contents("{$dir}/without-bom.csv", "1,2\n");
    $diffFile = CsvLib::decimalDiff("{$dir}/with-bom.csv", "{$dir}/without-bom.csv");
    assert(file_get_contents($diffFile) === "0,0\n");

    # Methods that rewrite the file keep its byte order mark, even when the first column is removed.
    file_put_contents("{$dir}/rewrite.csv", "{$bom}id,name\n1,x\n");
    CsvLib::removeColumns("{$dir}/rewrite.csv", [1]);
    assert(file_get_contents("{$dir}/rewrite.csv") === "{$bom}name\nx\n");
    CsvLib::removeRows("{$dir}/rewrite.csv", [1]);
    assert(file_get_contents("{$dir}/rewrite.csv") === "{$bom}name\n");
    file_put_contents("{$dir}/rewrite.csv", "{$bom}id,name\n1,x\n");
    CsvLib::removeRows("{$dir}/rewrite.csv", [0]);
    assert(file_get_contents("{$dir}/rewrite.csv") === "{$bom}1,x\n");

    # Merging only puts a byte order mark at the start, never in the middle.
    CsvLib::mergeFiles(["{$dir}/with-bom.csv", "{$dir}/with-bom.csv"], "{$dir}/merged-bom.csv", false);
    assert(file_get_contents("{$dir}/merged-bom.csv") === "{$bom}1,2\n1,2\n");
});

# These were testing/tests/TestConvertArrayToCsv.php, TestConvertInvalidArrayToCsv.php and TestCsvWalk.php.
$smoke->run("convertArrayToCsv null values and mismatched keys", function () use ($dir) {
    # A null value is written as an empty value.
    $rows = [
        ["key1" => "value1", "key2" => "value2", "key3" => "value3"],
        ["key1" => "value4", "key2" => null, "key3" => "value5"],
    ];
    CsvLib::convertArrayToCsv("{$dir}/null-values.csv", $rows, true, addByteOrderMark: false);
    assert(file_get_contents("{$dir}/null-values.csv") === "key1,key2,key3\nvalue1,value2,value3\nvalue4,,value5\n");

    # Rows with different keys from each other throw.
    $mismatchedRows = [
        ["key1" => "value1", "key2" => "value2"],
        ["key1" => "some other value", "key3" => "hello"],
    ];
    SmokeTest::expectException(
        BadArgumentException::class,
        fn() => CsvLib::convertArrayToCsv("{$dir}/mismatched.csv", $mismatchedRows, true),
        "missing the expected key key2"
    );
});

$smoke->run("csvWalk headers, keys and row indexes", function () {
    # The file is "first,second", "hello,world", "foo,bar". The row index is the row's position in the file, so the
    # data starts at 1 when there is a header row.
    $filepath = __DIR__ . "/../assets/example-csv.csv";
    $walk = function (bool $hasHeaders, ?array $headers) use ($filepath): array {
        $rows = [];
        CsvLib::csvWalk(
            $filepath,
            function (array $row, int $rowIndex) use (&$rows) { $rows[$rowIndex] = $row; },
            $hasHeaders,
            $headers
        );
        return $rows;
    };

    $expectedWithHeader = [1 => ["first" => "hello", "second" => "world"], 2 => ["first" => "foo", "second" => "bar"]];
    assert($walk(true, null) === $expectedWithHeader);

    # Overriding the keys replaces the header row's names, which is still skipped.
    $expectedOverridden = [1 => ["k1" => "hello", "k2" => "world"], 2 => ["k1" => "foo", "k2" => "bar"]];
    assert($walk(true, ["k1", "k2"]) === $expectedOverridden);

    # Without a header row, every row is data, using the given keys or none.
    $expectedNoHeader = [
        ["k1" => "first", "k2" => "second"],
        ["k1" => "hello", "k2" => "world"],
        ["k1" => "foo", "k2" => "bar"],
    ];
    assert($walk(false, ["k1", "k2"]) === $expectedNoHeader);
    assert($walk(false, null) === [["first", "second"], ["hello", "world"], ["foo", "bar"]]);
});

$smoke->run("CSV methods change files in place", function () use ($dir) {
    # trim(), removeColumns() and removeRows() write to a temporary file in the system's temporary directory, then copy
    # the result back into the original. They used to rename a 0600 temporary file over the original.
    $methods = [
        "trim" => fn(string $file) => CsvLib::trim($file),
        "removeColumns" => fn(string $file) => CsvLib::removeColumns($file, [1]),
        "removeRows" => fn(string $file) => CsvLib::removeRows($file, [0]),
        "removeTrailingBlankLines" => fn(string $file) => CsvLib::removeTrailingBlankLines($file),
    ];
    $tempFilesBefore = glob(sys_get_temp_dir() . "/corelibs-tmp-*");

    # The directory isn't writable (for anyone but root), as only the file itself needs to be.
    mkdir("{$dir}/in-place");

    foreach ($methods as $name => $method)
    {
        $file = "{$dir}/in-place/{$name}.csv";
        file_put_contents($file, " a , b \n c , d \n\n");
        chmod($file, 0640);
        $ownedByOtherUser = SmokeTest::giveToOtherUser($file, "{$name}()");

        # A hard link to the file sees the change too, which shows the file was changed in place rather than replaced.
        link($file, "{$file}.link");
        chmod("{$dir}/in-place", 0555);
        $method($file);
        chmod("{$dir}/in-place", 0755);
        clearstatcache();
        SmokeTest::assertPermissions($file, 0640, "{$name}() changed the file's permissions");
        assert(file_get_contents($file) !== " a , b \n c , d \n\n", "{$name}() didn't change the file");
        assert(file_get_contents("{$file}.link") === file_get_contents($file), "{$name}() replaced the file");

        if ($ownedByOtherUser)
        {
            SmokeTest::assertOwnedByOtherUser($file, "{$name}()");
        }
    }

    # A failure part way through leaves the original as it was.
    file_put_contents("{$dir}/in-place/bad.csv", "a,b\n\nc,d\n");
    SmokeTest::expectException(
        InvalidCsvException::class,
        fn() => CsvLib::removeColumns("{$dir}/in-place/bad.csv", [1])
    );
    assert(file_get_contents("{$dir}/in-place/bad.csv") === "a,b\n\nc,d\n");

    # Nothing is left behind in the temporary directory, whether the change worked or not.
    assert(glob(sys_get_temp_dir() . "/corelibs-tmp-*") === $tempFilesBefore);

    # A file that can't be written is rejected before any work is done. Root can write to any file, so this can only be
    # checked when not running as root.
    file_put_contents("{$dir}/in-place/read-only.csv", "a\n");
    chmod("{$dir}/in-place/read-only.csv", 0444);

    if (!is_writable("{$dir}/in-place/read-only.csv"))
    {
        foreach ($methods as $method)
        {
            SmokeTest::expectException(
                BadFilePermissionsException::class,
                fn() => $method("{$dir}/in-place/read-only.csv")
            );
        }
    }

    # Make it writable again, as Windows won't let a read-only file be deleted, so the clean-up couldn't remove it.
    chmod("{$dir}/in-place/read-only.csv", 0644);

    SmokeTest::expectException(FileDoesNotExistException::class, fn() => CsvLib::trim("{$dir}/in-place/nope.csv"));
});

$smoke->run("CSV reading methods close the file however they end", function () use ($dir) {
    # get_resources() lists every open stream, so the count is the same before and after if the file was closed.
    $openStreams = fn() => count(get_resources('stream'));
    file_put_contents("{$dir}/close-good.csv", "a,b\n1,2\n3,4\n");
    file_put_contents("{$dir}/close-bad.csv", "a,b\n1,2\n3,4,5\n");
    $throwingCallback = function (array $row, int $rowIndex) { throw new \RuntimeException("callback failed"); };
    $cases = [
        "convertCsvToArray() finishing" => fn() => CsvLib::convertCsvToArray("{$dir}/close-good.csv", true),
        "convertCsvToArray() on a bad row" => fn() => CsvLib::convertCsvToArray("{$dir}/close-bad.csv", true),
        "csvWalk() finishing" => fn() => CsvLib::csvWalk("{$dir}/close-good.csv", fn(array $row) => null),
        "csvWalk() on a bad row" => fn() => CsvLib::csvWalk("{$dir}/close-bad.csv", fn(array $row) => null),
        "csvWalk() when the callback throws" => fn() => CsvLib::csvWalk("{$dir}/close-good.csv", $throwingCallback),
    ];

    foreach ($cases as $name => $case)
    {
        $before = $openStreams();

        try
        {
            $case();
        }
        catch (InvalidCsvException | \RuntimeException $e)
        {
            # Expected for the bad rows and the throwing callback. Only whether the file was closed matters here.
        }

        assert($openStreams() === $before, "{$name} left the file open");
    }

    # A missing file throws FileDoesNotExistException, without a PHP warning (which the test runner would report).
    SmokeTest::expectException(
        FileDoesNotExistException::class,
        fn() => CsvLib::convertCsvToArray("{$dir}/nope.csv", true)
    );

    SmokeTest::expectException(FileDoesNotExistException::class, fn() => CsvLib::csvWalk("{$dir}/nope.csv", fn() => null));
});

$smoke->run("CSV writing methods only replace the output once it is complete", function () use ($dir) {
    $base = "{$dir}/csv-output";
    mkdir($base);
    $tempFilesBefore = glob(sys_get_temp_dir() . "/corelibs-tmp-*");
    $resetExisting = function (string $path): void {
        file_put_contents($path, "old contents\n");
        chmod($path, 0640);
    };

    # convertArrayToCsv(): a bad row part way through leaves an existing file exactly as it was.
    $resetExisting("{$base}/array.csv");
    $rows = [["a" => 1, "b" => 2], ["a" => 3, "c" => 4]];
    SmokeTest::expectException(
        BadArgumentException::class,
        fn() => CsvLib::convertArrayToCsv("{$base}/array.csv", $rows, true, addByteOrderMark: false),
        "missing the expected key b"
    );
    assert(file_get_contents("{$base}/array.csv") === "old contents\n");

    # When it works, an existing file keeps its permissions, and a new one gets the default for a new file.
    CsvLib::convertArrayToCsv("{$base}/array.csv", [["a" => 1, "b" => null]], true, addByteOrderMark: false);
    CsvLib::convertArrayToCsv("{$base}/new.csv", [["a" => 1]], true, addByteOrderMark: false);
    clearstatcache();
    assert(file_get_contents("{$base}/array.csv") === "a,b\n1,\n");
    SmokeTest::assertPermissions("{$base}/array.csv", 0640);
    SmokeTest::assertPermissions("{$base}/new.csv", (0666 & ~umask()));

    # convertCsvToJson(): a value that can't be encoded (invalid UTF-8) leaves an existing file as it was.
    $resetExisting("{$base}/out.json");
    file_put_contents("{$base}/bad-utf8.csv", "name\nok\n\xFF\n");
    SmokeTest::expectException(
        \JsonException::class,
        fn() => CsvLib::convertCsvToJson("{$base}/bad-utf8.csv", "{$base}/out.json", true)
    );
    assert(file_get_contents("{$base}/out.json") === "old contents\n");

    # mergeFiles(): the output can be one of the files being merged. This used to empty it before it was read.
    file_put_contents("{$base}/merge-a.csv", "h\n1\n");
    file_put_contents("{$base}/merge-b.csv", "h\n2\n");
    CsvLib::mergeFiles(["{$base}/merge-a.csv", "{$base}/merge-b.csv"], "{$base}/merge-a.csv", true);
    assert(file_get_contents("{$base}/merge-a.csv") === "h\n1\n2\n");

    # A missing file is found before anything is written, leaving an existing merged file as it was.
    $resetExisting("{$base}/merged.csv");
    SmokeTest::expectException(
        FileDoesNotExistException::class,
        fn() => CsvLib::mergeFiles(["{$base}/merge-b.csv", "{$base}/nope.csv"], "{$base}/merged.csv", true),
        "There is no file at {$base}/nope.csv"
    );
    assert(file_get_contents("{$base}/merged.csv") === "old contents\n");

    # Nothing is left in the temporary directory, whether they worked or not.
    assert(glob(sys_get_temp_dir() . "/corelibs-tmp-*") === $tempFilesBefore);

    if (SmokeTest::canTestPermissions("CSV writing methods checking the output can be written first"))
    {
        # An output that can't be written is found before anything is done.
        file_put_contents("{$base}/read-only.csv", "old\n");
        chmod("{$base}/read-only.csv", 0444);
        mkdir("{$base}/read-only-dir");
        chmod("{$base}/read-only-dir", 0555);
        $unwritable = [
            fn() => CsvLib::convertArrayToCsv("{$base}/read-only.csv", [["a" => 1]], true),
            fn() => CsvLib::mergeFiles(["{$base}/merge-b.csv"], "{$base}/read-only.csv", true),
            fn() => CsvLib::convertCsvToJson("{$base}/merge-b.csv", "{$base}/read-only-dir/out.json", true),
        ];

        foreach ($unwritable as $call)
        {
            SmokeTest::expectException(BadFilePermissionsException::class, $call);
        }

        assert(file_get_contents("{$base}/read-only.csv") === "old\n");
        assert(!file_exists("{$base}/read-only-dir/out.json"));
        chmod("{$base}/read-only-dir", 0755);
    }
});

$smoke->run("convertCsvToJson", function () use ($dir) {
    # A quoted value can contain a line break, and blank lines at the end are ignored.
    file_put_contents("{$dir}/multi-line.csv", "h1,h2\n\"line1\nline2\",x\n\n");
    CsvLib::convertCsvToJson("{$dir}/multi-line.csv", "{$dir}/multi-line.json", true);
    assert(file_get_contents("{$dir}/multi-line.json") === '[{"h1":"line1\\nline2","h2":"x"}]');

    # The readable form indents each object inside the array.
    file_put_contents("{$dir}/pretty.csv", "h1\na\nb\n");
    CsvLib::convertCsvToJson("{$dir}/pretty.csv", "{$dir}/pretty.json", false);
    $expected = "[\n    {\n        \"h1\": \"a\"\n    },\n    {\n        \"h1\": \"b\"\n    }]";
    assert(file_get_contents("{$dir}/pretty.json") === $expected);

    # Just a header gives an empty array.
    file_put_contents("{$dir}/header-only.csv", "h1,h2\n");
    CsvLib::convertCsvToJson("{$dir}/header-only.csv", "{$dir}/header-only.json", true);
    assert(file_get_contents("{$dir}/header-only.json") === "[]");

    # A blank line in the middle makes the file invalid, and nothing is written.
    file_put_contents("{$dir}/blank-middle.csv", "h1\na\n\nb\n");
    SmokeTest::expectException(
        InvalidCsvException::class,
        fn() => CsvLib::convertCsvToJson("{$dir}/blank-middle.csv", "{$dir}/blank-middle.json", true)
    );
    assert(!file_exists("{$dir}/blank-middle.json"));

    SmokeTest::expectException(
        FileDoesNotExistException::class,
        fn() => CsvLib::convertCsvToJson("{$dir}/missing.csv", "{$dir}/missing.json", true)
    );
});

$smoke->run("CSV methods treating a blank line in the middle as invalid", function () use ($dir) {
    $base = "{$dir}/blank-middle";
    mkdir($base);
    file_put_contents("{$base}/invalid.csv", "h1,h2\na,b\n\nc,d\n");
    file_put_contents("{$base}/valid.csv", "h1,h2\n\"multi\nline\",b\n\n");

    $calls = [
        "convertCsvToArray" => fn(string $path) => CsvLib::convertCsvToArray($path, true),
        "csvWalk" => fn(string $path) => CsvLib::csvWalk($path, fn() => null),
        "mergeFiles" => fn(string $path) => CsvLib::mergeFiles(["{$base}/valid.csv", $path], "{$base}/merged.csv", true),
        "removeColumns" => fn(string $path) => CsvLib::removeColumns($path, [1]),
    ];

    foreach ($calls as $name => $call)
    {
        SmokeTest::expectException(InvalidCsvException::class, fn() => $call("{$base}/invalid.csv"));
        assert(file_get_contents("{$base}/invalid.csv") === "h1,h2\na,b\n\nc,d\n", "{$name} changed the file");
    }

    assert(!file_exists("{$base}/merged.csv"));

    # Blank lines at the end are fine, and a quoted value can contain a line break.
    assert(CsvLib::convertCsvToArray("{$base}/valid.csv", true) === [["h1" => "multi\nline", "h2" => "b"]]);

    # mergeFiles() leaves out blank lines at the end of each file, so they don't end up in the middle.
    file_put_contents("{$base}/second.csv", "h1,h2\nc,d\n\n");
    CsvLib::mergeFiles(["{$base}/valid.csv", "{$base}/second.csv"], "{$base}/merged.csv", true);
    assert(file_get_contents("{$base}/merged.csv") === "h1,h2\n\"multi\nline\",b\nc,d\n");

    # removeColumns() counts rows rather than lines, so a multi-line value is fine, and blank lines at the end are kept.
    CsvLib::removeColumns("{$base}/valid.csv", [2]);
    assert(file_get_contents("{$base}/valid.csv") === "h1\n\"multi\nline\"\n\n");

    # A column the file doesn't have is found before anything is changed.
    SmokeTest::expectException(
        InvalidCsvException::class,
        fn() => CsvLib::removeColumns("{$base}/second.csv", [3]),
        "does not have a column 3, as it has 2."
    );
});

$smoke->run("mergeFiles checking the files match", function () use ($dir) {
    $base = "{$dir}/merge-match";
    mkdir($base);
    file_put_contents("{$base}/a.csv", "h1,h2\n1,2\n");
    file_put_contents("{$base}/three-columns.csv", "h1,h2,h3\n1,2,3\n");
    file_put_contents("{$base}/other-header.csv", "h1,other\n3,4\n");
    file_put_contents("{$base}/empty.csv", "");

    SmokeTest::expectException(
        InvalidCsvException::class,
        fn() => CsvLib::mergeFiles(["{$base}/a.csv", "{$base}/three-columns.csv"], "{$base}/out.csv", true),
        "has 3 columns, but {$base}/a.csv has 2"
    );

    SmokeTest::expectException(
        InvalidCsvException::class,
        fn() => CsvLib::mergeFiles(["{$base}/a.csv", "{$base}/other-header.csv"], "{$base}/out.csv", true),
        "has a different header"
    );

    assert(!file_exists("{$base}/out.csv"));

    # Without headers, only the number of columns has to match, and an empty file has nothing to merge.
    CsvLib::mergeFiles(["{$base}/a.csv", "{$base}/empty.csv", "{$base}/other-header.csv"], "{$base}/out.csv", false);
    assert(file_get_contents("{$base}/out.csv") === "h1,h2\n1,2\nh1,other\n3,4\n");
});

$smoke->run("file-rewriting methods passing on where to write first", function () {
    # In /dev/shm (a separate filesystem from the system's temporary directory in the test containers), AUTO puts the
    # new contents next to the original and renames them over it, so the file gets a new inode, while the default
    # (ALWAYS) copies them back, so it keeps its inode.
    if (!is_dir("/dev/shm") || stat("/dev/shm")['dev'] === stat(sys_get_temp_dir())['dev'])
    {
        SmokeTest::skip(
            "file-rewriting methods passing on where to write first",
            "needs /dev/shm on another filesystem"
        );
    }
    else
    {
        $base = "/dev/shm/corelibs-rewrite-" . getmypid();
        mkdir($base);

        try
        {
            $rewrites = [
                "trim" => function (string $path, UseSystemTempDirEnum $where): void {
                    CsvLib::trim($path, useSystemTempDir: $where);
                },
                "removeRows" => function (string $path, UseSystemTempDirEnum $where): void {
                    CsvLib::removeRows($path, [1], useSystemTempDir: $where);
                },
                "removeColumns" => function (string $path, UseSystemTempDirEnum $where): void {
                    CsvLib::removeColumns($path, [2], useSystemTempDir: $where);
                },
                "fileFilter" => function (string $path, UseSystemTempDirEnum $where): void {
                    Filesystem::fileFilter($path, fn(string $line) => true, useSystemTempDir: $where);
                },
                "CsvFile::removeHeader" => function (string $path, UseSystemTempDirEnum $where): void {
                    (new CsvFile($path, true))->removeHeader($where);
                },
            ];

            foreach ($rewrites as $name => $rewrite)
            {
                foreach ([UseSystemTempDirEnum::AUTO, UseSystemTempDirEnum::ALWAYS] as $where)
                {
                    $path = "{$base}/file.csv";
                    file_put_contents($path, "h1,h2\n a ,b\nc,d\n");
                    $inodeBefore = fileinode($path);
                    $rewrite($path, $where);
                    clearstatcache();
                    $wasRenamed = fileinode($path) !== $inodeBefore;
                    assert($wasRenamed === ($where === UseSystemTempDirEnum::AUTO), "{$name} with {$where->name}");
                    assert(glob("{$base}/.corelibs-tmp-*") === [], "{$name} left a temporary file behind");
                }
            }
        }
        finally
        {
            Filesystem::deleteDir($base);
        }
    }
});

$smoke->run("CsvFile trim, removeColumns and removeRows", function () use ($dir) {
    $base = "{$dir}/csvfile-changes";
    mkdir($base);

    # After each change, the object's details must match a fresh CsvFile made from the changed file.
    $assertMatchesFile = function (CsvFile $csvFile, string $name): void {
        $fresh = new CsvFile($csvFile->getFilepath(), $csvFile->hasHeader());
        assert($csvFile->getRowCount() === $fresh->getRowCount(), "{$name}: row count");
        assert($csvFile->getColumnCount() === $fresh->getColumnCount(), "{$name}: column count");
    };

    file_put_contents("{$base}/a.csv", " h1 ,h2,h3\n a ,b,c\nd,e,f\n\n");
    $csvFile = new CsvFile("{$base}/a.csv", true);
    $csvFile->trim();
    assert(file_get_contents("{$base}/a.csv") === "h1,h2,h3\na,b,c\nd,e,f\n");
    $assertMatchesFile($csvFile, "trim");

    $csvFile->removeColumns([2, 2]);
    assert(file_get_contents("{$base}/a.csv") === "h1,h3\na,c\nd,f\n" && $csvFile->getColumnCount() === 2);
    $assertMatchesFile($csvFile, "removeColumns");

    $csvFile->removeRows([2]);
    assert(file_get_contents("{$base}/a.csv") === "h1,h3\na,c\n" && $csvFile->getRowCount() === 2);
    assert($csvFile->hasHeader());
    $assertMatchesFile($csvFile, "removeRows");

    # Removing the first row removes the header (counting from 1 here).
    $csvFile->removeRows([1], false);
    assert(file_get_contents("{$base}/a.csv") === "a,c\n" && !$csvFile->hasHeader() && $csvFile->getRowCount() === 1);
    $assertMatchesFile($csvFile, "removeRows of the header");

    # Removing every row matches what the constructor gives for an empty file.
    $csvFile->removeRows([0]);
    assert($csvFile->getRowCount() === 0 && $csvFile->getColumnCount() === 0);
    $assertMatchesFile($csvFile, "removeRows of everything");

    # A failure leaves the object as it was.
    file_put_contents("{$base}/b.csv", "h1,h2\n1,2\n");
    $csvFile = new CsvFile("{$base}/b.csv", true);
    SmokeTest::expectException(InvalidCsvException::class, fn() => $csvFile->removeColumns([3]));
    SmokeTest::expectException(BadArgumentException::class, fn() => $csvFile->removeRows([5]));

    # Removing every column (even listed twice) would leave only blank rows, so is refused.
    SmokeTest::expectException(
        BadArgumentException::class,
        fn() => $csvFile->removeColumns([2, 1, 2]),
        "can't remove all 2 columns"
    );

    assert(file_get_contents("{$base}/b.csv") === "h1,h2\n1,2\n");
    assert($csvFile->getColumnCount() === 2 && $csvFile->getRowCount() === 2 && $csvFile->hasHeader());
});

