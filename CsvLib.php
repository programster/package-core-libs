<?php

namespace iRAP\CoreLibs;


/*
 * A library for handling CSV files
 */

class CsvLib
{
    /**
     * Converts a CSV file into a JSON file.
     * @param string $csvFilepath - location of the CSV file we wish to convert.
     * @param string $jsonFilepath - path to where we wish to store the result. If the file already
     *                               exists, then it will be overwritten. If not then it will be
     *                               created.
     * @param bool $compressed - if true then this will have no line endings or padding, if false
     *                           then the outputted json will be much easier to read by humans.
     */
    public static function convertCsvToJson($csvFilepath, $jsonFilepath, $compressed)
    {
        $lines = file($csvFilepath);
        $jsonFile = fopen($jsonFilepath, "w");
        $headers = array();
        
        if ($compressed)
        {
            fwrite($jsonFile, "[");
        }
        else
        {
            fwrite($jsonFile, "[" . PHP_EOL); 
        }
        
        
        foreach ($lines as $lineIndex => $line)
        {
            if ($lineIndex === 0)
            {
                # this is the header line
                $headers = str_getcsv($line);
            }
            else
            {
                $data = str_getcsv($line);
                $obj = new \stdClass();
                
                foreach ($headers as $headerIndex => $header)
                {
                    $obj->$header = $data[$headerIndex];
                }
                
                if ($compressed)
                {
                    $jsonString = json_encode($obj, JSON_UNESCAPED_SLASHES);
                }
                else
                {
                    $jsonString = json_encode($obj, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
                }
                
                if (!$compressed)
                {
                    $jsonStringLines = explode(PHP_EOL, $jsonString);
                    
                    foreach ($jsonStringLines as $jsonStringLineIndex => $line)
                    {
                        $jsonStringLines[$jsonStringLineIndex] = "    " . $line;
                    }
                    
                    $jsonString = implode(PHP_EOL, $jsonStringLines);
                }
                
                if ($lineIndex > 1)
                {
                    if ($compressed)
                    {
                        $jsonString = "," . $jsonString;
                    }
                    else
                    {
                        $jsonString = "," . PHP_EOL . $jsonString;
                    }
                    
                }
                
                fwrite($jsonFile, $jsonString); 
            }
        }
        
        fwrite($jsonFile, "]"); 
    }
    
    
    /**
     * Loops through the CSV file and puts the data into an array. You can specify whether
     * the CSV has a header and if keys is not provided then we will use these as indexes. If there 
     * is no header and no keys, then an array list of rows is returned. If keys are provided then
     * these are always used as the indexes even if it has to override the header.
     * WARNING - this can be a memory hog.
     * @param string $filepath - the path to the file, including the name.
     * @param bool $hasHeader - specify whether the CSV file has a header which it will have to
     *                          skip for values, and may or may not be used for indexes of the
     *                          rows depending on whether $keys is provided.
     * @param array $keys - optional parameter to specify the indexes to use in the rows. If not
     *                      provided and there is a header, then we will use the header columns
     *                      as indexes.
     *                        
     * @return array - an array list of indexed rows that the CSV has been converted into.
     */
    public static function convertCsvToArray($filepath, $hasHeader, $keys=null)
    {
        $file = fopen($filepath, 'r');
        $output = array();
        $firstRow = true;
        
        while ($row = fgetcsv($file))
        {
            if ($firstRow)
            {
                $firstRow = false;
                
                if ($hasHeader)
                {
                    if ($keys == null)
                    {
                        $keys = $row;
                    }
                    
                    continue;
                }
            }
            
            if ($keys != null)
            {
                $output[] = array_combine($keys, $row);
            }
            else
            {
                $output[] = $row;
            }
        }
        
        return $output;
    }
}
