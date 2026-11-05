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
use Programster\CoreLibs\CsvLib;
use Programster\CoreLibs\DataSizeUnitEnum;
use Programster\CoreLibs\EncryptionLib;
use Programster\CoreLibs\Exceptions\BadArgumentException;
use Programster\CoreLibs\Exceptions\DecryptionFailedException;
use Programster\CoreLibs\Exceptions\EmptyArrayException;
use Programster\CoreLibs\Exceptions\FilesystemException;
use Programster\CoreLibs\Exceptions\NoFileExtensionException;
use Programster\CoreLibs\Exceptions\PhpVersionTooLowException;
use Programster\CoreLibs\Exceptions\StringConversionFailedException;
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
     * @return void
     * @throws \Throwable - if the callback throws a different exception, it is rethrown.
     * @throws \Exception - if the callback doesn't throw at all.
     */
    public static function expectException(string $exceptionClass, callable $callback): void
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
    assert(str_starts_with(file_get_contents($diffFile), "0,0\n"));

    # Methods that rewrite the file keep its byte order mark, even when the first column is removed.
    file_put_contents("{$dir}/rewrite.csv", "{$bom}id,name\n1,x\n");
    CsvLib::removeColumns("{$dir}/rewrite.csv", [1]);
    assert(file_get_contents("{$dir}/rewrite.csv") === "{$bom}name\nx\n");
    CsvLib::removeRows("{$dir}/rewrite.csv", [2]);
    assert(file_get_contents("{$dir}/rewrite.csv") === "{$bom}name\n");

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
$smoke->run("directories and files", function () use ($dir) {
    Filesystem::mkdir("{$dir}/sub/deep");
    Filesystem::mkdir("{$dir}/sub/deep"); # already existing is fine
    $isFile = "{$dir}/sub/deep/f.txt";
    file_put_contents($isFile, "");
    SmokeTest::expectException(FilesystemException::class, fn() => Filesystem::mkdir("{$isFile}/child"));
    file_put_contents("{$dir}/sub/deep/f.txt", "abc\nxyz\n");
    Filesystem::getDirectories($dir, true);
    Filesystem::getDirContents($dir);
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

    # The zip is only written when it is closed, so this checks that a failed close() is caught.
    SmokeTest::expectException(
        FilesystemException::class,
        fn() => Filesystem::zipDir("{$dir}/ziptest", "/nonexistent-dir/test.zip", false)
    );
});

# ---------------------------------------------------------------------------------------------------------------------
# Network
# ---------------------------------------------------------------------------------------------------------------------
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
