# Exceptions

Methods throw an exception when something goes wrong, rather than returning `false` or `null` for you to check. Each
method's docblock lists every exception it can throw, and when.

- [Catching them](#catching-them)
- [The exceptions](#the-exceptions)
- [Exceptions with extra details](#exceptions-with-extra-details)

## Catching them
Every exception this library throws extends the abstract `Programster\CoreLibs\Exceptions\CoreLibsException`, so you
can catch all of them at once, as well as specific ones:

```php
use Programster\CoreLibs\CsvLib;
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

Errors raised by PHP itself, such as a `TypeError` from passing the wrong type of argument, aren't library exceptions,
so they don't extend `CoreLibsException`. Neither do a few from PHP that are passed through: `\JsonException` from the
CSV to JSON conversion, and `\Random\RandomException` if the system has no source of randomness.

## The exceptions
All are in the `Programster\CoreLibs\Exceptions` namespace. Those indented extend the one above them.

| Exception | Thrown when |
|---|---|
| `CoreLibsException` | The base class of all of them. Never thrown itself |
| `BadArgumentException` | An argument isn't valid, e.g. a name that should be a name is a path, or a number is out of range |
| &nbsp;&nbsp;&nbsp;&nbsp;`EmptyArrayException` | An array that needs at least one element has none |
| `BadFilePermissionsException` | A file or directory can't be read or written as needed |
| &nbsp;&nbsp;&nbsp;&nbsp;`DirectoryNotDeletableException` | `deleteDir()` or `emptyDir()` found things that can't be deleted, so deleted nothing |
| &nbsp;&nbsp;&nbsp;&nbsp;`DirectoryNotMovableException` | A `Directory` move or rename found things that would stop it, so moved nothing |
| `CliTitleNotSetException` | `Core::setCliTitle()` couldn't set the title, e.g. from a web request |
| `CommandFailedException` | A shell command couldn't be run, or exited with a code other than 0 |
| `DecryptionFailedException` | Encrypted text couldn't be decrypted: the key is wrong, or it was changed |
| `DirectoryDoesNotExistException` | There's no directory at a path that needs one |
| `FileAlreadyExistsException` | Something already exists where a file or directory would be created or moved to |
| `FileDoesNotExistException` | There's no file at a path that needs one |
| `FilesystemException` | A filesystem operation failed for another reason, e.g. a file couldn't be opened, or a disk filled up |
| `InputEndedException` | The input ended while `CliLib::getPasswordFromUserInput()` was reading |
| `InvalidCsvException` | A CSV file isn't valid, e.g. it has a blank line in the middle, or rows of different lengths |
| &nbsp;&nbsp;&nbsp;&nbsp;`NonNumericCsvValuesException` | `CsvLib::diffTolerance()` found values that aren't numbers in the columns to compare |
| `MissingExtensionException` | A method needs a PHP extension that isn't installed (see [Optional extensions](../README.md#optional-extensions)) |
| `MoveCancelledException` | Thrown by you, from a move listener, to cancel a move. It is passed back to you once the move is undone |
| `NetworkException` | A network request failed, e.g. couldn't connect, or got no response |
| `NetworkInterfaceDoesNotExistException` | `Network::getIps()` was given an interface that doesn't exist |
| `NoCsvHeaderException` | `CsvFile::toJson()` was called for a file without a header row |
| `NoFileExtensionException` | `Filesystem::getFileExtension()` was given a name without an extension |
| `NoWebRequestException` | Something that needs a web request (e.g. `Core::getCurrentUrl()`) was called from the command line |
| `PhpVersionTooLowException` | `Core::versionGuard()` found an older version of PHP than required |
| `StringConversionFailedException` | A string couldn't be converted, e.g. by `StringLib::base64UrlDecode()` |
| `UnexpectedOutputException` | A command or system file didn't give what was expected, e.g. `/proc/meminfo` is missing a field |
| `UnsupportedPlatformException` | Something isn't available on this operating system |
| `UserCancelledException` | The user pressed Ctrl+C or Ctrl+D while `CliLib::getPasswordFromUserInput()` was reading |

## Exceptions with extra details

| Exception | Methods |
|---|---|
| `CommandFailedException` | `getCommand()`, `getExitCode()`, and `getErrorOutput()` (what the command wrote to stderr) |
| `DirectoryNotDeletableException` | `getProblems()`: a list of `FileDeletionProblem`, each with `path`, `directory` (what needs its permissions changing) and `reason` (a `FileDeletionProblemEnum`). See [Deleting](filesystem.md#deleting) |
| `DirectoryNotMovableException` | `getProblems()`: a list of `DirectoryMoveProblem`, each with `getPath()`, `getReason()` (a `DirectoryMoveProblemEnum`) and `getDescription()`. See [When a directory can't be moved](moving-and-renaming.md#when-a-directory-cant-be-moved) |
| `NetworkInterfaceDoesNotExistException` | `getInterface()` |
| `NonNumericCsvValuesException` | `getErrors()`: a list of `CsvCellError`, each with `filepath`, `rowNumber`, `columnNumber` and `value` |
| `PhpVersionTooLowException` | `getRequiredVersion()` and `getCurrentVersion()` |
| `StringConversionFailedException` | `getFailedString()`. The string is kept out of the message and `var_dump()`, as it may be sensitive (e.g. a token) |

`FileDeletionProblem` and `CsvCellError` only hold details, so their properties are public and read-only, e.g.
`$problem->path`.
