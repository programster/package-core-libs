<?php

/*
 * Smoke tests for EncryptionLib. These are run by testing/main.php, which provides $smoke and $dir.
 */

use Programster\CoreLibs\EncryptionLib;
use Programster\CoreLibs\Exceptions\DecryptionFailedException;
use Programster\CoreLibs\Testing\SmokeTest;

/** @var \Programster\CoreLibs\Testing\SmokeTest $smoke */
/** @var string $dir - a temporary directory for the tests to use, which is deleted afterwards. */

$smoke->run("encryption and passwords", function () {
    $key = EncryptionLib::generateKey();
    assert(EncryptionLib::decrypt(EncryptionLib::encrypt("hello", $key), $key) === "hello");
    $encryptedWithOtherKey = EncryptionLib::encrypt("hello", $key);

    $decryptWithWrongKey = function () use ($encryptedWithOtherKey): void {
        EncryptionLib::decrypt($encryptedWithOtherKey, EncryptionLib::generateKey());
    };

    SmokeTest::expectException(
        DecryptionFailedException::class,
        $decryptWithWrongKey
    );

    $iv = random_bytes(16);
    $legacy = openssl_encrypt("old", "AES-256-OFB", "old key", OPENSSL_ZERO_PADDING, $iv);
    assert(EncryptionLib::decryptLegacy($legacy, "old key", $iv) === "old");
    assert(EncryptionLib::verifyPassword("password", EncryptionLib::generatePasswordHash("password")));
});
