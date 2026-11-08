# Command line scripts (`CliLib`)

`Programster\CoreLibs\CliLib` has static methods for scripts run from a terminal.

- [Progress bars](#progress-bars)
- [Reading a password](#reading-a-password)
- [See also](#see-also)

## Progress bars
`showProgressBar()` draws a progress bar the width of the terminal. Each call draws over the last one (it ends with a
carriage return rather than a newline), so calling it repeatedly animates it:

```php
use Programster\CoreLibs\CliLib;

$total = count($files);

foreach ($files as $index => $file)
{
    processFile($file);
    CliLib::showProgressBar(($index + 1) / $total * 100, 1);
}

// Move to a new line, so the next output doesn't overwrite the bar.
echo PHP_EOL;
```

The second parameter is how many decimal places to show the percentage with. A percentage below 0 or above 100 is shown
as it is, but the bar itself stops at empty or full. The terminal's width comes from `tput` on Linux and macOS, and
`mode con` on Windows, falling back to 80 columns.

To show the progress of moving a directory to another disk, see
[Progress, cancelling and resuming](moving-and-renaming.md#progress-cancelling-and-resuming).

## Reading a password
`getPasswordFromUserInput()` reads a password without showing what is typed, showing a `*` for each character (pass
`false` to show nothing, so onlookers can't see its length either):

```php
use Programster\CoreLibs\Exceptions\UserCancelledException;

echo "Password: ";

try
{
    $password = CliLib::getPasswordFromUserInput();
}
catch (UserCancelledException $e)
{
    echo PHP_EOL . "Cancelled" . PHP_EOL;
    exit(1);
}
```

- Backspace and Delete remove the last character typed, and multi-byte characters (e.g. "é") are handled.
- Ctrl+C or Ctrl+D throw a `UserCancelledException`.
- The terminal's settings are always restored afterwards, even if the user cancels.
- If the input isn't a terminal (e.g. the password is piped in), there is nothing to hide, so it just reads a line.
- It throws an `InputEndedException` if the input ends before a password is entered, and a `CommandFailedException`
  if `stty` (or PowerShell on Windows) fails, before anything is read, so the password is never shown by mistake.

It uses `stty` on Linux and macOS, and PowerShell on Windows. It is for the command line only, not for web requests.

## See also
Other methods that are useful in command line scripts:

- `Core::runCommand()`, to run a command and get an exception if it fails. See [Running commands](core.md#running-commands).
- `Core::setCliTitle()` and `Core::getNumProcRunning()`, to name and count worker processes. See
  [Naming and counting processes](core.md#naming-and-counting-processes).
- `Core::isCli()`, to tell whether a script is being run from the command line.
- `Core::setClipboard()` and `Core::getClipboard()`. See [The clipboard](core.md#the-clipboard).
