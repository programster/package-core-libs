<?php

namespace Irap\CoreLibs;


/*
 * Library for just array functions. I had thought about creating a wrapper class around the
 * array instead, but this is more flexible and doesn't require users to convert their array objects
 */


class ArrayLib
{
    /**
     * Returns true or false based on whether the provided array is an associative array or not.
     * note that this will return true if it is integer based but they index does not start at 0.
     * @param arr - the array to check
     * @return bool - true if the array is associative, false otherwise.
     */
    public static function is_assoc($arr)
    {
        return array_keys($arr) !== range(0, count($arr) - 1);
    }
    
    
    /**
     * Returns the first element of the array (useful for maps/hashtables or if you dont know)
     * @param Array $inputArray - the array we want the first element of.
     * @param bool $removeElement - optionally override to true to remove the element from the array
     * @return mixed $element - the last element of the array.
     */
    public static function get_first_element(Array &$inputArray, $removeElement=false)
    {
        if (!$removeElement)
        {
            $element = array_shift(array_values($inputArray));
        }
        else
        {
            $element = array_shift($inputArray);
        }
        
        return $element;
    }
    
    
    /**
     * Fetches the last element of an array
     * @param Array &$inputArray - the array we want the last element of.
     * @param bool removeElement - override to true if you also want to remove that element
     * @return $element - the last element of the array.
     */
    public static function get_last_element(Array &$inputArray, $removeElement=false)
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
     * Removes the specified indexes from the input array before returning it.
     * 
     * @param array $inputArray - the array we are manipulating
     * @param array $indexes - array list of indexes whose elements we wish to remove
     * @param bool $reindex - override to true if your array needs re-indexing (e.g. 0,1,2,3)
     * 
     * @return Array $outputArray - the newly generated output array.
     */
    public static function remove_indexes(Array $inputArray, Array $indexes, $reIndex=false)
    {        
        if ($reIndex)
        {
            # Was going to use array_filter here but realized user may want 'false' values.
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
     * Wrap all of elements in an array with the specified string (before and after)
     * e.g. wrapElements on array(foo,bar), "`" would create array(`foo`,`bar`)
     * @param $inputArray - the array we are going to create our wrapped array from
     * @param $wrapString - the string of characters we wish to wrap with.
     * @return Array
     */
    public static function wrap_elements($inputArray, $wrapString)
    {
        foreach ($inputArray as &$value)
        {
            $value = $wrapString . $value . $wrapString;
        }
        
        return $inputArray;
    }
}
