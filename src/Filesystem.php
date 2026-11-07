<?php

/*
 * Library just for filesystem operations.
 */

namespace Programster\CoreLibs;


use Programster\CoreLibs\Enums\DataSizeUnitEnum;
use Programster\CoreLibs\Enums\FileDeletionOperationEnum;
use Programster\CoreLibs\Enums\FileDeletionProblemEnum;
use Programster\CoreLibs\Enums\MemoryTypeEnum;
use Programster\CoreLibs\Enums\PreserveOwnershipEnum;
use Programster\CoreLibs\Enums\UseSystemTempDirEnum;
use Programster\CoreLibs\Exceptions\DirectoryNotDeletableException;
use Programster\CoreLibs\Models\FileDeletionProblem;
use Programster\CoreLibs\Exceptions\BadFilePermissionsException;
use Programster\CoreLibs\Exceptions\CommandFailedException;
use Programster\CoreLibs\Exceptions\CoreLibsException;
use Programster\CoreLibs\Exceptions\FileAlreadyExistsException;
use Programster\CoreLibs\Exceptions\DirectoryDoesNotExistException;
use Programster\CoreLibs\Exceptions\FileDoesNotExistException;
use Programster\CoreLibs\Exceptions\MissingExtensionException;
use Programster\CoreLibs\Exceptions\NoFileExtensionException;
use Programster\CoreLibs\Exceptions\FilesystemException;
use Programster\CoreLibs\Exceptions\UnexpectedOutputException;
use Programster\CoreLibs\Exceptions\UnsupportedPlatformException;
use Programster\CoreLibs\Models\Directory;
use Programster\CoreLibs\Models\File;
use Programster\CoreLibs\Exceptions\BadArgumentException;
use Programster\CoreLibs\Models\SymlinkPathsTrait;
use ZipArchive;

final class Filesystem
{
    use SymlinkPathsTrait;

    # The file type and permissions of a symlink (S_IFLNK and 0777), as stored in zip entries.
    private const int SYMLINK_MODE = 0120777;

    private const MAX_DELETION_PROBLEMS = 100;
    private static array $s_fileLocks = [];


    /**
     * Retrieves a list of all the directories within the specified directory. It does not include
     * the dot directories (. and ..), and does not return any files.
     * A symlink to a directory is listed as a directory. With $recursive, it is only gone into if
     * $followSymlinks is true, and even then, never if it points back up to a directory it is inside
     * (e.g. ".."), which would otherwise go round in circles.
     * @param string $path - the path to the directory that we want to search.
     * @param bool $recursive - whether to recursively loop through the directories to find more.
     * @param bool $includePath - set to true to include the full path to the dir, not just the dir
     * name. Directories found by following a symlink are given under the symlink's path.
     * @param bool $followSymlinks - with $recursive, whether to go into symlinks to directories too.
     * Defaults to false.
     * @return array<string> - list of directories within the specified path, in no particular order.
     * @throws DirectoryDoesNotExistException - if there is no directory at $path.
     * @throws FilesystemException - if the directory (or, if $recursive, one of the subdirectories
     * gone into) can't be opened, e.g. because it can't be read.
     */
    public static function getDirectories(
        string $path,
        bool $recursive = false,
        bool $includePath = true,
        bool $followSymlinks = false
    ): array
    {
        # Throw rather than returning an empty list (with a warning), so that a missing or unreadable directory
        # can't be mistaken for an empty one.
        if (!is_dir($path))
        {
            throw new DirectoryDoesNotExistException("There is no directory at {$path}.");
        }

        $realPath = realpath($path);
        $directories = [];

        # The directories still to look in, each with the real paths of the directories it is inside in this
        # listing, so that a symlink back up to one of them isn't gone into.
        $toList = [[$realPath, [$realPath]]];

        while (count($toList) > 0)
        {
            [$directory, $ancestors] = array_shift($toList);

            foreach (self::readDirectoryEntries($directory) as $entry)
            {
                $entryPath = "{$directory}/{$entry}";

                if (is_dir($entryPath))
                {
                    $directories[] = $includePath ? $entryPath : $entry;

                    if (self::shouldGoInto($entryPath, $recursive, $followSymlinks, $ancestors))
                    {
                        $toList[] = [$entryPath, array_merge($ancestors, [realpath($entryPath)])];
                    }
                }
            }
        }

        return $directories;
    }


    /**
     * An alias for getDirectories(), for those who look for "list" (as with ls) rather than "get".
     * See getDirectories() for how it works.
     * @param string $path - the path to the directory that we want to search.
     * @param bool $recursive - whether to recursively loop through the directories to find more.
     * @param bool $includePath - set to true to include the full path to the dir, not just the dir
     * name.
     * @param bool $followSymlinks - with $recursive, whether to go into symlinks to directories too.
     * Defaults to false.
     * @return array<string> - list of directories within the specified path, in no particular order.
     * @throws DirectoryDoesNotExistException - if there is no directory at $path.
     * @throws FilesystemException - if the directory (or, if $recursive, one of the subdirectories
     * gone into) can't be opened, e.g. because it can't be read.
     */
    public static function listDirectories(
        string $path,
        bool $recursive = false,
        bool $includePath = true,
        bool $followSymlinks = false
    ): array
    {
        return self::getDirectories($path, $recursive, $includePath, $followSymlinks);
    }


    /**
     * Retrieves an array list of files/folders within the specified directory.
     *   Consider using the following instead:
     *   $directory = new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::CURRENT_AS_SELF);
     *   $iterator = new RecursiveIteratorIterator($directory, RecursiveIteratorIterator::CHILD_FIRST);
     *
     * A symlink to a directory is treated as a directory. With $recursive, it is only gone into if
     * $followSymlinks is true, and even then, never if it points back up to a directory it is inside
     * (e.g. ".."), which would otherwise go round in circles.
     * @param string $dir - the path to the directory you wish to find the contents of.
     * @param bool $recursive - whether we go through into each subfolder and retrieve its contents.
     * @param bool $includePath - whether we output the path to the entry such as '/folder/text.txt'
     * instead of just 'text.txt'. Entries found by following a symlink are given under the symlink's
     * path.
     * @param bool $onlyFiles - whether to leave the directories themselves out of the returned list
     * @param bool $includeHiddenFilesAndFolders - if false, then this will not include hidden files
     * and folders (those starting with '.')
     * @param bool $followSymlinks - with $recursive, whether to go into symlinks to directories too.
     * Defaults to false.
     * @return array - the names of all the files/folders within the directory, in no particular order.
     * @throws DirectoryDoesNotExistException - if there is no directory at $dir.
     * @throws FilesystemException - if the directory (or, if $recursive, one of the subdirectories
     * gone into) can't be opened, e.g. because it can't be read.
     */
    public static function getDirContents(
        string $dir,
        bool $recursive   = true,
        bool $includePath = true,
        bool $onlyFiles   = true,
        bool $includeHiddenFilesAndFolders = true,
        bool $followSymlinks = false
    ): array
    {
        # Throw rather than returning an empty list (with a warning), so that a missing or unreadable directory
        # can't be mistaken for an empty one.
        if (!is_dir($dir))
        {
            throw new DirectoryDoesNotExistException("There is no directory at {$dir}.");
        }

        $realPath = realpath($dir);
        $fileNames = [];

        # The directories still to look in, each with the real paths of the directories it is inside in this
        # listing, so that a symlink back up to one of them isn't gone into.
        $toList = [[$realPath, [$realPath]]];

        while (count($toList) > 0)
        {
            [$directory, $ancestors] = array_shift($toList);

            foreach (self::readDirectoryEntries($directory) as $entry)
            {
                if ($includeHiddenFilesAndFolders || !str_starts_with($entry, "."))
                {
                    $entryPath = "{$directory}/{$entry}";
                    $isDirectory = is_dir($entryPath);

                    if (!$isDirectory || !$onlyFiles)
                    {
                        $fileNames[] = $includePath ? $entryPath : $entry;
                    }

                    if ($isDirectory && self::shouldGoInto($entryPath, $recursive, $followSymlinks, $ancestors))
                    {
                        $toList[] = [$entryPath, array_merge($ancestors, [realpath($entryPath)])];
                    }
                }
            }
        }

        return $fileNames;
    }


    /**
     * An alias for getDirContents(), for those who look for "list" (as with ls) rather than "get".
     * See getDirContents() for how it works.
     * @param string $dir - the path to the directory you wish to find the contents of.
     * @param bool $recursive - whether we go through into each subfolder and retrieve its contents.
     * @param bool $includePath - whether we output the path to the entry such as '/folder/text.txt'
     * instead of just 'text.txt'.
     * @param bool $onlyFiles - whether to leave the directories themselves out of the returned list
     * @param bool $includeHiddenFilesAndFolders - if false, then this will not include hidden files
     * and folders (those starting with '.')
     * @param bool $followSymlinks - with $recursive, whether to go into symlinks to directories too.
     * Defaults to false.
     * @return array - the names of all the files/folders within the directory, in no particular order.
     * @throws DirectoryDoesNotExistException - if there is no directory at $dir.
     * @throws FilesystemException - if the directory (or, if $recursive, one of the subdirectories
     * gone into) can't be opened, e.g. because it can't be read.
     */
    public static function listDirContents(
        string $dir,
        bool $recursive   = true,
        bool $includePath = true,
        bool $onlyFiles   = true,
        bool $includeHiddenFilesAndFolders = true,
        bool $followSymlinks = false
    ): array
    {
        return self::getDirContents(
            $dir,
            $recursive,
            $includePath,
            $onlyFiles,
            $includeHiddenFilesAndFolders,
            $followSymlinks
        );
    }


    /**
     * Wrapper around the mkdir that will only execute it if the directory doesn't already exist. By
     * default, the parent directory must already exist. Set $recursive to also create any missing
     * parent directories.
     *
     * @param string $dirPath - the path to the directory we wish to create
     * @param string $perms - the permissions to set for the directory, as 3 or 4 octal digits,
     * optionally with a leading 0 or 0o, e.g. "0700", "755" or "0o755". Defaults to "0777", to
     * match PHP's own mkdir(). This relies on the umask to reduce it to what is appropriate for the
     * system, which in most cases (with the usual umask of 022) gives 0755. Pass a stricter value,
     * e.g. "0700", for a directory that must stay private whatever the umask is.
     * @param bool $recursive - whether to also create any missing parent directories. Defaults to
     * false, so a mistyped path fails rather than creating a chain of unexpected directories. If
     * true, every directory created, including the parents, gets the same $perms, which may not be
     * what you want for the parents (e.g. a private "0700" directory inside parents that other
     * users need to get into).
     * @param bool $overrideUmask - by default, as with PHP's mkdir(), $perms is reduced by the
     * umask (e.g. "0775" usually gives 0755). Set this to true to give every directory this call
     * creates (including any parents, if $recursive) exactly $perms instead. Directories that
     * already existed are never changed. Note that with the default $perms of "0777", this makes
     * the directory writable by everyone, so pass $perms as well.
     *
     * @return void
     * @throws BadArgumentException - if $perms is not 3 or 4 octal digits, optionally with a
     * leading 0 or 0o.
     * @throws FilesystemException - if the directory doesn't exist and could not be created, or
     * $overrideUmask is set and its permissions could not be set.
     */
    public static function mkdir(
        string $dirPath,
        string $perms = "0777",
        bool $recursive = false,
        bool $overrideUmask = false,
    ): void
    {
        try
        {
            $permsInt = self::permissionsStringToInt($perms);
        }
        catch (BadArgumentException $e)
        {
            throw new BadArgumentException(
                "mkdir: \$perms must be 3 or 4 octal digits, optionally with a leading 0 or 0o, e.g. \"0755\" or " .
                "\"0o755\", but is \"{$perms}\".",
                0,
                $e
            );
        }

        if (!is_dir($dirPath))
        {
            # Note which directories this call is about to create, deepest first, so that only those have their
            # permissions set exactly. Without $recursive, that can only be $dirPath itself.
            $directoriesToCreate = [];
            $path = rtrim($dirPath, '/');

            while (
                !in_array($path, ['', '.', '/'], true)
                && !file_exists($path)
                && !is_link($path)
                && ($recursive || count($directoriesToCreate) === 0)
            )
            {
                $directoriesToCreate[] = $path;
                $path = dirname($path);
            }

            # Capture the warning to put its reason in the exception, rather than letting it through.
            $createDirectory = function () use ($dirPath, $permsInt, $recursive): bool {
                return mkdir($dirPath, $permsInt, $recursive);
            };

            $created = Core::runCapturingWarning($createDirectory, $error);
            $error ??= 'unknown error';

            # The is_dir() check is in case another process created the directory at the same time.
            if (!$created && !is_dir($dirPath))
            {
                throw new FilesystemException("Failed to create the directory {$dirPath}: {$error}");
            }

            if ($created && $overrideUmask)
            {
                foreach ($directoriesToCreate as $directory)
                {
                    if (!@chmod($directory, $permsInt))
                    {
                        throw new FilesystemException(
                            "Created {$directory}, but failed to set its permissions to {$perms}."
                        );
                    }
                }
            }
        }
    }


    /**
     * Move (or rename) a directory and everything in it, updating the symlinks inside it so that
     * they keep pointing at the same things. This is a shortcut for Directory::rename(), which
     * explains what is checked and how the symlinks are updated. Unlike Directory, a relative path
     * here is relative to the current working directory, as with PHP's own functions.
     * @param string $source - the directory to move.
     * @param string $destination - the path it should have afterwards. Its parent must already
     * exist, and nothing may already exist at it.
     * @param bool $overrideUmask - when moving to a different filesystem, where everything has to
     * be copied: whether to give the copies exactly the original permissions, rather than reducing
     * them by the umask (and stopping the move if that can't be done). On the same filesystem,
     * nothing is copied, so permissions are always kept exactly and this has no effect. Defaults to
     * false.
     * @param PreserveOwnershipEnum $preserveOwnership - when moving to a different filesystem: how
     * hard to try to keep everything's owner and group. Defaults to YES, which stops the move if
     * they can't all be kept (see Directory::move()).
     * @return void
     * @throws BadArgumentException - if $source is a symlink, or $destination is empty or inside
     * $source.
     * @throws DirectoryDoesNotExistException - if there is no directory at $source, or
     * $destination's parent doesn't exist.
     * @throws FileAlreadyExistsException - if something already exists at $destination.
     * @throws BadFilePermissionsException - if anything would stop the move or the symlink updates.
     * Nothing is moved if this happens.
     * @throws FilesystemException - if the move or copy failed, or the original couldn't be deleted
     * after a copy to a different filesystem.
     */
    public static function moveDir(
        string $source,
        string $destination,
        bool $overrideUmask = false,
        PreserveOwnershipEnum $preserveOwnership = PreserveOwnershipEnum::YES
    ): void
    {
        $absoluteDestination = str_starts_with($destination, "/") ? $destination : getcwd() . "/" . $destination;
        $directory = new Directory($source);
        $directory->rename($absoluteDestination, $overrideUmask, $preserveOwnership);
    }


