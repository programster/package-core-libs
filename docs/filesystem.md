# Files and directories (`Filesystem`)

`Programster\CoreLibs\Filesystem` has static methods for working with files, directories and paths. Moving and
renaming have their own page, [Moving and renaming](moving-and-renaming.md), and the methods that change a file's
contents safely are explained in [Changing files safely](safe-file-changes.md).

- [Listing a directory](#listing-a-directory)
- [Creating directories](#creating-directories)
- [Temporary directories](#temporary-directories)
- [Deleting](#deleting)
- [Sizes](#sizes)
- [Reading and filtering a file line by line](#reading-and-filtering-a-file-line-by-line)
- [Replacing a file's contents](#replacing-a-files-contents)
- [Zipping and unzipping](#zipping-and-unzipping)
- [Paths](#paths)
- [Symlinks](#symlinks)
- [Permissions](#permissions)
- [Filesystems](#filesystems)
- [Locking a file](#locking-a-file)
- [Downloading a file](#downloading-a-file)
- [Sending a file to the browser](#sending-a-file-to-the-browser)
- [Text encoding](#text-encoding)
- [File extensions](#file-extensions)

## Listing a directory
`getDirContents()` lists the files in a directory, and by default everything below it too. `getDirectories()` lists
only the directories. `listDirContents()` and `listDirectories()` are the same, for those who look for "list".

```php
use Programster\CoreLibs\Filesystem;

// Every file below /srv/site, as absolute paths.
$files = Filesystem::getDirContents("/srv/site");

// Just the names of what is directly in it, including directories: $recursive, $includePath, $onlyFiles.
$names = Filesystem::getDirContents("/srv/site", false, false, false);

// Leave out hidden files and folders (those starting with ".").
$visible = Filesystem::getDirContents("/srv/site", includeHiddenFilesAndFolders: false);

// The directories directly in /srv/site, or with true, all of them below it.
$directories = Filesystem::getDirectories("/srv/site");
$allDirectories = Filesystem::getDirectories("/srv/site", true);
```

The results are in no particular order, so `sort()` them if the order matters.

- **Paths** are absolute (a relative path is taken from the current directory), and keep any symlinks in the path you
  gave, e.g. listing `/srv/current` (a symlink to `/srv/releases/42`) gives `/srv/current/...`. Pass
  `resolveSymlinks: true` for the real paths instead. Either way, they use `/`, including on Windows.
- **Symlinks** to directories are listed, but not gone into, unless you pass `followSymlinks: true`. Even then, one that
  points back up to a directory it is in (e.g. `..`) is never gone into, as it would go round in circles.
- **Unreadable subdirectories** throw a `FilesystemException` naming them, so you never get an incomplete list without
  knowing. Pass `skipUnreadable: true` to leave them out instead.

## Creating directories
`mkdir()` creates a directory if it doesn't already exist, and throws a `FilesystemException` if it can't:

```php
Filesystem::mkdir("/srv/uploads");

// Also create any missing parent directories.
Filesystem::mkdir("/srv/data/2026/11", recursive: true);

// Private to this user, whatever the umask.
Filesystem::mkdir("/srv/secrets", "0700");
```

By default, the parent must already exist, so a mistyped path fails rather than creating a chain of directories you
didn't mean to. As with PHP's `mkdir()`, the permissions (`"0777"` by default) are reduced by the umask, so usually
give `0755`. Pass `overrideUmask: true` to give exactly the permissions asked for, but pass the permissions too, as
`"0777"` would then make it writable by everyone.

`$perms` is a string of 3 or 4 octal digits, optionally starting with `0` or `0o`, e.g. `"755"`, `"0755"` or
`"0o2775"`. Anything else throws a `BadArgumentException`, rather than being misread. To use the same format with
PHP's `chmod()`, use `permissionsStringToInt()`:

```php
chmod($path, Filesystem::permissionsStringToInt("0640"));
```

## Temporary directories
`tmpDir()` creates a new, empty, randomly named directory in the system's temporary directory, e.g.
`/tmp/corelibs-dir-1a2b3c4d5e6f7a8b`, and returns its path. It is always one this call made, never one that already
existed, so another process can't have made it first.

```php
$workDir = Filesystem::tmpDir("0700");

try
{
    // ...
}
finally
{
    Filesystem::deleteDir($workDir);
}
```

## Deleting
`deleteDir()` deletes a directory and everything in it. `emptyDir()` deletes everything in it, leaving the directory.

```php
Filesystem::deleteDir("/tmp/build");
Filesystem::emptyDir("/var/cache/app");
```

Everything is checked before anything is deleted, so if anything can't be deleted, nothing is, and a
`DirectoryNotDeletableException` lists what, and why:

```php
use Programster\CoreLibs\Exceptions\DirectoryNotDeletableException;

try
{
    Filesystem::deleteDir("/srv/old-site");
}
catch (DirectoryNotDeletableException $e)
{
    foreach ($e->getProblems() as $problem)
    {
        // $problem->path is what can't be deleted, $problem->directory is what needs its permissions changing,
        // and $problem->reason is a FileDeletionProblemEnum, e.g. DIRECTORY_NOT_WRITABLE.
        echo "{$problem->path}: {$problem->reason->name}" . PHP_EOL;
    }
}
```

The reasons are `DIRECTORY_NOT_LISTABLE`, `DIRECTORY_NOT_WRITABLE`, `NOT_OWNED_IN_STICKY_DIRECTORY` (in a directory such
as `/tmp`, only a file's owner can delete it) and `READ_ONLY_ON_WINDOWS`. A few things can't be seen beforehand, such
as an immutable file, a read-only filesystem, or another process changing the directory at the same time. If one of
those happens, a `FilesystemException` is thrown part way through.

**Symlinks are never followed by default.** A symlink inside is removed without touching what it points to, and if the
directory you give is itself a symlink, `deleteDir()` throws a `BadArgumentException` rather than guessing whether you
meant the link or the directory. Use `removeSymlink()` to remove just the link. With `followSymlinks: true`, what the
symlinks point to is deleted too (never one pointing up to a directory that contains it, and nothing twice).

They use the same memory however many files there are, so are fine for very large trees.

## Sizes

```php
use Programster\CoreLibs\Enums\DataSizeUnitEnum;

$bytes = Filesystem::getFileSize("/srv/video.mp4");
$mib = Filesystem::getFileSize("/srv/video.mp4", DataSizeUnitEnum::MIB);

// A directory and everything in it (or a single file).
$gib = Filesystem::getDirSize("/srv/uploads", DataSizeUnitEnum::GIB);

echo DataSizeUnitEnum::formatBytes(Filesystem::getDirSize("/srv/uploads")); // e.g. "2.4 GiB"
```

Both return a float. `getFileSize()` always gets the current size, unlike `filesize()`, whose result PHP caches during a
script. `getDirSize()` gives the total size of the files, as `du --bytes` does, rather than the space they take up on
disk. Symlinks aren't followed, and a file with several hard links is only counted once. It uses `du` on Linux, and adds
the sizes up itself elsewhere, giving the same result.

## Reading and filtering a file line by line
`fileWalk()` calls a function with each line of a file, and `fileFilter()` keeps only the lines a function returns true
for. Neither reads the whole file into memory. Each line includes its line ending.

```php
$errorCount = 0;

$countErrors = function (string $line) use (&$errorCount): void {
    if (str_starts_with($line, "ERROR"))
    {
        $errorCount++;
    }
};

Filesystem::fileWalk("/var/log/app.log", $countErrors);

$isError = function (string $line): bool {
    return str_starts_with($line, "ERROR");
};

// Write the errors to another file, or leave the third parameter out to filter the file itself.
Filesystem::fileFilter("/var/log/app.log", $isError, "/var/log/errors.log");
```

`fileFilter()` writes to a temporary file first, so if anything fails (including your function throwing), the file is
left as it was. See [Changing files safely](safe-file-changes.md).

## Replacing a file's contents
`replaceFileContents()` is a safer `file_put_contents()` for a file that already exists: the new contents are written
to a temporary file first, so a failure (e.g. the disk filling up) leaves the original as it was, and the file keeps
its owner and permissions. Where it can, it renames the new file into place, so anything reading the file never sees
half of the new contents.

```php
Filesystem::replaceFileContents("/etc/app/config.json", json_encode($config, JSON_PRETTY_PRINT));
```

Unlike `file_put_contents()`, it throws a `FileDoesNotExistException` rather than creating a file that isn't there, so
a mistyped path is an error. To build the new contents a piece at a time, use `createReplacementFile()` and
`replaceWithFile()`, explained in [Changing files safely](safe-file-changes.md).

## Zipping and unzipping
These need the `zip` extension.

```php
// Gives a zip with a single "project" folder in it. The third parameter is $deleteOnComplete, which defaults to true!
Filesystem::zipDir("/srv/project", "/backups/project.zip", false);

// Just what's in the folder, without the folder itself.
Filesystem::zipDir("/srv/project", "/backups/project.zip", false, includeFolder: false);

// Extract into /srv/restored, creating it if needed. The third parameter is also $deleteOnComplete.
Filesystem::unzip("/backups/project.zip", "/srv/restored", false);
```

**Both delete their source by default** (`$deleteOnComplete`), so pass `false` to keep it.

`zipDir()` includes empty directories, and stores symlinks as symlinks. Those pointing inside the folder are stored
relative to themselves, so they still work wherever the zip is extracted. Those pointing outside are stored as they
are, or with `followSymlinks: true`, what they point to is zipped in their place. A broken symlink stops it, and no zip
is left behind. It throws a `FileAlreadyExistsException` if the zip file already exists.

`unzip()` extracts into a temporary directory first, then moves everything into place, so nothing appears in the
destination until it is complete, and a failure part way leaves nothing behind. Existing files with the same names are
replaced, and existing directories are merged into, as with `ZipArchive::extractTo()`, but anything that can't be
(e.g. a file where there is a directory) is checked for first.

**A zip with a symlink pointing outside the destination is refused**, as an untrusted zip could use one to make later
code read or write somewhere else (e.g. `/etc/passwd`). Pass `trusted: true` for zips from a source you trust. On
Windows, see [Supported platforms](platforms.md) for how symlinks are created.

## Paths
These work from the path alone, without checking the filesystem:

```php
Filesystem::normalisePath("/a/./b/../c/");                  // "/a/c"
Filesystem::normalisePath("a/../../b");                     // "../b"
Filesystem::relativePathBetween("/srv/a/b", "/srv/c/d.txt"); // "../../c/d.txt"
Filesystem::isAbsolutePath("relative/path");                // false
```

These follow symlinks, so give the real answer:

```php
// The real path, like realpath(), but works for a path that doesn't exist yet.
// With /srv/link pointing at /data:
Filesystem::resolvePath("/srv/link/new/file.txt");          // "/data/new/file.txt"

// Whether a path is inside a directory (or is it).
Filesystem::isWithinDirectory($uploadedPath, "/srv/uploads");
```

`isWithinDirectory()` follows symlinks in both paths by default, which is what a check like "is this inside the uploads
directory?" needs: reading or writing the path goes wherever its symlinks lead, so `/srv/uploads/link/passwd` with
`link` pointing at `/etc` isn't inside `/srv/uploads`. Pass `false` as the third parameter to compare the paths as they
are written instead.

`resolveLinkTarget($linkDirectory, $target)` works out the absolute path a symlink's target (e.g. from `readlink()`)
points to, as the system would, even for a broken link.

On Windows, `\` is a separator too, drive letters and UNC paths (`\\server\share`) are absolute, and these methods give
paths with `/`.

## Symlinks
`removeSymlink()` removes a symlink, leaving what it points to. On Windows, a symlink to a directory has to be removed
with `rmdir()` rather than `unlink()`, which this handles. It throws a `BadArgumentException` if the path isn't a
symlink, so it can never delete a real file or directory by mistake.

```php
Filesystem::removeSymlink("/srv/current");
```

## Permissions

```php
// A file's type and permissions as ls -l shows them.
Filesystem::fileperms("/srv/script.sh");  // "-rwxr-xr-x"
Filesystem::fileperms("/tmp");            // "drwxrwxrwt"

// Whether a directory can be entered, written to, or listed.
Filesystem::canEnterDirectory("/srv/data");
Filesystem::canWriteToDirectory("/srv/data");
Filesystem::canListDirectory("/srv/data");

// Throw if a file can't be both read and written, before you start changing it.
Filesystem::checkCanChangeFile("/srv/data/report.csv");
```

`fileperms()` reports a symlink itself (`"lrwxrwxrwx"`) rather than what it points to, so works for a broken one.
`canWriteToDirectory()` and `canListDirectory()` give the right answer on Windows for directories in WSL, where PHP's
`is_writable()` and `is_readable()` say no.

## Filesystems

```php
// Whether two paths are on the same disk or partition, so something can be renamed between them rather than copied.
Filesystem::isOnSameFilesystem("/srv/uploads", "/tmp");

// Whether a path is held in memory (a tmpfs, e.g. /dev/shm), so is fast, but lost on restart.
Filesystem::isTmpfs("/tmp");

// Whether SELinux is enabled, which labels every file.
Filesystem::isSelinuxEnabled();

// Whether a rename only changes the case of the name, on a filesystem that ignores case (e.g. macOS or Windows).
Filesystem::isCaseOnlyRename("/srv/report.txt", "/srv/Report.txt");
```

`isOnSameFilesystem()` needs absolute paths, and checks one that doesn't exist yet by the nearest directory above it
that does.

## Locking a file
`lockFile()` takes an advisory lock, which only stops other processes that also ask for a lock. It doesn't stop anything
else changing the file.

```php
// An exclusive (write) lock, waiting until it can be had.
Filesystem::lockFile("/var/run/app.lock", true, true);

try
{
    // ...
}
finally
{
    Filesystem::unlockFile("/var/run/app.lock");
}

// Don't wait: false means something else has it.
if (Filesystem::lockFile("/var/run/app.lock", true, false) === false)
{
    exit("Already running" . PHP_EOL);
}
```

Pass `false` as the second parameter for a shared (read) lock, which several processes can have at once.

## Downloading a file
`downloadFile()` downloads a URL into a temporary file and returns its path, streaming it to disk rather than holding it
in memory. It uses curl if it is installed.

```php
$path = Filesystem::downloadFile("https://example.com/data.csv");
```

An HTTP error (a status of 400 or more, such as 404) throws a `FilesystemException`, rather than downloading the error
page, and the temporary file is removed. Only `http://` and `https://` URLs are allowed, including redirects, so it
can't be made to read a local file. It can still reach anything your server can, though, including services only meant
to be reachable from inside your network (e.g. `http://169.254.169.254/`, a cloud provider's metadata service), so check
the host of any URL that comes from a user.

## Sending a file to the browser
`streamFileToBrowser()` sends a file as the response to a web request, e.g. a report download:

```php
Filesystem::streamFileToBrowser("/srv/reports/42.pdf", "Report for résumé.pdf");
exit;
```

```php
// Show it in the browser (e.g. a PDF) rather than downloading it.
Filesystem::streamFileToBrowser("/srv/reports/42.pdf", "report.pdf", asAttachment: false);
exit;
```

The download name can contain any characters. The MIME type is worked out from the file (pass one as the third
parameter, or `null` to send none). Nothing may have been output before it, as it sends headers, and it doesn't exit,
so stop afterwards if anything else would be output.

## Text encoding
`isValidUtf8File()` checks a file's encoding, a chunk at a time. `convertFileToUtf8()` converts a file to UTF-8 in place,
and needs the `mbstring` extension.

```php
if (!Filesystem::isValidUtf8File("/data/import.csv"))
{
    Filesystem::convertFileToUtf8("/data/import.csv", "Windows-1252");
}
```

The encoding of a file can't be reliably detected (in single-byte encodings such as Windows-1252 and Windows-1251, every
byte is valid, but means a different character), so tell it what the file is in. `"Windows-1252"` is the default, which
is common for files from Western European versions of Windows and Excel. It leaves a file that is already UTF-8 alone,
and uses a UTF-16 or UTF-32 byte order mark if there is one, removing it. mbstring doesn't support `"Windows-1250"`.

## File extensions
`getFileExtension()` gives the extension of a file name or path, without the dot, keeping its case:

```php
Filesystem::getFileExtension("/var/v1.2/photo.JPG");  // "JPG"
Filesystem::getFileExtension("archive.tar.gz");       // "gz"
Filesystem::getFileExtension(".bashrc");              // throws NoFileExtensionException
```

It throws a `NoFileExtensionException` for a name with no extension, such as `"README"`, `"file."` or `".bashrc"` (the
dot at the start of a hidden file isn't an extension, unlike with PHP's `pathinfo()`). `".config.json"` gives `"json"`.
