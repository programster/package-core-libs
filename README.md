Core Libs
========

This is a PHP package providing a set of static libraries that act to "extend" the standard library. 
These are built primarily with a focus on rapid development and ease of understanding over 
performance.

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

This package supports PHP 8.3 and above. To check it works on every supported version, run the following, which
uses Docker to test each version in its own container. Use this before releasing, as it catches code that only
works on newer versions of PHP than the minimum.

```bash
testing/run-all-php-versions.sh             # test every supported version (8.3, 8.4 and 8.5)
testing/run-all-php-versions.sh 8.3 8.5     # test only specific versions
testing/run-all-php-versions.sh --rebuild   # rebuild the Docker images first, to get the latest PHP patch releases
```

For each version, this lints every file, runs the test suite, and runs a smoke test
(`testing/php-versions/smoke.php`) that calls nearly every public method and checks its result. It exits with a
non-zero code if anything fails. The Docker images are built the first time they are needed, and then reused.
When a new version of PHP is released, add it to `SUPPORTED_VERSIONS` at the top of the script.
