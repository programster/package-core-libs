<?php

/* 
 * 
 */

require_once(__DIR__ . '/vendor/autoload.php');
require_once(__DIR__ . '/TestSettings.php');

$classDirs = array(
    __DIR__, 
    __DIR__ . '/tests',
    __DIR__ . '/libs',
);

new \iRAP\Autoloader\Autoloader($classDirs);

$tests = iRAP\CoreLibs\Filesystem::getDirContents(
    $dir=__DIR__ . '/tests', 
    $recursive = true, 
    $includePath = false, 
    $onlyFiles = true
);


foreach ($tests as $testFilename)
{
    $testName = substr($testFilename, 0, -4);
    
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
    }
}