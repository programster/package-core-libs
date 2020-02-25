<?php

/*
 * Library just for string operations.
 */

namespace Programster\CoreLibs;

use function Safe\file_get_contents;
use function Safe\json_decode;
use function Safe\json_encode;


class StringLib
{
    # Thse are here because they 'belong' to the function below
    const PASSWORD_DISABLE_LOWER_CASE    = 2;
    const PASSWORD_DISABLE_UPPER_CASE    = 4;
    const PASSWORD_DISABLE_NUMBERS       = 8;
    const PASSWORD_DISABLE_SPECIAL_CHARS = 16;


    /**
     * Generates a random string. This can be useful for password generation
     * or to create a single-use token for the user to do something
     * (e.g. click an email link to register).
     *
     * @param int $numChars - how many characters long the string should be
     * @param int $charOptions - bitwise result of following vars
     *          PASSWORD_DISABLE_LOWER_CASE
     *          PASSWORD_DISABLE_UPPER_CASE
     *          PASSWORD_DISABLE_NUMBERS
     *          PASSWORD_DISABLE_SPECIAL_CHARS
     *
     * @return token - the generated string
     */
    public static function generateRandomString($numChars, $charOptions=0)
    {
        $userLowerCase   = !($charOptions & self::PASSWORD_DISABLE_LOWER_CASE);
        $useUppercase    = !($charOptions & self::PASSWORD_DISABLE_UPPER_CASE);
        $useNumbers      = !($charOptions & self::PASSWORD_DISABLE_NUMBERS);
        $useSpecialChars = !($charOptions & self::PASSWORD_DISABLE_SPECIAL_CHARS);

        $lowerCase      = str_split('abcdefghijklmnopqrstuvwxyz', 1);
        $numbers        = str_split('0123456789', 1);
        $capitalLetters = str_split('ABCDEFGHIJKLMNOPQRSTUVWXYZ', 1);
        $specialChars   = str_split('!@#$%^&*(){}[]+-/_', 1);

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

            for ($s=0; $s<$numChars; $s++)
            {
                $token .= $possibleChars[rand(0, $maxPossibleCharIndex)];
            }

            $stringArray = str_split($token);

            foreach ($stringArray as $character)
            {
                if (count($outstandingRequirements) > 0) # must recalc each time
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
    * @param string haystack       - the string to search in.
    * @param string needle         - the string to look for
    * @param bool caseSensitive    - whether to enforce case sensitivity or not
    *                                (default true)
    * @param bool ignoreWhiteSpace - whether to ignore white space at the ends
    *                                of the inputs
    *
    * @return true if haystack begins with the provided string.  False otherwise.
    */
    public static function endsWith($haystack,
                                    $needle,
                                    $caseSensitive = true,
                                    $ignoreWhiteSpace = false)
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
     *
     * @param haystack         - the string to search in.
     * @param needle           - the string to look for
     * @param caseSensitive    - whether to enforce case sensitivity or not (default true)
     * @param ignoreWhiteSpace - whether to ignore white space at the ends of the inputs
     * functionfunction
     * @return result - true if the haystack begins with the provided string. False otherwise.
     */
    public static function startsWith($haystack,
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
     * My own 'extended' version of nl2br which works in a lot of cases where
     * the standard nl2br doesnot
     * @param string $input - the input string to convert
     * @return string $output - the converted string
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
    public static function convertLineEndings($input)
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
     * @param String $message - the message to encrypt
     * @param String $key - the key to encrypt and then decrypt the message.
     * @param string $initializationVector - a 16 character string to initialize the cipher.
     *                                       this way two plaintext messages can result in different
     *                                       encrypted strings. (like a salt in hashing).
     * @return string - the encryptd form of the string
     */
    public static function encrypt(string $message, string $key, string $initializationVector) : string
    {
        $cipherMethod = "AES-256-OFB";

        return openssl_encrypt(
            $textToEncrypt,
            $cipherMethod,
            $key,
            OPENSSL_ZERO_PADDING,
            $initializationVector
        );
    }


    /**
     * Decrypt a string that was encrypted with our encrypt method.
     * @param type $encryptedData - the encrypted text.
     * @param type $key - the decryption key/password
     * @param string $initializationVector - a 16 character string to initialize the cipher.
     * @return string - the decrypted string
     */
    public static function decrypt(string $encryptedText, string $key, string $initializationVector) : string
    {
        $cipherMethod = "AES-256-OFB";
        $initializationVector = "someStaticRandwd";

        return openssl_decrypt(
            $encryptedText,
            $cipherMethod,
            $key,
            OPENSSL_ZERO_PADDING,
            $initializationVector
        );
    }


    /**
     * Fetch the file extension of a specified filename or file path. E.g. "csv" or "txt"
     * @param String $filename - the name of the file or the full file path
     * @return String - the file extension.
     */
    public static function getFileExtension($filename)
    {
        return end(explode('.', $filename));
    }


    /**
     * Check whether the provided string is a regexp.
     * Reference:
     * http://stackoverflow.com/questions/8825025/test-if-a-regular-expression-is-a-valid-one-in-php
     */
    public static function isRegExp($regexp)
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
    public static function replace($search, $replace, $subject)
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
    public static function replacePairs(array $pairs, $subject)
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