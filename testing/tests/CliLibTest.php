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
