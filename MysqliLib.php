<?php

namespace iRAP\CoreLibs;


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
     */
    private function generateBatchQueryCore(array $rows, $tableName, \mysqli $mysqli)
    {
        $firstRow = true;
        $dataStringRows = array(); # will hold an array list of strings like "('x', 'y', 'z')"
        
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
}
