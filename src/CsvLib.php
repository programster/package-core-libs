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
     * Create a CSV file from the provided array of data. 
     * This is done in a memory efficient manner of writing one line at a time to the file 
     * rather then building a massive string and dumping the entire string to the file.
     * 
     * @param string $filepath - the path to the file that we will write the csv to, creating
     *                           if necessary.
     * @param array $rows - a collection of assosciative name/value pairs for the data to fill the
     *                      csv file. The values will be filled in in the order of the keys in
     *                      the first row.
     * @param bool $addHeader - specify whether the CSV file we should put the header row in for
     *                          the csv file. If true, we will use the keys of the first row
     *                          as we expect all keys to match.
     * @param string $delimiter - optionally specify a delimiter if you dont wish to use the comma
     * @param string $enclosure - optionally specify the enclosure if you don't wish to use "
     * @return void - all data written to the passed in filepath. Throws exception if anything
     *                goes wrong.
     * @throws Exception
     * @throws \Exception
     */
    public static function convertArrayToCsv(string $filepath, array $rows, bool $addHeader, string $delimiter = ",", string $enclosure = '"')
    {
        $fileHandle = fopen($filepath, 'w');
        
        if ($fileHandle === FALSE)
        {
            throw new Exception("Failed to open {$filepath} for writing.");
        }
        
        if (count($rows) === 0)
        {
            throw new \Exception("Cannot create CSV file with no data.");
        }
        
        $firstRow = ArrayLib::getFirstElement($rows);
        
        if (ArrayLib::isAssoc($firstRow) === FALSE)
        {
            throw new \Exception("convertArrayToCsv expects a list of assosciative arrays.");
        }
        
        $keys = array_keys($firstRow);
        
        if ($addHeader)
        {
            fputcsv($fileHandle, $keys, $delimiter, $enclosure);
        }
        
        foreach ($rows as $index => $row)
        {
            if (count($keys) != count($row))
            {
                $msg = "Cannot convert array to CSV. Number of keys: " . count($keys) . 
                       " is not the same as the number of values: " . count($row);
                
                throw new \Exception($msg);
            }
                
            $rowOfValues = array();
            
            foreach ($keys as $key)
            {
                if (!isset($row[$key]))
                {
                    throw new \Exception("row missing expected key {$key} on row {$index}");
                }
                
                $rowOfValues[] = $row[$key];
            }
            
            fputcsv($fileHandle, $rowOfValues, $delimiter, $enclosure);
        }
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
    
    
    /**
     * Perform a diff on two CSV files with a tolerance of values being different by the amount
     * specified. This can be useful in situations with floating point values where the last decimal
     * place may be different.
     * 
     * WARNING: if your CSV file has headers, you will need to remove them with a call to 
     * CsvLib::removeRows
     * 
     * @param string $filepath1 - the path to the file we are checking
     * @param array $tolerances - array of column index / tolerance pairs. For this function, 
     *                            the columns indexes start from 1, not 0.
     * @param bool $compareOtherColumns - optionally specify false to tell this function to ignore
     *                                    all the other columns and only compare the ones specified
     *                                    in the tolerances array.
     * @param type $delimiter - optionally specify the delimiter to pass to fgetcsv (comma default)
     * @param string $enclosure - optionally specify the enclosure to pass to fgetcsv (default: ")
     * @return bool - true if the files are the same, false if different.
     * @throws \Exception
     */
    public static function diffTolerance($filepath1, $filepath2, array $tolerances, $compareOtherColumns = true, $delimiter=",", $enclosure='"')
    {
        $hasFailed = false;
        $humanRowNumber = 1;
        $fileHandle1 = fopen($filepath1, "r");
        $fileHandle2 = fopen($filepath2, "r");
        
        if (!$fileHandle1 || !$fileHandle2)
        {
            throw new \Exception("diffTolerance: Failed to open CSV files for comparison.");
        }
        
        while (!feof($fileHandle1) && $hasFailed === FALSE)
        {
            $lineArray1 = fgetcsv($fileHandle1, 0, $delimiter, $enclosure);
            $lineArray2 = fgetcsv($fileHandle2, 0, $delimiter, $enclosure);
            
            if ($lineArray1)
            {
                foreach ($lineArray1 as $index => $value1)
                {
                    $humanColumnNumber = $index + 1;
                    
                    if (!isset($lineArray2[$index]))
                    {
                        throw new \Exception("decimalDiff: rows do not have the same column count.");
                    }
                    
                    $value2 = $lineArray2[$index];
                    
                    if ($compareOtherColumns || (isset($tolerances[$humanColumnNumber])))
                    {
                        if (is_numeric($value1) && is_numeric($value2))
                        {
                            $numericDifference = abs($value1 - $value2);
                            
                            if (isset($tolerances[$humanColumnNumber]))
                            {
                                if ($numericDifference > $tolerances[$humanColumnNumber])
                                {
                                    $hasFailed = TRUE;
                                }
                            }
                            else
                            {
                                if ($numericDifference > 0) // can't use !== on the values as 1.100 !== 1.1
                                {
                                    $hasFailed = TRUE;
                                }
                            }
                        }
                        else
                        {
                            // comparing non numeric values...
                            if (isset($tolerances[$humanColumnNumber]))
                            {
                                $msg = "diffTolerance: Trying to perform numeric tolerance " . 
                                       "diff on non numeric column. Column: $humanColumnNumber " . 
                                       "Row: $humanRowNumber";
                                
                                throw new \Exception($msg);
                            }
                        }
                    }
                    else
                    {
                        // Skip this column as we don't wish to check it.
                    }
                }
            }
            
            $humanRowNumber++;
        }
        
        fclose($fileHandle1);
        fclose($fileHandle2);
        
        $passed = !$hasFailed;
        return $passed;
    }
    
    
    /**
     * Merges multiple csv files together in a memory efficient way.
     * This will gracefully handle cases where the files may or may not have
     * ended with an endline.
     * This will also gracefully handle keeping the headers from the first file
     * (if hasHeaders is set to true), and ignoring headers in the subsequent files.
     * WARNING - all files need to either have headers or not, not both
     * @param array $filepaths - array of filepaths to csv files we wish to merge
     * @param string $mergedFilepath - where to write the merged file. This will overwrite
     *                                 any existing file if there is one there already.
     * @param bool $hasHeaders - specify if the files have headers or not.
     * @param string $delimiter - optinoally specify the delimiter being used in the files
     * @param string $enclosure - optionally specify the enclosure being used in the files
     * @throws Exception
     */
    public static function mergeFiles(array $filepaths, string $mergedFilepath, bool $hasHeaders, string $delimiter=",", string $enclosure='"')
    {
        $isFirstFile = true;
        $outputFileHandle = fopen($mergedFilepath, "w");
        
        foreach ($filepaths as $filepath)
        {
            $readHandle = fopen($filepath, "r");
            $isFirstLine = true;
            
            if ($readHandle === false)
            {
                throw new Exception("Unable to read file for merging: {$filepath}");
            }
            
            while (($fields = fgetcsv($readHandle, 0, $delimiter, $enclosure)) !== FALSE)
            {
                $copyLineAcross = false;
                
                if ($isFirstLine)
                {
                    if ($hasHeaders && $isFirstFile)
                    {
                        $copyLineAcross = true;
                    }
                    elseif ($hasHeaders == FALSE)
                    {
                        $copyLineAcross = true;
                    }
                    
                    $isFirstLine = false;
                }
                else
                {
                    $copyLineAcross = true;
                }
                
                if ($copyLineAcross)
                {
                    fputcsv($outputFileHandle, $fields, $delimiter, $enclosure);
                }
            }
            
            $isFirstFile = false;
        }
    }
}