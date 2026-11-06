<?php

/*
 * A quick smoke test that calls (nearly) every public method in the library, and reports any that throw an error or
 * raise a warning/deprecation. This is used by run-all-php-versions.sh to check the library works on every supported
 * version of PHP, but it can also be run directly: php -d zend.assertions=1 -d assert.exception=1 smoke.php
 * Exits with 0 if everything passed, or 1 if anything failed.
 */

require_once(__DIR__ . '/../../vendor/autoload.php');

use Programster\CoreLibs\ArrayLib;
use Programster\CoreLibs\CliLib;
use Programster\CoreLibs\Core;
use Programster\CoreLibs\CsvFile;
use Programster\CoreLibs\CsvLib;
use Programster\CoreLibs\DataSizeUnitEnum;
use Programster\CoreLibs\EncryptionLib;
use Programster\CoreLibs\Exceptions\BadArgumentException;
use Programster\CoreLibs\Exceptions\DecryptionFailedException;
use Programster\CoreLibs\Exceptions\EmptyArrayException;
use Programster\CoreLibs\Exceptions\FileAlreadyExistsException;
use Programster\CoreLibs\Exceptions\FileDoesNotExistException;
use Programster\CoreLibs\Exceptions\FilesystemException;
use Programster\CoreLibs\Exceptions\InvalidCsvException;
use Programster\CoreLibs\Exceptions\NetworkException;
use Programster\CoreLibs\Exceptions\NoCsvHeaderException;
use Programster\CoreLibs\Exceptions\NoFileExtensionException;
use Programster\CoreLibs\Exceptions\PhpVersionTooLowException;
use Programster\CoreLibs\Exceptions\StringConversionFailedException;
use Programster\CoreLibs\File;
use Programster\CoreLibs\Filesystem;
use Programster\CoreLibs\MemoryTypeEnum;
use Programster\CoreLibs\Network;
use Programster\CoreLibs\StringLib;


final class SmokeTest
{
    private int $m_numPassed = 0;
    private array $m_failures = [];
    private array $m_warnings = [];


    public function __construct()
    {
        error_reporting(-1);

        set_error_handler(function (int $errorNumber, string $message, string $file, int $line): bool {
            # Respect the @ operator, as the library deliberately uses it in a few places.
            if (error_reporting() & $errorNumber)
            {
                $this->m_warnings[] = "[E{$errorNumber}] {$message} @ " . basename($file) . ":{$line}";
            }

            return true;
        });
    }


    /**
     * Run one test, recording it as failed if it throws, or raises any warnings/deprecations.
     * @param string $label - the name of the test, shown if it fails.
     * @param callable $test - the test to run.
     * @return void
     */
    public function run(string $label, callable $test): void
    {
        $numWarningsBefore = count($this->m_warnings);
        $error = "";

        try
        {
            $test();
        }
        catch (\Throwable $e)
        {
            $error = get_class($e) . ": " . $e->getMessage() . " @ " . basename($e->getFile()) . ":" . $e->getLine();
        }

        $newWarnings = array_slice($this->m_warnings, $numWarningsBefore);

        if ($error === "" && count($newWarnings) === 0)
        {
            $this->m_numPassed++;
        }
        else
        {
            $this->m_failures[] = trim("{$label}: {$error} " . implode(" | ", $newWarnings));
        }
    }


    /**
     * Assert that a callback throws a specific type of exception.
     * @param string $exceptionClass - the class of exception that should be thrown.
     * @param callable $callback - the code that should throw it.
     * @param string $messageContains - optionally, text the exception's message must contain, for when the same
     *                                  class of exception can be thrown for different reasons.
     * @return void
     * @throws \Throwable - if the callback throws a different exception, it is rethrown.
     * @throws \Exception - if the callback doesn't throw at all, or the message doesn't contain $messageContains.
     */
    public static function expectException(
        string $exceptionClass,
        callable $callback,
        string $messageContains = ""
    ): void
    {
        $threwExpectedException = false;

        try
        {
            $callback();
        }
        catch (\Throwable $e)
        {
            if (!($e instanceof $exceptionClass))
            {
                throw $e;
            }

            if (!str_contains($e->getMessage(), $messageContains))
            {
                throw new \Exception(
                    "Expected the {$exceptionClass} message to contain \"{$messageContains}\", but it was: " .
                    $e->getMessage()
                );
            }

            $threwExpectedException = true;
        }

        if (!$threwExpectedException)
        {
            throw new \Exception("Expected {$exceptionClass} to be thrown, but nothing was thrown.");
        }
    }


    # Accessors
    public function getNumPassed(): int { return $this->m_numPassed; }
    public function getFailures(): array { return $this->m_failures; }
}



