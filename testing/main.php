<?php

namespace Programster\CoreLibs\Testing;

require_once(__DIR__ . '/../vendor/autoload.php');


$tests = \Programster\CoreLibs\Filesystem::getDirContents(
    $dir=__DIR__ . '/tests',
    $recursive = true,
    $includePath = false,
    $onlyFiles = true
);


$numFailed = 0;

foreach ($tests as $testFilename)
{
    $testName = substr($testFilename, 0, -4);
    $testName = __NAMESPACE__ . "\\tests\\" . $testName;

    /* @var $testToRun AbstractTest */
    $testToRun = new $testName();
    $testToRun->runTest();

    if ($testToRun->getPassed())
    {
        print $testName . ": \e[32mPASSED\e[0m" . PHP_EOL;
    }
    else
    {
        print $testName . ": \e[31mFAILED\e[0m" . PHP_EOL;
        $numFailed++;
    }
}

# Exit with a non-zero code if any test failed, so that scripts (e.g. run-all-php-versions.sh) can detect it.
exit(($numFailed === 0) ? 0 : 1);