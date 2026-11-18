# Upgrading

What changed in each release, and what, if anything, to change in your code when upgrading, newest first.

- [4.0.0](#400)
- [3.1.0](#310)
- [3.0.0](#300)

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