/**
 * A stand-in for the https:// stream wrapper, so that methods that fetch a URL (such as Network::getPublicIp()) can be
 * tested without the internet. Register it with FakeHttpsStream::install(), and put things back with uninstall().
 */
final class FakeHttpsStream
{
    # PHP sets this on every stream wrapper, and requires it to be a public property.
    public $context;

    # The body to respond with, or null to fail as if the server couldn't be reached.
    private static ?string $s_response = null;
    private int $m_position = 0;


    /**
     * Replace the https:// stream wrapper with this one.
     * @param ?string $response - the body every request gets, or null for every request to fail.
     * @return void
     */
    public static function install(?string $response): void
    {
        self::$s_response = $response;
        stream_wrapper_unregister("https");
        stream_wrapper_register("https", self::class);
    }


    /**
     * Put the real https:// stream wrapper back.
     * @return void
     */
    public static function uninstall(): void
    {
        stream_wrapper_restore("https");
    }


    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        # Returning false makes PHP raise a "Failed to open stream" warning, as it would for a real failure.
        return self::$s_response !== null;
    }


    public function stream_read(int $count): string
    {
        $chunk = substr(self::$s_response, $this->m_position, $count);
        $this->m_position += strlen($chunk);
        return $chunk;
    }


    public function stream_eof(): bool { return $this->m_position >= strlen(self::$s_response); }
    public function stream_stat(): array { return []; }
}


$smoke = new SmokeTest();
$dir = sys_get_temp_dir() . "/corelibs-smoke-" . getmypid();
mkdir($dir);

# ---------------------------------------------------------------------------------------------------------------------
# ArrayLib
# ---------------------------------------------------------------------------------------------------------------------
$smoke->run("isAssoc", fn() => assert(ArrayLib::isAssoc(["a" => 1]) && !ArrayLib::isAssoc([1])));

$smoke->run("getFirstElement", function () {
    $array = [5 => "a", 9 => "b"];
    assert(ArrayLib::getFirstElement($array) === "a");
    assert(ArrayLib::getFirstElement($array, true) === "a" && $array === [0 => "b"]);
});

$smoke->run("getLastElement", function () {
    $array = ["x" => 1, "y" => 2];
    assert(ArrayLib::getLastElement($array) === 2);
    assert(ArrayLib::getLastElement($array, true) === 2 && $array === ["x" => 1]);
});

$smoke->run("getLastKey", function () {
    $array = ["x" => 1, "y" => 2];
    assert(ArrayLib::getLastKey($array, true) === "y" && $array === ["x" => 1]);
});

$smoke->run("getRandomKey / getRandomElement", function () {
    $array = [1, 2, 3];
    assert(in_array(ArrayLib::getRandomKey($array), [0, 1, 2], true));
    assert(in_array(ArrayLib::getRandomElement($array, true), [1, 2, 3], true) && count($array) === 2);
});

$smoke->run("empty arrays throw EmptyArrayException", function () {
    $empty = [];
    SmokeTest::expectException(EmptyArrayException::class, fn() => ArrayLib::getFirstElement($empty));
    SmokeTest::expectException(EmptyArrayException::class, fn() => ArrayLib::getLastElement($empty));
    SmokeTest::expectException(EmptyArrayException::class, fn() => ArrayLib::isAssoc([]));
    SmokeTest::expectException(EmptyArrayException::class, fn() => ArrayLib::split([], 2));
});

$smoke->run("other ArrayLib methods", function () {
    assert(ArrayLib::removeIndexes([1, 2, 3], [1], true) === [1, 3]);
    assert(ArrayLib::wrapElements(["a"], "`") === ["`a`"]);
    assert(ArrayLib::fastDiff([1, 2], [2]) === [1] && ArrayLib::fastIntersect([1, 2], [2]) === [2]);
    assert(ArrayLib::indexDiff(["x" => 1], [2]) === ["x"]);
    assert(ArrayLib::getValues(["a" => 1], ["a"]) === [1]);
    assert(ArrayLib::stripEmptyElements([0, 1, "", 2]) === [1, 2]);
    assert(ArrayLib::assocArrayMerge(["a" => 1], ["a" => 2, "b" => 3], fn($k, $x, $y) => $x + $y) === ["a" => 3, "b" => 3]);

    # setDiff() compares sets strictly, so order and types matter.
    assert(ArrayLib::setDiff([[1, 2], [3, 4], [5]], [[3, 4]]) === [[1, 2], [5]]);
    assert(ArrayLib::setDiff([[1, 2], [1]], [[2, 1], ["1"]]) === [[1, 2], [1]]);
    assert(ArrayLib::setDiff([[-0.0]], [[0.0]]) === []);
    # A closure can't be serialized, so this checks the fallback that compares every pair.
    $closure = fn() => 1;
    assert(ArrayLib::setDiff([[$closure], [1]], [[$closure]]) === [[1]]);

    # Keys that exist with a null value must be treated as existing.
    assert(ArrayLib::getValues(["a" => null], ["a"]) === [null]);
    assert(ArrayLib::removeIndexes(["a" => null, "b" => 1], ["a"]) === ["b" => 1]);
    assert(ArrayLib::assocArrayMerge(["a" => 1], ["a" => null], fn($k, $x, $y) => "handled") === ["a" => "handled"]);
    assert(ArrayLib::arrayCombineList(["k"], [[1], [2]]) === [["k" => 1], ["k" => 2]]);
    assert(ArrayLib::getValueOrDefault([], "a", 5) === 5 && ArrayLib::getValueOrDefault(["a" => null], "a", 5) === null);
});

