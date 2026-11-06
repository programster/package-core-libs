<?php

/*
 * Runs the smoke tests in testing/tests/, which call (nearly) every public method in the library, and reports any that
 * fail, throw an error, or raise a warning/deprecation. There is one file of tests per class, e.g. CsvLibTest.php.
 * This is used by run-all-php-versions.sh to check the library works on every supported version of PHP, but it can
 * also be run directly: php testing/main.php
 * Exits with 0 if everything passed, or 1 if anything failed.
 */

require_once(__DIR__ . '/../vendor/autoload.php');

use Programster\CoreLibs\Filesystem;
use Programster\CoreLibs\Testing\SmokeTest;


# The tests check things with assert(), which does nothing when zend.assertions is 0 or -1, so every test would pass
# without checking anything. It can be switched from 0 to 1 here, but -1 (the usual production setting) can only be
# changed in php.ini or on the command line.
if (ini_get('zend.assertions') !== '1' && @ini_set('zend.assertions', '1') === false)
{
    echo "zend.assertions must be 1 for the tests to check anything. Run: php -d zend.assertions=1 " . __FILE__ . "\n";
    exit(1);
}

$smoke = new SmokeTest();
$dir = sys_get_temp_dir() . "/corelibs-smoke-" . getmypid();
mkdir($dir);

$testFiles = glob(__DIR__ . '/tests/*Test.php');
sort($testFiles);

foreach ($testFiles as $testFile)
{
    require $testFile;
}

# Clean up, then report. A test that fails part way through can leave files it made read-only, so a failed clean-up is
# reported as a failure, rather than stopping the results being shown.
$failures = $smoke->getFailures();

try
{
    Filesystem::deleteDir($dir);
}
catch (\Throwable $e)
{
    $failures[] = "clean-up: couldn't delete {$dir}, probably because a test above failed part way through: " .
        get_class($e) . ": " . $e->getMessage();
}

printf("PHP %s: %d passed, %d failed\n", PHP_VERSION, $smoke->getNumPassed(), count($failures));

foreach ($failures as $failure)
{
    echo "  FAIL {$failure}\n";
}

# Report checks that couldn't run here, so that a run without root doesn't look like it checked everything.
foreach (SmokeTest::getSkipped() as $skipped)
{
    echo "  SKIPPED {$skipped}\n";
}

exit(count($failures) === 0 ? 0 : 1);
