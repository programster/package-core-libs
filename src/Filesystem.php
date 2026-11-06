<?php

/*
 * Library just for filesystem operations.
 */

namespace Programster\CoreLibs;


use Programster\CoreLibs\Enums\DataSizeUnitEnum;
use Programster\CoreLibs\Enums\FileDeletionOperationEnum;
use Programster\CoreLibs\Enums\FileDeletionProblemEnum;
use Programster\CoreLibs\Exceptions\DirectoryNotDeletableException;
use Programster\CoreLibs\Models\FileDeletionProblem;
use Programster\CoreLibs\Exceptions\BadFilePermissionsException;
use Programster\CoreLibs\Exceptions\CommandFailedException;
use Programster\CoreLibs\Exceptions\FileAlreadyExistsException;
use Programster\CoreLibs\Exceptions\FileDoesNotExistException;
use Programster\CoreLibs\Exceptions\MissingExtensionException;
use Programster\CoreLibs\Exceptions\NoFileExtensionException;
use Programster\CoreLibs\Exceptions\FilesystemException;
use Programster\CoreLibs\Exceptions\UnexpectedOutputException;
use Programster\CoreLibs\Exceptions\BadArgumentException;
use ZipArchive;

final class Filesystem
{
    private const MAX_DELETION_PROBLEMS = 100;
    private static array $s_fileLocks = [];


    /**
     * Retrieves a list of all the directories within the specified directory
     * It does not include the .. directory (dot directories)
     * This will NOT return any files
     * @param string $path - the path to the directory that we want to search.
     * @param bool $recursive - whether to recursively loop through the
     *                          directories to find more.
     * @param bool $includePath - set to true to include the full path to the
     *                            dir, not just the dir name.
     * @return array<string> - list of directories within the specified path.
     * @throws FileDoesNotExistException - if there is no directory at $path.
     * @throws FilesystemException - if the directory (or, if $recursive, one of its subdirectories) can't be opened,
     *                               e.g. because it can't be read.
     */
    public static function getDirectories(
        string $path,
        bool $recursive = false,
        bool $includePath = true
    ): array
    {
        $directories = array();
        $fpath     = realpath($path);

        # Throw rather than returning an empty list (with a warning), so that a missing or unreadable directory
        # can't be mistaken for an empty one.
        if (!is_dir($path))
        {
            throw new FileDoesNotExistException("There is no directory at {$path}.");
        }

        # Suppress the warning, as we throw an exception instead.
        $handle    = @opendir($path);

        if ($handle === false)
        {
            throw new FilesystemException("Failed to open the directory {$path}, e.g. because it can't be read.");
        }

        while (false !== ($filename = readdir($handle)))
        {
            if (strcmp($filename, "..") != 0 &&
                strcmp($filename, ".") != 0)
            {
                if (is_dir($fpath . "/" . $filename))
                {
                    if ($includePath)
                    {
                        $directories[] = $fpath . "/" . $filename;
                    }
                    else
                    {
                        $directories[] = $filename;
                    }

                    if ($recursive)
                    {
                        $subFilepath = $fpath . "/" . $filename;

                        $subFiles = self::getDirectories($subFilepath,
                                                         $recursive,
                                                         $includePath);

                        $directories = array_merge($directories, $subFiles);
                    }
                }
            }
        }

        closedir($handle);
        return $directories;
    }


