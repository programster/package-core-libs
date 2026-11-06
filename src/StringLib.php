<?php

/*
 * Library just for string operations.
 */

namespace Programster\CoreLibs;


use Programster\CoreLibs\Exceptions\StringConversionFailedException;
use Programster\CoreLibs\Exceptions\BadArgumentException;
use Random\Randomizer;

final class StringLib
{
    /**
     * Generates a random string that has at least one of each of the required types of characters
     * specified. E.g. if useSpecialChars is set to true (default) then the generated string will
     * have at least one special character.
     * @param int $numChars - how many characters long the string should be
     * @param bool $useSpecialChars - whether to use special characters. Defaults to true.
     * @param bool $useNumbers - whether to use numbers. Defaults to true.
     * @param bool $useUppercase - whether to use uppercase. Defaults to true.
     * @param bool $useLowercase - whether to contain lowercase letters. Defaults to true.
     * @return string - the generated random string.
     * @throws BadArgumentException - if $numChars is less than 1, all four types of character are
     * turned off, or $numChars is too short to fit one of each required type of character.
     */
    public static function generateRandomString(
        int $numChars,
        bool $useSpecialChars = true,
        bool $useNumbers = true,
        bool $useUppercase = true,
        bool $useLowercase = true
    ): string
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

        # Check these here, as otherwise Randomizer::getBytesFromString() throws a less helpful ValueError.
        if ($numChars < 1)
        {
            throw new BadArgumentException(
                "generateRandomString: \$numChars must be at least 1, but {$numChars} was given."
            );
        }

        if (count($requirements) === 0)
        {
            throw new BadArgumentException(
                "generateRandomString: at least one type of character (special characters, numbers, uppercase or " .
                "lowercase) must be turned on."
            );
        }

