# Moving and renaming (`File`, `Directory`)

`Programster\CoreLibs\Models\File` and `Programster\CoreLibs\Models\Directory` move and rename files and directories.
Unlike PHP's `rename()`, they work across filesystems (copying, then deleting the original), check everything they can
before changing anything, never replace a directory, keep symlinks pointing at the same things, and can keep owners and
permissions. `Filesystem::moveFile()` and `Filesystem::moveDir()` are shortcuts to them.

- [Renaming](#renaming)
- [Moving](#moving)
- [The shortcuts](#the-shortcuts)
- [What is kept](#what-is-kept)
- [Symlinks](#symlinks)
- [When a directory can't be moved](#when-a-directory-cant-be-moved)
- [Progress, cancelling and resuming](#progress-cancelling-and-resuming)

## Renaming
`rename()` gives a file or directory a new name in the same directory. It takes only a name, not a path, and throws a
`BadArgumentException` for one with a `/` in it. Use `move()` to put it somewhere else.

```php
use Programster\CoreLibs\Models\Directory;
use Programster\CoreLibs\Models\File;

$file = new File("/srv/reports/draft.txt");
$file->rename("final.txt");

$directory = new Directory("/srv/releases/next");
$directory->rename("42");
```

A file with the new name is only replaced if you pass `true` as `File::rename()`'s second parameter
(`$overwriteExistingFile`), and a directory is never replaced. Changing just the case of a name (e.g. `report.txt` to
`Report.txt`) works on filesystems that ignore case, such as macOS's and Windows'.

## Moving
`move()` moves a file or directory into another directory, keeping its name, or giving it `$newName` there:

```php
$file = new File("/srv/uploads/photo.jpg");
$file->move("/srv/archive/2026");

// Create the directory (and its parents) if it doesn't exist.
$file->move("/srv/archive/2026/11", createIfNotExists: true);

// Give it a new name there.
$file->move("/srv/archive", newName: "photo-old.jpg");

$directory = new Directory("/srv/uploads");
$directory->move("/mnt/backup");

echo $directory->getPath(); // "/mnt/backup/uploads"
```

A relative path is relative to where the file or directory is now, not the current working directory. After a rename
or move, `getFilepath()` (or `getPath()`) gives the new absolute path.

Within one filesystem, a move is a single, instant rename. To another filesystem (e.g. another disk), it can't be, so
everything is copied there, and the original is only deleted once the whole copy has worked. If the copy fails, it is
removed, and the original is left as it was. A directory is copied under a hidden name, then renamed into place once it
is complete, so an interrupted copy (e.g. by a timeout, or Ctrl+C) never looks like a finished one.

## The shortcuts
`Filesystem::moveFile()` and `Filesystem::moveDir()` take the path the file or directory should have afterwards, as
PHP's `rename()` does. A relative path is relative to the current working directory.

```php
use Programster\CoreLibs\Filesystem;

Filesystem::moveFile("/srv/uploads/photo.jpg", "/srv/archive/photo-2026.jpg");
Filesystem::moveDir("/srv/uploads", "/mnt/backup/uploads-2026");
```

The destination's directory must already exist, and for `moveDir()`, nothing may be at the destination.

## What is kept
Within one filesystem, nothing is copied, so everything is kept exactly. Moving to another filesystem copies, so:

- **Owner and group.** A copy belongs to whoever makes it, and only root can give a file to another user. With
  `PreserveOwnershipEnum::YES` (the default here), the move checks first that every owner and group can be kept, and
  doesn't move anything if not: when not running as root, that means everything must belong to you, and to a group
  you're in. Pass `PreserveOwnershipEnum::PREFERRED` to keep them where possible, or `NO` not to try. `YES` needs the
  `posix` extension.
- **Permissions.** A copy's permissions are the original's, reduced by the umask, as for any new file (e.g. `0664`
  becomes `0644` with a umask of `022`). Pass `overrideUmask: true` to `Directory::move()` to give them exactly the
  original's, stopping the move if that can't be done.
- **Modification times** are kept.
- **ACLs and extended attributes** can't be copied by PHP, so are lost (apart from on Windows, below).

```php
use Programster\CoreLibs\Enums\PreserveOwnershipEnum;

// Running as a normal user, copying files that belong to others: let the copies belong to us.
$directory->move("/mnt/backup", preserveOwnership: PreserveOwnershipEnum::PREFERRED);
```

On Windows, a move to another drive gives the copies their originals' owners and permissions (ACLs) using PowerShell.
Setting another user as the owner needs administrator rights, so with `YES`, a move that can't keep them all is undone.

## Symlinks
A symlink is moved as the link itself, never what it points to, and is kept pointing at the same thing:

- **A `File` that is a symlink** with a relative target (e.g. `data.txt`, rather than `/srv/data.txt`) would point
  somewhere else from a new directory, so it is created again with its target worked out from there (e.g.
  `../data.txt`). It stays relative.
- **Symlinks inside a `Directory`** that point inside it are changed to point at the new location, and those that point
  outside keep pointing at the same place. Relative ones stay relative, and absolute ones stay absolute. A relative one
  that still works from the new location is left exactly as it is.

A symlink that has to be created again ends up owned by whoever runs the move. That rarely matters, as a symlink's
permissions are ignored, but it does in a "sticky" directory such as `/tmp`, where only its owner can delete it.
Symlinks elsewhere that point into a moved directory can't be found, so they will break.

`new Directory()` throws a `BadArgumentException` for a symlink to a directory, as moving it would move the link, not the
directory. Use `File` for the link instead.

On Windows, PHP can't create relative or broken symlinks, so any symlink that has to be created again is made with an
absolute target, and a broken one stops the move. See [Supported platforms](platforms.md).

## When a directory can't be moved
Everything is checked before anything is moved, and if anything would stop the move, it throws a
`DirectoryNotMovableException`, listing every problem found (up to 100), without changing anything:

```php
use Programster\CoreLibs\Enums\DirectoryMoveProblemEnum;
use Programster\CoreLibs\Exceptions\DirectoryNotMovableException;

try
{
    $directory->move("/mnt/backup");
}
catch (DirectoryNotMovableException $e)
{
    foreach ($e->getProblems() as $problem)
    {
        // A DirectoryMoveProblem
        echo $problem->getPath() . ": " . $problem->getDescription() . PHP_EOL;

        if ($problem->getReason() === DirectoryMoveProblemEnum::OWNED_BY_ANOTHER_USER)
        {
            // ...
        }
    }
}
```

| `DirectoryMoveProblemEnum` | Means |
|---|---|
| `DIRECTORY_NOT_WRITABLE` | A directory that has to be written to can't be: the one it is in, the one it is moving to, or the directory itself |
| `DIRECTORY_NOT_LISTABLE` | A directory can't be listed, so what is in it can't be checked |
| `SYMLINK_NOT_UPDATABLE` | A symlink inside needs updating, but its directory can't be written to |
| `NOT_READABLE` | Something can't be read, so can't be copied to another filesystem |
| `ORIGINAL_NOT_DELETABLE` | After copying to another filesystem, the original couldn't be deleted |
| `OWNED_BY_ANOTHER_USER`, `GROUP_NOT_A_MEMBER_OF` | A copy couldn't keep the owner or group (only checked with `PreserveOwnershipEnum::YES`) |
| `POSIX_EXTENSION_MISSING` | `PreserveOwnershipEnum::YES` needs the `posix` extension to check who owns what |
| `HARD_LINK` | A file has more than one name, so copying it would make separate copies |
| `SPECIAL_FILE` | A pipe, socket or device, which can't be copied |
| `NOT_ENOUGH_SPACE` | The other filesystem hasn't enough free space |
| `SYMLINK_NOT_CREATABLE_ON_WINDOWS` | On Windows, a broken symlink would have to be created again, which Windows can't do |

`DirectoryNotMovableException` extends `BadFilePermissionsException`. A `File` move that can't keep the owner with
`PreserveOwnershipEnum::YES` throws a `BadFilePermissionsException`.

## Progress, cancelling and resuming
Moves take an optional `$listener`, to show progress or cancel the move. Extend `AbstractMoveListener` and override the
events you want (or implement `MoveListenerInterface` for all of them). Throwing a `MoveCancelledException` from one
cancels the move: what was copied so far is removed, the original is left as it was, and your exception is passed back
to you.

```php
use Programster\CoreLibs\CliLib;
use Programster\CoreLibs\Models\AbstractMoveListener;
use Programster\CoreLibs\Models\MoveProgress;

$listener = new class extends AbstractMoveListener {
    public function onFileMoved(MoveProgress $progress, string $path): void
    {
        CliLib::showProgressBar($progress->getPercentMoved(), 0);
    }

    public function onBytesCopied(MoveProgress $progress, string $path): void
    {
        CliLib::showProgressBar($progress->getPercentMoved(), 0);
    }
};

$directory->move("/mnt/backup", listener: $listener);
```

| Event | Called |
|---|---|
| `onMoveStarting($progress)` | Once everything has been checked, just before anything is changed |
| `onFileStarting($progress, $path, $bytes)` | Just before each file (or symlink) is copied |
| `onBytesCopied($progress, $path)` | After each 8 MiB of a file is copied, apart from the last |
| `onFileMoved($progress, $path)` | Once each file (or symlink) has been copied |
| `onMoveFinished($progress)` | Once the move is complete, and the original is gone |

A move within one filesystem is a single instant rename, so it only calls `onMoveStarting()` and `onMoveFinished()`. A
move to another filesystem also calls `onFileStarting()` and `onFileMoved()` for each file, and `onBytesCopied()` during
a big one, so the progress keeps moving (and it can be cancelled part way through).

`MoveProgress` has `getTotalFiles()`, `getTotalBytes()`, `getFilesMoved()`, `getBytesMoved()` and `getPercentMoved()`
(of the bytes, or of the files if there are no bytes to copy).

```php
use Programster\CoreLibs\Exceptions\MoveCancelledException;

$stopAfterOneGib = new class extends AbstractMoveListener {
    public function onBytesCopied(MoveProgress $progress, string $path): void
    {
        if ($progress->getBytesMoved() > 1024 ** 3)
        {
            throw new MoveCancelledException("That's enough for today.");
        }
    }
};
```

`Directory` moves to another filesystem also take `$resumable`. With it set, a copy that fails, is cancelled or is
interrupted (e.g. a network filesystem dropping out, or a timeout) is kept, and making the same move again carries on
from there: files already copied are only copied again if they have changed (by their size and modification time), and
anything removed from the original since is removed from the copy. A copy that is never resumed stays, under a hidden
name beside the destination or in the system's temporary directory, until you delete it.

```php
$directory->move("/mnt/nas/backup", listener: $listener, resumable: true);
```
