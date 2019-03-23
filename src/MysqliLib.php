<?php

namespace Programster\CoreLibs;


/*
 * A library for all your time/date calculation needs!
 */

class MysqliLib
{
    /**
     * Generates the SET part of a mysql query with the provided name/value 
     * pairs provided
     * @param pairs - assoc array of name/value pairs to go in mysql
     * @param bool $wrapWithQuotes - (optional) set to false to disable quote 
     *                               wrapping if you have already taken care of 
     *                               this.
     * @return query - the generated query string that can be appended.
     */
    public static function generateQueryPairs($pairs, \mysqli $mysqli, $wrapWithQuotes=true)
    {
        $escapedPairs = self::escapeValues($pairs, $mysqli);
        
        $query = '';
        
        foreach ($escapedPairs as $name => $value)
        {
            if ($wrapWithQuotes)
            {
                if ($value === null)
                {
                    $query .= "`" . $name . "`= NULL, ";
                }
                else
                {
                    $query .= "`" . $name . "`='" . $value . "', ";
                }
            }
            else
            {
                if ($value === null)
                {
                    $query .= $name . "= NULL, ";
                }
                else
                {
                    $query .= $name . "=" . $value . ", ";
                }
            }
        }
        
        $query = substr($query, 0, -2); # remove the last comma.
        return $query;
    }
    
    
    /**
     * Generates the Select as section for a mysql query, but does not include 
     * SELECT, directly.
     * example: $query = "SELECT " . generateSelectAs($my_columns) . ' WHERE 1';
     * @param array $columns - map of sql column names to the new names
     * @param bool $wrapWithQuotes - optionally set to false if you have taken 
     *                               care of quotation already. Useful if you 
     *                               are doing something like table1.`field1` 
     *                               instead of field1
     * @return string - the genereted query section
     */
    public static function generateSelectAsPairs(array $columns, 
                                                 $wrapWithQuotes=true)
    {
        $query = '';
        
        foreach ($columns as $column_name => $new_name)
        {
            if ($wrapWithQuotes)
            {
                $query .= '`' . $column_name . '` AS `' . $new_name . '`, ';
            }
            else
            {
                $query .= $column_name . ' AS ' . $new_name . ', ';
            }
        }
        
        $query = substr($query, 0, -2);
        
        return $query;
    }
    
    
    /**
     * Escape an array of data for the database.
     * @param array $data - the data to be escaped, either as list or name/value pairs
     * @param \mysqli $mysqli - the mysqli connection we are going to use for escaping.
     * @return array - the escaped input array.
     */
    public static function escapeValues(array $data, \mysqli $mysqli)
    {
        foreach($data as $index => $value)
        {
            if ($value !== null)
            {
                $data[$index] = mysqli_escape_string($mysqli, $value);
            }
        }
        
        return $data;
    }
    
    
    /**
     * Generates a single REPLACE query that can replace any number of rows. Replacements will
     * perform an insert except if a row with the same primary key or unique index already exists,
     * in which case an UPDATE will take place.
     * @param array $rows - the data we wish to insert/replace into the database.
     * @param string tableName - the name of the table being manipulated.
     * @param \mysqli $mysqli - the database connection that would be used for the query.
     * @return string - the generated query.
     */
    public static function generateBatchReplaceQuery(array $rows, $tableName, \mysqli $mysqli)
    {
        $query = "REPLACE " . self::generateBatchQueryCore($rows, $tableName, $mysqli);
        return $query;
    }
    
    
    /**
     * Generates a single INSERT query that for any number of rows. This is one of the most
     * efficient ways to insert a lot of data.
     * @param array $rows - the data we wish to insert/replace into the database.
     * @param string tableName - the name of the table being manipulated.
     * @param \mysqli $mysqli - the database connection that would be used for the query.
     * @return string - the generated query.
     */
    public static function generateBatchInsertQuery(array $rows, $tableName, \mysqli $mysqli)
    {
        $query = "INSERT " . self::generateBatchQueryCore($rows, $tableName, $mysqli);
        return $query;
    }
    
    
    /**
     * Helper function to generateBatchReplaceQuery and generateBatchInsertQuery which are 99% 
     * exactly the same except for the word REPLACE or INSERT.
     * @param array $rows - the data we wish to insert/replace into the database.
     * @param string tableName - the name of the table being manipulated.
     * @param \mysqli $mysqli - the database connection that would be used for the query.
     * @return string - the generated query.
     * @throws \Exception - if there is no data in the rows table and thus no query to generate.
     */
    private static function generateBatchQueryCore(array $rows, $tableName, \mysqli $mysqli)
    {
        $firstRow = true;
        $dataStringRows = array(); # will hold an array list of strings like "('x', 'y', 'z')"
        
        if (count($rows) == 0)
        {
            throw new \Exception("Cannot create batch query with no data.");
        }
        
        foreach ($rows as $row)
        {
            if ($firstRow)
            {
                $columns = array_keys($row);
                sort($columns);
                $firstRow = false;
            }
            
            ksort($row);
            $escapedRow = self::escapeValues($row, $mysqli);
            
            $quotedValues = array();
            # Need just the values, but order is very important.
            foreach ($escapedRow as $columnName => $value)
            {
                if ($value !== null)
                {
                    $quotedValues[] = "'" . $value . "'";
                }
                else
                {
                    $quotedValues[] = 'NULL';  
                }
            }
            
            $dataStringRows[] = "(" . implode(",", $quotedValues) . ")";
        }
        
        $columns = ArrayLib::wrapElements($columns, '`');
        $query = "INTO `" . $tableName . "` (" . implode(',', $columns) . ") " .
                 "VALUES " . implode(",", $dataStringRows);
        
        return $query;
    }
    
    
    /**
     * Convert a mysqli_result object into a list of rows. This is not memory efficient
     * but can save the developer from writing a loop.
     * @param mysqli_result $result
     * @return array
     */
    public static function convertResultToArrayList(\mysqli_result $result)
    {
        $list = array();
        
        while (($row = $result->fetch_assoc()) != null)
        {
            $list[] = $row;
        }
        
        return $list;
    }
    
    
    /**
     * Convert a mysqli result into a CSV file in a memory efficient manner (line by line)
     * One day this may support specifying the delimiter and decimal mark, but not today.
     * @param \mysqli_result $result
     * @param string $filepath
     * @return type
     */
    public static function convertResultToCsv(\mysqli_result $result, $filepath, $includeHeaders)
    {
        if ($result === FALSE)
        {
            throw new \Exception("Cannot convert mysql result to CSV. Result is 'FALSE'");
        }
        
        $fileHandler = fopen($filepath, 'w');
        
        if ($fileHandler === FALSE)
        {
            $msg = "Failed to open file for writing at: $filepath. " . 
                   "Does this tool have write access to that directory?";
            throw new \Exception($msg);
        }
        
        $firstRow = true;
        
        while (($row = $result->fetch_assoc()) != null)
        {
            if ($firstRow && $includeHeaders)
            {
                fputcsv($fileHandler, array_keys($row));
                $firstRow = false;
            }
            
            fputcsv($fileHandler, array_values($row));
        }
        
        fclose($fileHandler);
    }
    
    
    /**
     * Convert a mysqli result into a JSON file in a memory efficient manner (line by line)
     * This way we don't need to worry about running out of memory for large tables/results.
     * @param \mysqli_result $result - the mysqli result to convert
     * @param string $filepath - the path to the file we wish to write to (will be created/overwritten)
     * @param Bitmask $jsonOptions - any options you wish to specify, such as JSON_HEX_QUOT
     * @throws Exception
     */
    public static function convertResultToJsonFile(\mysqli_result $result, string $filepath, $jsonOptions=null)
    {
        if ($result === FALSE)
        {
            throw new \Exception("Cannot convert mysql result to JSON. Result is 'FALSE'");
        }
        
        $fileHandle = fopen($filepath, 'w');
        
        if ($fileHandle === FALSE)
        {
            $msg = "Failed to open file for writing at: $filepath. " . 
                   "Does this tool have write access to that file/directory?";
            throw new \Exception($msg);
        }
        
        fwrite($fileHandle, "[");
        $firstRow = true;
        
        while (($row = $result->fetch_assoc()) != null)
        {
            $jsonForm = json_encode($row, $jsonOptions);
            
            if ($jsonForm === FALSE)
            {
                $msg = "Failed convert row to json. " . 
                       "Perhaps you need to set the MySQL connection charset to UTF8?";
                throw new \Exception($msg);
            }
            
            if ($firstRow)
            {
                $firstRow = false;
                fwrite($fileHandle, PHP_EOL);
            }
            else
            {
                fwrite($fileHandle, "," . PHP_EOL);
            }
            
            fwrite($fileHandle, $jsonForm);
        }
        
        fwrite($fileHandle, PHP_EOL . "]"); // end the JSON array list.
        fclose($fileHandle);
    }
    
    
    /**
     * Fetches the names of the columns for a particular table.
     * @return array - the collection of column names
     */
    public static function getTableColumnNames(\mysqli $mysqliConn, string $tableName) : array
    {
        $sql = "SHOW COLUMNS FROM `{$tableName}`";
        $result = $mysqliConn->query($sql);
        
        $columns = array();
        
        while (($row = $result->fetch_array()) != null)
        {
            $columns[] = $row[0];
        }
        
        return $columns;
    }
    
    
    /**
     * Generates a hash for the entire table so we can quickly compare tables to see if they are 
     * the same. The hash will be an empty string if the table has no data.
     * @param \mysqli $mysqliConn
     * @param string $tableName - the name of the table to fetch a hash for.
     * @param array $columns - optionally specify the columns of the table to hash. If not provided,
     *                         then we will get the column names, sort alphabetically, and return
     *                         the hash of that. 
     *                         WARNING - order matters as it will change the hash and we do not 
     *                         perform any sorting by index etc.
     * @return string - the md5 of the table data.
     * @throws \Exception
     */
    public static function getTableHash(
        \mysqli $mysqliConn, 
        string $tableName, 
        array $columns = array()
    ) : string
    {
        $tableHash = "";
        
        if (count($columns) == 0)
        {
            $columns = MysqliLib::getTableColumnNames($mysqliConn, $tableName);
            sort($columns);
        }
        
        $wrappedColumnList = array();
        
        # Using coalesce to prevent null values causing sync issues as raised in the
        # NullColumnTest test. E.g. [2, null, null] and [null, 2, null] would be considered equal
        # otherwise.
        foreach ($columns as $column)
        {
            $wrappedColumnList[] = "COALESCE(`" . $column . "`, 'NULL')";
        }
        
        # This fixes an issue with using group_concat on extremely large tables (num rows)
        # and allows for tables with up to 576,460,752,303,423,000 rows 
        # (18,446,744,073,709,547,520 / 33)
        $mysqliConn->query("SET group_concat_max_len = 18446744073709547520");
        
        $primaryKeyArray = MysqliLib::fetchPrimaryKey($mysqliConn, $tableName);
        $primaryKeyString = MysqliLib::convertPrimaryKeyArrayToString($primaryKeyArray);
        $orderByString = $primaryKeyString;
        
        $query =
            "SELECT MD5(GROUP_CONCAT(MD5(CONCAT_WS('#'," . implode(',', $wrappedColumnList) . ")))) " .
            "AS `hash` " .
            "FROM `{$tableName}` ORDER BY {$orderByString}";
        
        /* @var $result mysqli_result */
        $result = $mysqliConn->query($query);
        
        if ($result !== FALSE)
        {
            $row = $result->fetch_assoc();
            
            if ($row['hash'] === NULL)
            {
                // table has no data
                $tablehash = "";
            }
            else
            {
                $tableHash = $row['hash'];
            }
        }
        else
        {
            throw new \Exception("Failed to fetch table hash");
        }
        
        return $tableHash;
    }
    
    
    /**
     * Fetch th the primary key array into a string that can be used in queries.
     * e.g. array('id') would become: "(`id`)"
     * @param array $primaryKey - the columns that act as the primary key.
     * @return string
     */
    public static function convertPrimaryKeyArrayToString(array $primaryKey) : string
    {
        $wrappedElements = \Programster\CoreLibs\ArrayLib::wrapElements($primaryKey, '`');
        $csv = implode(',', $wrappedElements);
        return $csv;
    }
    
    
    /**
     * Dynamically discovers the primary key for this table and sets this objects member variable 
     * accordingly. This returns an array
     * @param \mysqli $mysqliConn - the mysqli connection to get primary key through.
     * @param string $tableName - the name of the table to fetch the primary key for.
     * @return array - the column names that act as the primary key because it could be 
     *                 a combined key.
     * @throws Exception
     */
    public static function fetchPrimaryKey(\mysqli $mysqliConn, string $tableName) : array
    {
        $primaryKeyArray = array();
        
        $query = "show index FROM `" . $tableName . "`";
        /*@var $result mysqli_result */
        $result = $mysqliConn->query($query);
        
        if ($result === FALSE)
        {
            throw new \Exception("Failed to fetch primary key", 500);
        }
        
        while (($row = $result->fetch_assoc()) != null)
        {
            if ($row["Key_name"] === "PRIMARY")
            {
                $primaryKeyArray[] = $row["Column_name"];
            }
        }
        
        if (count($primaryKeyArray) == 0)
        {
            throw new \Exception($tableName . " does not have a primary key.");
        }
        
        return $primaryKeyArray;
    }
}
