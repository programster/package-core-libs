# Encryption and passwords (`EncryptionLib`)

`Programster\CoreLibs\EncryptionLib` encrypts and decrypts strings, and hashes passwords.

- [Encrypting and decrypting](#encrypting-and-decrypting)
- [Keys](#keys)
- [Moving data from version 2](#moving-data-from-version-2)
- [Passwords](#passwords)

## Encrypting and decrypting
`encrypt()` uses libsodium's authenticated encryption (`crypto_secretbox`: XSalsa20-Poly1305). As well as keeping the
contents secret, this means `decrypt()` refuses anything that has been changed, or was encrypted with a different key,
rather than returning garbage.

```php
use Programster\CoreLibs\EncryptionLib;
use Programster\CoreLibs\Exceptions\DecryptionFailedException;

$key = EncryptionLib::generateKey();

$encrypted = EncryptionLib::encrypt("secret message", $key);

try
{
    $message = EncryptionLib::decrypt($encrypted, $key);
}
catch (DecryptionFailedException $e)
{
    // The key is wrong, or the encrypted text was changed or damaged.
}
```

The encrypted text is base64, so it is safe to store in a database, JSON or a config file. A new random nonce is made
for each call, and stored in it, so encrypting the same text twice gives different results, and there's nothing else to
store alongside it.

## Keys
`generateKey()` makes a random 32 byte key. It is raw binary, so `base64_encode()` it to store it (e.g. in an
environment variable), and `base64_decode()` it when loading it:

```php
// Once, when setting up:
echo base64_encode(EncryptionLib::generateKey());

// Each time it is needed:
$key = base64_decode(getenv("ENCRYPTION_KEY"), true);
```

`encrypt()` and `decrypt()` throw a `BadArgumentException` for a key that isn't exactly 32 bytes, which usually means it
wasn't decoded. Keep the key secret, and don't lose it, as what was encrypted with it can't be decrypted without it.

## Moving data from version 2
Version 2's `encrypt()` used AES-256-OFB with a separate initialisation vector, which can't detect a wrong key or
changed data. `decryptLegacy()` takes the same arguments as version 2's `decrypt()`, so you can decrypt existing data
and encrypt it again in the new format:

```php
$oldKey = getenv("OLD_ENCRYPTION_KEY");
$newKey = base64_decode(getenv("ENCRYPTION_KEY"), true);

foreach ($db->query("SELECT id, secret, iv FROM accounts") as $row)
{
    $plaintext = EncryptionLib::decryptLegacy($row["secret"], $oldKey, $row["iv"]);
    $db->update("accounts", ["secret" => EncryptionLib::encrypt($plaintext, $newKey), "iv" => null], $row["id"]);
}
```

`decryptLegacy()` needs the `openssl` extension. As the old format can't detect a wrong key, decrypting with the wrong
key or initialisation vector gives garbage rather than an exception, so check a few results before deleting the
originals. A short initialisation vector is padded with zero bytes, as version 2 did.

## Passwords
`generatePasswordHash()` and `verifyPassword()` wrap `password_hash()` (with `PASSWORD_DEFAULT`) and `password_verify()`:

```php
$hash = EncryptionLib::generatePasswordHash($_POST["password"]);

// Later, when they sign in:
if (EncryptionLib::verifyPassword($_POST["password"], $hash))
{
    // Correct.
}
```

The hash includes the algorithm and its settings, so store it as it is. To upgrade old hashes when PHP's default
algorithm changes, use PHP's `password_needs_rehash()` after a successful sign-in.
