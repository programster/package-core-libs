<?php

/*
 * A file on disk, which can be renamed or moved, keeping track of where it is. This doesn't hold
 * the file's data, only its path. It can be used on its own, or extended for specific types of file
 * (e.g. CsvFile).
 */

namespace Programster\CoreLibs\Models;


use Programster\CoreLibs\Filesystem;
use Programster\CoreLibs\Exceptions\BadArgumentException;
use Programster\CoreLibs\Exceptions\FileAlreadyExistsException;
use Programster\CoreLibs\Exceptions\FileDoesNotExistException;
use Programster\CoreLibs\Exceptions\FilesystemException;

class File
{
    # Updated when the file is renamed or moved.
    protected string $m_filepath;


    /**
     * Create an object for an existing file.
     * @param string $filepath - the path to the file. This is kept as given (e.g. relative) until
     * the file is renamed or moved, after which it is the file's absolute path.
     * @throws FileDoesNotExistException - if there is no file at $filepath (including if it is a
     * directory).
     */
    public function __construct(string $filepath)
    {
        if (!is_file($filepath))
        {
            throw new FileDoesNotExistException("There is no file at {$filepath}.");
        }

        $this->m_filepath = $filepath;
    }


    /**
     * Rename the file. If given just a name (e.g. "bob.txt"), the file stays in the same directory.
     * If given a path, the file is moved there as well. A relative path (e.g. "archive/bob.txt" or
     * "../bob.txt") is relative to the file's current directory, rather than the current working
     * directory, so that a plain name works the same way. The directory it is moved to must already
     * exist. To move the file without renaming it, use move(). If this file is a symlink, the link
     * itself is renamed, not the file it points to. A symlink with a relative target can't be
     * moved into a different directory this way (see move()), but can still be renamed in place.
     * @param string $newPath - the new name or path for the file. It must not end with a "/".
     * @param bool $overwriteExistingFile - whether to replace a file (or symlink) that already
     * exists at $newPath. Defaults to false. A directory is never replaced.
     * @return void
     * @throws BadArgumentException - if $newPath is empty, or ends with a "/", or this file is a
     * symlink with a relative target and $newPath is in a different directory.
     * @throws FileDoesNotExistException - if the directory $newPath is in doesn't exist.
     * @throws FileAlreadyExistsException - if there is a directory at $newPath, there is a file
     * there and $overwriteExistingFile is false, or it is the same file as this one (see moveTo()).
     * Nothing is changed if this happens.
     * @throws FilesystemException - if the file could not be renamed.
     */
    public function rename(string $newPath, bool $overwriteExistingFile = false): void
    {
        if ($newPath === "" || str_ends_with($newPath, "/"))
        {
            throw new BadArgumentException(
                "rename: \$newPath must be a name or path for the file, not \"{$newPath}\". To move the file into a " .
                "directory, use move()."
            );
        }

        $targetPath = $this->resolvePath($newPath);
        $targetDirectory = dirname($targetPath);

        if (!is_dir($targetDirectory))
        {
            throw new FileDoesNotExistException(
                "rename: Can't rename {$this->m_filepath} to {$targetPath}, as there is no directory at " .
                "{$targetDirectory}."
            );
        }

        $this->checkSymlinkCanMoveTo($targetDirectory);
        $this->moveTo($targetPath, $overwriteExistingFile);
    }


    /**
     * Move the file into another directory, keeping its name. A relative path is relative to the
     * file's current directory, rather than the current working directory, in the same way as
     * rename(). If this file is a symlink, the link itself is moved, not the file it points to. A
     * symlink with a relative target (e.g. "data.txt" rather than "/srv/data.txt") can't be moved
     * into a different directory, as its target is relative to where the link is, so moving it would
     * break it (or point it at something else). A symlink with an absolute target can be moved.
     * @param string $directory - the path to the directory to move the file into.
     * @param bool $createIfNotExists - whether to create the directory (and any parent directories)
     * if it doesn't exist. Defaults to false, in which case it must already exist.
     * @param bool $overwriteExistingFile - whether to replace a file (or symlink) with this file's
     * name that already exists in the directory. Defaults to false. A directory is never replaced.
     * @return void
     * @throws BadArgumentException - if $directory is empty, or is something other than a directory
     * (e.g. a file), or this file is a symlink with a relative target. Nothing is created or moved if
     * this happens.
     * @throws FileDoesNotExistException - if the directory doesn't exist, and $createIfNotExists is
     * false.
     * @throws FileAlreadyExistsException - if the directory has a directory with this file's name
     * in it, it has a file with this name and $overwriteExistingFile is false, or that file is the
     * same file as this one (see moveTo()). The file isn't moved if this happens.
     * @throws FilesystemException - if the directory could not be created, or the file could not be
     * moved.
     */
    public function move(string $directory, bool $createIfNotExists = false, bool $overwriteExistingFile = false): void
    {
        if ($directory === "")
        {
            throw new BadArgumentException("move: \$directory must not be empty.");
        }

        $targetDirectory = $this->resolvePath($directory);

        # Check this before creating the directory, so a refused move doesn't leave an empty one behind.
        $this->checkSymlinkCanMoveTo($targetDirectory);

        if (!is_dir($targetDirectory))
        {
            if (file_exists($targetDirectory))
            {
                throw new BadArgumentException(
                    "move: Can't move {$this->m_filepath} into {$targetDirectory}, as it isn't a directory."
                );
            }
            elseif ($createIfNotExists)
            {
                Filesystem::mkdir($targetDirectory, recursive: true);
            }
            else
            {
                throw new FileDoesNotExistException(
                    "move: Can't move {$this->m_filepath} into {$targetDirectory}, as there is no directory there. " .
                    "Set \$createIfNotExists to true to create it."
                );
            }
        }

        $this->moveTo(rtrim($targetDirectory, "/") . "/" . basename($this->m_filepath), $overwriteExistingFile);
    }


