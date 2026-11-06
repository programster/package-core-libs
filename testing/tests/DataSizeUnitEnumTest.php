<?php

/*
 * Smoke tests for DataSizeUnitEnum. These are run by testing/main.php, which provides $smoke and $dir.
 */

use Programster\CoreLibs\DataSizeUnitEnum;

/** @var \Programster\CoreLibs\Testing\SmokeTest $smoke */
/** @var string $dir - a temporary directory for the tests to use, which is deleted afterwards. */

$smoke->run("formatBytes / convertFromBytes", function () {
    assert(DataSizeUnitEnum::formatBytes(1992294) === "1.9 MiB");
    assert(DataSizeUnitEnum::formatBytes(1048525, 1, false) === "1.0 MB");
    assert(DataSizeUnitEnum::MIB->convertFromBytes(1048576) === 1.0);
});
