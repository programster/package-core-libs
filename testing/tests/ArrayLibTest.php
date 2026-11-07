<?php

/*
 * Smoke tests for ArrayLib. These are run by testing/main.php, which provides $smoke and $dir.
 */

use Programster\CoreLibs\ArrayLib;
use Programster\CoreLibs\Exceptions\EmptyArrayException;
use Programster\CoreLibs\Testing\SmokeTest;

/** @var \Programster\CoreLibs\Testing\SmokeTest $smoke */
/** @var string $dir - a temporary directory for the tests to use, which is deleted afterwards. */

$smoke->run("isAssoc", function (): void {
    assert(ArrayLib::isAssoc(["a" => 1]) && !ArrayLib::isAssoc([1]));
});

$smoke->run("getFirstElement", function () {
    $array = [5 => "a", 9 => "b"];
    assert(ArrayLib::getFirstElement($array) === "a");
    assert(ArrayLib::getFirstElement($array, true) === "a" && $array === [0 => "b"]);
});

$smoke->run("getLastElement", function () {
    $array = ["x" => 1, "y" => 2];
    assert(ArrayLib::getLastElement($array) === 2);
    assert(ArrayLib::getLastElement($array, true) === 2 && $array === ["x" => 1]);
});

$smoke->run("getLastKey", function () {
    $array = ["x" => 1, "y" => 2];
    assert(ArrayLib::getLastKey($array, true) === "y" && $array === ["x" => 1]);
});

$smoke->run("getRandomKey / getRandomElement", function () {
    $array = [1, 2, 3];
    assert(in_array(ArrayLib::getRandomKey($array), [0, 1, 2], true));
    assert(in_array(ArrayLib::getRandomElement($array, true), [1, 2, 3], true) && count($array) === 2);
});

$smoke->run("empty arrays throw EmptyArrayException", function () {
    $empty = [];

    $getFirstOfEmpty = function () use ($empty): void {
        ArrayLib::getFirstElement($empty);
    };

    SmokeTest::expectException(EmptyArrayException::class, $getFirstOfEmpty);

    $getLastOfEmpty = function () use ($empty): void {
        ArrayLib::getLastElement($empty);
    };

    SmokeTest::expectException(EmptyArrayException::class, $getLastOfEmpty);

    $checkEmptyIsAssoc = function (): void {
        ArrayLib::isAssoc([]);
    };

    SmokeTest::expectException(EmptyArrayException::class, $checkEmptyIsAssoc);

    $splitEmpty = function (): void {
        ArrayLib::split([], 2);
    };

    SmokeTest::expectException(EmptyArrayException::class, $splitEmpty);
});

$smoke->run("other ArrayLib methods", function () {
    assert(ArrayLib::removeIndexes([1, 2, 3], [1], true) === [1, 3]);
    assert(ArrayLib::wrapElements(["a"], "`") === ["`a`"]);
    assert(ArrayLib::fastDiff([1, 2], [2]) === [1] && ArrayLib::fastIntersect([1, 2], [2]) === [2]);
    assert(ArrayLib::indexDiff(["x" => 1], [2]) === ["x"]);
    assert(ArrayLib::getValues(["a" => 1], ["a"]) === [1]);
    assert(ArrayLib::stripEmptyElements([0, 1, "", 2]) === [1, 2]);

    $addValues = function (string $key, int $value1, int $value2): int {
        return $value1 + $value2;
    };

    $merged = ArrayLib::assocArrayMerge(["a" => 1], ["a" => 2, "b" => 3], $addValues);
    assert($merged === ["a" => 3, "b" => 3]);

    # setDiff() compares sets strictly, so order and types matter.
    assert(ArrayLib::setDiff([[1, 2], [3, 4], [5]], [[3, 4]]) === [[1, 2], [5]]);
    assert(ArrayLib::setDiff([[1, 2], [1]], [[2, 1], ["1"]]) === [[1, 2], [1]]);
    assert(ArrayLib::setDiff([[-0.0]], [[0.0]]) === []);

    # A closure can't be serialized, so this checks the fallback that compares every pair.
    $closure = function (): int {
        return 1;
    };

    assert(ArrayLib::setDiff([[$closure], [1]], [[$closure]]) === [[1]]);

    # Keys that exist with a null value must be treated as existing.
    assert(ArrayLib::getValues(["a" => null], ["a"]) === [null]);
    assert(ArrayLib::removeIndexes(["a" => null, "b" => 1], ["a"]) === ["b" => 1]);

    $handleNull = function (string $key, mixed $value1, mixed $value2): string {
        return "handled";
    };

    assert(ArrayLib::assocArrayMerge(["a" => 1], ["a" => null], $handleNull) === ["a" => "handled"]);
    assert(ArrayLib::arrayCombineList(["k"], [[1], [2]]) === [["k" => 1], ["k" => 2]]);
    assert(ArrayLib::getValueOrDefault([], "a", 5) === 5);
    assert(ArrayLib::getValueOrDefault(["a" => null], "a", 5) === null);
});

$smoke->run("split", function () {
    assert(ArrayLib::split([1, 2, 3, 4, 5], 4) === [[1, 2], [3], [4], [5]]);
    assert(ArrayLib::split([1, 2, 3, 4, 5], 2, false) === [[1, 3, 5], [2, 4]]);
    assert(ArrayLib::split([1, 2], 3) === [[1], [2], []]);
});