    /**
     * Check that this file can be moved into $targetDirectory without breaking it. A symlink with a
     * relative target (e.g. "data.txt", or "../shared/data.txt") is resolved from the directory the
     * link is in, so moving it into a different directory would leave it pointing at the wrong place.
     * @param string $targetDirectory - the directory the file would be moved into. It doesn't have to
     * exist yet.
     * @return void
     * @throws BadArgumentException - if this file is a symlink with a relative target, and
     * $targetDirectory is a different directory from the one it is in now.
     */
    private function checkSymlinkCanMoveTo(string $targetDirectory): void
    {
        if (is_link($this->m_filepath))
        {
            $linkTarget = readlink($this->m_filepath);
            $currentDirectory = realpath(dirname($this->m_filepath));

            # A directory that doesn't exist yet can't be the one the link is in now.
            $isSameDirectory = realpath($targetDirectory) === $currentDirectory;

            if ($linkTarget !== false && !str_starts_with($linkTarget, "/") && !$isSameDirectory)
            {
                throw new BadArgumentException(
                    "Can't move {$this->m_filepath} into {$targetDirectory}, as it is a symlink to the relative " .
                    "path \"{$linkTarget}\", which would then point somewhere else. Recreate the link in the new " .
                    "directory instead, or make its target an absolute path."
                );
            }
        }
    }


    /**
     * Turn a path given to rename() or move() into one that can be used from anywhere. An absolute
     * path is returned as it is, and a relative one is made relative to the file's current
     * directory.
     * @param string $path - the absolute or relative path.
     * @return string - the path, starting from the file's directory if it was relative.
     */
    private function resolvePath(string $path): string
    {
        return str_starts_with($path, "/") ? $path : dirname($this->m_filepath) . "/" . $path;
    }


    /**
     * Move the file to a new path, whose directory must already exist, and update the stored path.
     * Whatever $overwriteExistingFile is set to, this refuses to:
     *  - replace a directory, or a symlink to one (which rename() would otherwise silently
     *    replace).
     *  - replace a path that is the same file as this one under another name, i.e. a symlink to
     *    this file, a file this symlink points to, or a hard link. E.g. renaming a symlink over the
     *    file it points to would replace the file with a link to itself, losing its data.
     * Checking and then renaming isn't atomic, so if another process creates a file at the new path
     * in between, rename() will still replace it.
     * @param string $targetPath - the new path for the file.
     * @param bool $overwriteExistingFile - whether to replace a file or symlink that already exists
     * at $targetPath.
     * @return void
     * @throws FileAlreadyExistsException - if there is a directory at $targetPath, something else
     * exists there and $overwriteExistingFile is false, or it is the same file as this one.
     * @throws FilesystemException - if the file could not be moved.
     */
    private function moveTo(string $targetPath, bool $overwriteExistingFile): void
    {
        # Compare the real directories, so that e.g. "./bob.txt" and "../dir/bob.txt" count as where the file already
        # is. Only the directory is resolved, so that a symlink is renamed rather than the file it points to.
        $currentPath = realpath(dirname($this->m_filepath)) . "/" . basename($this->m_filepath);
        $newPath = realpath(dirname($targetPath)) . "/" . basename($targetPath);

        if ($newPath !== $currentPath)
        {
            # is_link() is needed too, as file_exists() is false for a broken symlink, which rename() would replace.
            $somethingExists = file_exists($newPath) || is_link($newPath);

            # is_dir() follows symlinks, so this also catches a symlink to a directory.
            if (is_dir($newPath))
            {
                throw new FileAlreadyExistsException(
                    "Can't move {$this->m_filepath} to {$newPath}, as there is a directory there. Directories are " .
                    "never replaced."
                );
            }
            elseif ($somethingExists && !$overwriteExistingFile)
            {
                throw new FileAlreadyExistsException(
                    "Can't move {$this->m_filepath} to {$newPath}, as something already exists there. Set " .
                    "\$overwriteExistingFile to true to replace it."
                );
            }
            elseif ($somethingExists && self::isSameFile($currentPath, $newPath))
            {
                throw new FileAlreadyExistsException(
                    "Can't move {$this->m_filepath} to {$newPath}, as they are the same file (one is a symlink to " .
                    "the other, or they are hard links), so replacing one with the other could lose its data."
                );
            }

            # Suppress the warning, as we throw an exception instead.
            if (!@rename($currentPath, $newPath))
            {
                throw new FilesystemException("Failed to move {$this->m_filepath} to {$newPath}.");
            }
        }

        $this->m_filepath = $newPath;
    }


    /**
     * Check whether two paths lead to the same file on disk, following any symlinks. This compares
     * the device and inode numbers, so it also spots hard links.
     * @param string $path1 - the first path.
     * @param string $path2 - the second path.
     * @return bool - true if both lead to the same file, false if they don't (or either is a broken
     * symlink).
     */
    private static function isSameFile(string $path1, string $path2): bool
    {
        # Suppress the warning for a broken symlink, which can't be the same file as anything.
        $stats1 = @stat($path1);
        $stats2 = @stat($path2);

        return $stats1 !== false
            && $stats2 !== false
            && $stats1['dev'] === $stats2['dev']
            && $stats1['ino'] === $stats2['ino'];
    }


    # Accessors
    public function getFilepath() : string { return $this->m_filepath; }
}
