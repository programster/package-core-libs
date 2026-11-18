<?php

/*
 * Smoke tests for StringLib. These are run by testing/main.php, which provides $smoke and $dir.
 */

use Programster\CoreLibs\Exceptions\BadArgumentException;
use Programster\CoreLibs\Exceptions\StringConversionFailedException;
use Programster\CoreLibs\Enums\UuidVersionEnum;
use Programster\CoreLibs\StringLib;
use Programster\CoreLibs\Testing\SmokeTest;

/** @var \Programster\CoreLibs\Testing\SmokeTest $smoke */
/** @var string $dir - a temporary directory for the tests to use, which is deleted afterwards. */

$smoke->run("StringLib", function () {
    assert(strlen(StringLib::generateRandomString(20)) === 20);

    $generateEmptyString = function (): void {
        StringLib::generateRandomString(0);
    };

    SmokeTest::expectException(BadArgumentException::class, $generateEmptyString);

    $generateNegativeLength = function (): void {
        StringLib::generateRandomString(-1);
    };

    SmokeTest::expectException(BadArgumentException::class, $generateNegativeLength);

    $generateFromNoCharacters = function (): void {
        StringLib::generateRandomString(5, false, false, false, false);
    };

    SmokeTest::expectException(
        BadArgumentException::class,
        $generateFromNoCharacters
    );

    $generateTooShortForAllTypes = function (): void {
        StringLib::generateRandomString(3);
    };

    SmokeTest::expectException(BadArgumentException::class, $generateTooShortForAllTypes);
    assert(StringLib::base64UrlDecode(StringLib::base64UrlEncode("hi?>")) === "hi?>");

    $decodeInvalidBase64 = function (): void {
        StringLib::base64UrlDecode("a");
    };

    SmokeTest::expectException(StringConversionFailedException::class, $decodeInvalidBase64);
    assert(StringLib::startsWith("abc", "a") && StringLib::endsWith("abc", "c"));
    assert(StringLib::br2nl("a<br />b") === "a" . PHP_EOL . "b" && StringLib::nl2br("a\nb") === "a<br />b");
    assert(StringLib::nl2br("a\r\nb") === "a<br />b" && StringLib::nl2br("a\rb") === "a<br />b");
    $expected = implode(PHP_EOL, ["1", "2", "3", "4", "5<bridge>"]);
    assert(StringLib::br2nl("1<br>2<br/>3<BR />4<br class=\"x\">5<bridge>") === $expected);

    # A line break straight after a tag is part of it, so PHP's nl2br() output converts back to one line break per
    # line, whichever line endings it used. Only one line break is taken, so a blank line after a tag is kept.
    foreach (["\n", "\r\n", "\r"] as $lineEnding)
    {
        $text = "one{$lineEnding}two{$lineEnding}three";
        assert(StringLib::br2nl(nl2br($text)) === "one" . PHP_EOL . "two" . PHP_EOL . "three");
    }

    assert(StringLib::br2nl("a<br>\n\nb") === "a" . PHP_EOL . "\nb");
    assert(StringLib::br2nl("a<br><br>b") === "a" . PHP_EOL . PHP_EOL . "b");
    StringLib::convertLineEndings("a\r\nb");
    assert(StringLib::isRegExp("/a/") && !StringLib::isRegExp("not a regex"));
    assert(StringLib::replace("a", "b", "aa") === "bb" && StringLib::replacePairs(["a" => "b"], "aa") === "bb");
    assert(StringLib::contains("abc", "B", false));
});

$smoke->run("generateUuid(V7) creates UUIDv7s holding the time, each greater than the last", function () {
    $count = 10000;
    $startMilliseconds = (int)floor(microtime(true) * 1000);
    $uuids = [];

    for ($i = 0; $i < $count; $i++)
    {
        $uuids[] = StringLib::generateUuid(UuidVersionEnum::V7);
    }

    $endMilliseconds = (int)ceil(microtime(true) * 1000);
    $v7Pattern = '#^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$#D';

    foreach ($uuids as $uuid)
    {
        assert(preg_match($v7Pattern, $uuid) === 1, "{$uuid} isn't a lowercase UUIDv7.");
        assert(StringLib::isValidUuid($uuid), "isValidUuid() accepts {$uuid}.");
        $milliseconds = hexdec(substr(str_replace('-', '', $uuid), 0, 12));
        $holdsTime = ($milliseconds >= $startMilliseconds && $milliseconds <= $endMilliseconds);
        assert($holdsTime, "{$uuid} doesn't hold the time it was generated.");
    }

    $sorted = $uuids;
    sort($sorted, SORT_STRING);
    assert($sorted === $uuids, "The UUIDs sort in the order they were generated.");
    assert(count(array_unique($uuids)) === $count, "No two UUIDs are the same.");
});

