<?php

/*
 * Library for just array functions. I had thought about creating a wrapper
 * class around the array instead, but this is more flexible and doesn't require
 * users to convert their array objects
 */

namespace Programster\CoreLibs;

use Programster\CoreLibs\Exceptions\ExceptionEmptyArray;


final class ArrayLib
{
    /**
     * Returns true or false based on whether the provided array is an
     * associative array or not.
     * Note that this will return true if it is integer based but the index
     * does not start at 0.
     * An empty array is neither, so an exception is thrown for one.
     * @param array $arr - the array to check
     * @return bool - true if the array is associative, false otherwise.
     * @throws ExceptionEmptyArray - if the array has no elements.
     */
    public static function isAssoc(array $arr): bool
    {
        if (count($arr) === 0)
        {
            throw new ExceptionEmptyArray('Cannot determine whether an array that has no elements is associative.');
        }

        return array_keys($arr) !== range(0, count($arr) - 1);
    }


    /**
     * Returns the first element of the array (useful for maps/hashtables or if you don't know)
     * @param array $inputArray - the array we want the first element of.
     * @param bool $removeElement - optionally override to true to remove the element from the array. This uses
     *                              array_shift(), so the remaining numeric keys are re-indexed to start from 0 (e.g.
     *                              [5 => 'a', 9 => 'b'] becomes [0 => 'b']), whilst string keys are left as they are.
     * @return mixed - the first element of the array.
     * @throws ExceptionEmptyArray - if the array has no elements.
     */
    public static function getFirstElement(array &$inputArray, bool $removeElement=false): mixed
    {
        if (count($inputArray) === 0)
        {
            throw new ExceptionEmptyArray('Trying to get the first element of an array that has no elements.');
        }

        if (!$removeElement)
        {
            # array_first() was only added in PHP 8.5, so fall back to array_key_first() on older versions.
            $element = function_exists('array_first')
                ? array_first($inputArray)
                : $inputArray[array_key_first($inputArray)];
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
     * @param bool $removeElement - override to true if you also want to remove that element
     * @return mixed - the last element of the array.
     * @throws ExceptionEmptyArray - if the array has no elements.
     */
    public static function getLastElement(array &$inputArray, bool $removeElement=false): mixed
    {
        if (count($inputArray) > 0)
        {
            if (!$removeElement)
            {
                # array_last() was only added in PHP 8.5, so fall back to array_key_last() on older versions.
                $element = function_exists('array_last')
                    ? array_last($inputArray)
                    : $inputArray[array_key_last($inputArray)];
            }
            else
            {
                $element = array_pop($inputArray);
            }
        }
        else
        {
            throw new ExceptionEmptyArray('Trying to get the last element of an array that has no elements.');
        }

        return $element;
    }


    /**
     * Returns the last key in the provided array
     * @param array $inputArray - the array we want the last key of
     * @param bool $removeElement - override to true if you want to also remove the last element (and its key) from
     *                              the array.
     * @return int|string - the last key in the array. This is deliberately a union type, as PHP array keys can only
     *                      be integers or strings.
     * @throws ExceptionEmptyArray - if the array has no elements.
     */
    public static function getLastKey(array &$inputArray, bool $removeElement=false): int|string
    {
        $lastKey = array_key_last($inputArray);

        if ($lastKey === null)
        {
            throw new ExceptionEmptyArray('Trying to get the last key of an array that has no elements.');
        }

        if ($removeElement)
        {
            unset($inputArray[$lastKey]);
        }

        return $lastKey;
    }


    /**
     * Fetch a random element from the provided array.
     * @param array $inputArray - the array to get a random element from.
     * @param bool $removeElement - optionally set to true to have the element removed from the array as well.
     * @return mixed - a random element from the array.
     * @throws ExceptionEmptyArray - if the array has no elements.
     */
    public static function getRandomElement(array &$inputArray, bool $removeElement = false): mixed
    {
        if (count($inputArray) === 0)
        {
            throw new ExceptionEmptyArray('Trying to get a random element from an array that has no elements.');
        }

        $randomKey = ArrayLib::getRandomKey($inputArray);
        $element = $inputArray[$randomKey];

        if ($removeElement)
        {
            unset($inputArray[$randomKey]);
        }

        return $element;
    }


    /**
     * Get a random key from the provided array.
     * @param array $inputArray - the array to get a random key from.
     * @param bool $removeElement - optionally specify true to have the key (and element) removed from the array.
     * @return int|string - a random key in the array. This is deliberately a union type, as PHP array keys can only be
     *                      integers or strings.
     * @throws ExceptionEmptyArray - if the array has no elements.
     */
    public static function getRandomKey(array &$inputArray, bool $removeElement = false): int|string
    {
        if (count($inputArray) === 0)
        {
            throw new ExceptionEmptyArray('Trying to get a random key from an array that has no elements.');
        }

        $keys = array_keys($inputArray);
        $key = $keys[random_int(0, (count($keys) - 1))];

        if ($removeElement)
        {
            unset($inputArray[$key]);
        }

        return $key;
    }


    /**
     * Removes the specified indexes from the input array before returning it.
     * @param array $inputArray - the array we are manipulating
     * @param array $indexes - array list of indexes whose elements we wish to remove
     * @param bool $reIndex - override to true if your array needs re-indexing (e.g. 0,1,2,3)
     * @return array $outputArray - the newly generated output array.
     */
    public static function removeIndexes(array $inputArray, array $indexes, bool $reIndex=false): array
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
     * Wrap all of the elements in an array with the string (before and after)
     * e.g. wrapElements on array(foo,bar), "`" would create array(`foo`,`bar`)
     * @param array $inputArray - array we are going to create our wrapped array from
     * @param string $wrapString - string of characters we wish to wrap with.
     * @return array
     */
    public static function wrapElements(array $inputArray, string $wrapString): array
    {
        foreach ($inputArray as &$value)
        {
            $value = $wrapString . $value . $wrapString;
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
    public static function fastDiff(array $array1, array $array2): array
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
    public static function fastIntersect(array $array1, array $array2): array
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
     * Fetches an array of values for the specified indexes from the provided array.
     * All the specified indexes must be within the haystack.
     * This does NOT keep index association (e.g. returns a list of values)
     * @param array $haystack - the array we are pulling values from
     * @param array $indexes - array list of keys we want the values of.
     */
    public static function getValues(array $haystack, array $indexes): array
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
    private static function setDiff(array $arr1, array $arr2): array
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
    public static function indexDiff(array $array1, array $array2): array
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
     * If the input array is not associative, then it will be re-indexed. 0,1,2,3 etc
     * @param array $inputArray - the array to perform actions upon.
     */
    public static function stripEmptyElements(array $inputArray): array
    {
        $outputArray = array_filter($inputArray);

        # An empty array has nothing to re-index (and isAssoc() would throw for it).
        if (count($inputArray) > 0 && !self::isAssoc($inputArray))
        {
            $outputArray = array_values($outputArray);
        }

        return $outputArray;
    }


    /**
     * Merge two associative arrays. The indexes will be in the order of the first array, then the second
     * @param array $array1 - an associative array to merge
     * @param array $array2 - an associative array to merge.
     * @param \Closure $clashHandler - callback function to return the value we should keep, should we encounter
     *                                 the same index in the two arrays. This will take as params:
     *                                 [index], [array1 value], [array2 value]
     *
     * @return array - combined associative array.
     */
    public static function assocArrayMerge(array $array1, array $array2, \Closure $clashHandler): array
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
     * list of arrays whose keys are provided by the keys parameter and the values are the
     * corresponding values in the rows' arrays.
     *
     * @param array $keys - list of keys to set for the rows. The length should be exactly the
     *                      same as the length of every row in the rows array.
     * @param array $rows - list of arrays that will have array_combine performed on. Every row
     *                      in this list should have the same length as keys.
     *                      If any of the rows have indexes, these will be lost.
     * @return array - the generated array list of rows.
     */
    public static function arrayCombineList(array $keys, array $rows): array
    {
        $output = array();

        foreach ($rows as $row)
        {
            if (count($row) !== count($keys))
            {
                $msg = "arrayCombineList: Number of values in one of the rows is " .
                       "not equal to the number of keys.";

                throw new \Exception($msg);
            }

            $output[] = array_combine($keys, $row);
        }

        return $output;
    }


    /**
     * Divide an array into $numberOfArrays arrays, filled as equally as possible. Exactly $numberOfArrays arrays are
     * always returned, and their sizes are never more than one element apart. If there are fewer elements than
     * arrays, then some of the arrays will be empty.
     * @param array $inputArray - the array to split into multiple arrays
     * @param int $numberOfArrays - the number of arrays to break up into and return
     * @param bool $useBucketFill - default true to fill the arrays in order, so the first elements go in the first
     *                              array, the next elements in the next array, and so on. The larger arrays (if
     *                              they can't all be the same size) come first. If set to false, then elements will
     *                              be spread across the arrays in turn.
     *                              E.g. true  - [1,2,3,4,5] / 2 -> [ [1,2,3], [4,5] ]
     *                              E.g. true  - [1,2,3,4,5] / 4 -> [ [1,2], [3], [4], [5] ]
     *                              E.g. false - [1,2,3,4,5] / 2 -> [ [1,3,5], [2,4] ]
     *                              E.g. true  - [1,2] / 3       -> [ [1], [2], [] ]
     *
     * @param bool $preserveKeys - if true key/value association is maintained in the split arrays
     * @return array - an array of the resulting arrays.
     * @throws ExceptionEmptyArray - if $inputArray has no elements.
     * @throws \InvalidArgumentException - if $numberOfArrays is less than 1.
     */
    public static function split(
        array $inputArray,
        int $numberOfArrays,
        bool $useBucketFill=true,
        bool $preserveKeys=false
    ): array
    {
        if (count($inputArray) === 0)
        {
            throw new ExceptionEmptyArray('Trying to split an array that has no elements.');
        }

        if ($numberOfArrays < 1)
        {
            throw new \InvalidArgumentException(
                "split: \$numberOfArrays must be at least 1, but {$numberOfArrays} was given."
            );
        }

        $result = array_fill(0, $numberOfArrays, []);

        # For bucket fill: every array gets $baseSize elements, and the first $numExtra arrays get one more, so that
        # the sizes are never more than one apart.
        $baseSize = intdiv(count($inputArray), $numberOfArrays);
        $numExtra = count($inputArray) % $numberOfArrays;

        $arrayIndex = 0;
        $numInCurrentArray = 0;
        $counter = 0;

        foreach ($inputArray as $key => $value)
        {
            if ($useBucketFill)
            {
                # Move on to the next array once this one has all of its share.
                $currentArraySize = $baseSize + (($arrayIndex < $numExtra) ? 1 : 0);

                if ($numInCurrentArray >= $currentArraySize)
                {
                    $arrayIndex++;
                    $numInCurrentArray = 0;
                }

                $numInCurrentArray++;
            }
            else
            {
                # Deal the elements out across the arrays in turn.
                $arrayIndex = $counter % $numberOfArrays;
                $counter++;
            }

            if ($preserveKeys)
            {
                $result[$arrayIndex][$key] = $value;
            }
            else
            {
                $result[$arrayIndex][] = $value;
            }
        }

        return $result;
    }



    /**
     * Sets a variable to the specified default if it is not set within the
     * provided input array. You can think of this as overriding the default
     * if it is set in the $_REQUEST superglobal.
     *
     * @param string $key - the name of the variable if it would appear
     *                          within the $_REQUEST
     * @param mixed $defaultValue - the value to set if the var is not set
     *                              within $_REQUEST
     *
     * @return mixed - the resulting value. (default value if not set)
     */
    public static function getValueOrDefault(array $inputArray, string $key, mixed $defaultValue): mixed
    {
        $returnVar = $defaultValue;

        # Use array_key_exists() rather than isset(), so that a key that exists with a null value returns null.
        if (array_key_exists($key, $inputArray))
        {
            $returnVar = $inputArray[$key];
        }

        return $returnVar;
    }
}
