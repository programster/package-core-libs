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
}
