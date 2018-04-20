<?php

class TestConvertArrayToCsv extends AbstractTest
{
    public function getDescription(): string 
    {
        return "Test that we can convert a collection of rows to a " . 
               "CSV file even if some of the values are null";
    }
    
    
    public function run() 
    {
        $rowsOfData = [
            ['key1' => 'value1', 'key2' => 'value2', 'key3' => 'value3'],
            ['key1' => 'value4', 'key2' => null,     'key3' => 'value5'],
            ['key1' => 'value6', 'key2' => 'value7', 'key3' => 'value8'],
        ];
        
        $filepath = tempnam(sys_get_temp_dir(), "temp_");
        \iRAP\CoreLibs\CsvLib::convertArrayToCsv($filepath, $rowsOfData, TRUE);
        // If we didn't throw an exception we passed.
        $this->m_passed = true;
        unlink($filepath);
    }
}
