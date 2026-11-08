# Changing files safely

The methods that change a file's contents never write over it directly. They write the new contents to a temporary
file, and only replace the original once that is complete, so a failure part way through (an exception, the disk
filling up, a bad row of data) leaves the original as it was. This page explains how that works, and the two
optional parameters that control it.

- [Which methods do this](#which-methods-do-this)
- [How a file is replaced](#how-a-file-is-replaced)
- [Where the temporary file goes (`UseSystemTempDirEnum`)](#where-the-temporary-file-goes-usesystemtempdirenum)
- [Keeping the owner and group (`PreserveOwnershipEnum`)](#keeping-the-owner-and-group-preserveownershipenum)
- [Doing it yourself](#doing-it-yourself)

## Which methods do this
- `Filesystem::replaceFileContents()`, `fileFilter()` and `convertFileToUtf8()`
- `CsvLib::convertArrayToCsv()`, `convertCsvToJson()`, `trim()`, `removeColumns()`, `removeRows()` and `mergeFiles()`,
  and the matching `CsvFile` methods

Each takes two optional parameters at the end, `$useSystemTempDir` and `$preserveOwnership`, which default to `AUTO`
and `PREFERRED`. The defaults are right for nearly everything, but read on if the file is in a directory served to the
public, or its owner matters.

## How a file is replaced
Once the temporary file is complete, it replaces the original in one of two ways:

- **Renaming it over the original.** This is instant, and atomic: anything reading the file sees either all of the old
  contents or all of the new, never a mix. It is only done when it is safe:
    - the temporary file is on the same filesystem as the original,
    - the original is a regular file (not a symlink) with no other hard links (which would keep the old contents),
    - its directory can be written to,
    - its owner and group can be kept (as root, or if the file already belongs to whoever is running this), unless
      `PreserveOwnershipEnum::NO` says not to bother,
    - and with SELinux enabled, the temporary file is next to the original, as one from `/tmp` would keep `/tmp`'s
      SELinux label, which can stop services reading it.

  The renamed file gets the original's permissions, but not any ACLs or extended attributes it had.

- **Copying the new contents back into the original.** This keeps everything about the file: its owner, group,
  permissions, ACLs, extended attributes and hard links, and only needs the file (not its directory) to be writable. It
  takes as long as writing the contents did, and isn't atomic: if it fails part way (e.g. the disk fills up), the
  original may be half written. The temporary file is kept if that happens, and its path is in the exception's message,
  so the new contents aren't lost.

## Where the temporary file goes (`UseSystemTempDirEnum`)
The temporary file is private (created with `0600` permissions, so other users can't read it), and goes either in the
system's temporary directory (e.g. `/tmp`), or next to the original as a hidden file (e.g. `.corelibs-tmp-AbC123`).

| `UseSystemTempDirEnum` | Puts it |
|---|---|
| `AUTO` (the default) | Wherever will work, then wherever is fastest. That is the system's temporary directory if it is held in memory (a tmpfs, as `/tmp` is on Debian 13) and there's room, or if it is on the same filesystem as the original and the file could be renamed from there. Otherwise next to the original if the file could then be renamed, and otherwise in the system's temporary directory, unless there isn't room for it there |
| `ALWAYS` | Always in the system's temporary directory, so nothing appears next to the original, even for a moment |
| `NEVER` | Always next to the original, so it can be renamed over it, where that is safe |
| `WHEN_SAME_FS` | In the system's temporary directory if it is on the same filesystem as the original, and otherwise next to it, e.g. so a very large file isn't written to another disk |

**For a directory served to the public** (e.g. by a web server), pass `UseSystemTempDirEnum::ALWAYS`, as `AUTO` can put
the hidden file next to the original for a moment:

```php
use Programster\CoreLibs\CsvLib;
use Programster\CoreLibs\Enums\UseSystemTempDirEnum;

CsvLib::trim("/var/www/html/downloads/prices.csv", useSystemTempDir: UseSystemTempDirEnum::ALWAYS);
```

## Keeping the owner and group (`PreserveOwnershipEnum`)
A new file belongs to whoever creates it, and only root can give a file to another user, so a file renamed into place
can end up with a different owner. `PreserveOwnershipEnum` says how hard to try to stop that:

| `PreserveOwnershipEnum` | Does |
|---|---|
| `NO` | Doesn't worry about it, and takes the fastest route, even if the file then belongs to whoever is running this |
| `PREFERRED` (the default here) | Keeps the owner and group if there is any way to, even if that is slower, and otherwise carries on without |
| `YES` | Keeps them, taking the fastest route that does, and throws rather than changing anything if they can't be kept |

When replacing a file's contents, copying them back always keeps the owner and group, so `PREFERRED` and `YES` behave the
same: the file is only renamed if they would be kept, and copied back otherwise.

The same enum is used when [moving files and directories](moving-and-renaming.md) to another filesystem, where it does
make a difference, and defaults to `YES`.

## Doing it yourself
`createReplacementFile()` and `replaceWithFile()` let you write a file's new contents a piece at a time, in the same way,
e.g. to rewrite a huge file line by line without changing the original until you're done:

```php
use Programster\CoreLibs\Filesystem;

$filepath = "/data/huge.log";
Filesystem::checkCanChangeFile($filepath);
$tmpFilepath = Filesystem::createReplacementFile($filepath);

try
{
    $input = fopen($filepath, "r");
    $output = fopen($tmpFilepath, "w");

    while (($line = fgets($input)) !== false)
    {
        fwrite($output, strtoupper($line));
    }

    fclose($input);
    fclose($output);
}
catch (Throwable $e)
{
    unlink($tmpFilepath);
    throw $e;
}

// Rename or copy it into place, and delete it.
Filesystem::replaceWithFile($filepath, $tmpFilepath);
```

The temporary file must be closed before `replaceWithFile()`. Pass the same `UseSystemTempDirEnum` and
`PreserveOwnershipEnum` to both, as `createReplacementFile()` uses the ownership setting to choose where the file can go.
Real code should also check that `fopen()` and `fwrite()` succeed.

For a file whose new contents you already have as a string, `replaceFileContents()` does all of this in one call.
