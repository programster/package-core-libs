<?php

class TestGettingTableHash extends AbstractTest
{
    public function getDescription(): string 
    {
        return "Test the fetching of a table hash.";
    }
    
    
    public function run() 
    {
        $filepath = tempnam(sys_get_temp_dir(), "temp_");
        
        $rowsOfData = [
            ['key1' => 'value1',           'key2' => null,      'key3' => 'value3'],
            ['key1' => 'some other value', 'key2' => 'hello',   'key3' => 'value4' ],
        ];
        
        $row1String = "value1#NULL#value3";
        $row2String = "some othe value#hello#value4";
        $row1hash = md5($row1String);
        $row2hash = md5($row2String);
        $rowsString = "{$row1hash},{$row2hash}";
        $expectedHash = md5($rowsString);
        
        
        // prep the database
        $mysqli = new mysqli(DB_HOST, DB_USER, DB_PASSWORD, DB_NAME, DB_PORT);
        
        $queries = array();
        
        $queries[] = "DROP TABLE IF EXISTS `test_table`";
        
        $queries[] = 
            "CREATE TABLE `test_table` (
                `key1` varchar(255) NOT NULL,
                `key2` varchar(255),
                `key3` varchar(255)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8;";
        
        $queries[] = iRAP\CoreLibs\MysqliLib::generateBatchInsertQuery(
            $rowsOfData, 
            'test_table', 
            $mysqli
        );
        
        foreach ($queries as $query)
        {
            $result = $mysqli->query($query);
            
            if ($result === FALSE)
            {
                throw new Exception("Database query failed. \n{$query}");
            }
        }
        
        try
        {
            $tableHash = iRAP\CoreLibs\MysqliLib::getTableHash($mysqli, "test_table");
            
            if ($tableHash === $expectedHash)
            {
                $this->m_passed = true;
            }
            else
            {
                print "{$tableHash} did not equal the expected hash of {$expectedHash}" . PHP_EOL;
                $this->m_passed = true;
            }
        } 
        catch (Exception $ex)
        {
            $this->m_passed = false;
        }
        
        unlink($filepath);
    }
}
