<?php

/*
 * Smoke tests for CliLib. These are run by testing/main.php, which provides $smoke and $dir.
 */

use Programster\CoreLibs\CliLib;
use Programster\CoreLibs\Exceptions\UnexpectedOutputException;
use Programster\CoreLibs\Testing\SmokeTest;

/** @var \Programster\CoreLibs\Testing\SmokeTest $smoke */
/** @var string $dir - a temporary directory for the tests to use, which is deleted afterwards. */

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

$smoke->run("showProgressBar finding the terminal's width", function () {
    # Windows' mode con is only run on Windows, so check reading it here, with sample output. Its names are translated,
    # so the columns are the second number, whatever they are called.
    $parseModeCon = new ReflectionMethod(CliLib::class, "parseModeConWidth");
    $parseTput = new ReflectionMethod(CliLib::class, "parseTputWidth");

    $english = "\r\nStatus for device CON:\r\n----------------------\r\n    Lines:          9001\r\n" .
        "    Columns:        120\r\n    Keyboard rate:  31\r\n    Keyboard delay: 1\r\n    Code page:      437\r\n";

    $german = "\r\nStatus von Ger\u{00E4}t CON:\r\n---------------------\r\n    Zeilen:         30\r\n" .
        "    Spalten:        100\r\n    Wiederholrate:  31\r\n";

    assert($parseModeCon->invoke(null, $english) === 120);
    assert($parseModeCon->invoke(null, $german) === 100);
    assert($parseTput->invoke(null, "132\n") === 132);

    $parseNonsense = function () use ($parseModeCon): void {
        $parseModeCon->invoke(null, "The handle is invalid.");
    };

    $parseZeroWidth = function () use ($parseTput): void {
        $parseTput->invoke(null, "0");
    };

    SmokeTest::expectException(UnexpectedOutputException::class, $parseNonsense);
    SmokeTest::expectException(UnexpectedOutputException::class, $parseZeroWidth);

    # Whatever it finds (80 when the output isn't a terminal, as here), it is a usable width.
    $getWidth = new ReflectionMethod(CliLib::class, "getTerminalWidth");
    assert($getWidth->invoke(null) > 0);
});

$smoke->run("reading a password on Windows", function () {
    # Reading a typed password on Windows needs a real console, so it can't be run here, but the PowerShell script it
    # runs can be checked: it decodes back into the script, and shows stars only when asked to.
    $build = new ReflectionMethod(CliLib::class, "buildWindowsPasswordScript");

    foreach ([true, false] as $stars)
    {
        $encoded = $build->invoke(null, $stars);
        $script = mb_convert_encoding(base64_decode($encoded, true), "UTF-8", "UTF-16LE");
        assert(str_contains($script, '$showStars = ' . ($stars ? '$true' : '$false')));
        assert(str_contains($script, "[Console]::ReadKey(\$true)") && str_contains($script, "exit 2"));
        assert(str_contains($script, "[Console]::Out.Write([Convert]::ToBase64String("));
    }
});

