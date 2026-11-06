<?php

/*
 * Smoke tests for StringLib. These are run by testing/main.php, which provides $smoke and $dir.
 */

use Programster\CoreLibs\Exceptions\BadArgumentException;
use Programster\CoreLibs\Exceptions\StringConversionFailedException;
use Programster\CoreLibs\StringLib;
use Programster\CoreLibs\Testing\SmokeTest;

/** @var \Programster\CoreLibs\Testing\SmokeTest $smoke */
/** @var string $dir - a temporary directory for the tests to use, which is deleted afterwards. */

$smoke->run("StringLib", function () {
    assert(strlen(StringLib::generateRandomString(20)) === 20);
    SmokeTest::expectException(BadArgumentException::class, fn() => StringLib::generateRandomString(0));
    SmokeTest::expectException(BadArgumentException::class, fn() => StringLib::generateRandomString(-1));
    SmokeTest::expectException(
        BadArgumentException::class,
        fn() => StringLib::generateRandomString(5, false, false, false, false)
    );
    SmokeTest::expectException(BadArgumentException::class, fn() => StringLib::generateRandomString(3));
    assert(StringLib::base64UrlDecode(StringLib::base64UrlEncode("hi?>")) === "hi?>");
    SmokeTest::expectException(StringConversionFailedException::class, fn() => StringLib::base64UrlDecode("a"));
    assert(StringLib::startsWith("abc", "a") && StringLib::endsWith("abc", "c"));
    assert(StringLib::br2nl("a<br />b") === "a" . PHP_EOL . "b" && StringLib::nl2br("a\nb") === "a<br />b");
    assert(StringLib::nl2br("a\r\nb") === "a<br />b" && StringLib::nl2br("a\rb") === "a<br />b");
    $expected = implode(PHP_EOL, ["1", "2", "3", "4", "5<bridge>"]);
    assert(StringLib::br2nl("1<br>2<br/>3<BR />4<br class=\"x\">5<bridge>") === $expected);
    StringLib::convertLineEndings("a\r\nb");
    assert(StringLib::isRegExp("/a/") && !StringLib::isRegExp("not a regex"));
    assert(StringLib::replace("a", "b", "aa") === "bb" && StringLib::replacePairs(["a" => "b"], "aa") === "bb");
    assert(StringLib::contains("abc", "B", false));
});