$smoke->run("generateUuid(V1) creates UUIDv1s holding the time, with a random multicast node", function () {
    $count = 10000;
    $gregorianOffset = 122192928000000000;
    $startMicroseconds = (int)floor(microtime(true) * 1000000) - 1;
    $uuids = [];

    for ($i = 0; $i < $count; $i++)
    {
        $uuids[] = StringLib::generateUuid(UuidVersionEnum::V1);
    }

    $endMicroseconds = (int)ceil(microtime(true) * 1000000) + 1;
    $v1Pattern = '#^[0-9a-f]{8}-[0-9a-f]{4}-1[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$#D';
    $lastTimestamp = 0;

    foreach ($uuids as $uuid)
    {
        assert(preg_match($v1Pattern, $uuid) === 1, "{$uuid} isn't a lowercase UUIDv1.");
        assert(StringLib::isValidUuid($uuid), "isValidUuid() accepts {$uuid}.");
        [$timeLow, $timeMid, $timeHigh, , $node] = explode('-', $uuid);
        $timestamp = hexdec(substr($timeHigh, 1) . $timeMid . $timeLow);
        $microseconds = intdiv($timestamp - $gregorianOffset, 10);
        $holdsTime = ($microseconds >= $startMicroseconds && $microseconds <= $endMicroseconds);
        assert($holdsTime, "{$uuid} doesn't hold the time it was generated.");
        assert($timestamp > $lastTimestamp, "{$uuid}'s time is after the one before it.");
        assert((hexdec(substr($node, 0, 2)) & 0x01) === 1, "{$uuid}'s node has its multicast bit set.");
        $lastTimestamp = $timestamp;
    }

    assert(count(array_unique($uuids)) === $count, "No two UUIDs are the same.");
});

$smoke->run("generateUuid(V4) creates random UUIDv4s", function () {
    $count = 10000;
    $uuids = [];
    $v4Pattern = '#^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$#D';

    for ($i = 0; $i < $count; $i++)
    {
        $uuid = StringLib::generateUuid(UuidVersionEnum::V4);
        assert(preg_match($v4Pattern, $uuid) === 1, "{$uuid} isn't a lowercase UUIDv4.");
        assert(StringLib::isValidUuid($uuid), "isValidUuid() accepts {$uuid}.");
        $uuids[] = $uuid;
    }

    assert(count(array_unique($uuids)) === $count, "No two UUIDs are the same.");
});

$smoke->run("isValidUuid() accepts only a UUID in its usual form", function () {
    $valid = [
        '019a8f2e-5c1b-7d3a-9e4f-0b6c2d8a1f37',
        '019A8F2E-5C1B-7D3A-9E4F-0B6C2D8A1F37',
        '952036f3-3486-4630-9889-9f46b50a54e5',
        '00000000-0000-0000-0000-000000000000',
    ];

    foreach ($valid as $uuid)
    {
        assert(StringLib::isValidUuid($uuid), "{$uuid} is valid.");
    }

    $invalid = [
        '',
        'task-1',
        '019a8f2e5c1b7d3a9e4f0b6c2d8a1f37',
        '{019a8f2e-5c1b-7d3a-9e4f-0b6c2d8a1f37}',
        'urn:uuid:019a8f2e-5c1b-7d3a-9e4f-0b6c2d8a1f37',
        "019a8f2e-5c1b-7d3a-9e4f-0b6c2d8a1f37\n",
        ' 019a8f2e-5c1b-7d3a-9e4f-0b6c2d8a1f37',
        '019a8f2e-5c1b-7d3a-9e4f-0b6c2d8a1f3g',
        '019a8f2e-5c1b-7d3a-9e4f-0b6c2d8a1f3',
    ];

    foreach ($invalid as $uuid)
    {
        assert(!StringLib::isValidUuid($uuid), var_export($uuid, true) . " is invalid.");
    }
});