        if ($numChars < count($requirements))
        {
            throw new BadArgumentException(
                "It is impossible to create a string with " . count($requirements) . " different types of characters " .
                "when the string can only be {$numChars} long."
            );
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
     * @param string $haystack - the string to search in.
     * @param string $needle - the string to look for
     * @param bool $caseSensitive - whether to enforce case sensitivity or not (default true)
     * @param bool $ignoreWhiteSpace - whether to ignore white space at the ends of the inputs
     * @return bool - true if the haystack ends with the provided string. False otherwise.
     */
    public static function endsWith(
        string $haystack,
        string $needle,
        bool $caseSensitive = true,
        bool $ignoreWhiteSpace = false
    ): bool
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
     * @return bool - true if the haystack begins with the provided string. False otherwise.
     */
    public static function startsWith(
        string $haystack,
        string $needle,
        bool $caseSensitive = true,
        bool $ignoreWhiteSpace = false
    ): bool
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
     * Replaces the given string's <br> tags with newlines for text fields. This handles every way
     * of writing the tag, in any case: <br>, <br/>, <br />, <BR>, and ones with attributes such as
     * <br class="x">. A line break straight after a tag (as PHP's nl2br() writes) is treated as part
     * of it, so "a<br />\nb" gives one newline rather than two.
     * @param string $input - the input string
     * @return string - the output string that has been converted.
     */
    public static function br2nl(string $input): string
    {
        # \b stops this matching other tags that start with "br", such as <bridge>. One line break
        # straight after the tag is matched too, so that converting nl2br()'s output back doesn't give
        # every line break twice. \r\n comes first, so it is matched as one line break, not two.
        $output = preg_replace('/<br\b[^>]*>(\r\n|\n|\r)?/i', PHP_EOL, $input);
        return $output;
    }


    /**
     * My own 'extended' version of nl2br which works in a lot of cases where the standard nl2br
     * doesn't. Unlike PHP's nl2br(), this replaces the line endings with <br /> rather than
     * inserting <br /> before them. Windows (\r\n), Unix (\n) and old Mac (\r) line endings are all
     * replaced, whatever OS this is running on.
     * @param string $input - the input string to convert
     * @return string - the converted string
     */
    public static function nl2br(string $input): string
    {
        # str_replace() replaces in the order given, so "\r\n" must come first, otherwise it would become two <br />s.
        $output = str_replace(["\r\n", "\n", "\r"], '<br />', $input);
        return $output;
    }


    /**
     * Converts any newlines to the system's format. The use of " instead of ' is very important!
     * @param string $input - any string input
     * @return string - the newly reformatted string
     */
    public static function convertLineEndings(string $input): string
    {
        # This must be first as it is the most specific of the endlines.
        $output = str_replace("\r\n", "\n",  $input);

        # There are some strange cases where it is just \r
        $output = str_replace("\r",   "\n",  $output);

        # the system might be Windows!
        $output = str_replace("\n", PHP_EOL, $output);

        return $output;
    }


    /**
     * Check whether the provided string is a regexp. Reference:
     * http://stackoverflow.com/questions/8825025/test-if-a-regular-expression-is-a-valid-one-in-php
     * @param string $regexp - the string to check, including its delimiters, e.g. "/^[a-z]+$/i".
     * @return bool - true if the string is a valid regular expression, false if not.
     */
    public static function isRegExp(string $regexp): bool
    {
        $isRegExp = true;

        if (@preg_match($regexp, "Put any string in here.") === false)
        {
            $isRegExp = false;
        }

        return $isRegExp;
    }


    /**
     * This is a wrapper around strtr that enforces the use of strings instead of arrays for the
     * parameters. If you want to substitute multiple items then please use replacePairs() instead.
     * Both methods wrap around strtr instead of str_replace because I believe that the behaviour is
     * closer to what the developer would expect if they hadn't read any documentation For
     * information about how strtr may be safer than str_replace, please read the comments in
     * http://php.net/manual/en/function.strtr.php
     * @param string $search - the string to search for within the subject that needs replacing
     * @param string $replace - the string to replace with
     * @param string $subject - the string to perform substitutions in.
     * @return string - the result of converting the subject string.
     */
    public static function replace(string $search, string $replace, string $subject): string
    {
        $pairs = array(
            $search => $replace
        );

        return strtr($subject, $pairs);
    }


    /**
     * An alias for strtr (http://php.net/manual/en/function.strtr.php). This operation will perform
     * multiple substitutions in a single pass so you don't need to worry about your replacement
     * pairs clashing with each other. This is faster and simpler to understand but if you need this
     * recursive behaviour, please use the in-built str_replace method instead.
     * @param array $pairs - array of search/replace pairs to perform
     * @param string $subject - the string to perform substitutions in.
     * @return string - the result of converting the subject string.
     */
    public static function replacePairs(array $pairs, string $subject): string
    {
        return strtr($subject, $pairs);
    }


    /**
     * Find out whether the $haystack string contains the $needle string. This will use strpos
     * rather than strstr because strpos is faster and less memory intensive.
     * https://stackoverflow.com/questions/5820586/which-method-is-preferred-strstr-or-strpos
     * @param string $haystack - the string to search in.
     * @param string $needle - the string to search for.
     * @param bool $caseSensitive - whether the search is case sensitive. Defaults to true.
     * @return bool - true if does contain, false if not
     */
    public static function contains(string $haystack, string $needle, bool $caseSensitive = true): bool
    {
        if ($caseSensitive)
        {
            $pos = strpos($haystack, $needle);
        }
        else
        {
            $pos = stripos($haystack, $needle);
        }

        # Need to be careful to be type sensitive here because could return value 0 which would
        # need to return true.
        return ($pos !== FALSE);
    }


    /**
     * Create a base64 URL-encoded string for JSON web tokens etc.
     * @param string $input - the input string to encode.
     * @return string - the resulting encoded form.
     */
    public static function base64UrlEncode(string $input): string
    {
        return rtrim(strtr(base64_encode($input), '+/', '-_'), '=');
    }


    /**
     * Decode a string that was encoded with base64UrlEncode(). This is strict, and only accepts
     * exactly what base64UrlEncode() produces: the URL-safe alphabet (A-Z, a-z, 0-9, "-" and "_")
     * with no "=" padding, whitespace, or standard base64 "+" and "/" characters.
     * @param string $input - the input string to be decoded. This is marked as sensitive, so that
     * it doesn't appear in stack traces, as it is commonly a token.
     * @return string - the decoded string
     * @throws StringConversionFailedException - if the string is not one that base64UrlEncode()
     * could have produced. The string that failed can be retrieved from the exception with
     * getFailedString().
     */
    public static function base64UrlDecode(#[\SensitiveParameter] string $input): string
    {
        $paddedInput = $input;
        $padding = strlen($paddedInput) % 4;

        if ($padding > 0)
        {
            $paddedInput .= str_repeat('=', 4 - $padding);
        }

        # Use strict mode, otherwise base64_decode() silently skips any invalid characters rather than failing.
        $result = base64_decode(strtr($paddedInput, '-_', '+/'), true);

        # Even in strict mode, base64_decode() accepts things that base64UrlEncode() would never produce, such as
        # whitespace, padding, and standard base64's "+" and "/". So check that encoding the result again gives us back
        # exactly the input.
        if ($result !== false && self::base64UrlEncode($result) !== $input)
        {
            $result = false;
        }

        if ($result === false)
        {
            # Deliberately leave the input out of the message, as it could be a token, and messages end up in logs.
            throw new StringConversionFailedException(
                "Failed to base64url decode a string of length " . strlen($input) . ".",
                $input
            );
        }

        return $result;
    }
}
