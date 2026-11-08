Core Libs
========

[![Linux tests](https://github.com/programster/package-core-libs/actions/workflows/tests.yml/badge.svg)](https://github.com/programster/package-core-libs/actions/workflows/tests.yml)
[![Windows tests](https://github.com/programster/package-core-libs/actions/workflows/tests-windows.yml/badge.svg)](https://github.com/programster/package-core-libs/actions/workflows/tests-windows.yml)
[![macOS tests](https://github.com/programster/package-core-libs/actions/workflows/tests-macos.yml/badge.svg)](https://github.com/programster/package-core-libs/actions/workflows/tests-macos.yml)
[![WSL tests](https://github.com/programster/package-core-libs/actions/workflows/tests-wsl.yml/badge.svg)](https://github.com/programster/package-core-libs/actions/workflows/tests-wsl.yml)

## Contents
- [About](#about)
- [Requirements](#requirements)
- [Installation](#installation)
    - [Optional extensions](#optional-extensions)
- [Quick start](#quick-start)
- [What's in it](#whats-in-it)
- [Documentation](#documentation)
    - [Upgrading from version 2](docs/upgrading-to-3.md)
    - [Arrays (`ArrayLib`)](docs/arrays.md)
    - [Strings (`StringLib`)](docs/strings.md)
    - [System, processes and web requests (`Core`)](docs/core.md)
    - [Command line scripts (`CliLib`)](docs/cli.md)
    - [CSV files (`CsvLib` and `CsvFile`)](docs/csv.md)
    - [Files and directories (`Filesystem`)](docs/filesystem.md)
    - [Changing files safely](docs/safe-file-changes.md)
    - [Moving and renaming (`File`, `Directory`)](docs/moving-and-renaming.md)
    - [Networking (`Network`)](docs/network.md)
    - [Encryption and passwords (`EncryptionLib`)](docs/encryption.md)
    - [Exceptions](docs/exceptions.md)
    - [Supported platforms (Linux, macOS, Windows, WSL)](docs/platforms.md)
    - [Testing](docs/testing.md)
- [Design principles](#design-principles)
- [License](#license)

## About
This is a PHP package of static libraries that "extend" the standard library, with the things that PHP projects keep
needing but PHP doesn't quite provide: reading and rewriting huge CSV files without running out of memory, deleting or
moving a directory tree safely, getting the system's memory, running a shell command that throws when it fails, and so
on. It is built primarily for rapid development and ease of understanding, rather than raw performance.

## Requirements
- PHP 8.3 or later (8.3, 8.4 and 8.5 are tested). Where PHP 8.5 has a native function for something, it is used.
- The `fileinfo` and `sodium` extensions, which most PHP installs already have.

Linux has first-class support, and macOS, Windows and WSL are tested too. See [Supported platforms](docs/platforms.md)
for the few things that differ.

## Installation
```bash
composer require programster/core-libs
```

Then use the classes from the `Programster\CoreLibs` namespace:

```php
use Programster\CoreLibs\Filesystem;

$size = Filesystem::getDirSize("/var/log");
```

### Optional extensions
Some methods need an extension that the package doesn't require, so that you can install it without them if you don't
use those methods. If one is missing, the method throws a `MissingExtensionException` saying which extension it needs,
rather than failing in some less obvious way.

| Extension | Needed by |
|---|---|
| `mbstring` | `Filesystem::convertFileToUtf8()` |
| `zip` | `Filesystem::zipDir()` and `Filesystem::unzip()` |
| `sockets` | `Network::sendTcpRequest()` |
| `openssl` | `Network::getPublicIp()` (which uses HTTPS), and `EncryptionLib::decryptLegacy()` |
| `posix` | `File` and `Directory` moves to another filesystem with `PreserveOwnershipEnum::YES` (the default), to check who owns what |
| `curl` | Nothing: `Filesystem::downloadFile()` uses it if it is there, and PHP's own HTTP support otherwise |

## Quick start
```php
use Programster\CoreLibs\Core;
use Programster\CoreLibs\CsvLib;
use Programster\CoreLibs\Enums\DataSizeUnitEnum;
use Programster\CoreLibs\Enums\MemoryTypeEnum;
use Programster\CoreLibs\Filesystem;
use Programster\CoreLibs\Models\Directory;

// Run a command, getting an exception (with its exit code and error output) if it fails.
$branch = trim(Core::runCommand("git rev-parse --abbrev-ref HEAD"));

// How much memory is available, in GiB.
$available = Core::getMemory(MemoryTypeEnum::AVAILABLE, DataSizeUnitEnum::GIB);

// Strip the whitespace from every value of a CSV file, one row at a time, however big it is.
CsvLib::trim("/data/export.csv");

// Move a directory to another disk, keeping its owners and permissions, and its symlinks working.
$uploads = new Directory("/srv/uploads");
$uploads->move("/mnt/archive");

// Delete a directory tree. Everything is checked first, so either all of it goes, or none of it does.
Filesystem::deleteDir("/tmp/build");
```

## What's in it

| Class | For |
|---|---|
| [`ArrayLib`](docs/arrays.md) | Getting the first, last or a random element or key, fast diffs and intersections, splitting an array evenly, merging with a clash handler |
| [`StringLib`](docs/strings.md) | Random strings, `startsWith()`/`endsWith()`/`contains()` with case options, line breaks and `<br>` tags, URL-safe base64 |
| [`Core`](docs/core.md) | Running commands, memory and processor counts, process titles, request arguments, safe redirects, signed data, the clipboard |
| [`CliLib`](docs/cli.md) | Progress bars, and reading a password without showing it |
| [`CsvLib`, `CsvFile`](docs/csv.md) | Validating, converting, trimming, merging and comparing CSV files, and removing their rows or columns |
| [`Filesystem`](docs/filesystem.md) | Listing, creating, deleting, zipping and sizing directories, filtering and converting files, paths, downloads |
| [`File`, `Directory`](docs/moving-and-renaming.md) | Moving and renaming, including across filesystems, with progress, cancelling and resuming |
| [`Network`](docs/network.md) | IP addresses, open ports, and simple TCP requests |
| [`EncryptionLib`](docs/encryption.md) | Authenticated encryption, and password hashing |

## Documentation
The [`docs`](docs/) folder has a page for each area, with examples, and the details that are easy to be caught out by.
Every public method also has a full docblock, which your IDE will show, saying what it does, what each parameter means,
and every exception it can throw.

If you are upgrading from version 2, start with [Upgrading from version 2](docs/upgrading-to-3.md), as version 3 has
breaking changes, some of which (e.g. `Filesystem::mkdir()` no longer creating parent directories by default) won't
show up as errors.

## Design principles
These apply throughout, so are worth knowing before you start:

- **Failures throw.** Methods throw an exception if something goes wrong, rather than returning `false` or `null`
  for you to check. Every exception the library throws extends `CoreLibsException`, so you can catch them all at
  once. See [Exceptions](docs/exceptions.md).
- **Safe by default.** Where a method could do something surprising or dangerous, the default is the safe choice, and
  the riskier behaviour needs an extra parameter. For example, `Core::redirectUser()` won't redirect to another site
  unless you pass `$allowExternal`, `Filesystem::unzip()` refuses a zip with symlinks pointing outside it unless you
  pass `$trusted`, and `Filesystem::deleteDir()` won't follow symlinks unless you pass `$followSymlinks`.
- **Nothing half done.** Methods that change files check everything they can before changing anything, and write new
  contents to a temporary file before replacing the original, so a failure part way through leaves things as they
  were. See [Changing files safely](docs/safe-file-changes.md).
- **Big files are fine.** The CSV and file methods work a row or line at a time, so the size of the file doesn't matter.
  `CsvLib::convertCsvToArray()` (and `CsvFile::toArray()`) is the exception, as it returns everything as an array.
- **Every class is `final`**, and the libraries only have static methods.

## License
MIT. See [LICENSE](LICENSE).