    /**
     * Move (or rename) a file. This is a shortcut for File::rename(), which explains what is
     * checked: a directory is never replaced, a file is only replaced with $overwriteExistingFile,
     * and a symlink is moved as the link itself (a relative one is recreated so that it still
     * points at the same place). Unlike File, a relative path here is relative to the current
     * working directory, as with PHP's own functions.
     * @param string $source - the file to move.
     * @param string $destination - the path it should have afterwards. The directory it is in must
     * already exist.
     * @param bool $overwriteExistingFile - whether to replace a file (or symlink) that already
     * exists at $destination. Defaults to false. A directory is never replaced.
     * @param PreserveOwnershipEnum $preserveOwnership - when moving to a different filesystem: how
     * hard to try to keep the file's owner and group. Defaults to YES, which doesn't move it if they
     * can't be kept (see File::move()).
     * @return void
     * @throws BadArgumentException - if $destination is empty, or ends with a "/".
     * @throws FileDoesNotExistException - if there is no file at $source.
     * @throws DirectoryDoesNotExistException - if $destination's directory doesn't exist.
     * @throws FileAlreadyExistsException - if there is a directory at $destination, there is a file
     * there and $overwriteExistingFile is false, or it is the same file as $source.
     * @throws BadFilePermissionsException - with $preserveOwnership set to YES, if moving it to a
     * different filesystem couldn't keep its owner and group. Nothing is changed if this happens.
     * @throws FilesystemException - if the file could not be moved.
     */
    public static function moveFile(
        string $source,
        string $destination,
        bool $overwriteExistingFile = false,
        PreserveOwnershipEnum $preserveOwnership = PreserveOwnershipEnum::YES
    ): void
    {
        $absoluteDestination = str_starts_with($destination, "/") ? $destination : getcwd() . "/" . $destination;
        $file = new File($source);
        $file->rename($absoluteDestination, $overwriteExistingFile, $preserveOwnership);
    }


    /**
     * Deletes a directory even if it is not already empty. This resolves the
     *  issue with trying to use unlink on a non-empty dir.
     * By default, symlinks are never followed: a symlink inside the directory is removed without
     * touching what it points to, and if $dir is itself a symlink, this throws rather than guessing
     * whether the link or the folder it points to should be deleted. (To remove just the link, use
     * unlink().) With $followSymlinks, symlinks are followed instead, and what they point to is
     * deleted as well as the links: if $dir is a symlink, the directory it points to is deleted,
     * and then the link. A symlink inside to a directory has that directory deleted (following the
     * symlinks in it too) before the link, and one to a file has the file deleted before the link.
     * To avoid loops and deleting more than intended, a symlink that points "up" to a directory
     * that contains it (e.g. ".." or "/") is never followed, and only the link is removed, and
     * nothing is deleted twice, however many symlinks lead to it. Everything is checked before
     * anything is deleted (see emptyDir()), so if anything can't be deleted, nothing is. This
     * includes anything that would be deleted by following symlinks.
     * @param string $dir - the path to the directory you wish to delete
     * @param bool $followSymlinks - whether to follow symlinks, and delete what they point to as
     * well as the links. Defaults to false.
     * @return void
     * @throws BadArgumentException - if $dir is a symlink and $followSymlinks is false, or $dir is
     * a symlink to a directory that contains it (so following it would delete the link's own
     * parent).
     * @throws DirectoryDoesNotExistException - if a directory does not exist at the path specified.
     * @throws DirectoryNotDeletableException - if the checks find anything that can't be deleted,
     * including $dir itself (e.g. because its parent directory can't be written to). Its
     * getProblems() says what, and why. Nothing is deleted if this happens. This extends
     * BadFilePermissionsException.
     * @throws FilesystemException - if deleting something still failed part way through, despite
     * the checks (see emptyDir()).
     */
    public static function deleteDir(string $dir, bool $followSymlinks = false): void
    {
        # Remove any trailing slash first, as is_link() is false for "link/" (it checks what it points to).
        $dirPath = rtrim($dir, '/');
        $isSymlink = is_link($dirPath);

        # Without this, deleting through a symlink would empty the real folder it points to, and then fail to remove
        # the link.
        if ($isSymlink && !$followSymlinks)
        {
            throw new BadArgumentException(
                "Cannot delete {$dir}, as it is a symlink. Use unlink() to remove the link itself, call this with " .
                "the path of the folder it points to, to delete that, or set \$followSymlinks to true to delete both."
            );
        }

        if (!is_dir($dir))
        {
            throw new DirectoryDoesNotExistException("There is no directory at {$dir}.");
        }

        $realDir = realpath($dir);
        $plan = [];
        $problems = [];

        # Everything that will be deleted, by real path, so nothing is planned twice, and loops are impossible.
        $planned = [$realDir => true];

        if ($isSymlink)
        {
            if (self::isWithinDirectory(realpath(dirname($dirPath)), $realDir))
            {
                throw new BadArgumentException(
                    "Cannot delete {$dir}, as it is a symlink to {$realDir}, which contains the link itself, so " .
                    "following it would delete the link's own parent directory."
                );
            }

            self::planDirectoryContents($realDir, $realDir, true, $plan, $problems, $planned);
            self::planDeletion(FileDeletionOperationEnum::RMDIR, $realDir, $plan, $problems);
            self::planDeletion(FileDeletionOperationEnum::UNLINK, $dirPath, $plan, $problems);
        }
        else
        {
            self::planDirectoryContents($dirPath, $realDir, $followSymlinks, $plan, $problems, $planned);
            self::planDeletion(FileDeletionOperationEnum::RMDIR, $dirPath, $plan, $problems);
        }

        self::throwIfDeletionProblems($dir, $problems);
        self::runDeletionPlan($dir, $plan);
    }


    /**
     * Deletes all files from a directory, but leaves the directory in place. If $dir is a symlink
     * to a directory, the contents of the directory it points to are deleted, and both the link and
     * that directory are left in place. Symlinks inside the directory are handled as in
     * deleteDir(): by default they are removed without touching what they point to, and with
     * $followSymlinks, what they point to is deleted as well (never following one that points "up"
     * to a directory containing it, or deleting anything twice). Everything is checked before
     * anything is deleted, so if anything can't be deleted, nothing is. A directory's contents can
     * only be deleted if it can be listed and written to, and in a "sticky" directory such as /tmp,
     * only things the current user owns (or that are in a directory they own) can be deleted. Some
     * causes of failure can't be seen beforehand, though, such as an immutable file, a read-only
     * filesystem, or another process changing the directory while it is being deleted. If one of
     * those happens, this throws a FilesystemException part way through, and some of the contents
     * will already have been deleted.
     * @param string $dir - the path to the directory one wishes to empty, or a symlink to it.
     * @param bool $followSymlinks - whether to follow symlinks inside the directory, and delete
     * what they point to as well as the links. Defaults to false.
     * @return void
     * @throws DirectoryDoesNotExistException - if a directory does not exist at the path specified.
     * @throws DirectoryNotDeletableException - if the checks find anything that can't be deleted.
     * Its getProblems() says what, and why (stopping at 100). Nothing is deleted if this happens.
     * This extends BadFilePermissionsException.
     * @throws FilesystemException - if deleting something still failed part way through, despite
     * the checks.
     */
    public static function emptyDir(string $dir, bool $followSymlinks = false): void
    {
        # is_dir() follows symlinks, so a symlink to a directory is emptied too, which is what it was asked to do.
        if (!is_dir($dir))
        {
            throw new DirectoryDoesNotExistException("There is no directory at {$dir}.");
        }

        $realDir = realpath($dir);
        $plan = [];
        $problems = [];
        $planned = [$realDir => true];
        self::planDirectoryContents($realDir, $realDir, $followSymlinks, $plan, $problems, $planned);
        self::throwIfDeletionProblems($dir, $problems);
        self::runDeletionPlan($dir, $plan);
    }


    /**
     * Lock a file so that only we can use it. If $isBlocking is false, this returns false
     * immediately if the lock can't be obtained. If true, it waits until the lock can be obtained.
     * Note that this only allows other processes to see that you have locked it and does not
     * actually guarantee that it cannot be edited at the same time on Linux.
     * @param string $filePath - the full path to the file that we wish to lock
     * @param bool $isWriteLock - if false this will get a shared read lock, if true this will get
     * an exclusive write lock.
     * @param bool $isBlocking - if true will wait to get lock.
     * @return bool - true if we managed to lock the file. False is only returned when $isBlocking
     * is false and something else already holds a conflicting lock, so a blocking lock always
     * returns true.
     * @throws FilesystemException - if the file could not be opened, or locking it failed for any
     * other reason.
     */
    public static function lockFile(string $filePath, bool $isWriteLock, bool $isBlocking): bool
    {
        # This creates the file if it doesn't exist.
        # This MUST be a+ instead of w or w+ as using w will result in the file being wiped.
        $fileHandle = fopen($filePath, 'a+');

        if ($fileHandle === false)
        {
            throw new FilesystemException("Failed to open {$filePath} in order to lock it.");
        }

        $lockParams = 0;

        if ($isWriteLock)
        {
            $lockParams =  $lockParams | LOCK_EX;
        }
        else
        {
            # read lock = shared lock
            $lockParams = $lockParams | LOCK_SH;
        }

        if (!$isBlocking)
        {
            $lockParams = $lockParams | LOCK_NB;
        }

        $isLocked = flock($fileHandle, $lockParams, $wouldBlock);

        if ($isLocked)
        {
            self::$s_fileLocks[$filePath] = $fileHandle;
        }
        else
        {
            # Don't keep the handle open if we didn't get the lock, as unlockFile() would never be called for it.
            fclose($fileHandle);

            # $wouldBlock is only set when a non-blocking lock failed because something else holds the lock, which is
            # a normal result. Anything else is a genuine failure.
            if ($wouldBlock !== 1)
            {
                throw new FilesystemException("Failed to lock {$filePath}.");
            }
        }

        return $isLocked;
    }


    /**
     * Unlock a file so that others may use it.
     * @param string $filePath - the full path to the file that we wish to unlock
     * @return void
     */
    public static function unlockFile(string $filePath): void
    {
        if (isset(self::$s_fileLocks[$filePath]))
        {
            fclose(self::$s_fileLocks[$filePath]);
            unset(self::$s_fileLocks[$filePath]);
        }
    }


    /**
     * Fetch the total size of a directory or file, including all its subdirectories. This is the
     * "apparent size" (the total of the file sizes), rather than the space taken up on disk, which
     * is rounded up to the filesystem's block size. This only works on Linux, not Windows. src:
     * http://stackoverflow.com/questions/478121/php-get-directory-size
     * @param string $dirPath - the path to the directory we wish to get the size of
     * @param DataSizeUnitEnum $unit - optionally specify the unit to return the size in. Defaults
     * to bytes.
     * @return float - the size of the directory and all its contents, in the specified unit. E.g.
     * 1.5 for 1536 bytes in KiB.
     * @throws CommandFailedException - if the du command failed, such as if there is nothing at
     * $dirPath.
     * @throws UnexpectedOutputException - if the output of du was not in the expected format.
     */
    public static function getDirSize(string $dirPath, DataSizeUnitEnum $unit = DataSizeUnitEnum::BYTES): float
    {
        # Output looks like: "4096\t/path/to/dir"
        $output = Core::runCommand('/usr/bin/du --bytes --summarize ' . escapeshellarg($dirPath));
        $size = explode("\t", $output)[0];

        if (!ctype_digit($size))
        {
            throw new UnexpectedOutputException("getDirSize: unexpected output from du: {$output}");
        }

        return $unit->convertFromBytes((int) $size);
    }


    /**
     * Fetch the size of a file. Unlike PHP's filesize(), this throws an exception rather than
     * returning false if it fails, and it always gets the file's current size, rather than one PHP
     * may have cached earlier in the script.
     * @param string $filepath - the path to the file to get the size of.
     * @param DataSizeUnitEnum $unit - optionally specify the unit to return the size in. Defaults
     * to bytes.
     * @return float - the size of the file in the specified unit. E.g. 1.5 for a 1536 byte file in
     * KiB.
     * @throws FileDoesNotExistException - if there is no file at $filepath, or it is a directory
     * (use getDirSize()).
     * @throws FilesystemException - if the size of the file could not be determined.
     */
    public static function getFileSize(string $filepath, DataSizeUnitEnum $unit = DataSizeUnitEnum::BYTES): float
    {
        # PHP caches file information such as sizes, so clear it for this file, otherwise checking a file's size
        # before and after writing to it in the same script would give the old size.
        clearstatcache(true, $filepath);

        if (is_dir($filepath))
        {
            throw new FileDoesNotExistException(
                "{$filepath} is a directory, not a file. Please use getDirSize() to get the size of a directory."
            );
        }

        if (!is_file($filepath))
        {
            throw new FileDoesNotExistException("There is no file at {$filepath}.");
        }

        $numBytes = filesize($filepath);

        if ($numBytes === false)
        {
            throw new FilesystemException("Failed to get the size of {$filepath}.");
        }

        return $unit->convertFromBytes($numBytes);
    }


