# Strings (`StringLib`)

`Programster\CoreLibs\StringLib` has static methods for working with strings.

- [Random strings](#random-strings)
- [UUIDs](#uuids)
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

## UUIDs
`generateUuid()` generates a UUID ([RFC 9562](https://www.rfc-editor.org/rfc/rfc9562)) of the version you give it, in
lowercase:

```php
use Programster\CoreLibs\Enums\UuidVersionEnum;

$id = StringLib::generateUuid(UuidVersionEnum::V7);    // e.g. "019a8f2e-5c1b-7d3a-9e4f-0b6c2d8a1f37"
$token = StringLib::generateUuid(UuidVersionEnum::V4); // e.g. "3b7c9e2a-1f4d-4a8b-9c6e-5d2f8a1b7e40"
```

- **`V7`** starts with the current time, to a 4096th of a millisecond, and ends with 62 random bits, so UUIDs
  generated later sort after those generated earlier, whether they're compared as strings or in binary. That makes
  them good primary keys, as each new row goes at the end of the index, rather than somewhere random in it. Within a
  process, each is always greater than the one before it, even if two are generated within the same 4096th of a
  millisecond, or the system clock goes backwards. UUIDs from different processes generated within the same 4096th of
  a millisecond can be in either order.
- **`V4`** is 122 random bits, so use it where a UUID mustn't give away when it was made, or the order things were made
  in.
- **`V1`** is for systems that expect one. It holds the time too, to a 10th of a microsecond, but doesn't sort by it.
  It ends with a random node, with its multicast bit set (as RFC 9562 allows), rather than the machine's MAC address,
  so it doesn't give away which machine made it. The node is chosen afresh in each process, including after a fork.

`isValidUuid()` checks whether a string is a UUID of any version, in its usual form: 32 hex digits, in either case, in
groups of 8, 4, 4, 4 and 12, separated by hyphens.

```php
StringLib::isValidUuid("019a8f2e-5c1b-7d3a-9e4f-0b6c2d8a1f37");   // true
StringLib::isValidUuid("019A8F2E-5C1B-7D3A-9E4F-0B6C2D8A1F37");   // true
StringLib::isValidUuid("{019a8f2e-5c1b-7d3a-9e4f-0b6c2d8a1f37}"); // false
StringLib::isValidUuid("019a8f2e-5c1b-7d3a-9e4f-0b6c2d8a1f37\n"); // false
```

Nothing else is accepted: no braces, `urn:uuid:` prefix, spaces or trailing newline, which some other libraries strip
first. A string that needs tidying before it's a UUID isn't safe to compare with UUIDs as they're stored, so tidy it
yourself first, if that's what you want.

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
