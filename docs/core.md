# System, processes and web requests (`Core`)

`Programster\CoreLibs\Core` has static methods for running commands, finding out about the system, and handling web
requests.

- [Running commands](#running-commands)
- [Memory](#memory)
- [Data sizes](#data-sizes)
- [Processors](#processors)
- [Naming and counting processes](#naming-and-counting-processes)
- [Request arguments](#request-arguments)
- [Redirects](#redirects)
- [Signing data](#signing-data)
- [The clipboard](#the-clipboard)
- [Turning warnings into exceptions](#turning-warnings-into-exceptions)
- [Other helpers](#other-helpers)

## Running commands
`runCommand()` runs a shell command and returns everything it wrote to stdout. Unlike `shell_exec()`, it throws a
`CommandFailedException` if the command fails (exits with a code other than 0), rather than returning `null`, and
returns `""` rather than `null` for a command that succeeds without writing anything.

```php
use Programster\CoreLibs\Core;
use Programster\CoreLibs\Exceptions\CommandFailedException;

$output = Core::runCommand("echo hello");        // "hello\n", including the newline

// Send something to the command's stdin.
$shouted = Core::runCommand("tr a-z A-Z", "shout"); // "SHOUT"

try
{
    Core::runCommand("ls /nope");
}
catch (CommandFailedException $e)
{
    $e->getCommand();     // "ls /nope"
    $e->getExitCode();    // 2
    $e->getErrorOutput(); // "ls: cannot access '/nope': No such file or directory\n"
}
```

Things to watch out for:

- **Escape what you put in.** The command runs through the shell, so put every value into it with
  `escapeshellarg()`, e.g. `Core::runCommand("du -sb " . escapeshellarg($path))`.
- **Pipelines.** The exit code is the last command's, so `false | true` succeeds. Use `set -o pipefail` in a bash
  command if an earlier failure matters.
- **Background processes.** A command that leaves something running with stdout still open (as `xclip` and `wl-copy`
  do, to keep serving the clipboard) makes `runCommand()` wait until that exits. Send its stdout to `/dev/null`.

## Memory
`getMemory()` gives a measurement of the system's memory, in the unit you ask for (MiB by default):

```php
use Programster\CoreLibs\Enums\DataSizeUnitEnum;
use Programster\CoreLibs\Enums\MemoryTypeEnum;

$totalMib = Core::getMemory();                                            // e.g. 15897.4
$availableGib = Core::getMemory(MemoryTypeEnum::AVAILABLE, DataSizeUnitEnum::GIB);
$swapUsedBytes = Core::getMemory(MemoryTypeEnum::SWAP_USED, DataSizeUnitEnum::BYTES);
```

The main measurements match the columns of the `free` command:

| `MemoryTypeEnum` | Means |
|---|---|
| `TOTAL` | All the usable RAM |
| `AVAILABLE` | How much can be given to new programs without swapping, including disk caches that would be freed up. This is usually the one you want for "how much memory is left?" |
| `FREE` | RAM that isn't used at all. Linux uses spare RAM for disk caches, so this is usually much lower than `AVAILABLE`, and rarely what you want |
| `USED` | `TOTAL - AVAILABLE` |
| `SHARED` | Used by tmpfs and shared memory |
| `BUFF_CACHE` | Used by kernel buffers and caches |
| `SWAP_TOTAL`, `SWAP_FREE`, `SWAP_USED` | Swap space |

On Linux, these come from `/proc/meminfo`, and every other field in it is available too (e.g. `DIRTY`, `HUGE_PAGES_TOTAL`,
`COMMITTED_AS`). Which fields exist depends on the kernel, so asking for one that isn't there throws an
`UnexpectedOutputException`. The enum's comments describe each one. Where there is no `MemAvailable` field (in WSL 1,
and on kernels older than 3.14), `AVAILABLE` and `USED` are estimated from the free memory, buffers and page cache, as
the `free` command did before that field existed.

On macOS and Windows, only `TOTAL`, `AVAILABLE`, `FREE`, `USED` and the three `SWAP_` measurements are available, and
the others throw an `UnsupportedPlatformException`. See [Supported platforms](platforms.md).

## Data sizes
`DataSizeUnitEnum` is used by `getMemory()`, `Filesystem::getFileSize()` and `Filesystem::getDirSize()`. It has the
binary units (`BYTES`, `KIB`, `MIB`, `GIB`, `TIB`, `PIB`, `EIB`, where 1 KiB is 1024 bytes) and the decimal ones (`KB`,
`MB`, `GB`, `TB`, `PB`, `EB`, where 1 kB is 1000 bytes). Each case's value is its symbol, e.g. `"MiB"`.

```php
DataSizeUnitEnum::MIB->convertFromBytes(1572864);   // 1.5
DataSizeUnitEnum::KB->getNumBytes();                // 1000

// Pick the most suitable unit for showing a size to someone.
DataSizeUnitEnum::formatBytes(1992294);             // "1.9 MiB"
DataSizeUnitEnum::formatBytes(1992294, 2, false);   // "1.99 MB"
DataSizeUnitEnum::formatBytes(512);                 // "512 B"
```

## Processors
`getNumProcessors()` gives the number of logical processors, counting every core and hyperthread (e.g. 8 for a 4 core
processor with hyperthreading), which is how many things the machine can run at the same time:

```php
$workers = Core::getNumProcessors();
```

## Naming and counting processes
`setCliTitle()` sets the current process's title (what `ps` shows) to a name followed by a number, counting up from 0
for each process already running with that name. `getNumProcRunning()` counts them, e.g. to keep a fixed number of
workers running:

```php
// In each worker: becomes "worker0", "worker1", and so on.
Core::setCliTitle("worker");

// In the script that starts them.
$running = Core::getNumProcRunning("worker");

for ($i = $running; $i < 4; $i++)
{
    Core::runCommand("php worker.php > /dev/null 2>&1 &");
}
```

`getNumProcRunning("worker")` counts titles that are the name followed by one or more digits, so it counts "worker12",
but not "worker" or "workers". On Windows, PHP sets the console window's title instead, so that is what's counted
there.

## Request arguments
`fetchReqArgsFromArray()` gets several values from an array such as `$_POST`, and throws a `BadArgumentException`
naming the first one missing. `fetchOptionalArgsFromArray()` gets whichever are there.

```php
use Programster\CoreLibs\Exceptions\BadArgumentException;

try
{
    $args = Core::fetchReqArgsFromArray(["name", "email"], $_POST);
    // ["name" => "...", "email" => "..."]
}
catch (BadArgumentException $e)
{
    // "Required parameter: email not specified"
}

$options = Core::fetchOptionalArgsFromArray(["sort", "page"], $_GET);
// Only the keys that were in $_GET.
```

A key that's there with a `null` value counts as provided, and its `null` is returned. The values are returned as
they are, so validate them before use.

## Redirects
`redirectUser()` sends a redirect and exits, so nothing after it runs by mistake. By default, it only redirects to a
path on this site, so a location taken from the request (e.g. `?next=...`) can't send the user to another site, which
attackers use to make phishing links look trustworthy (an "open redirect"):

```php
Core::redirectUser("/account?tab=2");

// Throws a BadArgumentException, as it is another site (as is anything starting with "//").
Core::redirectUser($_GET["next"]);

// Only for a location that can't come from the user, or that you've checked against a list of allowed sites.
Core::redirectUser("https://accounts.example.com/login", allowExternal: true);
```

To build the URL of the current page, see [Networking](network.md#web-requests).

## Signing data
`generateSignature()` signs some data with a secret (using HMAC-SHA256), so that when it comes back (e.g. in a link in
an email), you can tell it came from you and hasn't been changed:

```php
$secret = getenv("SIGNING_SECRET");
$data = ["user" => "5", "action" => "unsubscribe"];
$signature = Core::generateSignature($data, $secret);
$link = "https://example.com/unsubscribe?" . http_build_query($data) . "&signature=" . $signature;

// When the link is followed:
$received = $_GET;
unset($received["signature"]);

if (!Core::isValidSignedRequest($received, $_GET["signature"], $secret))
{
    // The data was changed, or didn't come from us.
}
```

The order of the keys doesn't matter, but the types of the values do, as the data is JSON encoded to sign it: `5` and
`"5"` give different signatures. Values from `$_GET` and `$_POST` are always strings, so sign strings, as above.
Signing doesn't stop a link being used more than once, or expire it, so put an expiry time in the data if you need one.

## The clipboard
`setClipboard()` and `getClipboard()` use the clipboard used by Ctrl+C and Ctrl+V. They need a desktop session: on
Linux they use `wl-copy` and `wl-paste` (from the `wl-clipboard` package) on Wayland, or `xclip` on X11, on macOS
`pbcopy` and `pbpaste`, and on Windows PowerShell.

```php
Core::setClipboard("text to paste");
$text = Core::getClipboard(); // "" if it is empty, or holds something other than text (e.g. an image)
```

They throw a `CommandFailedException` if the tool isn't installed, and an `UnsupportedPlatformException` without a
desktop session (e.g. over SSH).

## Turning warnings into exceptions
Many PHP functions report why they failed with a warning, as well as returning `false`. `runCapturingWarning()` runs
some code and captures the warning, so you can put the reason in an exception:

```php
$makeDirectory = function () use ($path): bool {
    return mkdir($path);
};

$made = Core::runCapturingWarning($makeDirectory, $warning);

if ($made === false)
{
    throw new RuntimeException("Couldn't create {$path}: {$warning}");  // e.g. "mkdir(): No such file or directory"
}
```

It uses a temporary error handler, which is always removed afterwards. Unlike `@` with `error_get_last()`, that works
when your application has its own error handler (which `error_get_last()` doesn't see past), and your handler doesn't
see the warning, just as with `@`.

## Other helpers

```php
// Whether this is running from the command line, rather than a web server.
Core::isCli();

// Print a line, with a <br /> as well in a web request.
Core::println("Done");

// var_dump() into a string, e.g. to log it.
$dump = Core::var_dump($value);

// Throw a PhpVersionTooLowException if PHP is older than this.
Core::versionGuard("8.4.0");

// Keep a value within a range: works with numbers and dates. Note that $max comes before $min.
Core::clampValue(15, 10, 0);   // 10
Core::clampValue(-3, 10, 0);   // 0
Core::clampValue($date, new DateTimeImmutable("2026-12-31"), new DateTimeImmutable("2026-01-01"));

// "Yes"/"No" and "True"/"False". false, null, "", "0", 0, 0.0 and [] are false, and everything else is true,
// including the string "false".
Core::generateYesNoString(1);            // "Yes"
Core::generateTrueFalseString([]);       // "False"
```