    /**
     * Zip a directory and all of its contents into a zip file. By default, the zip contains a single
     * folder, named after $sourceFolder (e.g. zipping "/var/www/project" gives "project/..."),
     * however the path is written (relative, with a trailing slash, through a symlink etc.), so
     * extracting it gives that folder. With $includeFolder set to false, it contains just what is in
     * the folder instead, so extracting it puts those straight into the destination. Empty
     * subdirectories are included, so they are recreated when the zip is extracted.
     * If $sourceFolder is itself a symlink, the folder it points to is zipped, named after the
     * symlink. Symlinks inside it are stored in the zip as symlinks (which unzip() recreates), rather
     * than followed. Those that point somewhere inside the folder are stored relative to themselves,
     * so they still work wherever the zip is extracted. Those that point outside it are stored as
     * they are, unless $followSymlinks is set.
     * @param string $sourceFolder - path to the folder we wish to zip up.
     * @param string $dest - path and name to give the zipfile e.g. (/tmp/my_zip.zip)
     * @param bool $deleteOnComplete - specify false if you want to keep the original uncompressed
     * files after they have been zipped. If $sourceFolder is a symlink, only the symlink is
     * deleted, and the folder it points to (and the files in it) are kept.
     * @param bool $includeFolder - true (the default) to put the folder itself at the top of the zip,
     * so extracting it gives a folder of the same name in the destination. False to only zip what
     * is in it, so extracting it puts those straight into the destination.
     * @param bool $followSymlinks - true to zip what symlinks pointing outside $sourceFolder point
     * to (their files, or their folders and everything in them), in place of the symlinks, so the
     * zip doesn't depend on anything outside it. Symlinks pointing inside $sourceFolder, and broken
     * ones, are still stored as symlinks. Nothing outside $sourceFolder is deleted by
     * $deleteOnComplete either way.
     * @return void
     * @throws FileAlreadyExistsException - if there is already a file at $dest.
     * @throws DirectoryDoesNotExistException - if there is no folder at $sourceFolder.
     * @throws MissingExtensionException - if the zip extension isn't installed.
     * @throws DirectoryNotDeletableException - with $deleteOnComplete, if the zip was created, but
     * $sourceFolder can't be deleted. Nothing is deleted if this happens (see deleteDir()).
     * @throws BadArgumentException - with $deleteOnComplete, if $sourceFolder was replaced by a
     * symlink while it was being zipped, so isn't deleted.
     * @throws FilesystemException - if the zip file could not be created or written, a symlink
     * couldn't be read, or (with $followSymlinks) following a symlink would go round in circles.
     */
    public static function zipDir(
        string $sourceFolder,
        string $dest,
        bool $deleteOnComplete = true,
        bool $includeFolder = true,
        bool $followSymlinks = false
    ): void
    {
        if (!extension_loaded('zip') )
        {
            throw new MissingExtensionException("Your PHP does not have the zip extension.");
        }

        if (!is_dir($sourceFolder))
        {
            throw new DirectoryDoesNotExistException("Cannot zip {$sourceFolder}, as there is no folder there.");
        }

        if (file_exists($dest))
        {
            $msg =
                "A file already exists at {$dest}. We do not wish to risk overwriting data. " .
                "Please provide a destination filepath that doesn't already exist.";

            throw new FileAlreadyExistsException($msg);
        }

        $zip = new ZipArchive();
        $opened = $zip->open($dest, ZipArchive::CREATE);

        # open() returns true on success, but an error code (a non-zero int, which is truthy) on failure.
        if ($opened !== true)
        {
            throw new FilesystemException("Unable to create the zip file {$dest} (ZipArchive error code {$opened}).");
        }

        # Zip the real folder, so a symlink, a relative path or a trailing slash makes no difference to what is zipped.
        $resolvedSourceFolder = realpath($sourceFolder);

        # Name the folder in the zip after the path we were given (e.g. a symlink's own name, rather than what it points
        # to), unless that has no name of its own, such as ".". Without $includeFolder, there's no folder at the top.
        $baseDir = "";

        if ($includeFolder)
        {
            $baseDir = basename(rtrim($sourceFolder, '/'));

            if ($baseDir === '' || $baseDir === '.' || $baseDir === '..')
            {
                $baseDir = basename($resolvedSourceFolder);
            }

            $zip->addEmptyDir($baseDir);
        }

        $root = ['source' => $resolvedSourceFolder, 'zip' => $baseDir, 'followSymlinks' => $followSymlinks];
        self::addDirectoryToZip($zip, $resolvedSourceFolder, $baseDir, $root, [$resolvedSourceFolder]);

        # The files are only actually read and written into the zip when it is closed, so this is where most failures
        # show up (e.g. a file that can't be read, or the disk being full). This raises a warning as well as returning
        # false, so capture the warning to put its reason in the exception, rather than letting it through.
        $closeZip = function () use ($zip): bool {
            return $zip->close();
        };

        $closed = Core::runCapturingWarning($closeZip, $error);

        if ($closed === false)
        {
            # ZipArchive's own status says what went wrong too, if for some reason there was no warning.
            $reason = $error ?? $zip->getStatusString();
            throw new FilesystemException("Failed to write the zip file {$dest}: {$reason}");
        }

        if ($deleteOnComplete)
        {
            # Remove any trailing slash first, as is_link() is false for "link/" (it checks what it points to).
            $sourceLink = rtrim($sourceFolder, '/');

            if (is_link($sourceLink))
            {
                # Only remove the symlink itself, and keep the folder it points to. (deleteDir() refuses symlinks.)
                if (!unlink($sourceLink))
                {
                    throw new FilesystemException("Zipped {$sourceFolder}, but failed to remove the symlink.");
                }
            }
            else
            {
                self::deleteDir($sourceFolder);
            }
        }
    }


    /**
     * Unzip a zip file and all of its contents into a directory. The directory (and any parent
     * directories) is created if it doesn't exist, once the zip has been opened successfully.
     * The zip is first extracted into a temporary directory (created with 0700 permissions, so other
     * users can't read it) on the same filesystem as the destination, so its contents can then be
     * moved into place instantly. That is in the system's temporary directory (e.g. /tmp) if it is on
     * the same filesystem, so nothing appears in the destination until it is complete. Otherwise it
     * is a hidden directory inside the destination. If extracting fails part way through, the
     * temporary directory is deleted (along with any directories this created), so nothing is left
     * behind. As with ZipArchive::extractTo(), existing files with the same names are replaced, and
     * existing directories are merged into. Anything that can't be (e.g. a file where the destination
     * has a directory, or an existing directory that can't be written to) is checked for before
     * anything is moved, and stops it.
     * Symlinks stored in the zip (e.g. by zipDir()) are recreated as symlinks. Unless $trusted is
     * set, a zip with a symlink that points outside $destinationFolder (e.g. to "/etc/passwd", or
     * "../../x") is refused before anything is moved into place, as an untrusted zip could use one
     * to make later code read or write files outside it.
     * @param string $sourceZip - zip file to unzip.
     * @param string $destinationFolder - path to the folder we wish to unzip into.
     * @param bool $deleteOnComplete - whether to delete the zip file once its contents have been
     * extracted. Defaults to true.
     * @param bool $trusted - true to allow symlinks that point outside $destinationFolder. Only set
     * this for zips from a source you trust. Defaults to false.
     * @return void
     * @throws MissingExtensionException - if PHP's zip extension isn't installed.
     * @throws FileDoesNotExistException - if there is no file at $sourceZip.
     * @throws BadArgumentException - if $destinationFolder exists, but isn't a directory.
     * @throws FilesystemException - if the zip file could not be opened or extracted, the destination
     * could not be created, the contents clash with what is already in the destination, it has a
     * symlink pointing outside the destination (without $trusted), or (with
     * $deleteOnComplete) the zip could not be deleted afterwards, in which case its contents have
     * already been extracted. Moving the extracted contents into place is checked first, so is very
     * unlikely to fail, but if it does, some will have been moved, and the message says where the
     * rest are.
     */
    public static function unzip(
        string $sourceZip,
        string $destinationFolder,
        bool $deleteOnComplete = true,
        bool $trusted = false
    ): void
    {
        if (!extension_loaded('zip'))
        {
            throw new MissingExtensionException("Your PHP does not have the zip extension.");
        }

        if (!is_file($sourceZip))
        {
            throw new FileDoesNotExistException("There is no zip file at {$sourceZip}.");
        }

        if (file_exists($destinationFolder) && !is_dir($destinationFolder))
        {
            throw new BadArgumentException("Can't unzip into {$destinationFolder}, as it isn't a directory.");
        }

        $zip = new ZipArchive();
        $opened = $zip->open($sourceZip);

        # open() returns true on success, but an error code (a non-zero int, which is truthy) on failure.
        if ($opened !== true)
        {
            throw new FilesystemException("Unable to open zip file: {$sourceZip} (ZipArchive error code {$opened}).");
        }

        # Only create the destination now that the zip has opened. Note the top directory this creates, so that
        # everything it created can be removed again if extracting fails.
        $topCreatedDirectory = null;

        if (!is_dir($destinationFolder))
        {
            $topCreatedDirectory = rtrim($destinationFolder, "/");

            while (!is_dir(dirname($topCreatedDirectory)))
            {
                $topCreatedDirectory = dirname($topCreatedDirectory);
            }

            self::mkdir($destinationFolder, recursive: true);
        }

        # Extract into a temporary directory first, so a failure part way through leaves nothing behind. It must be
        # on the same filesystem as the destination, so the contents can then be moved into place with rename().
        # Prefer the system's temporary directory, so nothing appears in the destination until it is complete, and
        # otherwise use a hidden directory inside the destination itself.
        $systemTmpDirectory = sys_get_temp_dir();
        $realDestination = realpath($destinationFolder);

        if (is_writable($systemTmpDirectory) && self::isOnSameFilesystem($systemTmpDirectory, $realDestination))
        {
            $tmpDirectory = $systemTmpDirectory . "/" . self::randomName("corelibs-unzip-");
        }
        else
        {
            # Hidden, as it is inside the destination until the contents are moved out of it.
            $tmpDirectory = $realDestination . "/" . self::randomName(".corelibs-unzip-");
        }

        self::mkdir($tmpDirectory, "0700", overrideUmask: true);

        $extractZip = function () use ($zip, $tmpDirectory): bool {
            return $zip->extractTo($tmpDirectory);
        };

        # Capture the warnings, to put their reasons in the exceptions, rather than letting them through.
        $extracted = Core::runCapturingWarning($extractZip, $error);
        $statusIfFailed = $zip->getStatusString();
        $symlinkEntries = self::findZipSymlinkEntries($zip);
        $zip->close();
        $problems = [];

        # extractTo() writes each symlink as a file holding its target, so check those targets, and then turn them
        # into symlinks, before checking they can all be merged into the destination.
        if ($extracted)
        {
            $problems = self::recreateExtractedSymlinks($tmpDirectory, $realDestination, $symlinkEntries, $trusted);
        }

        if ($extracted && count($problems) === 0)
        {
            $problems = self::findMergeProblems($tmpDirectory, $destinationFolder);
        }

        if (!$extracted || count($problems) > 0)
        {
            # Nothing has been moved into place yet, so remove everything this made. This is only a best effort, so
            # that a failure here doesn't hide the real reason, which is what gets thrown.
            $toRemove = array_filter([$tmpDirectory, $topCreatedDirectory]);
            $leftBehind = self::removeDirectoriesQuietly($toRemove);

            $reason = $extracted
                ? "of these problems:\n - " . implode("\n - ", array_slice($problems, 0, 20))
                : ($error ?? $statusIfFailed);

            $leftBehindText = (count($leftBehind) > 0)
                ? " (and couldn't remove what it made, so some is left in " . implode(" and ", $leftBehind) . ")"
                : "";

            throw new FilesystemException(
                "Unable to unzip {$sourceZip} into {$destinationFolder}, as {$reason}{$leftBehindText}"
            );
        }

        self::mergeDirectoryInto($tmpDirectory, $destinationFolder);

        # Suppress the warning: it is empty now, and if it can't be removed, the files are still all in place.
        @rmdir($tmpDirectory);

        if ($deleteOnComplete)
        {
            $deleteZip = function () use ($sourceZip): bool {
                return unlink($sourceZip);
            };

            if (!Core::runCapturingWarning($deleteZip, $error))
            {
                $error ??= 'unknown error';

                throw new FilesystemException(
                    "Extracted {$sourceZip} into {$destinationFolder}, but failed to delete the zip: {$error}"
                );
            }
        }
    }


    /**
     * Applies the user-defined callback function to each line of a file. This is inspired by
     * array_walk (http://php.net/manual/en/function.array-walk.php)
     * @param string $filepath - path to the file to open and loop over.
     * @param callable $callback - callback to execute on every line in the file.
     * @return void
     * @throws FilesystemException - if the file could not be opened.
     */
    public static function fileWalk(string $filepath, callable $callback): void
    {
        $handle = fopen($filepath, "r");

        if ($handle)
        {
            while (($line = fgets($handle)) !== false)
            {
                $callback($line);
            }

            fclose($handle);
        }
        else
        {
            throw new FilesystemException("fileWalk: Could not open file: " . $filepath);
        }
    }


