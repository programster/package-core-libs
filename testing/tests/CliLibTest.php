<?php

/*
 * Smoke tests for CliLib. These are run by testing/main.php, which provides $smoke and $dir.
 */

use Programster\CoreLibs\CliLib;

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