    /**
     * Retrieves an array list of files/folders within the specified directory.
     *   Consider using the following instead:
     *   $directory = new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::CURRENT_AS_SELF);
     *   $iterator = new RecursiveIteratorIterator($directory, RecursiveIteratorIterator::CHILD_FIRST);
     *
     * @param string $dir - the path to the directory you wish to find the contents of.
     * @param bool $recursive - whether we go through into each subfolder and retrieve its contents.
     * @param bool $includePath - whether we output the path to the entry such as '/folder/text.txt'
     *                            instead of just 'text.txt'
     * @param bool $onlyFiles - whether we include the directory itself in the returned list
     * @param bool $includeHiddenFilesAndFolders - if false, then this will not include
     *                                          hidden files and folders (those starting with '.')
     * @return array - the names of all the files/folders within the directory.
     * @throws FileDoesNotExistException - if there is no directory at $dir.
     * @throws FilesystemException - if the directory (or, if $recursive, one of its subdirectories) can't be opened,
     *                               e.g. because it can't be read.
     */
    public static function getDirContents(
        string $dir,
        bool $recursive   = true,
        bool $includePath = true,
        bool $onlyFiles   = true,
        bool $includeHiddenFilesAndFolders = true
    ): array
    {
        $fileNames = array();
        $fpath     = realpath($dir);

        # Throw rather than returning an empty list (with a warning), so that a missing or unreadable directory
        # can't be mistaken for an empty one.
        if (!is_dir($dir))
        {
            throw new FileDoesNotExistException("There is no directory at {$dir}.");
        }

        # Suppress the warning, as we throw an exception instead.
        $handle    = @opendir($dir);

        if ($handle === false)
        {
            throw new FilesystemException("Failed to open the directory {$dir}, e.g. because it can't be read.");
        }

        while (false !== ($fileName = readdir($handle)))
        {
            if ($includeHiddenFilesAndFolders === false && StringLib::startsWith($fileName, "."))
            {
                continue;
            }

            if (strcmp($fileName, "..") != 0 && strcmp($fileName, ".") != 0)
            {
                if (is_dir($fpath . "/" . $fileName))
                {
                    if (!$onlyFiles)
                    {
                        if ($includePath)
                        {
                            $fileNames[] = $fpath . "/" . $fileName;
                        }
                        else
                        {
                            $fileNames[] = $fileName;
                        }
                    }

                    if ($recursive)
                    {
                        $subFilePath = $fpath . "/" . $fileName;

                        $subFiles = self::getDirContents(
                            $subFilePath,
                            $recursive,
                            $includePath,
                            $onlyFiles,
                            $includeHiddenFilesAndFolders
                        );

                        $fileNames = array_merge($fileNames, $subFiles);
                    }
                }
                else
                {
                    if ($includePath)
                    {
                        $fileNames[] = $fpath . "/" . $fileName;
                    }
                    else
                    {
                        $fileNames[] = $fileName;
                    }
                }
            }
        }

        closedir($handle);
        return $fileNames;
    }


    /**
     * Wrapper around the mkdir that will only execute it if the directory
     * doesn't already exist. Also, this defaults to being recursive so that it
     * will create all relevant parent directories
     *
     * @param string $dirPath - the path to the directory we wish to create
     * @param string $perms - the permissions to set for the directory, as 3 or 4 octal digits, optionally with a
     *                        leading 0 or 0o, e.g. "0700", "755" or "0o755". Defaults to "0755".
     * @param bool $recursive - override to false if you want to fail if parent
     *                          dirs don't exist.
     *
     * @return void
     * @throws BadArgumentException - if $perms is not 3 or 4 octal digits, optionally with a leading 0 or 0o.
     * @throws FilesystemException - if the directory doesn't exist and could not be created.
     */
    public static function mkdir(
        string $dirPath,
        string $perms = "0755",
        bool $recursive = true
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

        # Suppress the warning, as we throw an exception with the details instead. The second is_dir() check is in
        # case another process created the directory at the same time.
        if (!is_dir($dirPath) && !@mkdir($dirPath, $permsInt, $recursive) && !is_dir($dirPath))
        {
            $error = error_get_last()['message'] ?? 'unknown error';
            throw new FilesystemException("Failed to create the directory {$dirPath}: {$error}");
        }
    }


