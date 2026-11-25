# Upgrading

What changed in each release, and what, if anything, to change in your code when upgrading, newest first.

- [4.1.0](#410)
- [4.0.0](#400)
- [3.1.0](#310)
- [3.0.0](#300)

## 4.1.0

### Changes you may notice
- **`Filesystem::unzip()` refuses a zip that won't fit**, throwing the new `NotEnoughDiskSpaceException` before anything
  is written, rather than filling the disk part way through and then failing. It extends `FilesystemException`, which
  was thrown for a full disk before, so code that catches that still catches it.

### Other changes
- New: `Filesystem::unzip()` takes `$maxExtractedBytes`, the most a zip's contents may add up to once extracted, and
  throws the new `ZipTooLargeException` for one that would extract to more. Use it for zips from users. See
  [Zipping and unzipping](filesystem.md#zipping-and-unzipping).
- New: `Filesystem::zipDir()` takes `$compression`, a `ZipCompressionEnum` (`DEFLATE`, the default, as before,
  `STORE`, `BZIP2`, `XZ` or `ZSTD`), or a `ZipCompressionEnumCollection` of them to try in order. See
  [Zipping and unzipping](filesystem.md#zipping-and-unzipping).
- New: `Filesystem::zipDir()` takes `$excludePaths`, files and folders to leave out of the zip, and the new
  `Filesystem::listHiddenFilesAndFolders()` lists the hidden ones, e.g. to leave those out. See
  [Listing a directory](filesystem.md#listing-a-directory) and
  [Zipping and unzipping](filesystem.md#zipping-and-unzipping).
- New: `Filesystem::updateSymlink()`, which points a symlink somewhere else atomically, e.g. to switch between
  versions of a directory. See [Symlinks](filesystem.md#symlinks).
- New: `Filesystem::replaceDir()`, which replaces a directory with another, and `Filesystem::replaceSymlink()`, which
  replaces a symlink with a directory, both without anything ever seeing a mix of the two. See
  [Replacing a directory](filesystem.md#replacing-a-directory).
- `Filesystem::tmpDir()` takes `$prefix`, after its other parameters, for what the directory's name starts with, and
  its names now start with `tmp-` by default, rather than `corelibs-dir-`.
- The temporary files and directories the library makes while it works have shorter names, starting with `tmp-` (or
  `.tmp-`, where they're hidden), e.g. `.tmp-move-...` rather than `.corelibs-move-...`. If you clean up leftovers by
  name, look for both. A resumable move that was interrupted before upgrading is still found, and resumed.
- New: `CommandFailedException::getOutput()`, what a failed command wrote to stdout, e.g. for PHP's command line, which
  reports errors there. It's kept out of the exception's message, as it could be sensitive.
- Fixed: reading a password on Windows (`CliLib::getPasswordFromUserInput()`) threw an `ArgumentCountError`, rather
  than a `CommandFailedException`, if PowerShell couldn't be run or failed.
- New: `Filesystem::tmpFile()`, which creates a temporary file, as `tmpDir()` does a directory. See
  [Temporary files and directories](filesystem.md#temporary-files-and-directories).
- New: `Filesystem::getFreeDiskSpace()`, which throws rather than returning `false`, as `disk_free_space()` does.
  See [Sizes](filesystem.md#sizes).
- New: `Filesystem::chown()` and `Filesystem::chgrp()`, which throw rather than returning `false`, and change a symlink
  itself rather than what it points to, unless told otherwise. See [Permissions](filesystem.md#permissions).

## 4.0.0

### Changes you need to make
- **`Filesystem::replaceFileContents()`'s third parameter is now `$createIfMissing`** (see below), before
  `$useSystemTempDir` and `$preserveOwnership`, as it's the one most likely to be changed. A call that passes either of
  those positionally, e.g. `replaceFileContents($path, $contents, UseSystemTempDirEnum::ALWAYS)`, now throws a
  `TypeError`, so it can't silently mean something else. Add `false` before them, or name them, e.g.
  `replaceFileContents($path, $contents, useSystemTempDir: UseSystemTempDirEnum::ALWAYS)`.
- **`Filesystem::lockFile()` takes a `FileLockTypeEnum`, rather than two booleans, and always waits.** The two
  booleans couldn't be told apart where it was called (`lockFile($path, true, false)`), and its `bool` result only
  meant anything when it didn't wait. Not waiting is now `tryLockFile()`, which returns whether it got the lock:

  | 3.x | 4.0.0 |
  |---|---|
  | `lockFile($path, true, true)` | `lockFile($path)` (or `lockFile($path, FileLockTypeEnum::EXCLUSIVE)`) |
  | `lockFile($path, false, true)` | `lockFile($path, FileLockTypeEnum::SHARED)` |
  | `lockFile($path, true, false)` | `tryLockFile($path)` |
  | `lockFile($path, false, false)` | `tryLockFile($path, FileLockTypeEnum::SHARED)` |

  `lockFile()` now returns nothing, as it always got the lock when it returned.

### Other changes
- **New: `Filesystem::replaceFileContents()` can create a file that doesn't exist**, with `$createIfMissing` set to
  `true`, in the same safe way: the contents are written to a temporary file next
  to it and renamed into place. It still throws a `FileDoesNotExistException` for a missing file by default, so a
  mistyped path is caught. See [Changing files safely](safe-file-changes.md#doing-it-yourself).
- Locking a file that can't be opened (e.g. its directory doesn't exist) no longer lets PHP's warning reach your error
  handler: the reason is in the `FilesystemException`'s message instead.

## 3.1.0
- New: `StringLib::generateUuid()`, which generates a UUIDv1, v4 or v7 (`UuidVersionEnum`), and
  `StringLib::isValidUuid()`. See [Strings](strings.md#uuids).

## 3.0.0
See [Upgrading from version 2](upgrading-to-3.md).
