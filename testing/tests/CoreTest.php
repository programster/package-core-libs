<?php

/*
 * Smoke tests for Core. These are run by testing/main.php, which provides $smoke and $dir.
 */

use Programster\CoreLibs\Core;
use Programster\CoreLibs\Enums\DataSizeUnitEnum;
use Programster\CoreLibs\Enums\MemoryTypeEnum;
use Programster\CoreLibs\Exceptions\UnexpectedOutputException;
use Programster\CoreLibs\Exceptions\UnsupportedPlatformException;
use Programster\CoreLibs\Exceptions\BadArgumentException;
use Programster\CoreLibs\Exceptions\PhpVersionTooLowException;
use Programster\CoreLibs\Network;
use Programster\CoreLibs\Testing\SmokeTest;

/** @var \Programster\CoreLibs\Testing\SmokeTest $smoke */
/** @var string $dir - a temporary directory for the tests to use, which is deleted afterwards. */

$smoke->run("getMemory", function () {
    # The main figures work on every supported system, and make sense together.
    $total = Core::getMemory(MemoryTypeEnum::TOTAL, DataSizeUnitEnum::BYTES);
    $available = Core::getMemory(MemoryTypeEnum::AVAILABLE, DataSizeUnitEnum::BYTES);
    $used = Core::getMemory(MemoryTypeEnum::USED, DataSizeUnitEnum::BYTES);
    assert($total > 0 && $available > 0 && $available <= $total, "total {$total}, available {$available}");
    # Each call reads the memory again (on Windows, by starting PowerShell), and what is available changes in between,
    # so allow 5% of the total for that.
    $difference = abs(($used + $available) - $total);
    assert($difference < $total * 0.05, "used and available are {$difference} bytes from adding up to the total");
    assert(Core::getMemory(MemoryTypeEnum::AVAILABLE, DataSizeUnitEnum::GIB) > 0);
    assert(Core::getMemory(MemoryTypeEnum::SWAP_TOTAL, DataSizeUnitEnum::BYTES) >= 0);

    # The other measurements are only on Linux, and say so elsewhere.
    if (PHP_OS_FAMILY === "Linux")
    {
        Core::getMemory(MemoryTypeEnum::HUGE_PAGES_TOTAL);
    }
    else
    {
        SmokeTest::expectException(
            UnsupportedPlatformException::class,
            fn() => Core::getMemory(MemoryTypeEnum::HUGE_PAGES_TOTAL),
            "only available on Linux"
        );
    }
});

$smoke->run("getMemory reading macOS and Windows output", function () {
    # These only run on macOS and Windows, so check them here with sample output.
    $vmStat = new ReflectionMethod(Core::class, "parseVmStat");
    $swap = new ReflectionMethod(Core::class, "parseSwapUsage");
    $windows = new ReflectionMethod(Core::class, "parseWindowsMemory");

    $vmStatOutput = "Mach Virtual Memory Statistics: (page size of 16384 bytes)\n" .
        "Pages free:                                5000.\n" .
        "Pages active:                             90000.\n" .
        "Pages inactive:                           80000.\n" .
        "Pages speculative:                         2000.\n";

    $expected = ['size' => 16384, 'free' => 5000, 'inactive' => 80000, 'speculative' => 2000];
    assert($vmStat->invoke(null, $vmStatOutput) === $expected);

    $sizes = $swap->invoke(null, "total = 2048.00M  used = 1024.50M  free = 1023.50M  (encrypted)");
    assert($sizes['total'] === 2048.0 * 1024 ** 2 && $sizes['used'] === 1024.5 * 1024 ** 2);
    $sizes = $swap->invoke(null, "total = 0.00M  used = 0.00M  free = 0.00M");
    assert($sizes['total'] === 0.0);

    $windowsOutput = "TotalVisibleMemorySize=16777216\r\nFreePhysicalMemory=8388608\r\n" .
        "PageFileSize=4096\r\nPageFileUsed=100\r\n";

    $values = $windows->invoke(null, $windowsOutput);
    assert($values['TotalVisibleMemorySize'] === 16777216.0 && $values['PageFileUsed'] === 100.0);

    SmokeTest::expectException(UnexpectedOutputException::class, fn() => $vmStat->invoke(null, "nonsense"));
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
    # Windows ends lines with "\r\n", and has no cat command.
    assert(rtrim(Core::runCommand("echo hi")) === "hi");

    if (PHP_OS_FAMILY !== "Windows")
    {
        assert(Core::runCommand("echo hi") === "hi\n");
        assert(Core::runCommand("cat", "some input") === "some input");
    }

    assert(Core::getNumProcessors() > 0);
    assert(Core::getNumProcRunning("nothing-is-called-this") === 0);
    Core::setCliTitle("corelibs-smoke");
});

