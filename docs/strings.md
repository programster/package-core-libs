# Strings (`StringLib`)

`Programster\CoreLibs\StringLib` has static methods for working with strings.

- [Random strings](#random-strings)
- [Searching](#searching)
- [Replacing](#replacing)
- [Line breaks and `<br>` tags](#line-breaks-and-br-tags)
- [URL-safe base64](#url-safe-base64)
- [Regular expressions](#regular-expressions)

## Random strings
`generateRandomString()` makes a random string with at least one of each type of character you ask for. It uses PHP's
`Random\Randomizer` with its default, cryptographically secure engine, so it is suitable for passwords and tokens.

```php
use Programster\CoreLibs\StringLib;

// 16 characters, with at least one special character, number, uppercase and lowercase letter.
$password = StringLib::generateRandomString(16);

// Just numbers, e.g. a 6 digit code: $useSpecialChars, $useNumbers, $useUppercase, $useLowercase.
$code = StringLib::generateRandomString(6, false, true, false, false);
```

The special characters are `!@#$%^&*(){}[]+-/_`. It throws a `BadArgumentException` if `$numChars` is less than 1,
every type is turned off, or `$numChars` is too short to fit one of each type asked for.

## Searching
Each takes `$caseSensitive`, which defaults to true.

```php
StringLib::contains("Hello World", "world", false);   // true
StringLib::endsWith("report.CSV", ".csv", false);     // true

// $ignoreWhiteSpace trims both strings first.
StringLib::startsWith("  Hello", "hello", false, true); // true
```

## Replacing
`replace()` and `replacePairs()` use `strtr()` rather than `str_replace()`. That makes every replacement in one pass,
so a replacement is never replaced again:

```php
StringLib::replace("cat", "dog", "the cat sat");           // "the dog sat"

// Swap "a" and "b". str_replace() would give "aa", as it replaces "a" with "b", then every "b" with "a".
StringLib::replacePairs(["a" => "b", "b" => "a"], "ab");   // "ba"
```

## Line breaks and `<br>` tags

```php
// Replace each line ending (Windows, Unix or old Mac, whatever this is running on) with <br />.
StringLib::nl2br("a\r\nb\nc\rd");  // "a<br />b<br />c<br />d"

// Replace <br> tags with "\n", however they're written.
StringLib::br2nl("one<br>two<BR />three<br class=\"x\">\nfour"); // "one\ntwo\nthree\nfour"

// Change every line ending to this system's (PHP_EOL).
$text = StringLib::convertLineEndings($text);
```

Unlike PHP's `nl2br()`, which inserts `<br />` before each line ending, `StringLib::nl2br()` replaces them. `br2nl()`
treats a line ending straight after a tag as part of it (as PHP's `nl2br()` writes them), so it doesn't double them
up.

## URL-safe base64
`base64UrlEncode()` and `base64UrlDecode()` use the URL-safe alphabet (`-` and `_` instead of `+` and `/`), with no
`=` padding, as JSON web tokens do.

```php
$token = StringLib::base64UrlEncode(random_bytes(32));
$bytes = StringLib::base64UrlDecode($token);
```

`base64UrlDecode()` is strict: it only accepts exactly what `base64UrlEncode()` produces, and throws a
`StringConversionFailedException` for anything else, including padding, whitespace, or `+` and `/`. As the string is
often a token, it is kept out of the exception's message (which often ends up in logs) and its `var_dump()`. Use
`getFailedString()` if you need it:

```php
use Programster\CoreLibs\Exceptions\StringConversionFailedException;

try
{
    $bytes = StringLib::base64UrlDecode($_GET["token"]);
}
catch (StringConversionFailedException $e)
{
    // $e->getMessage() is "Failed to base64url decode a string of length 4."
    // $e->getFailedString() is the string itself.
}
```

## Regular expressions
`isRegExp()` checks whether a string is a valid regular expression, including its delimiters:

```php
StringLib::isRegExp("/^[a-z]+$/i"); // true
StringLib::isRegExp("[a-z");         // false
```
