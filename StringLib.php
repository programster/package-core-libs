<?php

namespace Irap\CoreLibs;


/*
 * Library just for string operations.
 */

 class StringLib
 {
    # Thse are here because they 'belong' to the function below
    const PASSWORD_DISABLE_LOWER_CASE    = 2;
    const PASSWORD_DISABLE_UPPER_CASE    = 4;
    const PASSWORD_DISABLE_NUMBERS       = 8;
    const PASSWORD_DISABLE_SPECIAL_CHARS = 16;

    /**
     * Generates a random string. This can be useful for password generation or to create a 
     * single-use token for the user to do something (e.g. click an email link to register).
     * those settings and copying it to the users clipboard as well as returning it.
     * 
     * @param numberOfChars - how many characters long the string should be
     * @param char_options - any optional bitwise parameters to disable default behaviour:
     *          PASSWORD_DISABLE_LOWER_CASE
     *          PASSWORD_DISABLE_UPPER_CASE
     *          PASSWORD_DISABLE_NUMBERS
     *          PASSWORD_DISABLE_SPECIAL_CHARS
     *
     * @return token - the generated string
     */
    public static function generate_random_string($numberOfChars, $char_options=0)
    {
        $userLowerCase   = !($char_options & self::PASSWORD_DISABLE_LOWER_CASE);
        $useUppercase    = !($char_options & self::PASSWORD_DISABLE_UPPER_CASE); 
        $useNumbers      = !($char_options & self::PASSWORD_DISABLE_NUMBERS);
        $useSpecialChars = !($char_options & self::PASSWORD_DISABLE_SPECIAL_CHARS);

        $lowerCase = array('a','b','c','d','e','f','g','h','i','j','k','l','m','n','o','p','q',
                           'r','s','t','u','v','w','x','y','z');

        $numbers = array('0', '1','2','3','4','5','6','7','8','9');

        $capitalLetters = array('A','B','C','D','E','F','G','H','I','J','K','L','M','N','O','P','Q',
                                'R','S','T','U','V','W','X','Y','Z');

        $specialChars = array('!','@','#','$','%','^','&','*','(',')','{','}','[',']','+','-','/',
                              '_');


        $possibleChars = array();

        if ($userLowerCase)
        {
            $possibleChars = array_merge($possibleChars, $lowerCase);
            $requirements['lower_case'] = $lowerCase;
        }

        if ($useUppercase)
        {
            $possibleChars = array_merge($possibleChars, $capitalLetters);
            $requirements['capitals'] = $capitalLetters;
        }

        if ($useNumbers)
        {
            $possibleChars = array_merge($possibleChars, $numbers);
            $requirements['numbers'] = $numbers;
        }

        if ($useSpecialChars)
        {
            $possibleChars = array_merge($possibleChars, $specialChars);
            $requirements['special_characters'] = $specialChars;
        }

        $acceptableToken = false;

        while (!$acceptableToken)
        {
            $outstandingRequirements = $requirements; #copy the array
            $token = '';
            $acceptableToken = true;
            $maxPossibleCharIndex = count($possibleChars) - 1;
            
            for ($s=0; $s<$numberOfChars; $s++)
            {
                $token .= $possibleChars[rand(0, $maxPossibleCharIndex)];
            }

            $stringArray = str_split($token);
            
            foreach ($stringArray as $character)
            {
                if (count($outstandingRequirements) > 0) # must recalculate each time.
                {
                    foreach ($outstandingRequirements as $name => $arrayOfChars)
                    {
                        if (array_search($character, $arrayOfChars) !== FALSE)
                        {
                            unset($outstandingRequirements[$name]);
                            break;
                        }
                    }           
                }
                else
                {
                    # Stop parsing the token as soon as all required chars found
                    break;
                }
            }

            if (count($outstandingRequirements) != 0)
            {
                $acceptableToken = false;
            }
        }

        return $token;
    }
    
    
   /**
    * Checks to see if the string in $haystack ends with $needle.
    * 
    * @param haystack         - the string to search in.
    * @param needle           - the string to look for
    * @param caseSensitive    - whether to enforce case sensitivity or not (default true)
    * @param ignoreWhiteSpace - whether to ignore white space at the ends of the inputs
    * 
    * @return true if haystack begins with the provided string.  False otherwise.
    */
    public static function ends_with($haystack, 
                                    $needle, 
                                    $caseSensitive = true, 
                                    $ignoreWhiteSpace = false)
    {
        //Reverse our input vars
        $revHaystack = strrev($haystack);
        $revNeedle = strrev($needle);
        
        return self::starts_with($revHaystack, $revNeedle, $caseSensitive, $ignoreWhiteSpace);
    }
    
    
    /**
     * Checks to see if the string in $haystack begins with $needle.
     * 
     * @param haystack         - the string to search in.
     * @param needle           - the string to look for
     * @param caseSensitive    - whether to enforce case sensitivity or not (default true)
     * @param ignoreWhiteSpace - whether to ignore white space at the ends of the inputs
     * functionfunction
     * @return result - true if the haystack begins with the provided string. False otherwise.
     */
    public static function starts_with($haystack, 
                                      $needle, 
                                      $caseSensitive = true, 
                                      $ignoreWhiteSpace = false)
    {       
        $result = false;
        
        if ($caseSensitive == false) //Reduce to lower case if required.
        {
            $haystack = strtolower($haystack);
            $needle = strtolower($needle);
        }
        
        if ($ignoreWhiteSpace)
        {
            $haystack = trim($haystack);
            $needle = trim($needle);
        }
        
        if (strpos($haystack, $needle) === 0)
        {
            $result = true;
        }
        
        return $result;
    }


    /**
     * Replaces the given string's <br> tags with newlines for textfields.
     * @param $input - the input string
     * @return output - the output string that has been converted.
     */
    public static function br2nl($input) 
    {
        //$output = preg_replace("/(\r\n|\n|\r)/", "", $input);
        $output = str_replace('<br />', PHP_EOL, $input);
        return $output;
    }
    
    
    /**
     * My own 'extended' version of nl2br which works in a lot of cases where the standard nl2br does
     * not
     * @param type $input - the input string to convert
     * @return $output - the newly converted string
     */
    public static function nl2br($input)
    {
        $output = str_replace(PHP_EOL, '<br />', $input);
        $output = str_replace("\r\n", '<br />', $output);
        return $output;
    }
    
    /**
     * Converts any newlines to the systems format.
     * The use of " instead of ' is very important!
     * @param $input - any string input
     * @return $output - the newly reformatted string
     */
    public static function convert_new_lines($input)
    {
        # This must be first as it is the most specific of the endlines.
        $output = str_replace("\r\n", "\n",  $input);
        
        # There are some strange cases where it is just \r
        $output = str_replace("\r",   "\n",  $output);
        
        # the system might be windows!
        $output = str_replace("\n", PHP_EOL, $output);
        
        return $output;
    }
 }