$smoke->run("yes/no, true/false, clamp", function () {
    assert(Core::generateYesNoString(0) === "No" && Core::generateTrueFalseString([]) === "False");
    assert(Core::clampValue(15, 10, 0) === 10);
    $clampedDate = Core::clampValue(new DateTime("2030-01-01"), new DateTime("2026-12-31"), new DateTime("2026-01-01"));
    assert($clampedDate->format("Y") === "2026");
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

$smoke->run("runCapturingWarning", function () {
    # It returns what the callback returns, and gives the warning's message, without letting it through (the test
    # runner would report a warning that got through as a failure).
    $result = Core::runCapturingWarning(fn() => file_get_contents("/nonexistent/file"), $warning);
    assert($result === false && str_contains((string)$warning, "No such file or directory"));

    # With no warning, it gives null.
    assert(Core::runCapturingWarning(fn() => "ok", $warning) === "ok" && $warning === null);

    # The previous error handler is put back afterwards, even if the callback throws.
    $handlerBefore = set_error_handler(fn() => true);
    restore_error_handler();
    $throwingCallback = fn() => throw new \RuntimeException("stop");
    SmokeTest::expectException(\RuntimeException::class, fn() => Core::runCapturingWarning($throwingCallback));
    $handlerAfter = set_error_handler(fn() => true);
    restore_error_handler();
    assert($handlerAfter === $handlerBefore, "runCapturingWarning() didn't restore the previous error handler");
});

$smoke->run("redirectUser", function () use ($dir) {
    # Without $allowExternal, anything that isn't a path on this site is refused before anything is sent.
    $otherSites = ["https://evil.example.com/", "//evil.example.com/", "/\\evil.example.com/", "evil.example.com", ""];

    foreach ($otherSites as $location)
    {
        SmokeTest::expectException(
            BadArgumentException::class,
            fn() => Core::redirectUser($location),
            "isn't a path on this site"
        );
    }

    # A path on this site, or anything with $allowExternal, sends the header and exits. That can only be seen through
    # a web server, as the CLI doesn't send headers, and exiting would end the tests.
    mkdir("{$dir}/redirect-www");
    $page = '<?php require ' . var_export(__DIR__ . '/../../vendor/autoload.php', true) . '; ' .
        'Programster\\CoreLibs\\Core::redirectUser($_GET["to"], isset($_GET["external"])); echo "did not exit";';
    file_put_contents("{$dir}/redirect-www/index.php", $page);
    $portFinder = stream_socket_server("tcp://127.0.0.1:0");
    $port = (int) substr(strrchr(stream_socket_get_name($portFinder, false), ":"), 1);
    fclose($portFinder);
    $nullDevice = ['file', SmokeTest::nullDevice(), 'w'];

    $server = proc_open(
        [PHP_BINARY, '-S', "127.0.0.1:{$port}", '-t', "{$dir}/redirect-www"],
        [1 => $nullDevice, 2 => $nullDevice],
        $pipes
    );

    $fetch = function (string $query) use ($port): array {
        $context = stream_context_create(['http' => ['follow_location' => 0, 'ignore_errors' => true]]);
        $body = file_get_contents("http://127.0.0.1:{$port}/index.php?{$query}", false, $context);
        return ['headers' => implode("\n", $http_response_header), 'body' => $body];
    };

    try
    {
        for ($i = 0; $i < 50 && !Network::isPortOpen("127.0.0.1", $port, 0.1); $i++)
        {
            usleep(100000);
        }

        $sameSite = $fetch("to=" . urlencode("/account?tab=2"));
        assert(str_contains($sameSite['headers'], "Location: /account?tab=2"));
        assert(!str_contains($sameSite['body'], "did not exit"));
        $external = $fetch("external=1&to=" . urlencode("https://other.example.com/"));
        assert(str_contains($external['headers'], "Location: https://other.example.com/"));
    }
    finally
    {
        proc_terminate($server);
        proc_close($server);
    }
});
