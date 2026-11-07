Core Libs
========

[![Linux tests](https://github.com/programster/package-core-libs/actions/workflows/tests.yml/badge.svg)](https://github.com/programster/package-core-libs/actions/workflows/tests.yml)
[![Windows tests](https://github.com/programster/package-core-libs/actions/workflows/tests-windows.yml/badge.svg)](https://github.com/programster/package-core-libs/actions/workflows/tests-windows.yml)
[![macOS tests](https://github.com/programster/package-core-libs/actions/workflows/tests-macos.yml/badge.svg)](https://github.com/programster/package-core-libs/actions/workflows/tests-macos.yml)
[![WSL tests](https://github.com/programster/package-core-libs/actions/workflows/tests-wsl.yml/badge.svg)](https://github.com/programster/package-core-libs/actions/workflows/tests-wsl.yml)

This is a PHP package providing a set of static libraries that act to "extend" the standard library. 
These are built primarily with a focus on rapid development and ease of understanding over 
performance.

### Supported platforms
Linux has first-class support: it is what this library is built and tested on, and every test runs on it
automatically. Windows and macOS are supported too, and their tests also run automatically (see the "Windows tests"
and "macOS tests" badges), but a few things work differently, or not at all, there:

| Method | On macOS | On Windows |
|---|---|---|
| `Core::getMemory()` | Only `TOTAL`, `AVAILABLE`, `FREE`, `USED` and the three `SWAP_` figures. The others throw `UnsupportedPlatformException`. `AVAILABLE` is worked out from `vm_stat`, so is an approximation | The same seven figures. `FREE` is the same as `AVAILABLE`, as Windows doesn't report them separately |
| `Core::getNumProcRunning()` | As on Linux | Matches window titles, as that is what `Core::setCliTitle()` sets on Windows |
| `Core::setClipboard()`, `getClipboard()` | Works, but untested | Works, but untested |
| `CliLib::getPasswordFromUserInput()` | As on Linux | Typing is hidden using PowerShell. Untested on a real console |
| `File` and `Directory` moves | As on Linux | With `PreserveOwnershipEnum::YES` (the default), a move to another drive is refused, as Windows can't keep a file's owner and ACLs when copying it. Pass `PREFERRED` or `NO` to allow it |
| Anything that creates symlinks (`unzip()`, `Directory` moves, `File` moves of a symlink) | As on Linux | Needs admin rights or developer mode, as on any Windows program. PHP can't create a relative or broken symlink there, so `unzip()` creates absolute ones (or copies, with `$convertSymlinksOnWindows`), and refuses a zip with a broken one. PHP's `readlink()` there gives a link's resolved absolute path, so relative links can't be told apart: `Directory` and `File` moves create each symlink they move again with an absolute target. A broken one is left as it is when a `Directory` is moved on the same drive, and refused otherwise |
| Permissions and ownership (e.g. `Filesystem::mkdir()`'s `$permissions`, `fileperms()`) | As on Linux | Windows has no Unix permissions or owners, so these are mostly ignored. Only the read-only setting has an effect |
| `Filesystem::isTmpfs()` | Reads the `mount` command's output | Always false, as Windows has no tmpfs |

Each method's docblock gives the details.

#### WSL
Inside WSL, PHP is running on Linux, so this library behaves as it does on Linux. The "WSL tests" badge shows the
tests run in WSL's own filesystem, and PHP on Windows working with WSL's files through `\\wsl.localhost`. GitHub's
Windows runners only have WSL 1, though, so WSL 2 isn't tested. Two places behave differently, because they are
really the other system's files:

| Where | What to expect |
|---|---|
| Windows drives seen from WSL (e.g. `/mnt/c`) | Unless the drive is mounted with the `metadata` option, Unix permissions and owners aren't stored there: `chmod()` and `chown()` have no lasting effect, and every file appears to belong to you. So checks that a copy kept its exact permissions (`$overrideUmask` on `File` and `Directory` moves) can fail there, and the moves that need them are refused. Moving files between there and WSL's own filesystem copies them, as they are different filesystems |
| WSL's files seen from Windows (e.g. `\\wsl.localhost\Ubuntu\home\...`) | PHP on Windows treats these as a network share on another drive, so moves between them and `C:` copy rather than rename, and with `PreserveOwnershipEnum::YES`, are refused (see the table above). Symlinks made inside WSL may not show as symlinks from Windows |

For the most predictable results, run PHP on the same side as the files: in WSL for files in WSL's filesystem, and on
Windows for files on Windows drives.

### Progress and cancelling moves
`File` and `Directory` moves (and renames) take an optional `$listener`, to show progress or cancel the move. Extend
`AbstractMoveListener` and override the events you want. Throwing `MoveCancelledException` cancels the move: what was
copied so far is removed, the original is left as it was, and your exception is passed back to you.

```php
$listener = new class extends AbstractMoveListener {
    public function onFileMoved(MoveProgress $progress, string $path): void
    {
        CliLib::showProgressBar($progress->getPercentMoved(), 0);
    }

    public function onBytesCopied(MoveProgress $progress, string $path): void
    {
        CliLib::showProgressBar($progress->getPercentMoved(), 0);
    }
};

$directory->move("/mnt/backup", listener: $listener);
```

A move within one filesystem is a single instant rename, so it only calls `onMoveStarting()` and `onMoveFinished()`.
A move to another filesystem also calls `onFileStarting()` and `onFileMoved()` for each file, and `onBytesCopied()`
after each 8 MiB of a big file, so the progress keeps moving during it (and it can be cancelled part way through).
A move to another filesystem copies everything under a hidden name, then renames it into place once it is complete,
so an interrupted copy (e.g. by a timeout, or Ctrl+C) never leaves a half-finished copy under the real name.

`Directory` moves to another filesystem also take `$resumable`. With it set, a copy that fails, is cancelled or is
interrupted is kept, and making the same move again carries on from there: files already copied (and unchanged since)
aren't copied again.

### Recommended Extensions
This package recommends various extensions such as mbstring in order to be able to perform some of
the provided methods. However, we did not wish to prevent this from allowing you to install the 
package in case you did not need to use these limited methods. Thus, if you try to execute one of
these methods without having the recommended extension installed, it will raise an
`MissingExtensionException` with details of which extension is missing.

### Exceptions
Every exception this library throws extends the abstract
`Programster\CoreLibs\Exceptions\CoreLibsException`, so you can catch all of them at once:

```php
use Programster\CoreLibs\Exceptions\CoreLibsException;
use Programster\CoreLibs\Exceptions\FilesystemException;

try
{
    CsvLib::removeColumns($filepath, [2]);
}
catch (FilesystemException $e)
{
    // handle a specific failure, such as the file not being openable
}
catch (CoreLibsException $e)
{
    // handle anything else that went wrong in this library
}
```

`CoreLibsException` extends `\Exception`, so existing `catch (\Exception $e)` blocks keep working.

Errors raised by PHP itself, such as a `TypeError` from passing the wrong type of argument, are not
library exceptions, so they don't extend `CoreLibsException`.

### Testing
To run the tests on your current version of PHP, run `composer install`, and then:

```bash
php testing/main.php
```

The tests are in `testing/tests/`, with one file per class (e.g. `CsvLibTest.php`). Between them, they call nearly
every public method and check its result. A test fails if it throws, fails an `assert()`, or raises a PHP warning or
deprecation. `main.php` runs every `*Test.php` file in that folder, so a new file is picked up automatically.

This package supports PHP 8.3 and above. To check it works on every supported version, run the following, which
uses Docker to test each version in its own container. Use this before releasing, as it catches code that only
works on newer versions of PHP than the minimum.

```bash
testing/run-all-php-versions.sh             # test every supported version (8.3, 8.4 and 8.5)
testing/run-all-php-versions.sh 8.3 8.5     # test only specific versions
testing/run-all-php-versions.sh --rebuild   # rebuild the Docker images first, to get the latest PHP patch releases
```

For each version, this lints every file and runs the tests. It exits with a non-zero code if anything fails. The
Docker images are built the first time they are needed, and then reused. When a new version of PHP is released, add
it to `SUPPORTED_VERSIONS` at the top of the script.

The same script runs on GitHub Actions (`.github/workflows/tests.yml`) for pushes to `master`, every tag, and pull
requests into `master`, which is what the "Linux tests" badge at the top of this README shows. Pushes to other
branches don't run it, but it can be run on any branch by hand, with "Run workflow" on the repository's Actions tab.
As the containers run as root, the checks that need root (e.g. that rewriting a file keeps its owner) run there. When
run without root, `main.php` lists those checks as skipped.
