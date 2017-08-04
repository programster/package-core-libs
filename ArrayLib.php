<?php

namespace iRAP\CoreLibs;


/*
 * Library for just array functions. I had thought about creating a wrapper 
 * class around the array instead, but this is more flexible and doesn't require
 * users to convert their array objects
 */


class ArrayLib
{
    /**
     * Returns true or false based on whether the provided array is an 
     * associative array or not.
     * Note that this will return true if it is integer based but they index 
     * does not start at 0.
     * @param array arr - the array to check
     * @return bool - true if the array is associative, false otherwise.
     */
    public static function isAssoc(array $arr)
    {
        return array_keys($arr) !== range(0, count($arr) - 1);
    }
    
    
    /**
     * Returns the first element of the array (useful for maps/hashtables or if you dont know)
     * @param array $inputArray - the array we want the first element of.
     * @param bool $removeElement - optionally override to true to remove the element from the array
     * @return mixed $element - the last element of the array.
     */
    public static function getFirstElement(array &$inputArray, $removeElement=false)
    {
        if (!$removeElement)
        {
            $values = array_values($inputArray);
            $element = array_shift($values); # split over two lines to prevent a NOTICE warning.
        }
        else
        {
            $element = array_shift($inputArray);
        }
        
        return $element;
    }
    
    
    /**
     * Fetches the last element of an array
     * @param array &$inputArray - the array we want the last element of.
     * @param bool removeElement - override to true if you also want to remove that element
     * @return $element - the last element of the array.
     */
    public static function getLastElement(array &$inputArray, $removeElement=false)
    {
        if (count($inputArray) > 0)
        {
            if (!$removeElement)
            {
                $element = end($inputArray);
            }
            else
            {
                $element = array_pop($inputArray);
            }
        }
        else
        {
            throw new \Exception('inputArray has no elements');
        }
        
        return $element;
    }
    
    
    /**
     * Returns the last index in the provided array
     * @param array $inputArray - the array we want the last index of
     * @param bool $removeElement - override to true if you want to also remove the element
     * @return mixed
     */
    public static function getLastIndex(array &$inputArray, $removeElement=false)
    {
        $arrayIndexes = array_keys($inputArray);
        return self::getLastElement($arrayIndexes, $removeElement);
    }
    
    
    /**
     * Removes the specified indexes from the input array before returning it.
     * 
     * @param array $inputArray - the array we are manipulating
     * @param array $indexes - array list of indexes whose elements we wish to 
     *                         remove
     * @param bool $reIndex - override to true if your array needs re-indexing 
     *                        (e.g. 0,1,2,3)
     * 
     * @return array $outputArray - the newly generated output array.
     */
    public static function removeIndexes(array $inputArray, 
                                         array $indexes, 
                                         $reIndex=false)
    {        
        if ($reIndex)
        {
            # Was going to use array_filter here but realized user may want 
            # 'false' values.
            $outputArray = array();
            
            foreach ($inputArray as $index => $value)
            {
                if (!in_array($index, $indexes))
                {
                    $outputArray[] = $value;
                }
            }
        }
        else
        {
            foreach ($indexes as $index)
            {
                if (isset($inputArray[$index]))
                {
                    unset($inputArray[$index]);
                }
            }
            
            $outputArray = $inputArray;
        }
        
        return $outputArray;
    }
    
    
    /**
     * Wrap all of elements in an array with the string (before and after) 
     * e.g. wrapElements on array(foo,bar), "`" would create array(`foo`,`bar`)
     * @param $inputArray - array we are going to create our wrapped array from
     * @param $wrapString - string of characters we wish to wrap with.
     * @return array
     */
    public static function wrapElements($inputArray, $wrapString)
    {
        foreach ($inputArray as &$value)
        {
            $value = $wrapString . $value . $wrapString;
        }
        
        return $inputArray;
    }
    
    
    /**
     * Wrap all of values in an array for insertion into a database. This is a 
     * specific variation of the wrap_elements method that will correctly 
     * convert null values into a NULL string without quotes so that nulls get 
     * inserted into the database correctly.
     * @param $inputArray - array we are going to create our wrapped array from
     * @return array
     */
    public static function mysqliWrapValues($inputArray)
    {
        foreach ($inputArray as &$value)
        {
            if ($value !== null)
            {
                $value = "'" . $value . "'";
            }
            else
            {
                $value = "NULL";
            }
        }
        
        return $inputArray;
    }
    
    
    /**
     * Faster version of array_diff that relies on not needing to keep indexes 
     * of the missing values
     * Compares values, but returns the indexes
     * @param array $array1 - array to compare
     * @param array $array2 - array to compare
     * @return array - values in array1 but not array2
     */
    public static function fastDiff($array1, $array2)
    {
        $missingValues = array();
        $flippedArray2 = array_flip($array2); # swaps indexes and values
       
        foreach ($array1 as $value)
        {
            if (!isset($flippedArray2[$value])) 
            {
                $missingValues[] = $value;
            }
        }
        
        return $missingValues;
    }
    
    
    /**
     * Returns all the values that are in array1 and array2.
     * Relies on the values being integers or strings
     * Will only return a value once, even if it appears multiple times in the 
     * array.
     * Does not maintain indexes.
     * @param array $array1 - array of integers or strings to compare
     * @param array $array2 - array of integers or strings to compare
     * @return array - values that are in both arrays
     */
    public static function fastIntersect($array1, $array2)
    {
        $sharedValues = array();
        $flippedArray2 = array_flip($array2); # swaps indexes and values
       
        foreach ($array1 as $value)
        {
            if (isset($flippedArray2[$value])) 
            {
                $sharedValues[] = $value;
            }
        }
        
        return $sharedValues;
    }
    
    
    /**
     * Fetches an array of values for the specified indexes from the provided 
     * array.
     * All the specified indexes must be within the haystack.
     * This does NOT keep index association (e.g. returns a list of values)
     * @param array $haystack - the array we are pulling values from
     * @param array $indexes - array list of keys we want the values of.
     */
    public static function getValues(array $haystack, array $indexes)
    {
        $values = array();
        
        foreach ($indexes as $index)
        {
            if (!isset($haystack[$index]))
            {
                $msg = "index [$index] does not exist in the provided array.";
                throw new \Exception($msg);
            }
            
            $values[] = $haystack[$index];
        }
        
        return $values;
    }
    
    
    /**
     * Returns an array of "sets" that are in arr1 but not arr2 where a "set" is
     * an array of values, e.g. array(1,2,3)
     * @param array $arr1 - array to compare
     * @param array $arr2 - array to compare
     * @return array - array of sets that are in array1 but not array2
     */
    private static function setDiff(array $arr1, array $arr2)
    {
        $missingSets = array();
        
        foreach ($arr1 as $searchSet)
        {
            $found = false;
            
            foreach ($arr2 as $subArray2)
            {
                if ($subArray2 === $searchSet)
                {
                    $found = true;
                    break;
                }
            }
            
            if (!$found)
            {
                $missingSets[] = $searchSet;
            }
        }
        
        return $missingSets;
    }
    
    
    /**
     * Same as array_diff except that this returns the indexes of the values 
     * that are in array1 but not array2.
     * WARNING - this is NOT comparing the indexes themselves.
     * @param array $array1
     * @param array $array2
     * @return array - array of indexes in array1 where the values are not in 
     *                 array2
     */
    public static function indexDiff(array $array1, array $array2)
    {
        $indexes = array();
        $flippedArray2 = array_flip($array2); # swaps indexes and values
       
        foreach ($array1 as $index => $value)
        {
            if (!isset($flippedArray2[$value])) 
            {
                $indexes[] = $index;
            }
        }
        
        return $indexes;
    }
    
    
    /**
     * Remove empty elements from the provided array.
     * If the input array is not assosciative, then it will be re-indexed. 0,1,2,3 etc
     * @param array $inputArray - the array to perform actions upon.
     */
    public static function stripEmptyElements($inputArray)
    {
        $outputArray = array_filter($inputArray);
        
        if (!self::isAssoc($inputArray))
        {
            $outputArray = array_values($outputArray);
        }
        
        return $outputArray;
    }
    
    
    /**
     * Merge two assosciative arrays. The indexes will be in the order of the first array, then the second
     * @param Array $array1 - an assosciative array to merge
     * @param Array $array2 - an assosciative array to merge.
     * @param \Closure $clashHandler - callback function to return the value should keep, should we encounter
     *                                 the same index in the two arrays. This will take as params:
     *                                 [index], [array1 value], [array2 value]
     *          
     * @return array - combined assosciative array.
     */
    public static function assoc_array_merge(array $array1, array $array2, \Closure $clashHandler)
    {
        $result_array = array();
        
        foreach ($array1 as $index => $value)
        {
            if (isset($array2[$index]))
            {
                # override the value by running the clash handler callback to decide.
                $value = $clashHandler($index, $array1[$index], $array2[$index]);
                unset($array2[$index]);
            }
            
            $result_array[$index] = $value;
            
            unset($array1[$index]);
        }
        
        # Array 2 should only have indexes that were not in array 1 as clashes were unset.
        # Thus we can just merge straight in.
        foreach ($array2 as $index => $value)
        {
            $result_array[$index] = $value;
        }
        
        return $result_array;
    }
    
    
    /**
     * Wrapper around array_combine that works on a list of arrays for the
     * values instead of being given a single array for the values. The result is a 
     * list of arrays who's keys are provided by the keys parameter and the values are the 
     * corresponding values in the rows' arrays.
     * 
     * @param array $keys - list of keys to set for the rows. The length should be exactly the
     *                      same as the length of every row in the rows array.
     * @param array $rows - list of arrays that whill have array_combine performed on. Every row
     *                      in this list should have the same length as keys.
     *                      If any of the rows have indexes, these will be lost.
     * @return array - the generated array list of rows.
     */
    public static function array_combine_list(array $keys, array $rows)
    {
        $output = array();
        
        foreach ($rows as $row)
        {
            if (count($row) !== count($keys))
            {
                $msg = "array_combine_list: Number of values in one of the rows is " . 
                       "not equal to the number of keys.";
                
                throw new Exception($msg);
            }
            
            $output[] = array_combine($keys, $row);
        }
        
        return $output;
    }
    
    
    /**
     * Break an array up into a number of other arrays specified by $numberOfArrays
     * @param array $inputArray - the array to split into multiple arrays
     * @param int $numberOfArrays - the number of arrays to break up into and return
     * @param bool $useBucketFill - default true to put the first elements in the first array and 
     *                              then start filling the next array. If set to false, then 
     *                              elements will be spread across the arrays in turn. 
     *                              E.G. true  - [1,2,3,4,5] / 2 -> [ [1,2,3], [4,5] ]
     *                              E.g. false - [1,2,3,4,5] / 2 -> [ [1,3,5], [2,4] ]
     *                              
     * @param bool $preserveKeys - if true key/value assosciation is maintained in the split arrays
     * @return array - an array of the resulting arrays.
     */
    public static function split(array $inputArray, $numberOfArrays, $useBucketFill=true, $preserveKeys=false)
    {
        if ($useBucketFill)
        {
            // fill one array at a time.
            $chunkSize = ceil(count($inputArray) / $numberOfArrays);
            $result = array_chunk($result, $chunkSize, $preserveKeys);
        }
        else
        {
            // spread the elements over the arrays
            $result = array();
            
            for ($i=0; $i < $numberOfArrays; $i++)
            {
                $result[] = array();
            }
            
            $counter = 0;
            
            if ($preserveKeys)
            {
                foreach ($inputArray as $key => $value)
                {
                    $result[$counter % $numberOfArrays][$key] = $value;
                    $counter++;
                }
            }
            else
            {
                while (count($inputArray) > 0)
                {
                    $element = array_shift($inputArray);
                    $result[$counter % $numberOfArrays][] = $element;
                    $counter++;
                }
            }
        }
        
        return $result;
    }
}