    /**
     * Filters a file using the provided callback, in a memory efficient way so that it can process
     * very large files. This is inspired by array_filter
     * (http://php.net/manual/en/function.array-filter.php) The kept lines are written to a
     * temporary file first (see $useSystemTempDir), and only replace the file being written once
     * every line has been filtered, so a failure part way through (including the callback throwing)
     * leaves it untouched. An existing file keeps its owner and permissions (see
     * replaceWithFile()).
     * @param string $inputFile - the path to the file to operate upon.
     * @param callable $filterCallback - the callback to execute on every line in the file to
     * determine if kept or not. It must return true to keep the line. Don't forget that this will
     * include its line ending, so you may wish to use trim() before analysis.
     * @param string|null $outputFilepath - optionally specify an output filepath to place the
     * filtered content into. If this is not specified (or is the same file as $inputFile), then the
     * existing file will be modified in-place. If the output file doesn't exist, it is created,
     * with the default permissions for a new file.
     * @param UseSystemTempDirEnum $useSystemTempDir - where to write the new contents before they
     * replace the output file (see UseSystemTempDirEnum). Defaults to AUTO, which picks the fastest place
     * that will work, which can be a hidden, private file next to it for a moment. Pass ALWAYS to
     * keep it in the system's temporary directory, e.g. for a directory served to the public.
     * @param PreserveOwnershipEnum $preserveOwnership - how hard to try to keep the output file's owner and
     * group when replacing it (see Filesystem::replaceWithFile()). Defaults to PREFERRED.
     * @return void
     * @throws FileDoesNotExistException - if there is no file at $inputFile.
     * @throws BadFilePermissionsException - if $inputFile can't be read, or the file to write can't
     * be written (or created, for a new output file). This is checked before filtering.
     * @throws FilesystemException - if the filtered content could not be written.
     */
    public static function fileFilter(
        string   $inputFile,
        callable $filterCallback,
        ?string  $outputFilepath = null,
        UseSystemTempDirEnum $useSystemTempDir = UseSystemTempDirEnum::AUTO,
        PreserveOwnershipEnum $preserveOwnership = PreserveOwnershipEnum::PREFERRED
    ): void
    {
        // If the output file already exists, it may be the input file under a different path (e.g. relative path or
        // symlink), so compare the resolved paths. If it doesn't exist yet, it can't be the input file.
        $isInPlace = (
            $outputFilepath === null
            || (file_exists($outputFilepath) && realpath($outputFilepath) === realpath($inputFile))
        );

        $targetFile = $isInPlace ? $inputFile : $outputFilepath;

        # Check everything before doing any work, so the callback isn't run over the whole file only to fail at the end.
        if (!is_file($inputFile))
        {
            throw new FileDoesNotExistException("There is no file at {$inputFile}.");
        }

        if (!is_readable($inputFile))
        {
            throw new BadFilePermissionsException("{$inputFile} can't be read, so it can't be filtered.");
        }

        if (file_exists($targetFile))
        {
            self::checkCanChangeFile($targetFile);
        }
        elseif (!is_writable(dirname($targetFile)))
        {
            throw new BadFilePermissionsException(
                "{$targetFile} can't be created, as " . dirname($targetFile) . " can't be written to."
            );
        }

        # Suppress the warning, as we throw an exception instead.
        $inputHandle = @fopen($inputFile, "r");

        if ($inputHandle === false)
        {
            throw new FilesystemException("Failed to open {$inputFile} for reading.");
        }

        $tmpFilepath = null;
        $tmpHandle = false;

        try
        {
            $tmpFilepath = self::createReplacementFile($targetFile, $useSystemTempDir, $preserveOwnership);
            $tmpHandle = fopen($tmpFilepath, "w");

            if ($tmpHandle === false)
            {
                throw new FilesystemException("Failed to open a temporary file to filter {$inputFile} into.");
            }

            while (($line = fgets($inputHandle)) !== false)
            {
                if ($filterCallback($line) === true && fwrite($tmpHandle, $line) === false)
                {
                    throw new FilesystemException("Failed to write the filtered content to {$tmpFilepath}.");
                }
            }
        }
        catch (\Throwable $e)
        {
            # Nothing has been written to the target yet, so it is untouched, and the temporary file can go.
            fclose($inputHandle);

            if ($tmpHandle !== false)
            {
                fclose($tmpHandle);
            }

            if ($tmpFilepath !== null)
            {
                @unlink($tmpFilepath);
            }

            throw $e;
        }

        fclose($inputHandle);
        fclose($tmpHandle);

        # Create a new output file first, so it gets the default permissions for a new file, rather than the temporary
        # file's 0600.
        if (!file_exists($targetFile) && @touch($targetFile) === false)
        {
            @unlink($tmpFilepath);
            throw new FilesystemException("Failed to create {$targetFile}.");
        }

        self::replaceWithFile($targetFile, $tmpFilepath, $preserveOwnership);
    }


    /**
     * Check whether two paths are on the same filesystem (e.g. the same disk or partition), so that
     * something can be moved between them with rename(), rather than having to be copied. A path that
     * doesn't exist yet is checked using the nearest directory above it that does, as that is where it
     * would be created. Symlinks are followed, so a symlink is checked by what it points to.
     * @param string $path1 - an absolute path.
     * @param string $path2 - another absolute path.
     * @return bool - true if they are on the same filesystem.
     * @throws BadArgumentException - if either path isn't absolute.
     */
    public static function isOnSameFilesystem(string $path1, string $path2): bool
    {
        foreach ([$path1, $path2] as $path)
        {
            if (!str_starts_with($path, "/"))
            {
                throw new BadArgumentException("isOnSameFilesystem: {$path} must be an absolute path.");
            }
        }

        return self::filesystemDeviceOf($path1) === self::filesystemDeviceOf($path2);
    }


    /**
     * Check whether a path is on a tmpfs filesystem, which is held in memory rather than on disk
     * (e.g. /tmp on Debian 13, or /dev/shm). Writing to one is fast, but uses memory, and it is lost
     * when the system restarts. This reads /proc/mounts, so only works on Linux. Symlinks are
     * followed, so a symlink is checked by what it points to.
     * @param string $path - the file or directory to check.
     * @return bool - true if it is on a tmpfs.
     * @throws FileDoesNotExistException - if there is nothing at $path.
     * @throws UnsupportedPlatformException - if /proc/mounts can't be read (e.g. not on Linux).
     */
    public static function isTmpfs(string $path): bool
    {
        $realPath = realpath($path);

        if ($realPath === false)
        {
            throw new FileDoesNotExistException("isTmpfs: there is nothing at {$path}.");
        }

        # Suppress the warning, as we throw an exception instead.
        $mounts = @file("/proc/mounts", FILE_IGNORE_NEW_LINES);

        if ($mounts === false)
        {
            throw new UnsupportedPlatformException("isTmpfs: /proc/mounts can't be read, so this only works on Linux.");
        }

        $decodeOctalEscape = self::decodeOctalEscape(...);
        $longestMountPoint = "";
        $type = "";

        foreach ($mounts as $mount)
        {
            # Each line is "device mount-point type options ...", with spaces etc. in paths written as octal (\040).
            $fields = explode(" ", $mount);
            $mountPoint = preg_replace_callback('/\\\\([0-7]{3})/', $decodeOctalEscape, $fields[1] ?? "");

            # The path is on the mount with the longest mount point that contains it. A later line for the same mount
            # point is mounted over the earlier one, so wins.
            $contains = ($mountPoint !== "") && self::isWithinDirectory($realPath, $mountPoint);

            if ($contains && strlen($mountPoint) >= strlen($longestMountPoint))
            {
                $longestMountPoint = $mountPoint;
                $type = $fields[2] ?? "";
            }
        }

        return $type === "tmpfs";
    }


    /**
     * Converts a permissions string, such as "0755", into the int that PHP's mkdir() and chmod()
     * expect. Unlike octdec(), this throws for anything that isn't valid, rather than silently
     * ignoring invalid characters and giving the wrong permissions.
     * @param string $permissions - 3 or 4 octal digits, optionally with a leading 0 or 0o, e.g.
     * "755", "0755", "0o755" or "1777".
     * @return int - the permissions as an int, e.g. 493 (0755) for "0755".
     * @throws BadArgumentException - if $permissions is not in one of the forms above.
     */
    public static function permissionsStringToInt(string $permissions): int
    {
        # E.g. an octal int like 0755 arrives here as the string "493", which octdec() would turn into 043.
        if (preg_match('/^(0o?)?[0-7]{3,4}$/D', $permissions) !== 1)
        {
            throw new BadArgumentException(
                "Invalid permissions string \"{$permissions}\". It must be 3 or 4 octal digits, optionally with a " .
                "leading 0 or 0o, e.g. \"0755\" or \"0o755\"."
            );
        }

        return octdec($permissions);
    }


    /**
     * Create a temporary directory. This will be a randomly named directory in the system's
     * temporary directory folder (usually /tmp), e.g. /tmp/corelibs-dir-1a2b3c4d5e6f7a8b. The
     * directory is always a new one made by this call, never one that already existed: mkdir()
     * fails if anything already exists at the path, so another process can't create it first. If
     * the random name is already taken, another is tried.
     * @param string $mode - the permissions the new temporary directory will have, as for mkdir().
     * As with mkdir(), these are reduced by the umask, so e.g. the default "0777" usually gives
     * 0755.
     * @param bool $overrideUmask - set to true to give the directory exactly $mode instead,
     * ignoring the umask. Note that with the default $mode of "0777", that makes it writable by
     * everyone.
     * @return string - the full path to the temporary directory, which is empty.
     * @throws BadArgumentException - if $mode is not 3 or 4 octal digits, optionally with a leading
     * 0 or 0o, e.g. "755", "0755", "0o755" or "1777".
     * @throws FilesystemException - if the temporary directory could not be created, e.g. because
     * the system's temporary directory can't be written to, or $overrideUmask is set and its
     * permissions could not be set.
     */
    public static function tmpDir(string $mode = "0777", bool $overrideUmask = false): string
    {
        try
        {
            $modeInt = self::permissionsStringToInt($mode);
        }
        catch (BadArgumentException $e)
        {
            throw new BadArgumentException(
                "tmpDir: \$mode must be 3 or 4 octal digits, optionally with a leading 0 or 0o, e.g. \"0755\" or " .
                "\"0o755\", but is \"{$mode}\".",
                0,
                $e
            );
        }

        $parentDir = sys_get_temp_dir();
        $maxAttempts = 10;
        $tmpDirPath = null;

        for ($attempt = 1; $attempt <= $maxAttempts && $tmpDirPath === null; $attempt++)
        {
            $path = "{$parentDir}/" . self::randomName("corelibs-dir-");

            # Create the directory in one step, as mkdir() fails if anything already exists there, so a success means
            # it is a new directory of ours. Suppress the warning, as we throw an exception instead.
            if (@mkdir($path, $modeInt))
            {
                $tmpDirPath = $path;
            }
            elseif (!file_exists($path) && !is_link($path))
            {
                # It failed for a reason other than the name being taken, which another name won't fix.
                throw new FilesystemException(
                    "Failed to create a temporary directory in {$parentDir}, e.g. because it can't be written to."
                );
            }
        }

        if ($tmpDirPath === null)
        {
            throw new FilesystemException(
                "Failed to create a temporary directory in {$parentDir}, as {$maxAttempts} random names were all " .
                "already taken."
            );
        }

        if ($overrideUmask && !@chmod($tmpDirPath, $modeInt))
        {
            # Don't leave behind a directory with the wrong permissions.
            @rmdir($tmpDirPath);
            throw new FilesystemException("Created {$tmpDirPath}, but failed to set its permissions to {$mode}.");
        }

        return $tmpDirPath;
    }


    /**
     * Download a file from the provided URL into a temporary file. This will use curl if it is
     * available, and if not, PHP's own HTTP support. Either way, the file is streamed to disk, rather
     * than held in memory. An HTTP error response (a status of 400 or above, such as 404 Not Found)
     * counts as a failed download, rather than downloading the error page.
     * Only http:// and https:// URLs are allowed, including any redirects, so this can't be used to
     * read local files (e.g. "file:///etc/passwd" or "/etc/passwd").
     * WARNING: if the URL comes from a user, check its host first. This can still reach anything the
     * server can, including services that are only meant to be reachable from inside the network
     * (e.g. http://127.0.0.1/, or a cloud provider's metadata service at http://169.254.169.254/).
     * @param string $url - the http:// or https:// URL of the file to download.
     * @return string - the full filepath to the downloaded file on the server.
     * @throws BadArgumentException - if $url isn't an http:// or https:// URL.
     * @throws FilesystemException - if the temporary file could not be created/written to, or the
     * download failed (including a redirect to anything other than http:// or https://). The
     * temporary file is removed if this happens.
     */
    public static function downloadFile(string $url): string
    {
        $scheme = strtolower((string)parse_url($url, PHP_URL_SCHEME));

        if (!in_array($scheme, ["http", "https"], true))
        {
            throw new BadArgumentException("downloadFile: {$url} isn't an http:// or https:// URL.");
        }

        $downloadedFilepath = tempnam(sys_get_temp_dir(), "");

        if ($downloadedFilepath === false)
        {
            throw new FilesystemException("Failed to create a temporary file to download {$url} into.");
        }

        try
        {
            // use curl if installed. Faster and probably more memory efficient.
            if (function_exists('curl_version'))
            {
                $fp = fopen($downloadedFilepath, 'w+');

                if ($fp === false)
                {
                    throw new FilesystemException("Failed to open {$downloadedFilepath} for writing.");
                }

                $ch = curl_init(str_replace(" ", "%20", $url));
                curl_setopt($ch, CURLOPT_TIMEOUT, 50);
                curl_setopt($ch, CURLOPT_FILE, $fp);
                curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);

                # Only allow web URLs, including where a redirect goes, so a redirect can't point at a local file.
                curl_setopt($ch, CURLOPT_PROTOCOLS, CURLPROTO_HTTP | CURLPROTO_HTTPS);
                curl_setopt($ch, CURLOPT_REDIR_PROTOCOLS, CURLPROTO_HTTP | CURLPROTO_HTTPS);

                # Without this, curl treats an HTTP error such as a 404 as a success, and "downloads" the error page.
                # This also matches the file_get_contents() fallback below, which fails on these.
                curl_setopt($ch, CURLOPT_FAILONERROR, true);

                $downloaded = curl_exec($ch);
                $curlError = curl_error($ch);
                fclose($fp);

                if ($downloaded === false)
                {
                    throw new FilesystemException("Failed to download {$url}: {$curlError}");
                }
            }
            else
            {
                # PHP's HTTP wrapper only follows redirects to other http:// and https:// URLs. Capture the warning to
                # put its reason in the exception, rather than letting it through.
                $openUrl = function () use ($url) {
                    return fopen($url, "r");
                };

                $source = Core::runCapturingWarning($openUrl, $error);

                if ($source === false)
                {
                    $error ??= 'unknown error';
                    throw new FilesystemException("Failed to download {$url}: {$error}");
                }

                $destination = fopen($downloadedFilepath, "w");

                # Stream it to the file, rather than reading it all into memory first.
                $copied = ($destination !== false) && stream_copy_to_stream($source, $destination) !== false;
                fclose($source);

                if ($destination !== false)
                {
                    fclose($destination);
                }

                if (!$copied)
                {
                    throw new FilesystemException("Failed to write the downloaded content to {$downloadedFilepath}.");
                }
            }
        }
        catch (FilesystemException $e)
        {
            # Don't leave a partly downloaded (or empty) temporary file behind.
            @unlink($downloadedFilepath);
            throw $e;
        }