    /**
     * Deletes a directory even if it is not already empty. This resolves the
     *  issue with trying to use unlink on a non-empty dir.
     * By default, symlinks are never followed: a symlink inside the directory is removed without touching what it
     * points to, and if $dir is itself a symlink, this throws rather than guessing whether the link or the folder it
     * points to should be deleted. (To remove just the link, use unlink().)
     * With $followSymlinks, symlinks are followed instead, and what they point to is deleted as well as the links: if
     * $dir is a symlink, the directory it points to is deleted, and then the link. A symlink inside to a directory has
     * that directory deleted (following the symlinks in it too) before the link, and one to a file has the file deleted
     * before the link. To avoid loops and deleting more than intended, a symlink that points "up" to a directory that
     * contains it (e.g. ".." or "/") is never followed, and only the link is removed, and nothing is deleted twice,
     * however many symlinks lead to it.
     * Everything is checked before anything is deleted (see emptyDir()), so if anything can't be deleted, nothing is.
     * This includes anything that would be deleted by following symlinks.
     * @param string $dir - the path to the directory you wish to delete
     * @param bool $followSymlinks - whether to follow symlinks, and delete what they point to as well as the links.
     *                               Defaults to false.
     * @return void
     * @throws BadArgumentException - if $dir is a symlink and $followSymlinks is false, or $dir is a symlink to a
     *                                directory that contains it (so following it would delete the link's own parent).
     * @throws FileDoesNotExistException - if a directory does not exist at the path specified.
     * @throws DirectoryNotDeletableException - if the checks find anything that can't be deleted, including $dir
     *                                         itself (e.g. because its parent directory can't be written to). Its
     *                                         getProblems() says what, and why. Nothing is deleted if this happens.
     *                                         This extends BadFilePermissionsException.
     * @throws FilesystemException - if deleting something still failed part way through, despite the checks (see
     *                               emptyDir()).
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
            throw new FileDoesNotExistException("There is no directory at {$dir}.");
        }

        $realDir = realpath($dir);
        $plan = array();
        $problems = array();

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
     * Deletes all files from a directory, but leaves the directory in place.
     * If $dir is a symlink to a directory, the contents of the directory it points to are deleted, and both the link
     * and that directory are left in place. Symlinks inside the directory are handled as in deleteDir(): by default
     * they are removed without touching what they point to, and with $followSymlinks, what they point to is deleted
     * as well (never following one that points "up" to a directory containing it, or deleting anything twice).
     * Everything is checked before anything is deleted, so if anything can't be deleted, nothing is. A directory's
     * contents can only be deleted if it can be listed and written to, and in a "sticky" directory such as /tmp, only
     * things the current user owns (or that are in a directory they own) can be deleted. Some causes of failure can't
     * be seen beforehand, though, such as an immutable file, a read-only filesystem, or another process changing the
     * directory while it is being deleted. If one of those happens, this throws a FilesystemException part way through,
     * and some of the contents will already have been deleted.
     * @param string $dir - the path to the directory one wishes to empty, or a symlink to it.
     * @param bool $followSymlinks - whether to follow symlinks inside the directory, and delete what they point to as
     *                               well as the links. Defaults to false.
     * @return void
     * @throws FileDoesNotExistException - if a directory does not exist at the path specified.
     * @throws DirectoryNotDeletableException - if the checks find anything that can't be deleted. Its getProblems()
     *                                         says what, and why (stopping at 100). Nothing is deleted if this
     *                                         happens. This extends BadFilePermissionsException.
     * @throws FilesystemException - if deleting something still failed part way through, despite the checks.
     */
    public static function emptyDir(string $dir, bool $followSymlinks = false): void
    {
        # is_dir() follows symlinks, so a symlink to a directory is emptied too, which is what it was asked to do.
        if (!is_dir($dir))
        {
            throw new FileDoesNotExistException("There is no directory at {$dir}.");
        }

        $realDir = realpath($dir);
        $plan = array();
        $problems = array();
        $planned = [$realDir => true];
        self::planDirectoryContents($realDir, $realDir, $followSymlinks, $plan, $problems, $planned);
        self::throwIfDeletionProblems($dir, $problems);
        self::runDeletionPlan($dir, $plan);
    }


