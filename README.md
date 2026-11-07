Core Libs
========

[![Tests](https://github.com/programster/package-core-libs/actions/workflows/tests.yml/badge.svg)](https://github.com/programster/package-core-libs/actions/workflows/tests.yml)
[![Windows tests](https://github.com/programster/package-core-libs/actions/workflows/tests-windows.yml/badge.svg)](https://github.com/programster/package-core-libs/actions/workflows/tests-windows.yml)
[![macOS tests](https://github.com/programster/package-core-libs/actions/workflows/tests-macos.yml/badge.svg)](https://github.com/programster/package-core-libs/actions/workflows/tests-macos.yml)

This is a PHP package providing a set of static libraries that act to "extend" the standard library. 
These are built primarily with a focus on rapid development and ease of understanding over 
performance.

### Supported platforms
Linux has first-class support: it is what this library is built and tested on, and every test runs on it
automatically. Windows and macOS are meant to be supported too, but aren't held to passing every test, so some parts
(especially the file and directory handling) may not work on them yet. The "Windows tests" and "macOS tests" badges
show how the tests do on each, which are run separately so that they don't affect the main "Tests" badge. Some
methods are Linux only by nature, such as `Core::getMemory()`, which reads `/proc/meminfo`, and throw an exception
saying so elsewhere.

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
requests into `master`, which is what the "Tests" badge at the top of this README shows. Pushes to other branches
don't run it, but it can be run on any branch by hand, with "Run workflow" on the repository's Actions tab. As the
containers run as root, the checks that need root (e.g. that rewriting a file keeps its owner) run there. When run
without root, `main.php` lists those checks as skipped.
