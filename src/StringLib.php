<?php

/*
 * Library just for string operations.
 */

namespace Programster\CoreLibs;


use Programster\CoreLibs\Exceptions\ExceptionMissingExtension;
use Random\Randomizer;

class StringLib
{
    /**
     * Generates a random string that has at least one of each of the required types of characters specified.
     * E.g. if useSpecialChars is set to true (default) then the generated string will have at least one special
     * character.
     * @param int $numChars - how many characters long the string should be
     * @param bool $useSpecialChars - whether to use special characters. Defaults to true.
     * @param bool $useNumbers - whether to use numbers. Defaults to true.
     * @param bool $useUppercase - whether to use uppercase. Defaults to true.
     * @param bool $useLowercase - whether to contain lowercase letters. Defaults to true.
     * @return string - the generated random string.
     */
    public static function generateRandomString(
        int $numChars,
        bool $useSpecialChars = true,
        bool $useNumbers = true,
        bool $useUppercase = true,
        bool $useLowercase = true
    ) : string
    {
        $lowerCase      = 'abcdefghijklmnopqrstuvwxyz';
        $numbers        = '0123456789';
        $capitalLetters = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';
        $specialChars   = '!@#$%^&*(){}[]+-/_';

        $possibleChars = "";
        $requirements = [];

        if ($useLowercase)
        {
            $possibleChars .= $lowerCase;
            $requirements['lower_case'] = $lowerCase;
        }

        if ($useUppercase)
        {
            $possibleChars .= $capitalLetters;
            $requirements['capitals'] = $capitalLetters;
        }

        if ($useNumbers)
        {
            $possibleChars .= $numbers;
            $requirements['numbers'] = $numbers;
        }

        if ($useSpecialChars)
        {
            $possibleChars .= $specialChars;
            $requirements['special_characters'] = $specialChars;
        }

        if ($numChars < count($requirements))
        {
            throw new \Exception("It is impossible to create a string with " . count($requirements) . " different types of characters when the string can only be {$numChars} long.");
        }

        $randomizer = new Randomizer();

        do
        {
            $outstandingRequirements = $requirements; #copy the array
            $token = $randomizer->getBytesFromString($possibleChars, $numChars);
            $acceptableToken = true;
            $stringArray = str_split($token);

            foreach ($stringArray as $character)
            {
                if (count($outstandingRequirements) > 0) # must recalc each time
                {
                    foreach ($outstandingRequirements as $name => $possibleCharsForRequirement)
                    {
                        if (str_contains($possibleCharsForRequirement, $character) !== FALSE)
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

            if (count($outstandingRequirements) !== 0)
            {
                $acceptableToken = false;
            }
        } while (!$acceptableToken);

        return $token;
    }


   /**
    * Checks to see if the string in $haystack ends with $needle.
    *
    * @param string haystack       - the string to search in.
    * @param string needle         - the string to look for
    * @param bool caseSensitive    - whether to enforce case sensitivity or not
    *                                (default true)
    * @param bool ignoreWhiteSpace - whether to ignore white space at the ends
    *                                of the inputs
    *
    * @return true if haystack begins with the provided string.  False otherwise.
    */
    public static function endsWith(
        string $haystack,
        string $needle,
        bool $caseSensitive = true,
        bool $ignoreWhiteSpace = false
    ) : bool
    {
        $revHaystack = strrev($haystack);
        $revNeedle   = strrev($needle);

        return self::startsWith(
            $revHaystack,
            $revNeedle,
            $caseSensitive,
            $ignoreWhiteSpace
        );
    }


    /**
     * Checks to see if the string in $haystack begins with $needle.
     * @param string $haystack - the string to search in.
     * @param string $needle - the string to look for
     * @param bool $caseSensitive - whether to enforce case sensitivity or not (default true)
     * @param bool $ignoreWhiteSpace - whether to ignore white space at the ends of the inputs
     *  functionfunction
     * @return bool - true if the haystack begins with the provided string. False otherwise.
     */
    public static function startsWith(
        string $haystack,
        string $needle,
        bool $caseSensitive = true,
        bool $ignoreWhiteSpace = false
    ) : bool
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
     * @return string - the output string that has been converted.
     */
    public static function br2nl(string $input) : string
    {
        //$output = preg_replace("/(\r\n|\n|\r)/", "", $input);
        $output = str_replace('<br />', PHP_EOL, $input);
        return $output;
    }


    /**
     * My own 'extended' version of nl2br which works in a lot of cases where
     * the standard nl2br doesnot
     * @param string $input - the input string to convert
     * @return string $output - the converted string
     */
    public static function nl2br(string $input) : string
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
    public static function convertLineEndings(string $input) : string
    {
        # This must be first as it is the most specific of the endlines.
        $output = str_replace("\r\n", "\n",  $input);

        # There are some strange cases where it is just \r
        $output = str_replace("\r",   "\n",  $output);

        # the system might be windows!
        $output = str_replace("\n", PHP_EOL, $output);

        return $output;
    }


    /**
     * Encrypt a String
     * @param String $textToEncrypt - the text to encrypt
     * @param String $key - the key to encrypt and then decrypt the message.
     * @param string $initializationVector - a 16 character string to initialize the cipher.
     *                                       this way two plaintext messages can result in different
     *                                       encrypted strings. (like a salt in hashing).
     * @return string - the encryptd form of the string
     */
    public static function encrypt(string $textToEncrypt, string $key, string $initializationVector) : string
    {
        trigger_error('Method ' . __METHOD__ . ' is deprecated. Please use the EncryptionLib instead.', E_USER_DEPRECATED);
        return EncryptionLib::encrypt($textToEncrypt, $key, $initializationVector);
    }


    /**
     * Decrypt a string that was encrypted with our encrypt method.
     * @param string $encryptedData - the encrypted text.
     * @param string $key - the decryption key/password
     * @param string $initializationVector - a 16 character string to initialize the cipher.
     * @return string - the decrypted string
     */
    public static function decrypt(string $encryptedText, string $key, string $initializationVector) : string
    {
        trigger_error('Method ' . __METHOD__ . ' is deprecated. Please use the EncryptionLib instead.', E_USER_DEPRECATED);
        return EncryptionLib::decrypt($encryptedText, $key, $initializationVector);
    }


    /**
     * Check whether the provided string is a regexp.
     * Reference:
     * http://stackoverflow.com/questions/8825025/test-if-a-regular-expression-is-a-valid-one-in-php
     */
    public static function isRegExp(string $regexp) : bool
    {
        $isRegExp = true;

        if (@preg_match($regexp, "Put any string in here.") === false)
        {
            $isRegExp = false;
        }

        return $isRegExp;
    }


    /**
     * This is an wrapper around strtr that enforces the use of strings instead of arrays for
     * the parameters. If you want to substitute multiple items then please use replacePairs()
     * instead. Both methods wrap around strtr instead of str_replace because I believe that the
     * behaviour is closer to what the developer would expect if they hadn't ready any documentation
     * For information about how strtr may be safer than str_replace, please read the comments
     * in http://php.net/manual/en/function.strtr.php
     * @param string $search - the string to search for within the subject that needs replacing
     * @param string $replace - the string to replace with
     * @param string $subject - the string to perform substitutions in.
     * @return string - the result of converting the subject string.
     */
    public static function replace(string $search, string $replace, string $subject) : string
    {
        if (is_array($search) || is_array($replace))
        {
            throw new \Exception("The search or replace parameters cannot be arrays.");
        }

        $pairs = array(
            $search => $replace
        );

        return strtr($subject, $pairs);
    }


    /**
     * An alias for strtr (http://php.net/manual/en/function.strtr.php).
     * This will operation will perform multiple substitutions in a single pass so you don't
     * need to worry about your replacement pairs clashing with each other. This is faster and
     * simpler to understand but if you need this recursive behaviour, please use the in-built
     * str_replace method instead.
     * @param string $pairs - array of search/replace pairs to perform
     * @param string $subject - the string to perform substitutions in.
     * @return string - the result of converting the subject string.
     */
    public static function replacePairs(array $pairs, string $subject) : string
    {
        return strtr($subject, $pairs);
    }


    /**
     * Find out whether the $needle string contains the $haystack string.
     * This will use strpos rather than strstr because strpos is faster and less
     * memory intensive.
     * https://stackoverflow.com/questions/5820586/which-method-is-preferred-strstr-or-strpos
     * @return bool - true if does contain, false if not
     */
    public static function contains(string $haystack, string $needle, $caseSensitive = true) : bool
    {
        if ($caseSensitive)
        {
            $pos = strpos($haystack, $needle);
        }
        else
        {
            $pos = stripos($haystack, $needle);
        }

        # Need to be careful to by type sensitive here because could return value 0 which would
        # need to return true.
        return ($pos !== FALSE);
    }
}