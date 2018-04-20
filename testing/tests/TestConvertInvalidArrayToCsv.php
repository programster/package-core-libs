<?php

class TestConvertInvalidArrayToCsv extends AbstractTest
{
    public function getDescription(): string 
    {
        return "Test that trying to convert an array of rows with different keys from each other " .
                "throws an Exception..";
    }
    
    
    public function run() 
    {
        $filepath = tempnam(sys_get_temp_dir(), "temp_");
        
        $rowsOfData = [
            ['key1' => 'value1',           'key2' => 'value2'],
            ['key1' => 'some other value', 'key3' => 'hello'],
        ];
        
        try
        {
            \iRAP\CoreLibs\CsvLib::convertArrayToCsv($filepath, $rowsOfData, TRUE);
            
            // if we get here, we didnt throw an exception and fail.
            $this->m_passed = false;
        } 
        catch (Exception $ex) 
        {
            $this->m_passed = true;
        }
        
        unlink($filepath);
    }
}