    /**
     * Lock a file so that only we can use it.
     * If $isBlocking is false, this returns false immediately if the lock can't be obtained. If true, it waits until
     * the lock can be obtained.
     * Note that this only allows other processes to see that you have locked it and does not
     * actually guarantee that it cannot be edited at the same time on Linux.
     * @param string $filePath - the full path to the file that we wish to lock
     * @param bool $isWriteLock - if false this will get a shared read lock, if true this
     *                               will get an exclusive write lock.
     * @param bool $isBlocking - if true will wait to get lock.
     * @return bool - true if we managed to lock the file. False is only returned when $isBlocking is false and
     *                something else already holds a conflicting lock, so a blocking lock always returns true.
     * @throws FilesystemException - if the file could not be opened, or locking it failed for any other reason.
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
     * Fetch the total size of a directory or file, including all
     * its subdirectories. This is the "apparent size" (the total of the file sizes),
     * rather than the space taken up on disk, which is rounded up to the filesystem's block size.
     * This only works on Linux, not Windows.
     * src: http://stackoverflow.com/questions/478121/php-get-directory-size
     * @param string $dirPath - the path to the directory we wish to get the
     *                          size of
     * @param DataSizeUnitEnum $unit - optionally specify the unit to return the size in. Defaults to bytes.
     * @return float - the size of the directory and all its contents, in the specified unit. E.g. 1.5 for 1536 bytes
     *                 in KiB.
     * @throws CommandFailedException - if the du command failed, such as if there is nothing at $dirPath.
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
     * Fetch the size of a file. Unlike PHP's filesize(), this throws an exception rather than returning false if it
     * fails, and it always gets the file's current size, rather than one PHP may have cached earlier in the script.
     * @param string $filepath - the path to the file to get the size of.
     * @param DataSizeUnitEnum $unit - optionally specify the unit to return the size in. Defaults to bytes.
     * @return float - the size of the file in the specified unit. E.g. 1.5 for a 1536 byte file in KiB.
     * @throws FileDoesNotExistException - if there is no file at $filepath, or it is a directory (use getDirSize()).
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
     * Zip a directory and all of its contents into a zip file.
     * The zip contains a single folder, named after $sourceFolder (e.g. zipping "/var/www/project" gives
     * "project/..."), however the path is written (relative, with a trailing slash, through a symlink etc.).
     * Empty subdirectories are included, so they are recreated when the zip is extracted.
     * @param string $sourceFolder - path to the folder we wish to zip up.
     * @param string $dest - path and name to give the zipfile e.g. (/tmp/my_zip.zip)
     * @param bool $deleteOnComplete - specify false if you want to keep the original uncompressed files after they
     *                                have been zipped. If $sourceFolder is a symlink, only the symlink is deleted,
     *                                and the folder it points to (and the files in it) are kept.
     * @return void
     * @throws FileAlreadyExistsException - if there is already a file at $dest.
     * @throws FileDoesNotExistException - if there is no folder at $sourceFolder.
     * @throws MissingExtensionException - if the zip extension isn't installed.
     * @throws FilesystemException - if the zip file could not be created or written.
     */
    public static function zipDir(string $sourceFolder, string $dest, bool $deleteOnComplete=true): void
    {
        if (!extension_loaded('zip') )
        {
            throw new MissingExtensionException("Your PHP does not have the zip extension.");
        }

        if (!is_dir($sourceFolder))
        {
            throw new FileDoesNotExistException("Cannot zip {$sourceFolder}, as there is no folder there.");
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

        # getDirContents() returns full, resolved paths (via realpath()), so resolve the source folder the same way, so
        # that it can be removed from the start of each one. Otherwise a relative path, a trailing slash, or a symlink
        # would leave the files' full paths in the zip.
        $resolvedSourceFolder = realpath($sourceFolder);

        # Include the directories as well as the files ($onlyFiles = false), so that empty directories are kept.
        $paths = self::getDirContents($resolvedSourceFolder, true, true, false);

        # Name the folder in the zip after the path we were given (e.g. a symlink's own name, rather than what it points
        # to), unless that has no name of its own, such as ".".
        $baseDir = basename(rtrim($sourceFolder, '/'));

        if ($baseDir === '' || $baseDir === '.' || $baseDir === '..')
        {
            $baseDir = basename($resolvedSourceFolder);
        }

        $zip->addEmptyDir($baseDir);

        foreach ($paths as $path)
        {
            $relativePath = $baseDir . substr($path, strlen($resolvedSourceFolder));

            if (is_dir($path))
            {
                $zip->addEmptyDir($relativePath);
            }
            else
            {
                $zip->addFile($path, $relativePath);
            }
        }

        # The files are only actually read and written into the zip when it is closed, so this is where most failures
        # show up (e.g. a file that can't be read, or the disk being full). This raises a warning as well as returning
        # false, so suppress it and include the reason in the exception instead.
        if (@$zip->close() === false)
        {
            $error = error_get_last()['message'] ?? $zip->getStatusString();
            throw new FilesystemException("Failed to write the zip file {$dest}: {$error}");
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
     * Unzip a zip file and all of its contents into a directory.
     *
     * @param string $sourceZip - zip file to unzip.
     * @param string $destinationFolder - path to the folder we wish to unzip into.
     * @param bool $deleteOnComplete - specify false if you want to keep the original uncompressed files after they have
     * been zipped.
     * @return void
     * @throws FileDoesNotExistException
     * @throws MissingExtensionException
     * @throws FilesystemException - if the zip file could not be opened or extracted.
     */
    public static function unzip(string $sourceZip, string $destinationFolder, bool $deleteOnComplete=true): void
    {
        if (!extension_loaded('zip') )
        {
            throw new MissingExtensionException("Your PHP does not have the zip extension.");
        }

        if (!file_exists($destinationFolder))
        {
            mkdir($destinationFolder);
        }

        $zip = new ZipArchive();
        $opened = $zip->open($sourceZip);

        # open() returns true on success, but an error code (a non-zero int, which is truthy) on failure.
        if ($opened !== true)
        {
            throw new FilesystemException("Unable to open zip file: {$sourceZip} (ZipArchive error code {$opened}).");
        }

        $unzip = $zip->extractTo($destinationFolder);

        if (!$unzip)
        {
            throw new FilesystemException(
                'Unable to unzip file: ' . $sourceZip . ' to destination: ' . $destinationFolder
            );
        }

        $zip->close();

        if ($deleteOnComplete)
        {
            unlink($sourceZip);
        }
    }


    /**
     * Applies the user-defined callback function to each line of a file.
     * This is inspired by array_walk (http://php.net/manual/en/function.array-walk.php)
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
     * Filters a file using the provided callback, in a memory efficient way so that it can process very large files.
     * This is inspired by array_filter (http://php.net/manual/en/function.array-filter.php)
     * The kept lines are written to a temporary file in the system's temporary directory first, and only copied into
     * the file being written once every line has been filtered, so a failure part way through (including the callback
     * throwing) leaves it untouched. They are copied in place with replaceWithFile(), so an existing file keeps its
     * permissions, owner and any links to it.
     * @param string $inputFile - the path to the file to operate upon.
     * @param callable $filterCallback - the callback to execute on every line in the file to determine if kept or not.
     * It must return true to keep the line. Don't forget that this will include its line ending, so you may wish to
     * use trim() before analysis.
     * @param string|null $outputFilepath - optionally specify an output filepath to place the filtered content into.
     * If this is not specified (or is the same file as $inputFile), then the existing file will be modified in-place.
     * If the output file doesn't exist, it is created, with the default permissions for a new file.
     * @return void
     * @throws FileDoesNotExistException - if there is no file at $inputFile.
     * @throws BadFilePermissionsException - if $inputFile can't be read, or the file to write can't be written (or
     *                                       created, for a new output file). This is checked before filtering.
     * @throws FilesystemException - if the filtered content could not be written.
     */
    public static function fileFilter(
        string   $inputFile,
        callable $filterCallback,
        ?string  $outputFilepath = null,
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
            $tmpFilepath = self::createReplacementFile();
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

        self::replaceWithFile($targetFile, $tmpFilepath);
    }


    /**
     * Converts a permissions string, such as "0755", into the int that PHP's mkdir() and chmod() expect. Unlike
     * octdec(), this throws for anything that isn't valid, rather than silently ignoring invalid characters
     * and giving the wrong permissions.
     * @param string $permissions - 3 or 4 octal digits, optionally with a leading 0 or 0o, e.g. "755", "0755",
     *                              "0o755" or "1777".
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
     * temporary directory folder (usually /tmp).
     * @param string $mode - the permissions the new temporary directory will have. Using the defaults from mkdir:
     * http://php.net/manual/en/function.mkdir.php
     * @return string - the full path to the temporary directory. E.g. /tmp/myRandomDir
     * @throws BadArgumentException - if $mode is not 3 or 4 octal digits, optionally with a leading 0 or 0o, e.g.
     *                                "755", "0755", "0o755" or "1777".
     * @throws FilesystemException - if the temporary directory could not be created.
     */
    public static function tmpDir(string $mode = "0777"): string
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

        # tempnam() is only used to get a unique name, so remove the file it creates and make a directory there.
        $tempName = tempnam(sys_get_temp_dir(), '');

        if ($tempName === false)
        {
            throw new FilesystemException("Failed to get a unique name for a temporary directory.");
        }

        unlink($tempName);
        mkdir($tempName, $modeInt);

        if (is_dir($tempName) === FALSE)
        {
            throw new FilesystemException("Failed to create a temporary directory.");
        }

        return $tempName;
    }


    /**
     * Download a file from the provided URL
     * This will use curl if it is available, if not it will
     * fall back to using file_get_contents.
     * An HTTP error response (a status of 400 or above, such as 404 Not Found) counts as a failed download, rather
     * than downloading the error page.
     * @param string $url - the URL of the file to download.
     * @return string - the full filepath to the downloaded file on the server.
     * @throws FilesystemException - if the temporary file could not be created/written to, or the download failed.
     *                               The temporary file is removed if this happens.
     */
    public static function downloadFile(string $url): string
    {
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
                # Suppress the warning, as we throw an exception instead.
                $content = @file_get_contents($url);

                if ($content === false)
                {
                    $error = error_get_last()['message'] ?? 'unknown error';
                    throw new FilesystemException("Failed to download {$url}: {$error}");
                }

                if (file_put_contents($downloadedFilepath, $content) === false)
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
     * A more human-readable version of the fileperms function. This one will output in the "-rw-r--r--" style format
     * rather than a bunch of numbers. The code is taken straight from the examples section of
     * https://www.php.net/manual/en/function.fileperms.php
     * @param string $filepath - the path to the file/folder we wish to get the permissions of.
     * @return string - the file permissions string.
     */
    public static function fileperms(string $filepath): string
    {
        $perms = fileperms($filepath);

        $info = match ($perms & 0xF000) {
            0xC000 => 's',
            0xA000 => 'l',
            0x8000 => 'r',
            0x6000 => 'b',
            0x4000 => 'd',
            0x2000 => 'c',
            0x1000 => 'p',
            default => 'u',
        };

        // Owner
        $info .= (($perms & 0x0100) ? 'r' : '-');
        $info .= (($perms & 0x0080) ? 'w' : '-');
        $info .= (($perms & 0x0040) ?
                    (($perms & 0x0800) ? 's' : 'x' ) :
                    (($perms & 0x0800) ? 'S' : '-'));

        // Group
        $info .= (($perms & 0x0020) ? 'r' : '-');
        $info .= (($perms & 0x0010) ? 'w' : '-');
        $info .= (($perms & 0x0008) ?
                    (($perms & 0x0400) ? 's' : 'x' ) :
                    (($perms & 0x0400) ? 'S' : '-'));

        // World
        $info .= (($perms & 0x0004) ? 'r' : '-');
        $info .= (($perms & 0x0002) ? 'w' : '-');
        $info .= (($perms & 0x0001) ?
                    (($perms & 0x0200) ? 't' : 'x' ) :
                    (($perms & 0x0200) ? 'T' : '-'));

        return $info;
    }


    /**
     * Fetch the file extension of a specified filename or file path. E.g. "csv" or "txt"
     * This only looks at the last part of a path, so dots in directory names are ignored (e.g. "/var/v1.2/README" has
     * no extension). The extension's case is kept as it is (e.g. "JPG"). As with PHP's pathinfo(), a name that starts
     * with a dot and has no other dots, such as ".bashrc", is treated as having that extension ("bashrc").
     * @param string $filename - the name of the file or the full file path
     * @return string - the file extension, without the dot.
     * @throws NoFileExtensionException - if there is no file extension, such as "README", "file." or "dir/".
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
     * @param string $downloadFilename - the filename the browser should use for the file, e.g. if the user saves it.
     * This can contain any characters, including spaces, punctuation and non-ASCII characters such as "résumé.pdf".
     * @param ?string $mimetype - optionally manually specify the mimetype. Can be null to force not setting mimetype on
     * download. Defaults to "auto" which will have PHP try to dynamically figure out the mimetype of the file.
     * @param bool $asAttachment - specify whether the file should be downloaded as an attachment in the browser
     * (default), or set to false for the file to be "inline" so it just appears in the webview rather than being
     * downloaded. E.g. for a PDF, the PDF could just be shown in the browser and not automatically downloaded, with
     * the user choosing to download the file if they wish
     * @return void
     * @throws FileDoesNotExistException - if there is no file at $filepath.
     * @throws BadFilePermissionsException - if the file exists but cannot be read.
     * @throws FilesystemException - if the mimetype or size of the file could not be determined, or the file could not
     * be read. These are checked before any headers are sent, apart from the file failing to be read.
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
     * @throws FilesystemException - if something goes wrong with getting a file handle even though file exists and
     * can be read, or reading the file fails part way through.
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
     * Converts a file to UTF-8, if it isn't already.
     * WARNING - this will overwrite/replace the file!
     *
     * It is not possible to reliably detect which encoding a file is in, as in single-byte encodings such as
     * Windows-1252, Windows-1251 and ISO-8859-2 every byte is valid, and the same byte means a different character in
     * each (e.g. 0xE6 is "æ" in Windows-1252, but "ж" in Windows-1251). So this only detects what can be detected
     * reliably, and otherwise relies on you telling it what encoding the file is in:
     *  1. If the file is already valid UTF-8, it is left untouched.
     *  2. If the file starts with a UTF-16 or UTF-32 byte order mark (BOM), it is converted from that encoding, and the
     *     BOM is removed. This overrides $sourceEncoding.
     *  3. Otherwise, the file is converted from $sourceEncoding.
     * The converted contents are written to a temporary file in the system's temporary directory first, then copied
     * back into the original, so the file keeps its permissions and owner, and only the file (not its directory) needs
     * to be writable. See replaceWithFile() for what happens if that copy fails part way through.
     *
     * @param string $inputFilepath - the path to the file to convert.
     * @param string $sourceEncoding - the encoding the file is in, if it is not UTF-8 and has no BOM. This must be an
     * encoding that the mbstring extension supports, e.g. "Windows-1252" (the default, common for Western European
     * text), "Windows-1251" (Cyrillic), "ISO-8859-2" (Central European), "ISO-8859-15" (Western European with the
     * euro sign) or "SJIS" (Japanese). Note that mbstring does NOT support "Windows-1250".
     * @return void
     * @throws BadArgumentException - if $sourceEncoding is not an encoding that mbstring supports.
     * @throws BadFilePermissionsException - if the file exists but can't be read, or needs converting but can't be
     *                                       written.
     * @throws FileDoesNotExistException - if input file does not exist.
     * @throws MissingExtensionException - if missing the mbstring extension which is required for this function.
     * @throws FilesystemException - if the file is not valid in the encoding it is being converted from, or anything
     * else goes wrong that is not covered by the other specific exceptions.
     */
    public static function convertFileToUtf8(string $inputFilepath, string $sourceEncoding = 'Windows-1252'): void
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
            self::replaceFileContents($inputFilepath, $converted);
        }
    }


    /**
     * Check that a file can be changed in place (e.g. with replaceWithFile()), before any work is done on it. Only
     * the file itself needs to be readable and writable, not the directory it is in, as the new contents are written
     * back into the same file.
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
     * Create an empty temporary file to write a file's new contents to, before they are copied into the original
     * with replaceWithFile(). This is useful for rewriting a file a piece at a time (e.g. row by row), without
     * changing the original until the new contents are complete. This is always in the system's temporary
     * directory, never next to the original, in case that directory is served to the public (e.g. by a web server).
     * tempnam() creates it with 0600 permissions, so other users can't read the data while it is there.
     * @return string - the path of the temporary file.
     * @throws FilesystemException - if the temporary file could not be created.
     */
    public static function createReplacementFile(): string
    {
        # Suppress the warning, as we throw an exception instead.
        $tmpFilepath = @tempnam(sys_get_temp_dir(), "corelibs-tmp-");

        if ($tmpFilepath === false)
        {
            throw new FilesystemException("Failed to create a temporary file in " . sys_get_temp_dir() . ".");
        }

        return $tmpFilepath;
    }


    /**
     * Copy the contents of a file made by createReplacementFile() into the original file, then delete it. The
     * contents are written into the original file in place, rather than the temporary file being renamed over it,
     * so the original keeps its permissions, owner, and any links to it, and only the file (not its directory)
     * needs to be writable.
     * This isn't atomic: if it fails part way through (e.g. the disk fills up), the original may be left
     * half-written. The temporary file is kept in that case, and its path is given in the exception, so that the
     * new contents aren't lost.
     * @param string $filepath - the path of the file to replace the contents of.
     * @param string $tmpFilepath - the path of the temporary file with the new contents. It must already be closed.
     * @return void
     * @throws FilesystemException - if the contents could not be copied across.
     */
    public static function replaceWithFile(string $filepath, string $tmpFilepath): void
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
     * Build the value for a Content-Disposition header, in the standard way (RFC 6266), so that any filename works.
     * This gives two versions of the filename: a quoted, ASCII-only fallback for very old clients, and the exact
     * filename, percent-encoded as UTF-8, which all current browsers use.
     * E.g. attachment; filename="r_sum_.pdf"; filename*=UTF-8''r%C3%A9sum%C3%A9.pdf
     * @param bool $asAttachment - true for "attachment" (download), false for "inline" (display in the browser).
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
     * Check whether a string is valid UTF-8, without needing the mbstring extension. This uses PCRE, which is always
     * part of PHP, and rejects the same things that mb_check_encoding() does, such as overlong encodings, surrogates,
     * and code points above U+10FFFF.
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
     * Get the bytes of a UTF-8 character that the provided bytes end part way through, if any. E.g. if the bytes end
     * with only the first of the two bytes that make up "é", that byte is returned.
     * This only looks at the start of the last character, so it does not check whether the bytes are valid UTF-8.
     * @param string $bytes - the bytes to check the end of.
     * @return string - the bytes of the unfinished character at the end (at most 3), or an empty string if the bytes
     *                  don't end part way through a character.
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
     * Add everything in a directory to a deletion plan, in an order that can be deleted (each directory's contents
     * before the directory itself), going into subdirectories, and checking that each thing can be deleted. See
     * deleteDir() for how symlinks are handled. Stops once there are 100 problems, as the plan won't be used then.
     * @param string $dir - the directory whose contents to plan the deletion of.
     * @param string $realDir - the real path of $dir (with symlinks resolved), used to spot symlinks pointing "up".
     * @param bool $followSymlinks - whether to follow symlinks, deleting what they point to as well as the links.
     * @param array $plan - the plan so far, as a list of [FileDeletionOperationEnum, path] pairs, which this adds to.
     * @param array $problems - the list of FileDeletionProblem objects found so far, which this adds to.
     * @param array $planned - real paths => true, for everything already in the plan, which this adds to.
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
     * @param FileDeletionOperationEnum $operation - UNLINK for a file or symlink, or RMDIR for a directory (once it
     *                                              is empty).
     * @param string $path - the thing to delete.
     * @param array $plan - the plan so far, as a list of [FileDeletionOperationEnum, path] pairs, which this adds to.
     * @param array $problems - the list of FileDeletionProblem objects found so far, which this adds to.
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
     * @throws FilesystemException - if something still couldn't be deleted. Some of the plan will already have been
     *                               carried out if this happens.
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
     * Check whether a path is a directory, or is inside it, by their real paths.
     * @param string $path - the real path to check.
     * @param string $directory - the real path of the directory.
     * @return bool - true if $path is $directory, or anywhere inside it.
     */
    private static function isWithinDirectory(string $path, string $directory): bool
    {
        # Compare with a trailing slash, so that e.g. /a/bc doesn't count as being inside /a/b. This also handles "/".
        return $path === $directory || str_starts_with($path . "/", rtrim($directory, "/") . "/");
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
     * Add a FileDeletionProblem to $problems if something can't be deleted from its directory. Deleting something
     * needs its directory to be writable and executable, and in a "sticky" directory (e.g. /tmp), it also needs the
     * current user to own it or the directory (or be root).
     * @param string $parentDir - the directory that $path is in.
     * @param string $path - the thing that would be deleted.
     * @param array $problems - the list of FileDeletionProblem objects found so far, which this adds to.
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
     * Replace the contents of a file. The new contents are written to a temporary file in the system's temporary
     * directory first, then copied into the original with replaceWithFile(), so the file keeps its permissions,
     * owner and any links to it, and only the file (not its directory) needs to be writable.
     * @param string $filepath - the path to the file to replace the contents of.
     * @param string $contents - the new contents for the file.
     * @return void
     * @throws FileDoesNotExistException - if there is no file at $filepath.
     * @throws BadFilePermissionsException - if the file can't be both read and written.
     * @throws FilesystemException - if the new contents could not be written. See replaceWithFile() for what happens
     *                               if this fails part way through copying them into the original.
     */
    private static function replaceFileContents(string $filepath, string $contents): void
    {
        self::checkCanChangeFile($filepath);
        $tmpFilepath = self::createReplacementFile();

        # Nothing has been copied into the original yet, so if this fails it is untouched, and the temporary file can
        # go.
        if (@file_put_contents($tmpFilepath, $contents) !== strlen($contents))
        {
            @unlink($tmpFilepath);
            throw new FilesystemException("Failed to write the new contents of {$filepath} to a temporary file.");
        }

        self::replaceWithFile($filepath, $tmpFilepath);
    }
}
