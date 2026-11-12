# Testing

- [Running the tests](#running-the-tests)
- [Testing every supported PHP version](#testing-every-supported-php-version)
- [Running some of the tests](#running-some-of-the-tests)
- [Continuous integration](#continuous-integration)
- [Writing tests](#writing-tests)

## Running the tests
To run the tests on your current version of PHP, run `composer install`, and then:

```bash
php testing/main.php
```

The tests are in `testing/tests/`, with one file per class (e.g. `CsvLibTest.php`). Between them, they call nearly
every public method and check its result. A test fails if it throws, fails an `assert()`, or raises a PHP warning or
deprecation. `main.php` runs every `*Test.php` file in that folder, so a new file is picked up automatically. It exits
with a non-zero code if anything fails, and lists the checks it had to skip, and why (e.g. one that needs root).

## Testing every supported PHP version
This package supports PHP 8.3 and above. To check it works on every supported version, run the following, which
uses Docker to test each version in its own container. Use this before releasing, as it catches code that only
works on newer versions of PHP than the minimum.

```bash
testing/run-all-php-versions.sh             # test every supported version (8.3, 8.4 and 8.5)
testing/run-all-php-versions.sh 8.3 8.5     # test only specific versions
testing/run-all-php-versions.sh --rebuild   # rebuild the Docker images first, to get the latest PHP patch releases
testing/run-all-php-versions.sh --tmp=tmpfs # only test with /tmp held in memory (or --tmp=disk for only on disk)
```

For each version, this lints every file and runs the tests. It exits with a non-zero code if anything fails. The
Docker images are built the first time they are needed, and then reused. When a new version of PHP is released, add
it to `SUPPORTED_VERSIONS` at the top of the script.

Each version is tested in two containers: one with `/tmp` on disk, and one with `/tmp` on a tmpfs (held in memory),
mounted as on Debian 13. Both matter, as `UseSystemTempDirEnum::AUTO` chooses where to write temporary files differently
for each (see [safe file changes](safe-file-changes.md)). A test checks that `/tmp` really is what each container is
meant to be testing.

In each container, the tests run three times:

1. as root, for the checks that need it (e.g. that rewriting a file keeps its owner),
2. as a normal user, for the checks that root would get past (e.g. a read-only folder),
3. and as a normal user with the temporary directory behind a symlink, as on macOS.

Each run lists the checks it had to skip, which another run covers. The script also gives the container a 1 MB
filesystem, for the checks of running out of space, and files owned by another user, which only root can create.

## Running some of the tests
Set `CORELIBS_TEST_ONLY` to run only the tests whose name contains it. It fails if no test matches, so a typo can't pass
without checking anything:

```bash
CORELIBS_TEST_ONLY="removeColumns" php testing/main.php
```

Other environment variables are set by the scripts for the checks that need something set up first:

| Variable | Used for |
|---|---|
| `CORELIBS_TEST_FIXTURES` | A directory of files owned by another user, made as root by `run-in-container.sh` |
| `CORELIBS_TEST_SMALL_FILESYSTEM` | A small filesystem, for the checks of running out of space |
| `CORELIBS_TEST_TMP_TYPE` | `disk` or `tmpfs`: what the system's temporary directory should be on, checked by a test |
| `CORELIBS_TEST_UNC_DIR` | A UNC path (e.g. `\\wsl.localhost\Ubuntu\...`) for the UNC path test on Windows |

## Continuous integration
Each platform has its own GitHub Actions workflow and badge, so a failure on one doesn't turn the others red:

| Workflow | Runs |
|---|---|
| `.github/workflows/tests.yml` ("Linux tests") | `run-all-php-versions.sh`, so every supported version, three times each, in two jobs: with `/tmp` on disk, and on a tmpfs |
| `.github/workflows/tests-windows.yml` ("Windows tests") | The tests on Windows, with each supported version |
| `.github/workflows/tests-macos.yml` ("macOS tests") | The tests on macOS, with each supported version |
| `.github/workflows/tests-wsl.yml` ("WSL tests") | The tests in WSL 1 and WSL 2 (as root and as a normal user), then the UNC path test from Windows into WSL's filesystem |

They run on pushes to `master`, every tag, and pull requests into `master`. Pushes to other branches don't run them, but
they can be run on any branch by hand, with "Run workflow" on the repository's Actions tab.

The results, skipped checks and failures are written as annotations, which can be read on the run's page without
logging in to GitHub, unlike the logs.

## Writing tests
Add a test to the file for its class in `testing/tests/` with `$smoke->run("Name of the test", $callback)`, or create a
new `*Test.php` file for a new class. `main.php` gives each file `$smoke`, and `$dir`, a temporary directory for the
tests to use, which is deleted afterwards. Use `assert()` for each check, and `SmokeTest::expectException()` to check
that something throws:

```php
$decodePadded = function (): void {
    StringLib::base64UrlDecode("abc=");
};

SmokeTest::expectException(StringConversionFailedException::class, $decodePadded);
```

Some checks can only run in some places (e.g. as root, or with symlinks, which Windows only allows with developer mode).
Skip them there with `SmokeTest::skip()`, or the helpers that check first (e.g. `SmokeTest::canCreateSymlinks()` and
`canTestPermissions()`), giving the reason, so it shows in the list of skipped checks.

Follow the coding standards used throughout `src/` in the tests too, e.g. no arrow functions, and a closure passed to
`expectException()` assigned to a descriptively named variable first.
