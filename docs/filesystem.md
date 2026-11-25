# Files and directories (`Filesystem`)

`Programster\CoreLibs\Filesystem` has static methods for working with files, directories and paths. Moving and
renaming have their own page, [Moving and renaming](moving-and-renaming.md), and the methods that change a file's
contents safely are explained in [Changing files safely](safe-file-changes.md).

- [Listing a directory](#listing-a-directory)
- [Creating directories](#creating-directories)
- [Temporary files and directories](#temporary-files-and-directories)
- [Deleting](#deleting)
- [Replacing a directory](#replacing-a-directory)
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

`listHiddenFilesAndFolders()` lists just the hidden ones: those whose own names start with `.`, e.g. `.gitignore` or
`.cache`. With `true`, it looks in the subdirectories too, but not inside hidden folders, as listing a hidden folder
already covers everything in it. It's handy for leaving them out of a zip (see
[Zipping and unzipping](#zipping-and-unzipping)).

```php
// e.g. ["/srv/site/.gitignore", "/srv/site/.cache"]
$hidden = Filesystem::listHiddenFilesAndFolders("/srv/site");

// And those in its subdirectories, e.g. "/srv/site/photos/.thumbs".
$allHidden = Filesystem::listHiddenFilesAndFolders("/srv/site", true);
```

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

## Temporary files and directories
`tmpDir()` creates a new, empty, randomly named directory in the system's temporary directory, e.g.
`/tmp/tmp-1a2b3c4d5e6f7a8b`, and returns its path. It is always one this call made, never one that already existed,
so another process can't have made it first. Pass `prefix: "build-"` to have its name show what it's for, e.g.
`/tmp/build-1a2b3c4d5e6f7a8b`.

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

`tmpFile()` does the same for a file: a new, empty, randomly named file in the system's temporary directory, e.g.
`/tmp/tmp-1a2b3c4d5e6f7a8b`. Unlike PHP's `tempnam()`, it throws a `FilesystemException` rather than
returning `false`, and never quietly uses another directory. A temporary file often holds something private, so by
default only you can read or write it (`0600`). As with `tempnam()`, it gets exactly the permissions asked for,
whatever the umask. Pass `overrideUmask: false` to reduce them by the umask instead, as for most new files. Delete it
when you're done with it.

```php
$reportPath = Filesystem::tmpFile(prefix: "report-");     // e.g. /tmp/report-1a2b3c4d5e6f7a8b

try
{
    // ...
}
finally
{
    unlink($reportPath);
}

// So a web server's group can read it too.
$sharedPath = Filesystem::tmpFile(mode: "0640");
```

The prefix can only have letters, digits, dots, dashes and underscores (and be up to 64 characters), so it can't put
the file anywhere else. Anything else throws a `BadArgumentException`.

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

## Replacing a directory
`replaceDir()` replaces a directory with another, e.g. with a new version of it. Afterwards, the directory holds what
the new one held, the new one is gone (moved into place), and the old contents are deleted. Anything using the
directory never sees a mix of the old and new contents:

```php
// Build the new version, then swap it in, keeping a file that isn't part of it.
Filesystem::replaceDir("/srv/site", "/srv/site-new", keepPaths: ["config/local.php"]);
```

- **Where Linux's `mv --exchange` is available** (coreutils 9.5 or later, e.g. Debian 13), the two are swapped in one
  atomic step. **Elsewhere**, the old directory is moved aside, then the new one moved into its place, leaving a few
  microseconds in between when there is nothing at the path. To be atomic everywhere, reach the directory through a
  symlink, and switch the symlink with `updateSymlink()` (see [Symlinks](#symlinks)).
- **The directory keeps its permissions**, and its owner and group as `$preserveOwnership` says: `PREFERRED` (the
  default) keeps them where it can (only root can give something to another user), `YES` throws, before anything
  changes, if they can't be kept, and `NO` doesn't try.
- **`$keepPaths`** are copied from the old directory into the new one before the swap, so they are there throughout.
  Anything written to them between the copy and the swap is lost. Something already at the same place in the new
  directory throws a `BadArgumentException`, rather than one of the two being lost.
- **A new directory on another filesystem** is first moved into the work directory, by copying, which is the slow part.
  The work directory also holds the old directory while it is swapped out. By default, it is the system's temporary
  directory, if that's on the same filesystem, and otherwise the directory the replaced one is in. Pass
  `$workDirectory` to choose, on the same filesystem.
- **Everything is checked first.** A symlink (use `updateSymlink()` or `replaceSymlink()`), a mount point (e.g. a Docker
  volume, which can't be renamed), directories inside each other, or a work directory that can't be used throw before
  anything changes. If anything fails part way, the directory is left as it was.

`replaceSymlink()` does the same for a symlink: it puts a real directory where the symlink was, leaving what the
symlink pointed to alone. The new directory is given that directory's permissions, and `$keepPaths` are copied from it.
A broken symlink can be replaced too, but only without `$keepPaths`, as there is nothing to keep them from:

```php
// /srv/site/current was a symlink, and is now a real directory, holding what /srv/site-new held.
Filesystem::replaceSymlink("/srv/site/current", "/srv/site-new");
```

On Windows, there's no `mv --exchange`, so the old directory is always moved aside first. Windows has no Unix
permissions or owners, so they aren't copied there. Something that has a file open in a directory (often antivirus or
the search indexer, briefly) stops it being renamed, so each rename is tried a few times before giving up.

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

`getFreeDiskSpace()` gives how much space is free for you on the filesystem (disk or partition) that a file or
directory is on. Unlike PHP's `disk_free_space()`, it throws a `FilesystemException` with the reason rather than
returning `false`, and takes a file as well as a directory.

```php
$freeBytes = Filesystem::getFreeDiskSpace("/srv/uploads");
$freeGib = Filesystem::getFreeDiskSpace("/srv/uploads", DataSizeUnitEnum::GIB);
```

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

// Or create it, the same way, if it doesn't exist yet. Without this, a missing file throws a FileDoesNotExistException,
// so a mistyped path is caught.
Filesystem::replaceFileContents("/etc/app/config.json", json_encode($config, JSON_PRETTY_PRINT), true);
```

Unlike `file_put_contents()`, it throws a `FileDoesNotExistException` rather than creating a file that isn't there, so
a mistyped path is an error. To build the new contents a piece at a time, use `createReplacementFile()` and
`replaceWithFile()`, explained in [Changing files safely](safe-file-changes.md).

## Zipping and unzipping
These need the `zip` extension.

```php
use Programster\CoreLibs\Enums\ZipCompressionEnum;
use Programster\CoreLibs\Models\ZipCompressionEnumCollection;

// Gives a zip with a single "project" folder in it. The third parameter is $deleteOnComplete, which defaults to true!
Filesystem::zipDir("/srv/project", "/backups/project.zip", false);

// Just what's in the folder, without the folder itself.
Filesystem::zipDir("/srv/project", "/backups/project.zip", false, includeFolder: false);

// Store the files as they are, without compressing them: much quicker for files that are already compressed, such as
// images, or .gz files, and the zip is barely bigger. The default is ZipCompressionEnum::DEFLATE.
Filesystem::zipDir("/srv/photos", "/backups/photos.zip", false, compression: ZipCompressionEnum::STORE);

// Use Zstandard if this PHP can, and otherwise DEFLATE.
$zstdIfPossible = new ZipCompressionEnumCollection(ZipCompressionEnum::ZSTD, ZipCompressionEnum::DEFLATE);
Filesystem::zipDir("/srv/logs", "/backups/logs.zip", false, compression: $zstdIfPossible);

// Leave things out, either absolute or relative to the folder. Leaving out a folder leaves out everything in it.
Filesystem::zipDir("/srv/site", "/backups/site.zip", false, excludePaths: ["cache", "config/secrets.php"]);

// Leave out every hidden file and folder.
Filesystem::zipDir(
    "/srv/site",
    "/backups/site.zip",
    false,
    excludePaths: Filesystem::listHiddenFilesAndFolders("/srv/site", true)
);

// Extract into /srv/restored, creating it if needed. The third parameter is also $deleteOnComplete.
Filesystem::unzip("/backups/project.zip", "/srv/restored", false);
```

**Compression**: `DEFLATE` (the default) is what zips normally use, and every zip tool can read it. `STORE` stores the
files as they are. `BZIP2`, `XZ` and `ZSTD` usually make smaller zips, but can only be used if PHP's zip extension was
built with them (`ZipCompressionEnum::ZSTD->isSupported()` says), and **many tools can't open them**, including
Windows' and macOS's built-in zip support, so only use them where you know what will extract the zip. One that can't
be used throws an `UnsupportedPlatformException`, before any zip file is made. A `ZipCompressionEnumCollection` tries
each in turn, and uses the first that can be used, so end it with `DEFLATE` to always get a zip.

**Both delete their source by default** (`$deleteOnComplete`), so pass `false` to keep it. With `$deleteOnComplete`,
what `$excludePaths` leaves out of the zip is deleted along with the rest.

A path in `$excludePaths` that doesn't exist, or isn't inside the folder, would leave nothing out, so it throws a
`BadArgumentException`, before any zip is created.

`zipDir()` includes empty directories, and stores symlinks as symlinks. Those pointing inside the folder are stored
relative to themselves, so they still work wherever the zip is extracted. Those pointing outside are stored as they
are, or with `followSymlinks: true`, what they point to is zipped in their place. A broken symlink stops it, and no zip
is left behind. It throws a `FileAlreadyExistsException` if the zip file already exists.

`unzip()` extracts into a temporary directory first, then moves everything into place, so nothing appears in the
destination until it is complete, and a failure part way leaves nothing behind. Existing files with the same names are
replaced, and existing directories are merged into, as with `ZipArchive::extractTo()`, but anything that can't be
(e.g. a file where there is a directory) is checked for first.

**A zip that won't fit is refused before anything is written.** `unzip()` adds up the sizes the zip gives for its
contents, and throws a `NotEnoughDiskSpaceException` if they're more than the free space where they'd go, rather than
filling the disk part way through. A small zip can extract to something far larger (a "zip bomb"), so for zips from
users, also give the most they may extract to, and it throws a `ZipTooLargeException` for one that would extract to
more:

```php
// At most 1 GiB once extracted.
Filesystem::unzip($uploadedZip, "/srv/restored", false, maxExtractedBytes: 1073741824);
```

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

`updateSymlink()` points an existing symlink somewhere else, **atomically**: a new symlink is made, then renamed over
the old one, so anything using the link at that moment finds either the old target or the new one, never a mix, and
never nothing. It returns the old target, as it was stored, and deletes nothing.

That makes it the way to swap a whole directory for another in one go, which a directory itself can't do (a rename
can't replace a directory that has anything in it). Keep each version in a directory of its own, point a symlink at
the one in use, and switch with `updateSymlink()`:

```php
// /srv/site/current is a symlink to releases/41, and releases/42 is ready.
$oldRelease = Filesystem::updateSymlink("/srv/site/current", "releases/42");   // "releases/41"

// Nothing uses the old one any more, so it can go.
Filesystem::deleteDir("/srv/site/{$oldRelease}");
```

The new target is stored as it's given, so a relative one is relative to the link's directory, as with `symlink()`. It
must exist, so a mistyped path can't leave the link broken (a `FileDoesNotExistException`). A real file or directory
at `$link` throws a `BadArgumentException`, rather than being replaced.

The new symlink is made in the system's temporary directory first, if that's on the same filesystem as the link, and
otherwise next to the link, as a hidden symlink, for an instant. Pass `$workDirectory` to choose where instead, e.g. so
nothing ever appears next to a link in a directory served to the public. A rename can't move anything to another
filesystem, so it must be on the same one as the link, or a `BadArgumentException` is thrown, before anything changes.

```php
Filesystem::updateSymlink("/var/www/current", "releases/42", "/var/www-work");
```

On Windows, PHP can't create a relative symlink, so the new one is given its target's absolute path. Windows can also
refuse to rename a symlink over one to a directory, in which case the old one is removed first and the new one put in
its place, which isn't atomic.

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

`chown()` and `chgrp()` change who owns a file or directory. Unlike PHP's, they throw a `FilesystemException` with the
reason (e.g. there's no such user, or it isn't allowed) rather than returning `false`. **A symlink is changed itself,
not what it points to**, so code running as root can't be tricked into changing the owner of something somewhere else
(e.g. a link to `/etc/shadow`). Pass `followSymlinks: true` to change what it points to instead, as PHP's do.

```php
// Give an upload to the web server's user and group, by name or ID.
Filesystem::chown("/srv/uploads/photo.jpg", "www-data");
Filesystem::chgrp("/srv/uploads/photo.jpg", "www-data");

// Change what a symlink points to, rather than the symlink.
Filesystem::chown("/srv/current", "deploy", followSymlinks: true);
```

Only root can give a file to another user, and a file's owner can only give it to a group they're in. They throw an
`UnsupportedPlatformException` on Windows, where PHP can't change a file's owner.
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
`lockFile()` takes an advisory lock, waiting until it can be had, and `tryLockFile()` takes one only if it can be had
straight away. A lock only stops other processes that also ask for one. It doesn't stop anything else changing the file.
The file is created if it doesn't exist, and is never emptied.

```php
use Programster\CoreLibs\Enums\FileLockTypeEnum;

// An exclusive lock (the default), waiting until it can be had.
Filesystem::lockFile("/tmp/app.lock");

try
{
    // ...
}
finally
{
    Filesystem::unlockFile("/tmp/app.lock");
}

// Don't wait: false means something else has it.
if (Filesystem::tryLockFile("/tmp/app.lock") === false)
{
    exit("Already running" . PHP_EOL);
}

Filesystem::unlockFile("/tmp/app.lock");
```

Pass `FileLockTypeEnum::SHARED` for a shared (read) lock, which several processes can have at once, but not while one
has an exclusive lock.

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
