<?php

namespace Programster\CoreLibs\Testing\Tests;

class TestConvertMysqlResultToJson extends \Programster\CoreLibs\Testing\AbstractTest
{
    public function getDescription(): string 
    {
        return "Test that we can convert a mysqli result to a JSON file";
    }
    
    
    public function run() 
    {
        $rowsOfData = [
            ['key1' => 'value1', 'key2' => 'value2', 'key3' => 'value3'],
            ['key1' => 'value4', 'key2' => null,     'key3' => 'value5'],
            ['key1' => 'value6', 'key2' => 'value7', 'key3' => 'value8'],
        ];
        
        $mysqli = new \mysqli(DB_HOST, DB_USER, DB_PASSWORD, DB_NAME, DB_PORT);
        
        $queries = array();
        
        $queries[] = "DROP TABLE IF EXISTS `test_table`";
        
        $queries[] = "CREATE TABLE `test_table` (
                    `key1` varchar(255) NOT NULL,
                    `key2` varchar(255),
                    `key3` varchar(255)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8;";
        
        $queries[] = \Programster\CoreLibs\MysqliLib::generateBatchInsertQuery(
            $rowsOfData, 
            'test_table', 
            $mysqli
        );
        
        $queries[] = \Programster\CoreLibs\MysqliLib::generateBatchInsertQuery(
            $rowsOfData, 
            'test_table', 
            $mysqli
        );
        
        $queries[] = "SELECT * FROM test_table";
        
        $result = null;
        
        foreach ($queries as $query)
        {
            $result = $mysqli->query($query);
            
            if ($result === FALSE)
            {
                throw new Exception("Database query failed. \n{$query}");
            }
        }
        
        $filepath = tempnam(sys_get_temp_dir(), "temp_");
        \Programster\CoreLibs\MysqliLib::convertResultToJsonFile($result, $filepath);
        
        // cleanup
        $dropResult = $mysqli->query("DROP TABLE `test_table`");
            
        if ($dropResult === FALSE)
        {
            throw new \Exception("Failed to drop the test table. \n{$query}");
        }
        
        // If we didn't throw an exception we passed.
        if (file_get_contents($filepath) === file_get_contents(__DIR__ . '/../assets/MysqlResultToJson.json'))
        {
            $this->m_passed = true;
        }
        else
        {
            $this->m_passed = false;
        }
        
        unlink($filepath);
    }
}