$smoke->run("split", function () {
    assert(ArrayLib::split([1, 2, 3, 4, 5], 4) === [[1, 2], [3], [4], [5]]);
    assert(ArrayLib::split([1, 2, 3, 4, 5], 2, false) === [[1, 3, 5], [2, 4]]);
    assert(ArrayLib::split([1, 2], 3) === [[1], [2], []]);
});

# ---------------------------------------------------------------------------------------------------------------------
# CsvLib
# ---------------------------------------------------------------------------------------------------------------------
$smoke->run("CSV methods", function () use ($dir) {
    CsvLib::convertArrayToCsv("{$dir}/a.csv", [["a" => 1, "b" => "x,y"], ["a" => 2, "b" => ""]], true, addByteOrderMark: false);
    assert(CsvLib::convertCsvToArray("{$dir}/a.csv", true) === [["a" => "1", "b" => "x,y"], ["a" => "2", "b" => ""]]);
    CsvLib::csvWalk("{$dir}/a.csv", fn(array $row) => null);
    CsvLib::convertCsvToJson("{$dir}/a.csv", "{$dir}/a.json", true);
    assert(json_decode(file_get_contents("{$dir}/a.json"), true) === [["a" => "1", "b" => "x,y"], ["a" => "2", "b" => ""]]);
    CsvLib::trim("{$dir}/a.csv", ",");
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
        "non-numeric column"
    );

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
    assert((fileperms("{$dir}/trailing.csv") & 0777) === 0640);
    SmokeTest::expectException(
        FilesystemException::class,
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
    assert((fileperms("{$dir}/rows-dir/good.csv") & 0777) === 0640);
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
    assert($semi1->getFilepath() === "{$dir}/semi1.csv" && $semi1->getDelimiter() === ";" && $semi1->hasHeader());
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
    assert($semi1->diffTolerance($semi1, []));
    assert($semi1->diffTolerance("{$dir}/semi1.csv", []));

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
    assert(CsvLib::diffTolerance("{$dir}/a.csv", "{$dir}/a.csv", []));
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

# ---------------------------------------------------------------------------------------------------------------------
# Core
# ---------------------------------------------------------------------------------------------------------------------
$smoke->run("getMemory", function () {
    assert(Core::getMemory() > 0);
    assert(Core::getMemory(MemoryTypeEnum::AVAILABLE, DataSizeUnitEnum::GIB) > 0);
    Core::getMemory(MemoryTypeEnum::HUGE_PAGES_TOTAL);
});

$smoke->run("signatures", function () {
    $signature = Core::generateSignature(["a" => 1], "secret");
    assert(Core::isValidSignedRequest(["a" => 1], $signature, "secret"));
    assert(!Core::isValidSignedRequest(["a" => 2], $signature, "secret"));
});

$smoke->run("versionGuard", function () {
    Core::versionGuard("8.0");
    SmokeTest::expectException(PhpVersionTooLowException::class, fn() => Core::versionGuard("99.0"));
});

$smoke->run("runCommand / processes", function () {
    assert(Core::runCommand("echo hi") === "hi\n");
    assert(Core::runCommand("cat", "some input") === "some input");
    assert(Core::getNumProcessors() > 0);
    assert(Core::getNumProcRunning("nothing-is-called-this") === 0);
    Core::setCliTitle("corelibs-smoke");
});

$smoke->run("yes/no, true/false, clamp", function () {
    assert(Core::generateYesNoString(0) === "No" && Core::generateTrueFalseString([]) === "False");
    assert(Core::clampValue(15, 10, 0) === 10);
    assert(Core::clampValue(new DateTime("2030-01-01"), new DateTime("2026-12-31"), new DateTime("2026-01-01"))->format("Y") === "2026");
});

$smoke->run("other Core methods", function () {
    assert(Core::isCli());
    ob_start();
    Core::println("x");
    ob_end_clean();
    assert(Core::fetchReqArgsFromArray(["a"], ["a" => 1]) === ["a" => 1]);
    assert(Core::fetchOptionalArgsFromArray(["a", "z"], ["a" => 1]) === ["a" => 1]);

    # A null value counts as provided, but a null argument name is not allowed.
    assert(Core::fetchReqArgsFromArray(["a"], ["a" => null]) === ["a" => null]);
    assert(Core::fetchOptionalArgsFromArray(["a", "z"], ["a" => null]) === ["a" => null]);
    SmokeTest::expectException(BadArgumentException::class, fn() => Core::fetchReqArgsFromArray(["a"], ["b" => 1]));
    SmokeTest::expectException(BadArgumentException::class, fn() => Core::fetchReqArgsFromArray([null], ["" => 1]));
    SmokeTest::expectException(BadArgumentException::class, fn() => Core::fetchOptionalArgsFromArray([null], []));
    assert(str_contains(Core::var_dump(1), "int(1)"));
});

# ---------------------------------------------------------------------------------------------------------------------
# DataSizeUnitEnum
# ---------------------------------------------------------------------------------------------------------------------
$smoke->run("formatBytes / convertFromBytes", function () {
    assert(DataSizeUnitEnum::formatBytes(1992294) === "1.9 MiB");
    assert(DataSizeUnitEnum::formatBytes(1048525, 1, false) === "1.0 MB");
    assert(DataSizeUnitEnum::MIB->convertFromBytes(1048576) === 1.0);
});

# ---------------------------------------------------------------------------------------------------------------------
# EncryptionLib
# ---------------------------------------------------------------------------------------------------------------------
$smoke->run("encryption and passwords", function () {
    $key = EncryptionLib::generateKey();
    assert(EncryptionLib::decrypt(EncryptionLib::encrypt("hello", $key), $key) === "hello");
    SmokeTest::expectException(DecryptionFailedException::class, fn() => EncryptionLib::decrypt(EncryptionLib::encrypt("hello", $key), EncryptionLib::generateKey()));
    $iv = random_bytes(16);
    $legacy = openssl_encrypt("old", "AES-256-OFB", "old key", OPENSSL_ZERO_PADDING, $iv);
    assert(EncryptionLib::decryptLegacy($legacy, "old key", $iv) === "old");
    assert(EncryptionLib::verifyPassword("password", EncryptionLib::generatePasswordHash("password")));
});

# ---------------------------------------------------------------------------------------------------------------------
# Filesystem
# ---------------------------------------------------------------------------------------------------------------------
$smoke->run("File rename and move", function () use ($dir) {
    $base = "{$dir}/file-test";
    mkdir("{$base}/sub", 0777, true);
    file_put_contents("{$base}/a.txt", "data");

    # A plain name renames the file in its own directory.
    $file = new File("{$base}/a.txt");
    $file->rename("b.txt");
    assert($file->getFilepath() === "{$base}/b.txt" && file_get_contents("{$base}/b.txt") === "data");
    assert(!file_exists("{$base}/a.txt"));

    # Relative paths are relative to the file's directory, and absolute paths are used as they are.
    $file->rename("sub/c.txt");
    assert($file->getFilepath() === "{$base}/sub/c.txt" && is_file("{$base}/sub/c.txt"));
    $file->rename("../d.txt");
    assert($file->getFilepath() === "{$base}/d.txt" && is_file("{$base}/d.txt"));
    $file->rename("{$base}/sub/e.txt");
    assert($file->getFilepath() === "{$base}/sub/e.txt" && is_file("{$base}/sub/e.txt"));

    # Renaming to where the file already is does nothing.
    $file->rename("e.txt");
    $file->rename("../sub/e.txt");
    assert($file->getFilepath() === "{$base}/sub/e.txt" && is_file("{$base}/sub/e.txt"));

    # rename() doesn't overwrite, create directories, or take a directory.
    file_put_contents("{$base}/sub/taken.txt", "other");
    SmokeTest::expectException(FileAlreadyExistsException::class, fn() => $file->rename("taken.txt"));
    assert(file_get_contents("{$base}/sub/taken.txt") === "other" && is_file("{$base}/sub/e.txt"));
    SmokeTest::expectException(FileDoesNotExistException::class, fn() => $file->rename("nope/f.txt"));
    SmokeTest::expectException(BadArgumentException::class, fn() => $file->rename("other/"));
    SmokeTest::expectException(BadArgumentException::class, fn() => $file->rename(""));
    assert($file->getFilepath() === "{$base}/sub/e.txt");

    # move() keeps the name, and only creates the directory if asked to.
    $file->move("..");
    assert($file->getFilepath() === "{$base}/e.txt" && is_file("{$base}/e.txt"));
    SmokeTest::expectException(
        FileDoesNotExistException::class,
        fn() => $file->move("{$base}/new/deeper"),
        "Set \$createIfNotExists to true"
    );
    assert(!file_exists("{$base}/new"));
    $file->move("{$base}/new/deeper", true);
    assert($file->getFilepath() === "{$base}/new/deeper/e.txt" && is_file("{$base}/new/deeper/e.txt"));
    SmokeTest::expectException(BadArgumentException::class, fn() => $file->move("{$base}/sub/taken.txt"));
    file_put_contents("{$base}/sub/e.txt", "other");
    SmokeTest::expectException(FileAlreadyExistsException::class, fn() => $file->move("{$base}/sub"));
    assert(file_get_contents("{$base}/sub/e.txt") === "other");

    # With $overwriteExistingFile, an existing file is replaced, by both rename() and move().
    $file->rename("{$base}/sub/taken.txt", true);
    assert($file->getFilepath() === "{$base}/sub/taken.txt" && file_get_contents("{$base}/sub/taken.txt") === "data");
    file_put_contents("{$base}/taken.txt", "other");
    $file->move("..", overwriteExistingFile: true);
    assert($file->getFilepath() === "{$base}/taken.txt" && file_get_contents("{$base}/taken.txt") === "data");
    assert(!file_exists("{$base}/sub/taken.txt"));

    # A directory, or a symlink to one, is never replaced, even with $overwriteExistingFile.
    mkdir("{$base}/a-dir");
    symlink("{$base}/a-dir", "{$base}/dir-link");
    mkdir("{$base}/sub/taken.txt");
    $directoryClashes = [
        fn() => $file->rename("a-dir", true),
        fn() => $file->rename("dir-link", true),
        fn() => $file->move("sub", false, true),
    ];

    foreach ($directoryClashes as $clash)
    {
        SmokeTest::expectException(FileAlreadyExistsException::class, $clash, "a directory");
    }

    assert(is_link("{$base}/dir-link") && is_dir("{$base}/a-dir") && is_file("{$base}/taken.txt"));
    rmdir("{$base}/sub/taken.txt");

    # Replacing a symlink replaces the link, not the file it points to. A broken symlink still counts as existing.
    file_put_contents("{$base}/pointed-at.txt", "keep");
    symlink("{$base}/pointed-at.txt", "{$base}/file-link");
    $file->rename("file-link", true);
    assert(!is_link("{$base}/file-link") && file_get_contents("{$base}/file-link") === "data");
    assert(file_get_contents("{$base}/pointed-at.txt") === "keep");
    symlink("{$base}/missing.txt", "{$base}/broken-link");
    SmokeTest::expectException(FileAlreadyExistsException::class, fn() => $file->rename("broken-link"));
    $file->rename("broken-link", true);
    assert(!is_link("{$base}/broken-link") && file_get_contents("{$base}/broken-link") === "data");

    # Moving a symlink over the file it points to (or the other way round), or over a hard link, is refused, as the
    # data could be lost.
    file_put_contents("{$base}/real.txt", "precious");
    symlink("{$base}/real.txt", "{$base}/to-real");
    link("{$base}/real.txt", "{$base}/hard.txt");
    $sameFileCases = [
        ["{$base}/to-real", "real.txt"],
        ["{$base}/real.txt", "to-real"],
        ["{$base}/real.txt", "hard.txt"],
    ];

    foreach ($sameFileCases as [$source, $target])
    {
        SmokeTest::expectException(
            FileAlreadyExistsException::class,
            fn() => (new File($source))->rename($target, true),
            "they are the same file"
        );
    }

    assert(!is_link("{$base}/real.txt") && file_get_contents("{$base}/real.txt") === "precious");
    assert(is_link("{$base}/to-real") && file_get_contents("{$base}/hard.txt") === "precious");

    # Only existing files can be used.
    SmokeTest::expectException(FileDoesNotExistException::class, fn() => new File("{$base}/nope.txt"));
    SmokeTest::expectException(FileDoesNotExistException::class, fn() => new File("{$base}/sub"));

    # CsvFile gets these too, and keeps working after a move.
    file_put_contents("{$base}/data.csv", "a,b\n1,2\n");
    $csvFile = new CsvFile("{$base}/data.csv", true);
    $csvFile->move("sub");
    $csvFile->rename("renamed.csv");
    assert($csvFile->getFilepath() === "{$base}/sub/renamed.csv");
    assert($csvFile->toArray() === [["a" => "1", "b" => "2"]]);
});

$smoke->run("directories and files", function () use ($dir) {
    Filesystem::mkdir("{$dir}/sub/deep");
    Filesystem::mkdir("{$dir}/sub/deep"); # already existing is fine
    $isFile = "{$dir}/sub/deep/f.txt";
    file_put_contents($isFile, "");
    SmokeTest::expectException(FilesystemException::class, fn() => Filesystem::mkdir("{$isFile}/child"));
    file_put_contents("{$dir}/sub/deep/f.txt", "abc\nxyz\n");
    Filesystem::getDirectories($dir, true);
    Filesystem::getDirContents($dir);
    SmokeTest::expectException(FileDoesNotExistException::class, fn() => Filesystem::getDirectories("{$dir}/nope"));
    SmokeTest::expectException(FileDoesNotExistException::class, fn() => Filesystem::getDirContents("{$dir}/nope"));
    # A file isn't a directory either.
    SmokeTest::expectException(
        FileDoesNotExistException::class,
        fn() => Filesystem::getDirContents("{$dir}/sub/deep/f.txt")
    );
    assert(Filesystem::getDirSize("{$dir}/sub") > 0);
    assert(Filesystem::getFileSize("{$dir}/sub/deep/f.txt") === 8.0);
    Filesystem::fileWalk("{$dir}/sub/deep/f.txt", fn(string $line) => null);
    Filesystem::fileFilter("{$dir}/sub/deep/f.txt", fn(string $line) => !str_contains($line, "x"));
    assert(file_get_contents("{$dir}/sub/deep/f.txt") === "abc\n");
    Filesystem::fileperms("{$dir}/sub/deep/f.txt");
    assert(Filesystem::getFileExtension("a.tar.gz") === "gz");
    SmokeTest::expectException(NoFileExtensionException::class, fn() => Filesystem::getFileExtension("README"));
    SmokeTest::expectException(
        NoFileExtensionException::class,
        fn() => Filesystem::getFileExtension("/var/v1.2/README")
    );
    SmokeTest::expectException(NoFileExtensionException::class, fn() => Filesystem::getFileExtension("file."));
    Filesystem::deleteDir(Filesystem::tmpDir());
    Filesystem::mkdir("{$dir}/perms", "0o750");
    assert((fileperms("{$dir}/perms") & 0777) === (0750 & ~umask()));
    SmokeTest::expectException(BadArgumentException::class, fn() => Filesystem::mkdir("{$dir}/bad", "493"));
    SmokeTest::expectException(BadArgumentException::class, fn() => Filesystem::mkdir("{$dir}/bad", "0758"));
    assert(!file_exists("{$dir}/bad"));
    SmokeTest::expectException(BadArgumentException::class, fn() => Filesystem::tmpDir("rwx"));
    assert(Filesystem::permissionsStringToInt("755") === 0755);
    assert(Filesystem::permissionsStringToInt("0o1777") === 01777);
    SmokeTest::expectException(BadArgumentException::class, fn() => Filesystem::permissionsStringToInt("0x755"));
    assert(Filesystem::lockFile("{$dir}/lock", true, false));
    Filesystem::unlockFile("{$dir}/lock");
});

$smoke->run("UTF-8 methods", function () use ($dir) {
    file_put_contents("{$dir}/u.txt", iconv("UTF-8", "WINDOWS-1251", "Привет"));
    assert(!Filesystem::isValidUtf8File("{$dir}/u.txt"));
    Filesystem::convertFileToUtf8("{$dir}/u.txt", "Windows-1251");
    assert(file_get_contents("{$dir}/u.txt") === "Привет");
    assert(Filesystem::isValidUtf8File("{$dir}/u.txt", 3));
});

$smoke->run("downloadFile", function () use ($dir) {
    assert(filesize(Filesystem::downloadFile("file:///etc/hostname")) > 0);

    # Serve a directory with PHP's built-in web server, so that HTTP can be tested without the internet.
    mkdir("{$dir}/www");
    file_put_contents("{$dir}/www/ok.txt", "downloaded content");
    $portFinder = stream_socket_server("tcp://127.0.0.1:0");
    $port = (int) substr(strrchr(stream_socket_get_name($portFinder, false), ":"), 1);
    fclose($portFinder);
    $nullDevice = ['file', '/dev/null', 'w'];
    $server = proc_open(
        [PHP_BINARY, '-S', "127.0.0.1:{$port}", '-t', "{$dir}/www"],
        [1 => $nullDevice, 2 => $nullDevice],
        $pipes
    );

    try
    {
        # Wait for the server to start listening.
        for ($i = 0; $i < 50 && !Network::isPortOpen("127.0.0.1", $port, 0.1); $i++)
        {
            usleep(100000);
        }

        $tempFilesBefore = glob(sys_get_temp_dir() . "/*");
        assert(file_get_contents(Filesystem::downloadFile("http://127.0.0.1:{$port}/ok.txt")) === "downloaded content");

        # An HTTP error must fail, rather than "downloading" the error page.
        SmokeTest::expectException(
            FilesystemException::class,
            fn() => Filesystem::downloadFile("http://127.0.0.1:{$port}/does-not-exist.txt")
        );

        # As must a server that can't be reached, which also checks that the temporary files are cleaned up.
        SmokeTest::expectException(FilesystemException::class, fn() => Filesystem::downloadFile("http://127.0.0.1:1/x"));
        $newTempFiles = array_diff(glob(sys_get_temp_dir() . "/*"), $tempFilesBefore);
        assert(count($newTempFiles) === 1, "expected only the successful download's file to be left behind");
    }
    finally
    {
        proc_terminate($server);
        proc_close($server);
    }
});

$smoke->run("zipDir / unzip", function () use ($dir) {
    mkdir("{$dir}/ziptest/sub", 0777, true);
    mkdir("{$dir}/ziptest/empty/nested-empty", 0777, true);
    file_put_contents("{$dir}/ziptest/sub/a.txt", "zipped content");
    Filesystem::zipDir("{$dir}/ziptest", "{$dir}/test.zip", false);
    Filesystem::unzip("{$dir}/test.zip", "{$dir}/unzipped", false);
    assert(file_get_contents("{$dir}/unzipped/ziptest/sub/a.txt") === "zipped content");

    # Empty directories must be kept, including ones nested inside other empty directories.
    assert(is_dir("{$dir}/unzipped/ziptest/empty/nested-empty"));

    # A relative path with a trailing slash used to put the files' full paths into the zip.
    $originalDir = getcwd();
    chdir($dir);
    Filesystem::zipDir("ziptest/", "{$dir}/relative.zip", false);
    chdir($originalDir);
    $zip = new ZipArchive();
    $zip->open("{$dir}/relative.zip");
    assert($zip->locateName("ziptest/sub/a.txt") !== false);
    assert($zip->locateName("ziptest/empty/nested-empty/") !== false);
    $zip->close();

    # ZipArchive::open() returns an error code (which is truthy) rather than false on failure, so check it's caught.
    file_put_contents("{$dir}/bad.zip", "this is not a zip file");
    SmokeTest::expectException(FilesystemException::class, fn() => Filesystem::unzip("{$dir}/bad.zip", "{$dir}/bad", false));

    # deleteDir() through a symlink used to empty the folder it points to, so it must now refuse, and touch nothing.
    symlink("{$dir}/ziptest", "{$dir}/ziplink");
    SmokeTest::expectException(BadArgumentException::class, fn() => Filesystem::deleteDir("{$dir}/ziplink"));
    SmokeTest::expectException(BadArgumentException::class, fn() => Filesystem::deleteDir("{$dir}/ziplink/"));
    assert(file_exists("{$dir}/ziptest/sub/a.txt") && is_link("{$dir}/ziplink"));

    # zipDir() with $deleteOnComplete on a symlink zips the folder, then removes only the link, keeping the folder.
    Filesystem::zipDir("{$dir}/ziplink/", "{$dir}/link.zip", true);
    assert(!is_link("{$dir}/ziplink") && file_exists("{$dir}/ziptest/sub/a.txt"));
    $linkZip = new ZipArchive();
    $linkZip->open("{$dir}/link.zip");
    assert($linkZip->locateName("ziplink/sub/a.txt") !== false);
    $linkZip->close();

    # The zip is only written when it is closed, so this checks that a failed close() is caught.
    SmokeTest::expectException(
        FilesystemException::class,
        fn() => Filesystem::zipDir("{$dir}/ziptest", "/nonexistent-dir/test.zip", false)
    );
});

# ---------------------------------------------------------------------------------------------------------------------
# Network
# ---------------------------------------------------------------------------------------------------------------------
$smoke->run("getPublicIp", function () {
    # Fake icanhazip.com's responses, so this doesn't need the internet.
    try
    {
        FakeHttpsStream::install("203.0.113.7\n");
        assert(Network::getPublicIp() === "203.0.113.7");

        # A response that isn't an IP address, such as a proxy's error page, must be rejected.
        FakeHttpsStream::install("<html>Please log in to the Wi-Fi</html>");
        SmokeTest::expectException(NetworkException::class, fn() => Network::getPublicIp());

        # As must the server not being reachable, with PHP's reason in the message rather than "unknown error".
        FakeHttpsStream::install(null);
        SmokeTest::expectException(NetworkException::class, fn() => Network::getPublicIp());

        try
        {
            Network::getPublicIp();
        }
        catch (NetworkException $e)
        {
            assert(str_contains($e->getMessage(), "Failed to open stream"));
            assert(!str_contains($e->getMessage(), "unknown error"));
        }
    }
    finally
    {
        FakeHttpsStream::uninstall();
    }
});

$smoke->run("isPrivateIp / isPortOpen / getIps", function () {
    assert(Network::isPrivateIp("10.0.0.1") && !Network::isPrivateIp("8.8.8.8"));
    assert(Network::isPrivateIp("127.0.0.1") && Network::isPrivateIp("fd00::1"));
    assert(!Network::isPrivateIp("2001:4860::8888"));
    SmokeTest::expectException(BadArgumentException::class, fn() => Network::isPrivateIp("not-an-ip"));
    SmokeTest::expectException(BadArgumentException::class, fn() => Network::isPrivateIp("[::1]"));
    assert(!Network::isPortOpen("127.0.0.1", 1, timeout: 1));
    assert(in_array("127.0.0.1", Network::getIps("lo"), true));
});

$smoke->run("sendTcpRequest", function () {
    if (function_exists("pcntl_fork"))
    {
        $server = stream_socket_server("tcp://127.0.0.1:0");
        $port = (int) substr(strrchr(stream_socket_get_name($server, false), ":"), 1);
        $childPid = pcntl_fork();

        if ($childPid === 0)
        {
            # The child process acts as the server, replying to a single request.
            $connection = stream_socket_accept($server, 5);
            fwrite($connection, "got:" . fgets($connection));
            fclose($connection);
            exit(0);
        }

        assert(Network::sendTcpRequest("127.0.0.1", $port, "hello") === "got:hello");
        pcntl_waitpid($childPid, $status);
    }
    else
    {
        echo "(skipping sendTcpRequest, as the pcntl extension isn't installed)\n";
    }
});

# ---------------------------------------------------------------------------------------------------------------------
# StringLib
# ---------------------------------------------------------------------------------------------------------------------
$smoke->run("StringLib", function () {
    assert(strlen(StringLib::generateRandomString(20)) === 20);
    SmokeTest::expectException(BadArgumentException::class, fn() => StringLib::generateRandomString(0));
    SmokeTest::expectException(BadArgumentException::class, fn() => StringLib::generateRandomString(-1));
    SmokeTest::expectException(
        BadArgumentException::class,
        fn() => StringLib::generateRandomString(5, false, false, false, false)
    );
    SmokeTest::expectException(BadArgumentException::class, fn() => StringLib::generateRandomString(3));
    assert(StringLib::base64UrlDecode(StringLib::base64UrlEncode("hi?>")) === "hi?>");
    SmokeTest::expectException(StringConversionFailedException::class, fn() => StringLib::base64UrlDecode("a"));
    assert(StringLib::startsWith("abc", "a") && StringLib::endsWith("abc", "c"));
    assert(StringLib::br2nl("a<br />b") === "a" . PHP_EOL . "b" && StringLib::nl2br("a\nb") === "a<br />b");
    assert(StringLib::nl2br("a\r\nb") === "a<br />b" && StringLib::nl2br("a\rb") === "a<br />b");
    $expected = implode(PHP_EOL, ["1", "2", "3", "4", "5<bridge>"]);
    assert(StringLib::br2nl("1<br>2<br/>3<BR />4<br class=\"x\">5<bridge>") === $expected);
    StringLib::convertLineEndings("a\r\nb");
    assert(StringLib::isRegExp("/a/") && !StringLib::isRegExp("not a regex"));
    assert(StringLib::replace("a", "b", "aa") === "bb" && StringLib::replacePairs(["a" => "b"], "aa") === "bb");
    assert(StringLib::contains("abc", "B", false));
});

# ---------------------------------------------------------------------------------------------------------------------
# CliLib
# ---------------------------------------------------------------------------------------------------------------------
$smoke->run("showProgressBar", function () {
    ob_start();
    CliLib::showProgressBar(50, 1);

    # Out of range percentages, and percentage text wider than the terminal, used to crash str_repeat().
    CliLib::showProgressBar(150, 0);
    CliLib::showProgressBar(-10, 0);
    CliLib::showProgressBar(50, 100);
    $output = ob_get_clean();

    assert(str_contains($output, "(150%) [===") && !str_contains($output, "(150%) [ "));
    assert(str_contains($output, "(-10%) [ ") && !str_contains($output, "(-10%) [="));
});

# Clean up, then report.
Filesystem::deleteDir($dir);
$failures = $smoke->getFailures();
printf("PHP %s: %d passed, %d failed\n", PHP_VERSION, $smoke->getNumPassed(), count($failures));

foreach ($failures as $failure)
{
    echo "  FAIL {$failure}\n";
}

exit(count($failures) === 0 ? 0 : 1);
