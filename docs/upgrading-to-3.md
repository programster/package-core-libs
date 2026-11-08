# Upgrading from version 2

Version 3 puts every breaking change in one release: removing old methods, giving everything types, throwing specific
exceptions rather than returning `false`, and making the defaults safe. This page lists what you may need to change.

- [Before you start](#before-you-start)
- [Changes that won't show up as errors](#changes-that-wont-show-up-as-errors)
- [Requirements](#requirements)
- [Exceptions](#exceptions)
- [Removed](#removed)
- [Renamed](#renamed)
- [ArrayLib](#arraylib)
- [Core](#core)
- [CsvLib](#csvlib)
- [EncryptionLib](#encryptionlib)
- [Filesystem](#filesystem)
- [Network](#network)
- [StringLib](#stringlib)

## Before you start
Search your code for `Programster\CoreLibs` and go through each class you use below. The changes that make PHP throw
an `Error` or `TypeError` (a removed method, a changed parameter type) are easy to find by running your tests. The ones
in the next section are not, so check those by hand.

## Changes that won't show up as errors
These still run, but do something different, so search for them:

| What | Version 2 | Version 3 | What to do |
|---|---|---|---|
| `Filesystem::mkdir($path)` | Created any missing parent directories (`$recursive` defaulted to true) | Only creates `$path`, and throws if its parent doesn't exist | Pass `recursive: true` where you relied on it |
| `Filesystem::mkdir()` result | Returned `false` if it failed | Returns nothing, and throws `FilesystemException` | Remove `if (!Filesystem::mkdir(...))` checks, or they'll always be true |
| `CsvLib::removeRows($file, $rows, ";")` | The third parameter was the delimiter | The third parameter is `$startFromZero`, then the delimiter | Use named arguments: `delimiter: ";"` |
| `CsvLib::trim($file, ",", "\\")` | The third parameter was the escape character | The third parameter is the enclosure, then the escape character | Use named arguments: `escape: "\\"` |
| `CsvLib::diffTolerance($a, $b, $tolerances, false)` | The fourth parameter was `$compareOtherColumns` | It was removed (only the listed columns are compared), so the fourth parameter is now the delimiter | Remove the argument |
| `CsvLib::csvWalk()` escape character | Defaulted to `"\\"` | Defaults to `""` (no escape character), as for the other CSV methods | Pass `escape: "\\"` if your files use backslash escapes |
| `Core::fetchReqArgsFromArray()`, `fetchOptionalArgsFromArray()` | A key whose value was `null` counted as missing | It counts as provided, and its `null` is returned | Check for `null` values where you relied on them being missing |
| `Core::generateTrueFalseString()` | Returned the opposite of what it should | Fixed | Remove any workaround |
| `Core::getCurrentUrl()` | Built the host from the request's `Host` header, which the client controls | Takes the host as `$fqdn`, and gives an `https://` URL unless `$forceHttps` is false | Pass your site's host name |
| `Core::redirectUser()` | Redirected anywhere | Only redirects to a path on this site (e.g. `/account`), and exits | Pass `allowExternal: true` for a URL you trust |
| `Filesystem::getFileExtension()` | Returned the whole name for a file without an extension (e.g. "README") | Throws `NoFileExtensionException`, and ignores the dot of a hidden file (".bashrc" has no extension) | Catch the exception where files may have no extension |
| `Filesystem::deleteDir()` given a symlink to a directory | Emptied the directory it pointed to, then failed to remove the link | Throws `BadArgumentException`, without deleting anything | Use `Filesystem::removeSymlink()` to remove the link, or pass `followSymlinks: true` to delete the directory too |
| `Filesystem::getDirectories()`, `getDirContents()` paths | Real paths | Absolute paths that keep any symlinks in the path you gave | Pass `resolveSymlinks: true` for real paths |
| `Network::sendTcpRequest()` | JSON encoded the request (so a string was sent in quotes), and tried to connect up to 100 times | Sends the request as it is, and tries 5 times | `json_encode()` the request yourself if the server expects JSON |
| `ArrayLib::split()` | Could return fewer arrays than asked for | Always returns exactly `$numberOfArrays`, some empty if there aren't enough elements | Check code that counts the result |
| `ArrayLib::fastIntersect()` | Gave a value once for each time it was in the first array | Gives each shared value once, as its docblock always said | Check code that relied on the repeats |
| `ArrayLib::getLastKey()` (was `getLastIndex()`) with `$removeElement` | Didn't remove the element | Removes it | Check you meant to remove it |

## Requirements
- PHP 8.3 or later. (Version 2 needed PHP 8.5's `array_first()`.)
- The `sodium` and `fileinfo` extensions are now required. The `thecodingmachine/safe` dependency is gone.
- Every class is now `final`, so a class extending one of them needs to call it instead.
- Every parameter and return value has a type. Code passing the wrong type (e.g. a `string` where an `int` is
  expected) under `declare(strict_types=1)` now gets a `TypeError`.

## Exceptions
Every exception now extends the abstract `Programster\CoreLibs\Exceptions\CoreLibsException`, which extends
`\Exception`, so existing `catch (\Exception $e)` blocks still work. Many methods that threw a plain `\Exception`, or
returned `false`, now throw a specific exception. See [Exceptions](exceptions.md) for the full list.

The exceptions from version 2 have been renamed, to end in "Exception":

| Version 2 | Version 3 |
|---|---|
| `ExceptionBadFilePermissions` | `BadFilePermissionsException` |
| `ExceptionFileAlreadyExists` | `FileAlreadyExistsException` |
| `ExceptionFileDoesNotExist` | `FileDoesNotExistException` |
| `ExceptionMissingExtension` | `MissingExtensionException` |
| `ExceptionStringConversionFailed` | `StringConversionFailedException` |
| `ExceptionMissingRequiredExtension` | Removed, as nothing threw it |
| `FilesystemException` | Unchanged |

## Removed

| Removed | Use instead |
|---|---|
| `TimeLib`, `TimeZoneEnum` | The [`programster/date-time-lib`](https://github.com/programster/package-date-time-lib) package (`composer require programster/date-time-lib`), where they are `Programster\DateTime\TimeLib` and `Programster\DateTime\TimeZoneEnum` |
| `Core::getRam()` | `Core::getMemory()`, which gives any measurement in any unit (see [Core](core.md#memory)) |
| `Core::throwException()` | `throw new ...` |
| `Core::generateConfig()` | Nothing. It wrote PHP code into a file with `var_export()` |
| `EncryptionLib::createInitializationVector()` | Nothing. `encrypt()` makes its own nonce (see [EncryptionLib](#encryptionlib)) |
| `StringLib::encrypt()`, `StringLib::decrypt()` | `EncryptionLib::encrypt()` and `decrypt()` |
| `StringLib::sanitizeRegExp()` | `preg_quote()` |
| `Network::getHostname()` | PHP's `gethostname()` |

## Renamed

| Version 2 | Version 3 |
|---|---|
| `ArrayLib::getLastIndex()` | `ArrayLib::getLastKey()` |
| `Network::getIp()` | `Network::getIps()`, which returns an array of every IPv4 address on the interface |
| `$is_write_lock`, `$is_blocking` (`lockFile()`) | `$isWriteLock`, `$isBlocking` |
| `$input_array` (`fetchReqArgsFromArray()`, `fetchOptionalArgsFromArray()`) | `$inputArray` |
| `$nameingPrefix` (`setCliTitle()`) | `$namingPrefix` |
| `$rowIndexes` (`CsvLib::removeRows()`) | `$rowNumbers` |

Renamed parameters only matter if you pass them by name.

## ArrayLib
- `isAssoc()`, `getFirstElement()`, `getLastElement()`, `getLastKey()`, `getRandomElement()`, `getRandomKey()` and
  `split()` throw `EmptyArrayException` for an empty array, rather than returning `null` or `false`.
- `getValues()` throws `BadArgumentException` if one of the keys isn't in the array. A key with a `null` value now
  counts as being there.
- `arrayCombineList()` throws `BadArgumentException` if a row has a different number of values to the keys.
- `setDiff()` is now public, and much faster for large arrays.

## Core
- `getMemory()` replaces `getRam()`. `getRam()` gave the total (or, with `true`, the available) memory in MiB on Linux.
  `getMemory()` gives a float in the `DataSizeUnitEnum` you ask for (MiB by default), and also works on macOS and
  Windows:

  ```php
  // Version 2
  $totalMib = Core::getRam();
  $availableMib = Core::getRam(true);

  // Version 3
  $totalMib = Core::getMemory();
  $availableMib = Core::getMemory(MemoryTypeEnum::AVAILABLE);
  ```

- `runCommand()` is new, and replaces `shell_exec()` throughout the library. It throws `CommandFailedException`
  (with the exit code and error output) if the command fails.
- `setCliTitle()` returns nothing, and throws `CliTitleNotSetException` if it can't set the title, rather than
  returning `false`.
- `versionGuard()` throws `PhpVersionTooLowException`, rather than calling `die()`.
- `getCurrentUrl()` throws `NoWebRequestException` when there is no web request (e.g. from the CLI).
- `isValidSignedRequest()` compares signatures with `hash_equals()`, so it can't leak them through timing.
- `getNumProcessors()`, `getNumProcRunning()`, `setClipboard()` and `getClipboard()` work on macOS and Windows, and the
  clipboard methods support Wayland as well as X11.

## CsvLib
- A blank line in the middle of a CSV file now makes it invalid, and the methods throw `InvalidCsvException` before
  changing anything. Blank lines at the end are allowed, as editors often add them. A row of just commas (e.g. `,,,`)
  is a row of empty values, not a blank line. See [CSV files](csv.md#what-counts-as-a-valid-csv-file).
- Every method takes `$delimiter`, `$enclosure` and `$escape`, in that order, and `$escape` defaults to `""`, as
  PHP 8.4 deprecated relying on the default.
- Methods that write a file (`convertArrayToCsv()`, `convertCsvToJson()`, `trim()`, `removeColumns()`, `removeRows()`,
  `mergeFiles()`) write to a temporary file first, and keep the file's owner and permissions. They take two new
  optional parameters for this, `$useSystemTempDir` and `$preserveOwnership`. See
  [Changing files safely](safe-file-changes.md).
- `decimalDiff()` and `diffTolerance()` take `$file1HasHeader` and `$file2HasHeader`, so a header row is skipped
  rather than compared as data.
- `diffTolerance()` throws `NonNumericCsvValuesException`, listing where they are, if a compared column isn't numeric.
- `removeRows()` counts CSV rows rather than lines (a quoted value can span several lines), and takes `$startFromZero`.
- New: `validateCsvFile()`, `removeTrailingBlankLines()`, and the [`CsvFile`](csv.md#the-csvfile-class) class.

## EncryptionLib
`encrypt()` and `decrypt()` now use libsodium's authenticated encryption. This detects a wrong key, or encrypted
text that has been tampered with, which the old AES-256-OFB format couldn't. It has its own key format, and no
separate initialisation vector, so:

- Make a new key with `EncryptionLib::generateKey()`.
- Decrypt existing data with `EncryptionLib::decryptLegacy($encryptedText, $key, $initializationVector)`, which takes
  the same arguments as version 2's `decrypt()`, and encrypt it again with `encrypt()`.

See [Encryption](encryption.md#moving-data-from-version-2) for an example.

## Filesystem
- `mkdir()` has changed signature, to `mkdir($dirPath, $perms = "0777", $recursive = false, $overrideUmask = false)`.
  See the table at the top of this page.
- `getDirSize()` returns a `float`, in the `DataSizeUnitEnum` you ask for (bytes by default), and works without `du`.
  It throws `FileDoesNotExistException` for a path that doesn't exist.
- `deleteDir()` and `emptyDir()` check everything before deleting anything, and throw `DirectoryNotDeletableException`
  (with a list of the problems) if anything couldn't be deleted. See [Deleting](filesystem.md#deleting).
- `fileFilter()`'s fourth parameter, `$streamChunkSize`, was removed. The file is written to a temporary file first,
  and the new fourth and fifth parameters say where (see [Changing files safely](safe-file-changes.md)).
- `convertFileToUtf8()` takes the file's encoding as a string (`"Windows-1252"` by default), rather than a list of
  encodings to try, as the encoding of a file can't be reliably detected.
- `zipDir()` and `unzip()` keep symlinks as symlinks, include empty directories, and `unzip()` refuses a zip with a
  symlink pointing outside the destination unless `$trusted` is true.
- `downloadFile()` only accepts `http://` and `https://` URLs (including redirects), so it can't read local files, and
  treats an HTTP error (e.g. 404) as a failed download.
- `tmpDir()` validates `$mode`, and takes `$overrideUmask`.
- `fileperms()` gives a symlink's own permissions (so works for a broken one), and shows the setuid, setgid and sticky
  bits exactly as `ls -l` does.
- New: `getFileSize()`, `moveFile()`, `moveDir()`, `replaceFileContents()`, `removeSymlink()`, path helpers such as
  `normalisePath()`, `resolvePath()` and `isWithinDirectory()`, and the `File` and `Directory` classes.

## Network
- `getIps()` replaces `getIp()`, and returns an array. It throws `NetworkInterfaceDoesNotExistException` for an
  interface that doesn't exist.
- `getPublicIp()` uses HTTPS (so needs the `openssl` extension), and takes a `$timeout`, 5 seconds by default.
- `isPortOpen($host, $port, $timeout = 5.0)` only checks TCP, so the `$protocol` parameter was removed.
- `sendTcpRequest()` sends and returns plain strings, supports IPv6, and has a working timeout.
- New: `isHttps()`.

## StringLib
- `base64UrlDecode()` is strict, and throws `StringConversionFailedException` for anything `base64UrlEncode()` couldn't
  have produced. The string isn't put in the exception's message, as it is often a token: use `getFailedString()`.
- `generateRandomString()` throws `BadArgumentException` if the length can't fit one of each type of character asked for.
- `br2nl()` handles every way of writing the tag, and doesn't double up line breaks after one.