        return $downloadedFilepath;
    }


    /**
     * Get a file's type and permissions in the "-rw-r--r--" style that ls -l shows, rather than as
     * a number. The first letter is the type: "-" for a regular file, "d" for a directory, "l" for a
     * symlink, "c" or "b" for a character or block device, "p" for a named pipe, or "s" for a socket.
     * The setuid, setgid and sticky bits show as "s", "s" and "t" in place of the matching "x" (or
     * "S", "S" and "T" when that "x" isn't set), as ls -l shows them.
     * A symlink is reported as the link itself (e.g. "lrwxrwxrwx"), rather than what it points to,
     * as ls -l does, so this works for a broken symlink too.
     * @param string $filepath - the path to the file, directory or symlink.
     * @return string - the type and permissions, e.g. "-rw-r--r--" or "drwxr-xr-x".
     * @throws FileDoesNotExistException - if there is nothing at $filepath.
     * @throws FilesystemException - if its details could not be read.
     */
    public static function fileperms(string $filepath): string
    {
        if (!file_exists($filepath) && !is_link($filepath))
        {
            throw new FileDoesNotExistException("There is nothing at {$filepath} to get the permissions of.");
        }

        # Use lstat() rather than fileperms(), so a symlink is reported as itself, rather than what it points to.
        $readDetails = function () use ($filepath): array|false {
            return lstat($filepath);
        };

        $details = Core::runCapturingWarning($readDetails, $error);

        if ($details === false)
        {
            $error ??= 'unknown error';
            throw new FilesystemException("Failed to read the permissions of {$filepath}: {$error}");
        }

        $mode = $details['mode'];

        $info = match ($mode & 0170000) {
            0140000 => 's',
            0120000 => 'l',
            0100000 => '-',
            0060000 => 'b',
            0040000 => 'd',
            0020000 => 'c',
            0010000 => 'p',
            default => '?',
        };

        # The owner's, group's and others' read, write and execute bits, each with the special bit (setuid, setgid or
        # sticky) that is shown in place of its execute bit.
        $permissionClasses = [
            [0400, 0200, 0100, 04000, 's'],
            [0040, 0020, 0010, 02000, 's'],
            [0004, 0002, 0001, 01000, 't'],
        ];

        foreach ($permissionClasses as [$readBit, $writeBit, $executeBit, $specialBit, $specialLetter])
        {
            $info .= ($mode & $readBit) ? 'r' : '-';
            $info .= ($mode & $writeBit) ? 'w' : '-';

            if ($mode & $specialBit)
            {
                $info .= ($mode & $executeBit) ? $specialLetter : strtoupper($specialLetter);
            }
            else
            {
                $info .= ($mode & $executeBit) ? 'x' : '-';
            }
        }

        return $info;
    }


    /**
     * Fetch the file extension of a specified filename or file path. E.g. "csv" or "txt" This only
     * looks at the last part of a path, so dots in directory names are ignored (e.g.
     * "/var/v1.2/README" has no extension). The extension's case is kept as it is (e.g. "JPG"). As
     * with PHP's pathinfo(), a name that starts with a dot and has no other dots, such as
     * ".bashrc", is treated as having that extension ("bashrc").
     * @param string $filename - the name of the file or the full file path
     * @return string - the file extension, without the dot.
     * @throws NoFileExtensionException - if there is no file extension, such as "README", "file."
     * or "dir/".
     */
    public static function getFileExtension(string $filename): string
    {
        $extension = pathinfo($filename, PATHINFO_EXTENSION);

        if ($extension === '')
        {
            throw new NoFileExtensionException("There is no file extension in \"{$filename}\".");
        }

        return $extension;
    }


    /**
     * Serve up a file to the user. This is particularly useful for CSV/PDF report downloads etc.
     * @param string $filepath - the path to the file you wish to serve up as a download
     * @param string $downloadFilename - the filename the browser should use for the file, e.g. if
     * the user saves it. This can contain any characters, including spaces, punctuation and
     * non-ASCII characters such as "résumé.pdf".
     * @param ?string $mimetype - optionally manually specify the mimetype. Can be null to force not
     * setting mimetype on download. Defaults to "auto" which will have PHP try to dynamically
     * figure out the mimetype of the file.
     * @param bool $asAttachment - specify whether the file should be downloaded as an attachment in
     * the browser (default), or set to false for the file to be "inline" so it just appears in the
     * webview rather than being downloaded. E.g. for a PDF, the PDF could just be shown in the
     * browser and not automatically downloaded, with the user choosing to download the file if they
     * wish
     * @return void
     * @throws FileDoesNotExistException - if there is no file at $filepath.
     * @throws BadFilePermissionsException - if the file exists but cannot be read.
     * @throws FilesystemException - if the mimetype or size of the file could not be determined, or
     * the file could not be read. These are checked before any headers are sent, apart from the
     * file failing to be read.
     */
    public static function streamFileToBrowser(
        string $filepath,
        string $downloadFilename,
        ?string $mimetype="auto",
        bool $asAttachment=true
    ): void
    {
        # Check everything that could fail before sending any headers, so that a failure doesn't leave the browser with
        # a half-sent response.
        if (!is_file($filepath))
        {
            throw new FileDoesNotExistException("There is no file at {$filepath}.");
        }

        if (!is_readable($filepath))
        {
            throw new BadFilePermissionsException("The file at {$filepath} exists but cannot be read.");
        }

        if ($mimetype === "auto")
        {
            $mimetype = (new \finfo(FILEINFO_MIME))->file($filepath);

            if ($mimetype === false)
            {
                throw new FilesystemException("Failed to determine the mimetype of {$filepath}.");
            }
        }

        $fileSize = filesize($filepath);

        if ($fileSize === false)
        {
            throw new FilesystemException("Failed to determine the size of {$filepath}.");
        }

        if ($mimetype !== null)
        {
            header("Content-type: {$mimetype}");
        }

        header("Content-Length: {$fileSize}");
        header("Content-Disposition: " . self::buildContentDisposition($asAttachment, $downloadFilename));
        header("Pragma: no-cache");
        header("Expires: 0");

        if (readfile($filepath) === false)
        {
            throw new FilesystemException("Failed to read {$filepath} in order to send it to the browser.");
        }
    }


    /**
     * Returns whether the file specified contains only valid UTF-8 character encoding or not.
     * @param string $filepath - the path to the file that we wish to check.
     * @param int $chunkSize - the size of each chunk to check at a time. This is a balance between
     * memory and performance.
     * @return bool - true if valid, false if it contains non-UTF-8 characters.
     * @throws BadFilePermissionsException - if file exists but cannot be read.
     * @throws FileDoesNotExistException - if file does not exist.
     * @throws FilesystemException - if something goes wrong with getting a file handle even though
     * file exists and can be read, or reading the file fails part way through.
     * @throws BadArgumentException - if $chunkSize is less than 1.
     */
    public static function isValidUtf8File(string $filepath, int $chunkSize = 8192): bool
    {
        if ($chunkSize < 1)
        {
            throw new BadArgumentException("isValidUtf8File: \$chunkSize must be at least 1.");
        }

        if (!file_exists($filepath))
        {
            throw new FileDoesNotExistException($filepath . " does not exist.");
        }

        if (!is_readable($filepath))
        {
            throw new BadFilePermissionsException("The file exists but cannot be read: $filepath");
        }

        $handle = fopen($filepath, 'rb');

        if ($handle === false)
        {
            throw new FilesystemException("Failed to get file handle for: $filepath");
        }

        $isValid = true;
        $hasReachedEnd = false;

        # A character such as "é" is made of several bytes, so a chunk can end part way through one. Those bytes are
        # held back and checked along with the start of the next chunk, otherwise each half would look invalid.
        $unfinishedCharacter = '';

        while ($isValid && !$hasReachedEnd)
        {
            $chunk = fread($handle, $chunkSize);

            if ($chunk === false)
            {
                fclose($handle);
                throw new FilesystemException("Failed to read from {$filepath} part way through checking it.");
            }

            if ($chunk === '')
            {
                # We've reached the end of the file, so a character that was never finished really is invalid.
                $hasReachedEnd = true;
                $isValid = ($unfinishedCharacter === '');
            }
            else
            {
                $bytes = $unfinishedCharacter . $chunk;
                $unfinishedCharacter = self::getUnfinishedUtf8Character($bytes);
                $bytesToCheck = substr($bytes, 0, strlen($bytes) - strlen($unfinishedCharacter));
                $isValid = self::isValidUtf8($bytesToCheck);
            }
        }

        fclose($handle);
        return $isValid;
    }


    /**
     * Converts a file to UTF-8, if it isn't already. WARNING - this will overwrite/replace the
     * file!
     *
     * It is not possible to reliably detect which encoding a file is in, as in single-byte
     * encodings such as Windows-1252, Windows-1251 and ISO-8859-2 every byte is valid, and the same
     * byte means a different character in each (e.g. 0xE6 is "æ" in Windows-1252, but "ж" in
     * Windows-1251). So this only detects what can be detected reliably, and otherwise relies on
     * you telling it what encoding the file is in:
     *  1. If the file is already valid UTF-8, it is left untouched.
     *  2. If the file starts with a UTF-16 or UTF-32 byte order mark (BOM), it is converted from
     *     that encoding, and the BOM is removed. This overrides $sourceEncoding.
     *  3. Otherwise, the file is converted from $sourceEncoding.
     * The converted contents are written to a temporary file first (see $useSystemTempDir), and
     * only replace the original once complete, keeping its owner and permissions. See
     * replaceWithFile() for how, and what happens if that fails part way through.
     *
     * @param string $inputFilepath - the path to the file to convert.
     * @param string $sourceEncoding - the encoding the file is in, if it is not UTF-8 and has no
     * BOM. This must be an encoding that the mbstring extension supports, e.g. "Windows-1252" (the
     * default, common for Western European text), "Windows-1251" (Cyrillic), "ISO-8859-2" (Central
     * European), "ISO-8859-15" (Western European with the euro sign) or "SJIS" (Japanese). Note
     * that mbstring does NOT support "Windows-1250".
     * @param UseSystemTempDirEnum $useSystemTempDir - where to write the new contents before they
     * replace the file (see UseSystemTempDirEnum). Defaults to AUTO, which picks the fastest place
     * that will work, which can be a hidden, private file next to it for a moment. Pass ALWAYS to
     * keep it in the system's temporary directory, e.g. for a directory served to the public.
     * @param PreserveOwnershipEnum $preserveOwnership - how hard to try to keep the file's owner and
     * group when replacing it (see Filesystem::replaceWithFile()). Defaults to PREFERRED.
     * @return void
     * @throws BadArgumentException - if $sourceEncoding is not an encoding that mbstring supports.
     * @throws BadFilePermissionsException - if the file exists but can't be read, or needs
     * converting but can't be written.
     * @throws FileDoesNotExistException - if input file does not exist.
     * @throws MissingExtensionException - if missing the mbstring extension which is required for
     * this function.
     * @throws FilesystemException - if the file is not valid in the encoding it is being converted
     * from, or anything else goes wrong that is not covered by the other specific exceptions.
     */
    public static function convertFileToUtf8(
        string $inputFilepath,
        string $sourceEncoding = 'Windows-1252',
        UseSystemTempDirEnum $useSystemTempDir = UseSystemTempDirEnum::AUTO,
        PreserveOwnershipEnum $preserveOwnership = PreserveOwnershipEnum::PREFERRED
    ): void
    {
        if (extension_loaded('mbstring') === FALSE)
        {
            throw new MissingExtensionException("The mbstring extension is not available.");
        }

        # Check the encoding is supported before doing anything, as mbstring throws a ValueError otherwise.
        try
        {
            mb_check_encoding('', $sourceEncoding);
        }
        catch (\ValueError $e)
        {
            throw new BadArgumentException(
                "convertFileToUtf8: \"{$sourceEncoding}\" is not an encoding that the mbstring extension supports.",
                0,
                $e
            );
        }

        if (!is_file($inputFilepath))
        {
            throw new FileDoesNotExistException("$inputFilepath file does not exist.");
        }

        if (!is_readable($inputFilepath))
        {
            throw new BadFilePermissionsException("$inputFilepath file exists but cannot be read.");
        }

        $content = file_get_contents($inputFilepath);

        if ($content === false)
        {
            throw new FilesystemException("Failed to read file: $inputFilepath");
        }

        if (self::isValidUtf8($content) === false)
        {
            # A byte order mark reliably tells us the encoding. Check the UTF-32 ones first, as the UTF-32LE BOM starts
            # with the same two bytes as the UTF-16LE one.
            $byteOrderMarks = [
                'UTF-32LE' => "\xFF\xFE\x00\x00",
                'UTF-32BE' => "\x00\x00\xFE\xFF",
                'UTF-16LE' => "\xFF\xFE",
                'UTF-16BE' => "\xFE\xFF",
            ];

            $encoding = $sourceEncoding;

            foreach ($byteOrderMarks as $bomEncoding => $byteOrderMark)
            {
                if ($encoding === $sourceEncoding && str_starts_with($content, $byteOrderMark))
                {
                    $encoding = $bomEncoding;
                    $content = substr($content, strlen($byteOrderMark));
                }
            }

            # For multi-byte encodings such as UTF-16, this catches a file that isn't really in that encoding. For
            # single-byte encodings such as Windows-1252, every byte is valid, so this can't catch the wrong encoding.
            if (mb_check_encoding($content, $encoding) === false)
            {
                throw new FilesystemException(
                    "$inputFilepath is not valid $encoding, so it cannot be converted from it."
                );
            }

            $converted = mb_convert_encoding($content, 'UTF-8', $encoding);
            self::replaceFileContents($inputFilepath, $converted, $useSystemTempDir, $preserveOwnership);
        }
    }


    /**
     * Check that a file can be changed in place (e.g. with replaceWithFile()), before any work is
     * done on it. Only the file itself needs to be readable and writable, not the directory it is
     * in, as the new contents are written back into the same file.
     * @param string $filepath - the path of the file that will be changed.
     * @return void
     * @throws FileDoesNotExistException - if there is no file at $filepath.
     * @throws BadFilePermissionsException - if the file can't be both read and written.
     */
    public static function checkCanChangeFile(string $filepath): void
    {
        if (!is_file($filepath))
        {
            throw new FileDoesNotExistException("There is no file at {$filepath}.");
        }

        if (!is_readable($filepath) || !is_writable($filepath))
        {
            throw new BadFilePermissionsException("{$filepath} must be readable and writable for it to be changed.");
        }
    }


    /**
     * Create an empty temporary file to write a file's new contents to, before they replace the
     * original with replaceWithFile(). This is useful for rewriting a file a piece at a time (e.g.
     * row by row), without changing the original until the new contents are complete. tempnam()
     * creates it with 0600 permissions, so other users can't read the data while it is there.
     * It goes either in the system's temporary directory, or next to the original as a hidden file
     * (e.g. ".corelibs-tmp-AbC123"), as $useSystemTempDir says. replaceWithFile() renames it over the
     * original rather than copying it back where that is safe, which needs it to be on the same
     * filesystem (see replaceWithFile()).
     * @param string $filepath - the path of the file it will replace (which needn't exist yet).
     * @param UseSystemTempDirEnum $useSystemTempDir - where to put it (see UseSystemTempDirEnum).
     * Defaults to AUTO, which picks the fastest place that will work, which can be next to the
     * original. Pass ALWAYS to keep it in the system's temporary directory, e.g. if the original's
     * directory is served to the public (e.g. by a web server).
     * @param PreserveOwnershipEnum $preserveOwnership - only used by AUTO: what will be passed to
     * replaceWithFile(), which decides whether it could rename the file over the original.
     * @return string - the path of the temporary file.
     * @throws DirectoryDoesNotExistException - if the directory $filepath is in doesn't exist.
     * @throws FilesystemException - if the temporary file could not be created.
     */
    public static function createReplacementFile(
        string $filepath,
        UseSystemTempDirEnum $useSystemTempDir = UseSystemTempDirEnum::AUTO,
        PreserveOwnershipEnum $preserveOwnership = PreserveOwnershipEnum::PREFERRED
    ): string
    {
        $directory = realpath(dirname($filepath));

        if ($directory === false || !is_dir($directory))
        {
            throw new DirectoryDoesNotExistException("There is no directory at " . dirname($filepath) . ".");
        }

        $useSystem = match ($useSystemTempDir) {
            UseSystemTempDirEnum::ALWAYS => true,
            UseSystemTempDirEnum::NEVER => false,
            UseSystemTempDirEnum::WHEN_SAME_FS => self::isOnSameFilesystem(sys_get_temp_dir(), $directory),
            UseSystemTempDirEnum::AUTO => self::shouldUseSystemTempDir($filepath, $preserveOwnership),
        };

        $tmpDirectory = $useSystem ? sys_get_temp_dir() : $directory;

        # Suppress the warning, as we throw an exception instead. tempnam() quietly falls back to the system's
        # temporary directory if it can't create the file where asked, so check it is where it should be.
        $tmpFilepath = @tempnam($tmpDirectory, $useSystem ? "corelibs-tmp-" : ".corelibs-tmp-");
        $isWhereAsked = ($tmpFilepath !== false) && (realpath(dirname($tmpFilepath)) === realpath($tmpDirectory));

        if (!$isWhereAsked)
        {
            if ($tmpFilepath !== false)
            {
                @unlink($tmpFilepath);
            }

            throw new FilesystemException("Failed to create a temporary file in {$tmpDirectory}.");
        }

        return $tmpFilepath;
    }


    /**
     * Replace a file's contents with those of a file made by createReplacementFile(), then delete
     * that file.
     * Where it is safe to, this renames the temporary file over the original, which is instant and
     * atomic. That is only when the temporary file is on the same filesystem as the original (next
     * to it, or in the system's temporary directory if that is on the same filesystem), the
     * original is a regular file (not a symlink) with no other hard links, its directory can be
     * written to, and its owner and group can be kept (which needs root, unless the original already
     * belongs to whoever is running this). The renamed file gets the original's permissions, but
     * not any ACLs or extended attributes it had. One renamed in from another directory also doesn't
     * get the original directory's default ACLs, which only apply to files created in it. So when
     * SELinux is enabled, only a temporary file next to the original is renamed, as one from the
     * system's temporary directory would keep that directory's SELinux label (e.g. tmp_t), which
     * can stop services reading it.
     * Otherwise, the new contents are copied into the original in place, so it keeps its
     * permissions, owner, ACLs, extended attributes and any links to it, and only the file (not its
     * directory) needs to be writable. This isn't atomic: if it fails part way through (e.g. the
     * disk fills up), the original may be left half-written. The temporary file is kept in that
     * case, and its path is given in the exception, so that the new contents aren't lost.
     * @param string $filepath - the path of the file to replace the contents of.
     * @param string $tmpFilepath - the path of the temporary file with the new contents. It must
     * already be closed.
     * @param PreserveOwnershipEnum $preserveOwnership - how hard to try to keep the file's owner and
     * group (see PreserveOwnershipEnum). With NO, the file is renamed whenever it can be, even if it
     * then belongs to whoever is running this. With PREFERRED (the default) or YES, it is only
     * renamed if the owner and group can be kept (as root, or if it already belongs to whoever is
     * running this), and otherwise copied back, which is slower for a large file. Copying back
     * always keeps them, so YES never has to throw here, and behaves the same as PREFERRED.
     * @return void
     * @throws FilesystemException - if the contents could not be copied across.
     */
    public static function replaceWithFile(
        string $filepath,
        string $tmpFilepath,
        PreserveOwnershipEnum $preserveOwnership = PreserveOwnershipEnum::PREFERRED
    ): void
    {
        $renamed = self::canReplaceByRenaming($filepath, $tmpFilepath)
            && self::replaceByRenaming($filepath, $tmpFilepath, $preserveOwnership);

        if (!$renamed)
        {
            self::replaceByCopying($filepath, $tmpFilepath);
        }
    }


    /**
     * Add everything in a directory to a zip, for zipDir(), going into subdirectories. Symlinks are
     * added by addSymlinkToZip().
     * @param ZipArchive $zip - the zip being written.
     * @param string $directory - the real path of the directory whose contents to add.
     * @param string $zipDirectory - the path in the zip to add them under, or "" for the top.
     * @param array{source: string, zip: string, followSymlinks: bool} $root - the real path of the
     * folder being zipped, its path in the zip ("" if its contents are at the top), and whether to
     * follow symlinks pointing outside it.
     * @param array<string> $ancestors - the real paths of the directories above this one that are
     * being zipped (including $directory), so following a symlink can't go round in circles.
     * @return void
     * @throws FilesystemException - if a directory or symlink couldn't be read, or following a
     * symlink would go round in circles.
     */
    private static function addDirectoryToZip(
        ZipArchive $zip,
        string $directory,
        string $zipDirectory,
        array $root,
        array $ancestors
    ): void
    {
        foreach (self::readDirectoryEntries($directory) as $entry)
        {
            $path = "{$directory}/{$entry}";

            # The top of the zip has no folder without $includeFolder, so nothing goes in front of those entries.
            $zipPath = ($zipDirectory === "") ? $entry : "{$zipDirectory}/{$entry}";

            if (is_link($path))
            {
                self::addSymlinkToZip($zip, $path, $zipPath, $root, $ancestors);
            }
            elseif (is_dir($path))
            {
                $zip->addEmptyDir($zipPath);
                self::addDirectoryToZip($zip, $path, $zipPath, $root, array_merge($ancestors, [$path]));
            }
            else
            {
                $zip->addFile($path, $zipPath);
            }
        }
    }


    /**
     * Add a symlink to a zip, for zipDir(). One pointing inside the folder being zipped is stored as
     * a symlink, relative to itself, so it still works wherever the zip is extracted. One pointing
     * outside it is stored as it is, or, when following symlinks, replaced by what it points to.
     * @param ZipArchive $zip - the zip being written.
     * @param string $linkPath - the path of the symlink.
     * @param string $zipPath - the path in the zip to add it at.
     * @param array{source: string, zip: string, followSymlinks: bool} $root - see addDirectoryToZip().
     * @param array<string> $ancestors - see addDirectoryToZip().
     * @return void
     * @throws FilesystemException - if the symlink couldn't be read, or following it would go round
     * in circles.
     */
    private static function addSymlinkToZip(
        ZipArchive $zip,
        string $linkPath,
        string $zipPath,
        array $root,
        array $ancestors
    ): void
    {
        # Suppress the warning, as we throw an exception instead.
        $rawTarget = @readlink($linkPath);

        if ($rawTarget === false)
        {
            throw new FilesystemException("Failed to read where the symlink {$linkPath} points.");
        }

        # Where it really leads, following any further links, or (for a broken link) where it would lead.
        $realTarget = realpath($linkPath);

        $resolvedTarget = ($realTarget !== false)
            ? $realTarget
            : self::resolveLinkTarget(dirname($linkPath), $rawTarget);

        if (self::isWithinDirectory($resolvedTarget, $root['source']))
        {
            $targetZipPath = $root['zip'] . substr($resolvedTarget, strlen($root['source']));
            self::addZipSymlink($zip, $zipPath, self::relativePathBetween(dirname($zipPath), $targetZipPath));
        }
        elseif (!$root['followSymlinks'] || $realTarget === false)
        {
            # Not following symlinks, or there's nothing there to follow.
            self::addZipSymlink($zip, $zipPath, $rawTarget);
        }
        elseif (is_dir($realTarget))
        {
            if (in_array($realTarget, $ancestors, true))
            {
                throw new FilesystemException(
                    "Can't follow the symlink {$linkPath}, as it points to {$realTarget}, which contains it, so " .
                    "following it would go round in circles."
                );
            }

            $zip->addEmptyDir($zipPath);
            self::addDirectoryToZip($zip, $realTarget, $zipPath, $root, array_merge($ancestors, [$realTarget]));
        }
        else
        {
            $zip->addFile($realTarget, $zipPath);
        }
    }


    /**
     * Add a symlink to a zip: an entry holding its target, marked as a symlink in the way unzip
     * tools (and unzip()) recognise.
     * @param ZipArchive $zip - the zip being written.
     * @param string $zipPath - the path in the zip to add it at.
     * @param string $target - where the symlink points.
     * @return void
     * @throws FilesystemException - if it couldn't be added.
     */
    private static function addZipSymlink(ZipArchive $zip, string $zipPath, string $target): void
    {
        $added = $zip->addFromString($zipPath, $target)
            && $zip->setExternalAttributesName($zipPath, ZipArchive::OPSYS_UNIX, self::SYMLINK_MODE << 16);

        if (!$added)
        {
            throw new FilesystemException("Failed to add the symlink {$zipPath} to the zip: {$zip->getStatusString()}");
        }
    }


    /**
     * Find the entries in a zip that are symlinks.
     * @param ZipArchive $zip - the open zip.
     * @return array<string> - the names of the symlink entries.
     */
    private static function findZipSymlinkEntries(ZipArchive $zip): array
    {
        $entries = [];

        for ($index = 0; $index < $zip->numFiles; $index++)
        {
            $zip->getExternalAttributesIndex($index, $operatingSystem, $attributes);

            $isSymlink = ($operatingSystem === ZipArchive::OPSYS_UNIX)
                && ((($attributes >> 16) & 0170000) === (self::SYMLINK_MODE & 0170000));

            if ($isSymlink)
            {
                $entries[] = $zip->getNameIndex($index);
            }
        }

        return $entries;
    }


    /**
     * Turn the symlinks unzip() extracted (which ZipArchive::extractTo() writes as files holding
     * their targets) back into symlinks. Every one is checked first, and nothing is changed if any
     * has a problem.
     * @param string $tmpDirectory - where the zip was extracted.
     * @param string $destination - the real path of where it will be moved, which the symlinks'
     * targets are checked against.
     * @param array<string> $entries - the names of the zip's symlink entries.
     * @param bool $trusted - true to allow symlinks pointing outside $destination.
     * @return array<string> - descriptions of the problems found, which is empty if there are none.
     */
    private static function recreateExtractedSymlinks(
        string $tmpDirectory,
        string $destination,
        array $entries,
        bool $trusted
    ): array
    {
        $problems = [];
        $targets = [];

        foreach ($entries as $entry)
        {
            $finalPath = "{$destination}/{$entry}";
            $extractedPath = "{$tmpDirectory}/{$entry}";

            # A target can't be longer than this, so anything longer isn't a real symlink.
            $target = (is_file($extractedPath) && filesize($extractedPath) <= 4096)
                ? file_get_contents($extractedPath)
                : false;

            $isSafeName = !str_starts_with($entry, "/") && !in_array("..", explode("/", $entry), true);
            $isValid = $target !== false && $target !== "" && $isSafeName;
            $resolvedTarget = $isValid ? self::resolveLinkTarget(dirname($finalPath), $target) : "";

            if (!$isValid)
            {
                $problems[] = "the zip has a symlink at {$finalPath}, which isn't a valid symlink.";
            }
            elseif (!$trusted && !self::isWithinDirectory($resolvedTarget, $destination))
            {
                $problems[] =
                    "the zip has a symlink at {$finalPath} pointing to {$target}, which is outside {$destination} " .
                    "(this is only allowed for a trusted zip).";
            }
            else
            {
                $targets[$extractedPath] = $target;
            }
        }

        if (count($problems) === 0)
        {
            foreach ($targets as $extractedPath => $target)
            {
                $replaced = @unlink($extractedPath) && @symlink($target, $extractedPath);

                if (!$replaced)
                {
                    $entry = substr($extractedPath, strlen($tmpDirectory) + 1);
                    $problems[] = "the symlink {$entry} couldn't be created.";
                }
            }
        }

        return $problems;
    }


    /**
     * Find anything that would stop mergeDirectoryInto() moving everything from $source into
     * $destination: a file where $destination has a directory, a directory where it has a file (or
     * a symlink), or an existing directory that can't be written to.
     * @param string $source - the directory whose contents would be moved.
     * @param string $destination - the directory they would be moved into.
     * @return array<string> - descriptions of the problems found, which is empty if there are none.
     * @throws FilesystemException - if one of the directories in $source can't be opened.
     */
    private static function findMergeProblems(string $source, string $destination): array
    {
        $problems = [];

        if (!is_writable($destination) || !is_executable($destination))
        {
            $problems[] = "{$destination} can't be written to.";
        }

        foreach (self::readDirectoryEntries($source) as $entry)
        {
            $from = "{$source}/{$entry}";
            $to = "{$destination}/{$entry}";
            $fromIsDirectory = is_dir($from) && !is_link($from);
            $toExists = file_exists($to) || is_link($to);
            $toIsDirectory = is_dir($to) && !is_link($to);

            if ($toExists && $fromIsDirectory && $toIsDirectory)
            {
                $problems = array_merge($problems, self::findMergeProblems($from, $to));
            }
            elseif ($toExists && ($fromIsDirectory || $toIsDirectory))
            {
                $problems[] = $fromIsDirectory
                    ? "the zip has a directory at {$to}, where there is already a file."
                    : "the zip has a file at {$to}, where there is already a directory.";
            }
        }

        return $problems;
    }


    /**
     * Move everything in $source into $destination, merging directories that already exist there,
     * and replacing files that do (as ZipArchive::extractTo() would). Each emptied directory in
     * $source is removed as it goes. findMergeProblems() should have found no problems first.
     * @param string $source - the directory to move everything out of.
     * @param string $destination - the directory to move it into.
     * @return void
     * @throws FilesystemException - if something couldn't be moved. Anything already moved stays
     * moved, and the rest stays in $source.
     */
    private static function mergeDirectoryInto(string $source, string $destination): void
    {
        foreach (self::readDirectoryEntries($source) as $entry)
        {
            $from = "{$source}/{$entry}";
            $to = "{$destination}/{$entry}";

            if (is_dir($from) && !is_link($from) && is_dir($to) && !is_link($to))
            {
                self::mergeDirectoryInto($from, $to);

                # Suppress the warning: it is empty now, and is only the extraction's own temporary copy.
                @rmdir($from);
            }
            elseif (!@rename($from, $to))
            {
                # Suppress the warning, as we throw an exception instead.
                throw new FilesystemException(
                    "Failed to move {$from} to {$to}, despite checking it could be, so some of the unzipped " .
                    "contents are still in {$source}."
                );
            }
        }
    }


    /**
     * Get the device number of the filesystem a path is on, using the nearest directory above it that
     * exists if the path itself doesn't.
     * @param string $path - an absolute path.
     * @return int - the device number, which is the same for every path on the same filesystem.
     */
    private static function filesystemDeviceOf(string $path): int
    {
        $existingPath = $path;

        # "/" always exists, so this always stops.
        while (!file_exists($existingPath))
        {
            $existingPath = dirname($existingPath);
        }

        return stat($existingPath)['dev'];
    }


    /**
     * Choose where createReplacementFile() puts its file for UseSystemTempDirEnum::AUTO: first
     * whatever will work, then whatever is fastest, then the system's temporary directory:
     *  1. the system's temporary directory, if it is a tmpfs (held in memory) with room, and that
     *     much memory available. Writing there and copying back is about as fast as renaming, and
     *     keeps the original's ACLs and extended attributes.
     *  2. the system's temporary directory, if replaceWithFile() could rename a file from there over
     *     the original (it is on the same filesystem, and SELinux isn't enabled), and it has room.
     *  3. otherwise next to the original, if replaceWithFile() could then rename it over the
     *     original, which is the fastest way.
     *  4. otherwise the system's temporary directory, if it has room, as it would be copied back
     *     either way.
     *  5. otherwise next to the original, if that has room (and the system's temporary directory if
     *     neither does, which will then fail with the reason).
     * @param string $filepath - the file it will replace (which needn't exist yet, but its directory
     * must).
     * @param PreserveOwnershipEnum $preserveOwnership - what will be passed to replaceWithFile().
     * @return bool - true to use the system's temporary directory, or false to put it next to the
     * original.
     */
    private static function shouldUseSystemTempDir(string $filepath, PreserveOwnershipEnum $preserveOwnership): bool
    {
        $directory = realpath(dirname($filepath));
        # The new contents aren't known yet, so judge the room needed by the original's size.
        $neededBytes = is_file($filepath) ? (int)filesize($filepath) : 0;
        $systemTmpDirectory = sys_get_temp_dir();
        $isWritable = is_writable($directory) && is_executable($directory);
        $hasRoom = $isWritable && self::hasFreeSpace($directory, $neededBytes);
        $systemIsTmpfs = self::isTmpfsIfKnown($systemTmpDirectory);

        # A tmpfs (e.g. /tmp on Debian 13) can be allowed more space than there is free memory, so filling it could push
        # the system into swap. Only count it as having room if the memory is free too.
        $systemHasRoom = is_writable($systemTmpDirectory)
            && self::hasFreeSpace($systemTmpDirectory, $neededBytes)
            && (!$systemIsTmpfs || self::hasFreeMemory($neededBytes));

        $fileCouldBeRenamed = self::couldRenameOver($filepath, $preserveOwnership);
        $couldRenameFromNextTo = $hasRoom && $fileCouldBeRenamed;

        $couldRenameFromSystem = $systemHasRoom
            && $fileCouldBeRenamed
            && !self::isSelinuxEnabled()
            && self::isOnSameFilesystem($systemTmpDirectory, $directory);

        return ($systemIsTmpfs && $systemHasRoom)
            || $couldRenameFromSystem
            || (!$couldRenameFromNextTo && ($systemHasRoom || !$hasRoom));
    }


    /**
     * Check whether replaceWithFile() could rename a file next to $filepath over it (see
     * canReplaceByRenaming()), including whether it could keep the owner and group, as far as can
     * be told before the file exists.
     * @param string $filepath - the original file.
     * @param PreserveOwnershipEnum $preserveOwnership - what will be passed to replaceWithFile().
     * @return bool - true if it could.
     */
    private static function couldRenameOver(string $filepath, PreserveOwnershipEnum $preserveOwnership): bool
    {
        $details = (is_file($filepath) && !is_link($filepath)) ? @lstat($filepath) : false;
        $ownershipKeepable = $preserveOwnership === PreserveOwnershipEnum::NO;

        # A new file belongs to whoever makes it, and only root can give it to another user, or to a group they're
        # not in. Without the posix extension, this can't be checked, so assume it can't.
        if (!$ownershipKeepable && $details !== false && function_exists('posix_geteuid'))
        {
            $userId = posix_geteuid();
            $groupIds = array_merge([posix_getegid()], posix_getgroups());

            $ownershipKeepable = ($userId === 0)
                || ($details['uid'] === $userId && in_array($details['gid'], $groupIds, true));
        }

        return $details !== false && $details['nlink'] === 1 && $ownershipKeepable;
    }


    /**
     * Check whether a directory is on a tmpfs, for UseSystemTempDirEnum::AUTO, treating "can't tell"
     * as no (see isTmpfs()).
     * @param string $directory - the directory.
     * @return bool - true if it is known to be on a tmpfs.
     */
    private static function isTmpfsIfKnown(string $directory): bool
    {
        try
        {
            $isTmpfs = self::isTmpfs($directory);
        }
        catch (CoreLibsException $e)
        {
            $isTmpfs = false;
        }

        return $isTmpfs;
    }


    /**
     * Turn an octal escape from /proc/mounts (e.g. "\040" for a space) back into its character.
     * @param array<string> $match - the preg match, whose first group is the three octal digits.
     * @return string - the character.
     */
    private static function decodeOctalEscape(array $match): string
    {
        return chr(octdec($match[1]));
    }


    /**
     * Check whether there is at least a number of bytes of memory available, from /proc/meminfo.
     * @param int $bytes - how many bytes are needed.
     * @return bool - true if there is, or if it can't be told (so the choice isn't based on a guess).
     */
    private static function hasFreeMemory(int $bytes): bool
    {
        try
        {
            $hasFreeMemory = Core::getMemory(MemoryTypeEnum::AVAILABLE, DataSizeUnitEnum::BYTES) >= $bytes;
        }
        catch (CoreLibsException $e)
        {
            $hasFreeMemory = true;
        }

        return $hasFreeMemory;
    }


    /**
     * Check whether a directory's filesystem has at least a number of bytes free.
     * @param string $directory - the directory.
     * @param int $bytes - how many bytes are needed.
     * @return bool - true if there is room, or if it can't be told (so the choice isn't based on a
     * guess).
     */
    private static function hasFreeSpace(string $directory, int $bytes): bool
    {
        # Suppress the warning: if it can't be told, assume there is room.
        $freeBytes = @disk_free_space($directory);
        return ($freeBytes === false) || ($freeBytes >= $bytes);
    }


    /**
     * Check whether SELinux is enabled (enforcing or permissive), which labels every file, so a file
     * renamed in from another directory keeps that directory's label.
     * @return bool - true if it is enabled.
     */
    private static function isSelinuxEnabled(): bool
    {
        return file_exists("/sys/fs/selinux/enforce");
    }


    /**
     * Check whether replaceWithFile() could rename a temporary file over the original, rather than
     * copying it back: the temporary file must be on the same filesystem, and in the same directory
     * if SELinux is enabled (so it has the same SELinux label as a new file there would get), the
     * original must be a regular file, not a symlink, with no other hard links (which would keep
     * the old contents), and the directory must be writable.
     * @param string $filepath - the original file.
     * @param string $tmpFilepath - the temporary file with the new contents.
     * @return bool - true if it could be renamed, as far as can be told before trying.
     */
    private static function canReplaceByRenaming(string $filepath, string $tmpFilepath): bool
    {
        $directory = realpath(dirname($filepath));
        $details = is_file($filepath) && !is_link($filepath) ? @lstat($filepath) : false;

        $tmpDirectory = realpath(dirname($tmpFilepath));

        $isRenamableFromElsewhere = ($tmpDirectory !== false)
            && !self::isSelinuxEnabled()
            && self::isOnSameFilesystem($tmpDirectory, $directory);

        $isRenamableFromThere = ($tmpDirectory === $directory) || $isRenamableFromElsewhere;

        return $directory !== false
            && $details !== false
            && $details['nlink'] === 1
            && $isRenamableFromThere
            && is_writable($directory)
            && is_executable($directory);
    }


    /**
     * Rename a temporary file over the original for replaceWithFile(), after giving it the
     * original's owner, group and permissions.
     * @param string $filepath - the original file.
     * @param string $tmpFilepath - the temporary file with the new contents.
     * @param PreserveOwnershipEnum $preserveOwnership - anything other than NO only renames it if
     * the owner and group could be kept.
     * @return bool - true if it was renamed, or false if it wasn't, in which case both files are
     * left in place, so the contents can be copied back instead.
     */
    private static function replaceByRenaming(
        string $filepath,
        string $tmpFilepath,
        PreserveOwnershipEnum $preserveOwnership
    ): bool
    {
        $original = stat($filepath);
        $tmp = stat($tmpFilepath);

        # Set the owner and group before the permissions, as changing the owner can clear the setuid and setgid bits.
        # Only root can change the owner, and only to a group it is in for anyone else, so just try, then check.
        # Suppress the warnings, as whether it worked is checked below.
        if ($tmp['uid'] !== $original['uid'])
        {
            @chown($tmpFilepath, $original['uid']);
        }

        if ($tmp['gid'] !== $original['gid'])
        {
            @chgrp($tmpFilepath, $original['gid']);
        }

        clearstatcache(true, $tmpFilepath);
        $tmp = stat($tmpFilepath);
        $ownershipKept = ($tmp['uid'] === $original['uid']) && ($tmp['gid'] === $original['gid']);

        return ($ownershipKept || $preserveOwnership === PreserveOwnershipEnum::NO)
            && @chmod($tmpFilepath, $original['mode'] & 07777)
            && @rename($tmpFilepath, $filepath);
    }


    /**
     * Copy the contents of a temporary file into the original in place for replaceWithFile(), then
     * delete it (see replaceWithFile()).
     * @param string $filepath - the original file.
     * @param string $tmpFilepath - the temporary file with the new contents.
     * @return void
     * @throws FilesystemException - if the contents could not be copied across.
     */
    private static function replaceByCopying(string $filepath, string $tmpFilepath): void
    {
        # Suppress the warnings, as we throw an exception instead.
        $source = @fopen($tmpFilepath, "r");
        $target = @fopen($filepath, "r+");

        if ($source === false || $target === false)
        {
            # Nothing has been written yet, so the original is untouched, and the temporary file isn't needed.
            if ($source !== false)
            {
                fclose($source);
            }

            @unlink($tmpFilepath);
            throw new FilesystemException("Failed to open {$filepath} to write its new contents to.");
        }

        $newSize = fstat($source)['size'];

        # Write over the old contents first, then cut off whatever is left of them, so the file is only ever
        # shortened once the new contents are all in place.
        $succeeded = stream_copy_to_stream($source, $target) === $newSize
            && ftruncate($target, $newSize)
            && fflush($target);

        fclose($source);
        fclose($target);

        if (!$succeeded)
        {
            throw new FilesystemException(
                "Failed to write the new contents of {$filepath}, so it may be incomplete. The complete new contents " .
                "are in {$tmpFilepath}."
            );
        }

        @unlink($tmpFilepath);
    }


    /**
     * Make a random name for a temporary file or directory, e.g. "corelibs-dir-1a2b3c4d5e6f7a8b".
     * @param string $prefix - what the name starts with.
     * @return string - the prefix, followed by 16 random hex characters.
     * @throws FilesystemException - if no random bytes could be got (which only happens if the system
     * has no source of randomness).
     */
    private static function randomName(string $prefix): string
    {
        try
        {
            $randomPart = bin2hex(random_bytes(8));
        }
        catch (\Random\RandomException $e)
        {
            throw new FilesystemException("Failed to make a random name for a temporary directory.", 0, $e);
        }

        return $prefix . $randomPart;
    }


    /**
     * Remove directories as a best effort, while cleaning up after something else has failed, so that a
     * failure to remove them doesn't replace the original error.
     * @param array<string> $directories - the directories to remove. Any that don't exist are skipped.
     * @return array<string> - the directories that couldn't be removed, which is empty if they all were.
     */
    private static function removeDirectoriesQuietly(array $directories): array
    {
        $leftBehind = [];

        foreach ($directories as $directory)
        {
            if (is_dir($directory))
            {
                try
                {
                    self::deleteDir($directory);
                }
                catch (CoreLibsException $e)
                {
                    $leftBehind[] = $directory;
                }
            }
        }

        return $leftBehind;
    }


    /**
     * Read the names of the entries in a directory, apart from "." and "..".
     * @param string $directory - the directory to read.
     * @return array<string> - the names, in the order the system gives them.
     * @throws FilesystemException - if the directory can't be opened.
     */
    private static function readDirectoryEntries(string $directory): array
    {
        # Suppress the warning, as we throw an exception instead.
        $handle = @opendir($directory);

        if ($handle === false)
        {
            throw new FilesystemException("Failed to open the directory {$directory}, e.g. because it can't be read.");
        }

        $entries = [];

        while (($entry = readdir($handle)) !== false)
        {
            if ($entry !== "." && $entry !== "..")
            {
                $entries[] = $entry;
            }
        }

        closedir($handle);
        return $entries;
    }


    /**
     * Decide whether a recursive listing should go into a directory it has found.
     * @param string $entryPath - the directory found (which may be a symlink to one).
     * @param bool $recursive - whether the listing is recursive at all.
     * @param bool $followSymlinks - whether to go into symlinks to directories.
     * @param array $ancestors - the real paths of the directories the listing is already inside.
     * @return bool - true to go into it.
     */
    private static function shouldGoInto(
        string $entryPath,
        bool $recursive,
        bool $followSymlinks,
        array $ancestors
    ): bool
    {
        # A symlink back up to a directory the listing is already inside would go round in circles.
        return $recursive
            && (!is_link($entryPath) || $followSymlinks)
            && !in_array(realpath($entryPath), $ancestors, true);
    }


    /**
     * Build the value for a Content-Disposition header, in the standard way (RFC 6266), so that any
     * filename works. This gives two versions of the filename: a quoted, ASCII-only fallback for
     * very old clients, and the exact filename, percent-encoded as UTF-8, which all current
     * browsers use. E.g. attachment; filename="r_sum_.pdf"; filename*=UTF-8''r%C3%A9sum%C3%A9.pdf
     * @param bool $asAttachment - true for "attachment" (download), false for "inline" (display in
     * the browser).
     * @param string $filename - the filename the browser should use.
     * @return string - the value for the Content-Disposition header.
     */
    private static function buildContentDisposition(bool $asAttachment, string $filename): string
    {
        # Remove newlines and other control characters, as PHP refuses to send a header containing a newline, and
        # would drop the whole header (and thus the filename).
        $cleanFilename = preg_replace('/[\x00-\x1F\x7F]/', '', $filename);

        # The fallback must be plain ASCII, so replace anything else with an underscore. Do this per character if the
        # filename is valid UTF-8 (so "é" becomes one underscore), otherwise per byte.
        $asciiFallback = preg_replace('/[^\x20-\x7E]/u', '_', $cleanFilename)
            ?? preg_replace('/[^\x20-\x7E]/', '_', $cleanFilename);

        # Inside the quotes, backslashes and double quotes must be escaped.
        $quotedFallback = '"' . addcslashes($asciiFallback, '"\\') . '"';

        $type = ($asAttachment) ? "attachment" : "inline";
        $contentDisposition = "{$type}; filename={$quotedFallback}; filename*=UTF-8''" . rawurlencode($cleanFilename);
        return $contentDisposition;
    }


    /**
     * Check whether a string is valid UTF-8, without needing the mbstring extension. This uses
     * PCRE, which is always part of PHP, and rejects the same things that mb_check_encoding() does,
     * such as overlong encodings, surrogates, and code points above U+10FFFF.
     * @param string $bytes - the bytes to check.
     * @return bool - true if the bytes are valid UTF-8 (an empty string is), false if not.
     */
    private static function isValidUtf8(string $bytes): bool
    {
        # With the u modifier, preg_match() returns false instead of matching if the subject isn't valid UTF-8.
        $isValid = (preg_match('//u', $bytes) === 1);
        return $isValid;
    }


    /**
     * Get the bytes of a UTF-8 character that the provided bytes end part way through, if any. E.g.
     * if the bytes end with only the first of the two bytes that make up "é", that byte is
     * returned. This only looks at the start of the last character, so it does not check whether
     * the bytes are valid UTF-8.
     * @param string $bytes - the bytes to check the end of.
     * @return string - the bytes of the unfinished character at the end (at most 3), or an empty
     * string if the bytes don't end part way through a character.
     */
    private static function getUnfinishedUtf8Character(string $bytes): string
    {
        $unfinished = '';
        $numBytes = strlen($bytes);
        $hasFoundStart = false;

        # A UTF-8 character is at most 4 bytes long, so an unfinished one is at most 3 bytes. Walk backwards over
        # those, looking for the byte that starts the last character (continuation bytes are 10xxxxxx).
        for ($distanceFromEnd = 1; !$hasFoundStart && $distanceFromEnd <= min(3, $numBytes); $distanceFromEnd++)
        {
            $byte = ord($bytes[$numBytes - $distanceFromEnd]);

            if (($byte & 0xC0) !== 0x80)
            {
                $hasFoundStart = true;

                # The start byte tells us how long the character should be: 11110xxx = 4, 1110xxxx = 3, 110xxxxx = 2.
                $expectedLength = match (true) {
                    $byte >= 0xF0 => 4,
                    $byte >= 0xE0 => 3,
                    $byte >= 0xC0 => 2,
                    default => 1,
                };

                if ($expectedLength > $distanceFromEnd)
                {
                    $unfinished = substr($bytes, -$distanceFromEnd);
                }
            }
        }

        return $unfinished;
    }


    /**
     * Add everything in a directory to a deletion plan, in an order that can be deleted (each
     * directory's contents before the directory itself), going into subdirectories, and checking
     * that each thing can be deleted. See deleteDir() for how symlinks are handled. Stops once
     * there are 100 problems, as the plan won't be used then.
     * @param string $dir - the directory whose contents to plan the deletion of.
     * @param string $realDir - the real path of $dir (with symlinks resolved), used to spot
     * symlinks pointing "up".
     * @param bool $followSymlinks - whether to follow symlinks, deleting what they point to as well
     * as the links.
     * @param array $plan - the plan so far, as a list of [FileDeletionOperationEnum, path] pairs,
     * which this adds to.
     * @param array $problems - the list of FileDeletionProblem objects found so far, which this
     * adds to.
     * @param array $planned - real paths => true, for everything already in the plan, which this
     * adds to.
     * @return void
     */
    private static function planDirectoryContents(
        string $dir,
        string $realDir,
        bool $followSymlinks,
        array &$plan,
        array &$problems,
        array &$planned
    ): void
    {
        # Check the count before every addition, and before going into each subdirectory, so the list never goes over
        # MAX_DELETION_PROBLEMS, and nothing more is looked at once it is full.
        if (count($problems) < self::MAX_DELETION_PROBLEMS)
        {
            # A directory has to be listed (readable) and opened (executable) to find and delete what's in it.
            $entries = (is_readable($dir) && is_executable($dir)) ? @scandir($dir) : false;

            if ($entries === false)
            {
                $problems[] = new FileDeletionProblem($dir, $dir, FileDeletionProblemEnum::DIRECTORY_NOT_LISTABLE);
            }
            else
            {
                $entries = array_values(array_diff($entries, ['.', '..']));

                for ($i = 0; $i < count($entries) && count($problems) < self::MAX_DELETION_PROBLEMS; $i++)
                {
                    $path = "{$dir}/{$entries[$i]}";

                    if (is_link($path))
                    {
                        # realpath() follows the link, and is false if it is broken.
                        $target = $followSymlinks ? realpath($path) : false;

                        # Only remove the link itself if not following links, it is broken, what it points to is
                        # already being deleted, or it points "up" to a directory that contains it (which would
                        # otherwise loop, or delete the directories the link is in).
                        $followLink = $target !== false
                            && !isset($planned[$target])
                            && !(is_dir($target) && self::isWithinDirectory($realDir, $target));

                        if ($followLink)
                        {
                            $planned[$target] = true;

                            if (is_dir($target))
                            {
                                self::planDirectoryContents($target, $target, true, $plan, $problems, $planned);
                                self::planDeletion(FileDeletionOperationEnum::RMDIR, $target, $plan, $problems);
                            }
                            else
                            {
                                self::planDeletion(FileDeletionOperationEnum::UNLINK, $target, $plan, $problems);
                            }
                        }

                        self::planDeletion(FileDeletionOperationEnum::UNLINK, $path, $plan, $problems);
                    }
                    else
                    {
                        # When following links, a symlink may already have led to this, in which case it's planned.
                        $realPath = $followSymlinks ? realpath($path) : "{$realDir}/{$entries[$i]}";

                        if (!isset($planned[$realPath]))
                        {
                            $planned[$realPath] = true;

                            if (is_dir($path))
                            {
                                self::planDirectoryContents(
                                    $path,
                                    $realPath,
                                    $followSymlinks,
                                    $plan,
                                    $problems,
                                    $planned
                                );

                                self::planDeletion(FileDeletionOperationEnum::RMDIR, $path, $plan, $problems);
                            }
                            else
                            {
                                self::planDeletion(FileDeletionOperationEnum::UNLINK, $path, $plan, $problems);
                            }
                        }
                    }
                }
            }
        }
    }


    /**
     * Add one thing to a deletion plan, after checking it can be deleted from its directory.
     * @param FileDeletionOperationEnum $operation - UNLINK for a file or symlink, or RMDIR for a
     * directory (once it is empty).
     * @param string $path - the thing to delete.
     * @param array $plan - the plan so far, as a list of [FileDeletionOperationEnum, path] pairs,
     * which this adds to.
     * @param array $problems - the list of FileDeletionProblem objects found so far, which this
     * adds to.
     * @return void
     */
    private static function planDeletion(
        FileDeletionOperationEnum $operation,
        string $path,
        array &$plan,
        array &$problems
    ): void
    {
        # Stop adding problems once the list is full, as a directory's own check comes after its contents', so could
        # otherwise take the list over the limit.
        if (count($problems) < self::MAX_DELETION_PROBLEMS)
        {
            self::findDeletionProblem(dirname($path), $path, $problems);
        }

        $plan[] = [$operation, $path];
    }


    /**
     * Throw a DirectoryNotDeletableException if anything was found that can't be deleted.
     * @param string $dir - the directory being deleted or emptied, for the message.
     * @param array $problems - the FileDeletionProblem objects found.
     * @return void
     * @throws DirectoryNotDeletableException - if there are any problems.
     */
    private static function throwIfDeletionProblems(string $dir, array $problems): void
    {
        $maxProblems = self::MAX_DELETION_PROBLEMS;

        if (count($problems) > 0)
        {
            # Keep the message short, giving the first problem as an example. getProblems() has them all.
            $countText = (count($problems) >= $maxProblems)
                ? "{$maxProblems} or more (stopped counting at {$maxProblems}) things"
                : count($problems) . " thing(s)";

            throw new DirectoryNotDeletableException(
                "Can't delete {$dir}, as {$countText} in it can't be deleted (e.g. " .
                self::describeDeletionProblem($problems[0]) . "), so nothing was deleted. Call getProblems() on " .
                "this exception for the full list.",
                $problems
            );
        }
    }


    /**
     * Carry out a deletion plan made by planDirectoryContents() and planDeletion(), in order.
     * @param string $dir - the directory being deleted or emptied, for the message.
     * @param array $plan - a list of [FileDeletionOperationEnum, path] pairs.
     * @return void
     * @throws FilesystemException - if something still couldn't be deleted. Some of the plan will
     * already have been carried out if this happens.
     */
    private static function runDeletionPlan(string $dir, array $plan): void
    {
        foreach ($plan as [$operation, $path])
        {
            # Suppress the warnings, as we throw an exception instead.
            # match() throws an UnhandledMatchError for a case it doesn't handle, rather than guessing.
            $deleted = match ($operation)
            {
                FileDeletionOperationEnum::UNLINK => @unlink($path),
                FileDeletionOperationEnum::RMDIR => @rmdir($path),
            };

            if (!$deleted)
            {
                throw new FilesystemException(
                    "Failed to delete {$path}, despite checking it could be (e.g. it is immutable, or on a read-only " .
                    "filesystem), so some of {$dir} may already have been deleted."
                );
            }
        }
    }


    /**
     * Describe why something can't be deleted, for an exception message.
     * @param FileDeletionProblem $problem - the problem to describe.
     * @return string - the description, e.g. "/x/a.txt, as /x can't be written to".
     */
    private static function describeDeletionProblem(FileDeletionProblem $problem): string
    {
        return match ($problem->reason)
        {
            FileDeletionProblemEnum::DIRECTORY_NOT_LISTABLE => "{$problem->path} can't be listed",
            FileDeletionProblemEnum::DIRECTORY_NOT_WRITABLE =>
                "{$problem->path}, as {$problem->directory} can't be written to",
            FileDeletionProblemEnum::NOT_OWNED_IN_STICKY_DIRECTORY =>
                "{$problem->path}, as {$problem->directory} is a sticky directory, and it isn't ours",
        };
    }


    /**
     * Add a FileDeletionProblem to $problems if something can't be deleted from its directory.
     * Deleting something needs its directory to be writable and executable, and in a "sticky"
     * directory (e.g. /tmp), it also needs the current user to own it or the directory (or be
     * root).
     * @param string $parentDir - the directory that $path is in.
     * @param string $path - the thing that would be deleted.
     * @param array $problems - the list of FileDeletionProblem objects found so far, which this
     * adds to.
     * @return void
     */
    private static function findDeletionProblem(string $parentDir, string $path, array &$problems): void
    {
        if (!is_writable($parentDir) || !is_executable($parentDir))
        {
            $problems[] = new FileDeletionProblem($path, $parentDir, FileDeletionProblemEnum::DIRECTORY_NOT_WRITABLE);
        }
        elseif ((fileperms($parentDir) & 01000) !== 0 && function_exists('posix_geteuid'))
        {
            # In a sticky directory, only the owner of a file, the owner of the directory, or root can delete it.
            # Without the posix extension the current user can't be found, so this is left for the delete to find.
            $userId = posix_geteuid();
            $stats = @lstat($path);
            $ownerId = ($stats !== false) ? $stats['uid'] : null;
            $ownsEnough = $userId === 0 || $ownerId === $userId || fileowner($parentDir) === $userId;

            if (!$ownsEnough)
            {
                $problems[] = new FileDeletionProblem(
                    $path,
                    $parentDir,
                    FileDeletionProblemEnum::NOT_OWNED_IN_STICKY_DIRECTORY
                );
            }
        }
    }


    /**
     * Replace the contents of a file. The new contents are written to a temporary file first (see
     * $useSystemTempDir), and then replace the original with replaceWithFile(), keeping its owner
     * and permissions.
     * @param string $filepath - the path to the file to replace the contents of.
     * @param string $contents - the new contents for the file.
     * @param UseSystemTempDirEnum $useSystemTempDir - where to write them first.
     * @param PreserveOwnershipEnum $preserveOwnership - how hard to try to keep the file's owner and
     * group.
     * @return void
     * @throws FileDoesNotExistException - if there is no file at $filepath.
     * @throws BadFilePermissionsException - if the file can't be both read and written.
     * @throws FilesystemException - if the new contents could not be written. See replaceWithFile()
     * for what happens if this fails part way through copying them into the original.
     */
    private static function replaceFileContents(
        string $filepath,
        string $contents,
        UseSystemTempDirEnum $useSystemTempDir,
        PreserveOwnershipEnum $preserveOwnership
    ): void
    {
        self::checkCanChangeFile($filepath);
        $tmpFilepath = self::createReplacementFile($filepath, $useSystemTempDir, $preserveOwnership);

        # Nothing has been copied into the original yet, so if this fails it is untouched, and the temporary file can
        # go.
        if (@file_put_contents($tmpFilepath, $contents) !== strlen($contents))
        {
            @unlink($tmpFilepath);
            throw new FilesystemException("Failed to write the new contents of {$filepath} to a temporary file.");
        }

        self::replaceWithFile($filepath, $tmpFilepath, $preserveOwnership);
    }
}
