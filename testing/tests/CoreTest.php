<?php

/*
 * Smoke tests for Core. These are run by testing/main.php, which provides $smoke and $dir.
 */

use Programster\CoreLibs\Core;
use Programster\CoreLibs\Enums\DataSizeUnitEnum;
use Programster\CoreLibs\Enums\MemoryTypeEnum;
use Programster\CoreLibs\Exceptions\BadArgumentException;
use Programster\CoreLibs\Exceptions\PhpVersionTooLowException;
use Programster\CoreLibs\Testing\SmokeTest;

/** @var \Programster\CoreLibs\Testing\SmokeTest $smoke */
/** @var string $dir - a temporary directory for the tests to use, which is deleted afterwards. */

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

