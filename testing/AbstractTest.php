<?php

/* 
 * Abstract class all tests should extend.
 */

namespace Programster\CoreLibs\Testing;

abstract class AbstractTest
{
    protected $m_passed = false;
    abstract public function getDescription() : string;
    abstract public function run();
    public function getPassed(): bool { return $this->m_passed; }
    
    
    public function runTest()
    {
        try
        {
            $this->run();
        } 
        catch (Exception $ex) 
        {
            $this->m_passed = false;
        }
    }
}