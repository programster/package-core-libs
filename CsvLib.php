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
            // Skip empty lines (users may accidentally put one at the end
            // libre calc wont strip this either.
            if (count($row) == 0 || $row[0] === "")
            {
                continue;
            }
            
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
                if (count($keys) != count($row))
                {
                    $msg = "Cannot convert csv to array. Number of keys: " . count($keys) . 
                           " is not the same as the number of values: " . count($row);
                    throw new \Exception($msg);
                }
                $output[] = array_combine($keys, $row);
            }
            else
            {
                $output[] = $row;
            }
        }
        
        return $output;
    }
    
    
    /**
     * Go through the CSV file and trim the values.
     * This saves memory by working line by line rather than reading everything into memory
     * This will replace the existing file's contents, so if you need to keep that, make a copy
     * first.
     * @param sring $filepath - the path to the CSV file we are trimming
     * @param string $delimiter - the delimiter used in the CSV. E.g. ',' or ';'
     * @throws \Exception
     */
    public static function trim($filepath, $delimiter)
    {
        $tmpFile = tmpfile();
        $uploaded_fh = fopen($filepath, "r");
        
        if ($uploaded_fh)
        {
            while (!feof($uploaded_fh))
            { 
                $lineArray = fgetcsv($uploaded_fh, 0, $delimiter);
                
                if (!empty($lineArray))
                {
                    foreach ($lineArray as $index => $value)
                    {
                        $lineArray[$index] = trim($value);
                    }
                    
                    fputcsv($tmpFile, $lineArray, ",");
                }
            }
            
            fclose($uploaded_fh);
            
            $meta_data = stream_get_meta_data($tmpFile);
            $tmpFileName = $meta_data["uri"];
            rename($tmpFileName, $filepath); # replace the old upload file with new.
        }
        else
        {
            throw new \Exception("Failed to open upload file for trimming.");
        }
    }
    
    
    /**
     * Remove columns from a CSV file.
     * @param string $filepath - the path of the CSV file we are modifying.
     * @param array $columnIndexes - array of integers specifying the column indexes to remove, starting at 1
     * @param string $delimiter - optionally specify the delimiter if it isn't a comma
     * @param string $enclosure - optionally specify the enclosure if it isn't double quote
     */
    public static function removeColumns($filepath, array $columnIndexes, $delimiter=",", $enclosure='"')
    {
        $tmpFile = tmpfile();
        $fileHandle = fopen($filepath, "r");
        $lineNumber = 1;
        
        if ($fileHandle)
        {
            while (!feof($fileHandle))
            {
                $lineArray = fgetcsv($fileHandle, 0, $delimiter, $enclosure);
                
                if (!empty($lineArray))
                {
                    foreach ($columnIndexes as $columnHumanIndex)
                    {
                        $columnIndex = $columnHumanIndex - 1;
                        
                        if (!isset($lineArray[$columnIndex]))
                        {
                            $msg = "removeColumns: source CSV file does not have " . 
                                   "column: " . $columnIndex . " on line: " . $lineNumber;
                            
                            throw new \Exception($msg);
                        }
                        
                        unset($lineArray[$columnIndex]);
                    }
                    
                    fputcsv($tmpFile, $lineArray, $delimiter, $enclosure);
                }
                
                $lineNumber++;
            }
            
            fclose($fileHandle);
            
            $meta_data = stream_get_meta_data($tmpFile);
            $tmpFileName = $meta_data["uri"];
            rename($tmpFileName, $filepath); # replace the old upload file with new.
        }
        else
        {
            throw new \Exception("removeColumns: failed to open CSV file for trimming.");
        }
    }
    
    
    /**
     * Remove rows from a CSV file.
     * @param string $filepath - the path of the CSV file we are modifying.
     * @param array $rowIndexes - array of integers specifying the row numbers to remove, starting at 1
     * @param string $delimiter - optionally specify the delimiter if it isn't a comma
     * @param string $enclosure - optionally specify the enclosure if it isn't double quote
     */
    public static function removeRows($filepath, array $rowIndexes, $delimiter=",", $enclosure='"')
    {
        $tmpFile = tmpfile();
        $fileHandle = fopen($filepath, "r");
        $lineNumber = 1;
        
        if ($fileHandle)
        {
            while (!feof($fileHandle))
            {
                $lineArray = fgetcsv($fileHandle, 0, $delimiter, $enclosure);
                
                if ($lineArray)
                {
                    if (!in_array($lineNumber, $rowIndexes))
                    {
                        fputcsv($tmpFile, $lineArray, $delimiter, $enclosure);
                    }
                }
                
                $lineNumber++;
            }
            
            fclose($fileHandle);
            $meta_data = stream_get_meta_data($tmpFile);
            $tmpFileName = $meta_data["uri"];
            rename($tmpFileName, $filepath); # replace the old upload file with new.
        }
        else
        {
            throw new \Exception("removeRows: Failed to open CSV file for trimming.");
        }
    }
    
    
    /**
     * Calculate the mathematical differences between two CSV files. If this comes across string 
     * values, it will check if they are the same and put the the string in if they are, otherwise,
     * it will concatenate the two values with a | divider.
     * WARNING - this expects the two files to have the same number of columns and rows.
     * @param string $filepath1 - path to a CSV file to compare.
     * @param string $filepath2 - path to a CSV file to compare.
     * @param string $delimiter - optionally specify the delimiter if it isn't a comma
     * @param string $enclosure - optionally specify the enclosure if it isn't double quote
     * @return string - the path to the created diff CSV file.
     * @throws \Exception
     */
    public static function decimalDiff($filepath1, $filepath2, $delimiter=",", $enclosure='"')
    {
        $newFileName = tempnam(sys_get_temp_dir(), "");
        $newFileHandle = fopen($newFileName, "w");
        
        if (!$newFileHandle)
        {
            throw new \Exception("Cannot create file to store diff in.");
        }
            
        $fileHandle1 = fopen($filepath1, "r");
        $fileHandle2 = fopen($filepath2, "r");
        
        $lineNumber = 0;
        
        if ($fileHandle1 && $fileHandle2)
        {
            while (!feof($fileHandle1))
            {
                $lineArray1 = fgetcsv($fileHandle1, 0, $delimiter, $enclosure);
                $lineArray2 = fgetcsv($fileHandle2, 0, $delimiter, $enclosure);
                $resultArray = array();
                
                if ($lineArray1)
                {
                    foreach ($lineArray1 as $index => $value1)
                    {
                        if (!isset($lineArray2[$index]))
                        {
                            throw new \Exception("decimalDiff: rows do not have the same column count.");
                        }
                        
                        $value2 = $lineArray2[$index];
                        
                        if (is_numeric($value1) && is_numeric($value2))
                        {
                            $resultArray[$index] = $value1 - $value2;
                        }
                        else
                        {
                            if ($value1 === $value2)
                            {
                                $resultArray[$index] = $value1;
                            }
                            else
                            {
                                $resultArray[$index] = $value1 . "|" . $value2;
                            }
                        }
                    }
                }
                
                fputcsv($newFileHandle, $resultArray, $delimiter, $enclosure);
                $lineNumber++;
            }
            
            fclose($fileHandle1);
            fclose($fileHandle2);
            fclose($newFileHandle);
            return $newFileName;
        }
        else
        {
            throw new \Exception("decimalDiff: Failed to open CSV file for processing.");
        }
        
        return $newFileName;
    }
}