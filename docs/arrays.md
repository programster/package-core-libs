# Arrays (`ArrayLib`)

`Programster\CoreLibs\ArrayLib` has static methods for working with arrays.

- [Getting an element or key](#getting-an-element-or-key)
- [Removing elements](#removing-elements)
- [Comparing arrays](#comparing-arrays)
- [Combining and merging](#combining-and-merging)
- [Splitting an array](#splitting-an-array)
- [Checking an array](#checking-an-array)
- [Reading request values](#reading-request-values)
- [Empty arrays](#empty-arrays)

## Getting an element or key
These work with any array, including ones with string keys or keys that don't start at 0, where `$array[0]` wouldn't.

| Method | Gives |
|---|---|
| `getFirstElement($array)` | The first value |
| `getLastElement($array)` | The last value |
| `getLastKey($array)` | The last key |
| `getRandomElement($array)` | A random value |
| `getRandomKey($array)` | A random key |

Each takes an optional `$removeElement`, which also removes that element from the array. That's why the array is passed
by reference, so it must be a variable, not a literal or the result of a function call:

```php
use Programster\CoreLibs\ArrayLib;

$queue = ["a" => 1, "b" => 2, "c" => 3];

// Take the first job off the queue.
$job = ArrayLib::getFirstElement($queue, true);

// $job is 1, and $queue is now ["b" => 2, "c" => 3].
```

```php
// Error: Argument #1 ($inputArray) could not be passed by reference
$first = ArrayLib::getFirstElement(["x", "y"]);
```

`getFirstElement()` with `$removeElement` uses `array_shift()`, so the remaining numeric keys are renumbered from 0
(e.g. `[5 => "a", 9 => "b"]` becomes `[0 => "b"]`), while string keys are left as they are.

`getRandomElement()` and `getRandomKey()` pick with `random_int()`, which is cryptographically secure.

## Removing elements

```php
// Remove the elements at keys 1 and 3.
ArrayLib::removeIndexes(["a", "b", "c", "d"], [1, 3]);        // [0 => "a", 2 => "c"]
ArrayLib::removeIndexes(["a", "b", "c", "d"], [1, 3], true);  // ["a", "c"], renumbered

// Remove the "empty" values, as PHP's empty() sees them: "", null, 0, "0", false and [].
ArrayLib::stripEmptyElements(["a", "", null, "b", 0, "0", []]); // ["a", "b"]
ArrayLib::stripEmptyElements(["a" => "x", "b" => "", "c" => "y"]); // ["a" => "x", "c" => "y"]
```

`stripEmptyElements()` renumbers a list (an array with keys 0, 1, 2...), and keeps the keys of any other array.

## Comparing arrays

| Method | Gives |
|---|---|
| `fastDiff($array1, $array2)` | The values in `$array1` that aren't in `$array2`, as a list |
| `indexDiff($array1, $array2)` | The *keys* in `$array1` whose values aren't in `$array2` |
| `fastIntersect($array1, $array2)` | The values that are in both arrays, each once, as a list |
| `setDiff($array1, $array2)` | The arrays in `$array1` that aren't in `$array2`, where each element is itself an array |

```php
ArrayLib::fastDiff([1, 2, 3, 4], [2, 4]);          // [1, 3]
ArrayLib::indexDiff(["x", "y", "z"], ["y"]);       // [0, 2]
ArrayLib::fastIntersect([1, 2, 3], [2, 3, 5]);     // [2, 3]
ArrayLib::setDiff([[1, 2], [3, 4], [5, 6]], [[3, 4]]); // [[1, 2], [5, 6]]
```

`fastDiff()`, `indexDiff()` and `fastIntersect()` are faster than `array_diff()` and `array_intersect()` on large
arrays, as they look the values up in a flipped copy of `$array2`, rather than comparing every pair. That means the
values must be integers or strings. `fastIntersect()` gives each shared value once, however many times it appears in
either array, in the order they first appear in `$array1`. `fastDiff()` keeps any repeats from `$array1`.

`setDiff()` compares the inner arrays strictly (with `===`), so they only match if they have the same keys and values,
of the same types, in the same order: `[1, 2]` doesn't match `[2, 1]`, and `[1]` doesn't match `["1"]`.

## Combining and merging

```php
// array_combine() for a list of rows.
$people = ArrayLib::arrayCombineList(["id", "name"], [[1, "Alice"], [2, "Bob"]]);

// [["id" => 1, "name" => "Alice"], ["id" => 2, "name" => "Bob"]]
```

`arrayCombineList()` throws a `BadArgumentException` if a row doesn't have the same number of values as there are keys.

`assocArrayMerge()` merges two associative arrays, calling a function you give it to decide the value when both
have the same key:

```php
$keepLarger = function (string|int $key, mixed $value1, mixed $value2): mixed {
    return max($value1, $value2);
};

$merged = ArrayLib::assocArrayMerge(["a" => 1, "b" => 5], ["b" => 3, "c" => 7], $keepLarger);

// ["a" => 1, "b" => 5, "c" => 7]
```

The keys come in the order of the first array, then any new ones from the second.

Other helpers:

```php
// Wrap every value, e.g. to quote column names for SQL.
ArrayLib::wrapElements(["id", "name"], "`"); // ["`id`", "`name`"]

// Get the values of some keys, in the order given. Every key must exist.
ArrayLib::getValues(["a" => 1, "b" => 2, "c" => 3], ["c", "a"]); // [3, 1]
```

`getValues()` throws a `BadArgumentException` if a key isn't in the array. A key whose value is `null` counts as being
there.

## Splitting an array
`split()` divides an array into a number of arrays, as evenly as possible, e.g. to share work out between processes.
It always gives exactly the number of arrays asked for, and their sizes are never more than one apart.

```php
ArrayLib::split([1, 2, 3, 4, 5], 2);         // [[1, 2, 3], [4, 5]]
ArrayLib::split([1, 2, 3, 4, 5], 4);         // [[1, 2], [3], [4], [5]]
ArrayLib::split([1, 2], 3);                  // [[1], [2], []]

// With $useBucketFill false, the elements are dealt out in turn instead.
ArrayLib::split([1, 2, 3, 4, 5], 2, false);  // [[1, 3, 5], [2, 4]]

// With $preserveKeys, each element keeps its key.
ArrayLib::split(["a" => 1, "b" => 2, "c" => 3], 2, true, true); // [["a" => 1, "b" => 2], ["c" => 3]]
```

It throws an `EmptyArrayException` for an empty array, and a `BadArgumentException` if `$numberOfArrays` is less than 1.

## Checking an array
`isAssoc()` says whether an array is associative, rather than a list:

```php
ArrayLib::isAssoc([1, 2]);              // false
ArrayLib::isAssoc(["a" => 1]);          // true
ArrayLib::isAssoc([1 => "a", 2 => "b"]); // true, as the keys don't start at 0
```

An empty array is neither, so it throws an `EmptyArrayException`. If you only need to know whether an array is a list,
PHP's own `array_is_list()` is true for an empty one.

## Reading request values
`getValueOrDefault()` gets a value from an array, or a default if the key isn't there:

```php
$sort = ArrayLib::getValueOrDefault($_GET, "sort", "name");
```

To get several at once, or require them, see `Core::fetchReqArgsFromArray()` and `fetchOptionalArgsFromArray()` in
[Core](core.md#request-arguments).

## Empty arrays
The methods that need at least one element (`isAssoc()`, the `get...()` methods above, and `split()`) throw an
`EmptyArrayException`, rather than returning `null` or `false`, which could be mistaken for a value that was in the
array. `EmptyArrayException` extends `BadArgumentException`, so catching either works.

```php
use Programster\CoreLibs\Exceptions\EmptyArrayException;

try
{
    $first = ArrayLib::getFirstElement($items);
}
catch (EmptyArrayException $e)
{
    $first = $defaultItem;
}
```
