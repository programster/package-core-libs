<?php

/*
 * A file on disk, which can be renamed or moved, keeping track of where it is. This doesn't hold
 * the file's data, only its path. It can be used on its own, or extended for specific types of file
 * (e.g. CsvFile).
 */

namespace Programster\CoreLibs\Models;


use Programster\CoreLibs\Exceptions\BadFilePermissionsException;
use Programster\CoreLibs\Enums\PreserveOwnershipEnum;
use Programster\CoreLibs\Filesystem;
use Programster\CoreLibs\Exceptions\BadArgumentException;
use Programster\CoreLibs\Exceptions\FileAlreadyExistsException;
use Programster\CoreLibs\Exceptions\DirectoryDoesNotExistException;
use Programster\CoreLibs\Exceptions\FileDoesNotExistException;
use Programster\CoreLibs\Exceptions\MoveCancelledException;
use Programster\CoreLibs\Exceptions\FilesystemException;

class File
{
    use MoveCopyingTrait;

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
     * Rename the file, keeping it in the same directory. To put it somewhere else, use move(),
     * which can also give it a new name there. If this file is a symlink, the link itself is
     * renamed, not the file it points to. As it stays in the same directory (so on the same
     * filesystem), it is renamed in one step, and nothing is copied.
     * @param string $newName - the new name, e.g. "bob.txt". It must be just a name, not a path.
     * @param bool $overwriteExistingFile - whether to replace a file (or symlink) that already
     * has the new name. Defaults to false. A directory is never replaced.
     * @return void
     * @throws BadArgumentException - if $newName isn't just a name (e.g. it has a "/" in it).
     * @throws FileAlreadyExistsException - if there is a directory with the new name, there is a
     * file with it and $overwriteExistingFile is false, or it is the same file as this one (see
     * moveTo()).
     * @throws FilesystemException - if the file could not be renamed.
     */
    public function rename(string $newName, bool $overwriteExistingFile = false): void
    {
        self::checkIsName($newName, "rename");
        $targetPath = dirname($this->m_filepath) . "/" . $newName;

        # Within one directory, nothing is copied, so how hard to try to keep the owner makes no difference.
        $this->moveTo($targetPath, $overwriteExistingFile, PreserveOwnershipEnum::YES, null);
    }


    /**
     * Move the file into another directory, keeping its name (or giving it $newName there). A
     * relative path is relative to the file's current directory, rather than the current working
     * directory. If this file is a symlink, the link itself is moved, not the file it points to. A
     * symlink's relative target (e.g. "data.txt" rather than "/srv/data.txt") is relative to where
     * the link is, so moving it as it is would leave it pointing somewhere else. So a symlink with
     * a relative target is recreated in the new directory instead, with its target worked out again
     * from there (e.g. "data.txt" becomes "../data.txt" when moved into a subdirectory), and the
     * old link is removed. It stays relative, and points at the same place as before (even if that
     * no longer exists). On Windows, where PHP can't create a relative symlink, and readlink() gives
     * the resolved absolute path (so a relative one can't be told apart), any symlink moved into
     * another directory is recreated with an absolute target to the same place instead, and a
     * broken one is refused, as Windows can't create a symlink to something that doesn't exist. The
     * recreated symlink is owned by whoever runs this, and their group, rather than the original's
     * owner and group. A symlink's owner rarely matters, as its permissions are ignored, but it does
     * in a sticky directory (e.g. /tmp), where only its owner
     * (or the directory's) can delete or rename it, and in a sticky, world-writable directory with
     * Linux's protected_symlinks setting (on by default), where it is only followed by its owner
     * (or the directory's).
     * @param string $directory - the path to the directory to move the file into.
     * @param bool $createIfNotExists - whether to create the directory (and any parent directories)
     * if it doesn't exist. Defaults to false, in which case it must already exist.
     * @param bool $overwriteExistingFile - whether to replace a file (or symlink) with this file's
     * name that already exists in the directory. Defaults to false. A directory is never replaced.
     * @param PreserveOwnershipEnum $preserveOwnership - when moving to a different filesystem, where
     * the file has to be copied: how hard to try to keep its owner and group (only root can give a
     * copy to another user). YES (the default) checks first that they can be kept, and doesn't move
     * it if not. PREFERRED keeps them where it can, and otherwise lets the copy belong to whoever is
     * running this. NO doesn't try. On the same filesystem, nothing is copied, so ownership is always
     * kept (apart from a recreated relative symlink, see move()) and this has no effect. On Windows,
     * a copy on another drive always belongs to whoever makes it, with the ACLs of the directory it
     * is copied into, so with YES, a move to another drive is always refused there.
     * @param MoveListenerInterface|null $listener - optionally, something to tell how the move is
     * going, which can also cancel it (see MoveListenerInterface). Defaults to null.
     * @param string|null $newName - optionally, a new name to give it in $directory, e.g. for when
     * something there already has its current name. It must be just a name, not a path. Defaults
     * to null, which keeps its current name.
     * @return void
     * @throws BadArgumentException - if $newName isn't just a name, or $directory is empty, or is
     * something other than a directory (e.g. a file).
     * @throws DirectoryDoesNotExistException - if the directory doesn't exist, and
     * $createIfNotExists is false.
     * @throws FileAlreadyExistsException - if the directory has a directory with this file's name
     * in it, it has a file with this name and $overwriteExistingFile is false, or that file is the
     * same file as this one (see moveTo()). The file isn't moved if this happens.
     * @throws BadFilePermissionsException - with $preserveOwnership set to YES, if the file has to be
     * copied to another filesystem, and its owner or group can't be kept. Nothing is changed if
     * this happens.
     * @throws FilesystemException - if the directory could not be created, or the file could not be
     * moved.
     */
    public function move(
        string $directory,
        bool $createIfNotExists = false,
        bool $overwriteExistingFile = false,
        PreserveOwnershipEnum $preserveOwnership = PreserveOwnershipEnum::YES,
        ?MoveListenerInterface $listener = null,
        ?string $newName = null
    ): void
    {
        if ($directory === "")
        {
            throw new BadArgumentException("move: \$directory must not be empty.");
        }

        if ($newName !== null)
        {
            self::checkIsName($newName, "move");
        }

        $targetDirectory = $this->resolvePath($directory);

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
                throw new DirectoryDoesNotExistException(
                    "move: Can't move {$this->m_filepath} into {$targetDirectory}, as there is no directory there. " .
                    "Set \$createIfNotExists to true to create it."
                );
            }
        }

        $targetPath = rtrim($targetDirectory, "/") . "/" . ($newName ?? basename($this->m_filepath));
        $this->moveTo($targetPath, $overwriteExistingFile, $preserveOwnership, $listener);
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
        return Filesystem::isAbsolutePath($path) ? $path : dirname($this->m_filepath) . "/" . $path;
    }


    /**
     * Move the file to a new path, whose directory must already exist, and update the stored path.
     * Whatever $overwriteExistingFile is set to, this refuses to:
     *  - replace a directory, or a symlink to one (which rename() would otherwise silently
     *    replace).
     *  - replace a path that is the same file as this one under another name, i.e. a symlink to
     *    this file, a file this symlink points to, or a hard link. E.g. renaming a symlink over the
     *    file it points to would replace the file with a link to itself, losing its data.
     * Without $overwriteExistingFile, a file another process creates at the new path after the
     * checks is never replaced, and this throws instead. A move to another filesystem copies the
     * file first, and only deletes the original once the copy is in place (see moveFile()).
     * @param string $targetPath - the new path for the file.
     * @param bool $overwriteExistingFile - whether to replace a file or symlink that already exists
     * at $targetPath.
     * @param PreserveOwnershipEnum $preserveOwnership - see move().
     * @param MoveListenerInterface|null $listener - see move().
     * @return void
     * @throws MoveCancelledException - if $listener cancelled the move, before anything changed.
     * @throws FileAlreadyExistsException - if there is a directory at $targetPath, something else
     * exists there and $overwriteExistingFile is false, or it is the same file as this one.
     * @throws BadFilePermissionsException - with $preserveOwnership set to YES, if the file has to be
     * copied to another filesystem, and its owner or group can't be kept. Nothing is changed if
     * this happens.
     * @throws FilesystemException - if the file could not be moved.
     * @throws BadArgumentException - only if given relative paths, which this never passes on.
     */
    private function moveTo(
        string $targetPath,
        bool $overwriteExistingFile,
        PreserveOwnershipEnum $preserveOwnership,
        ?MoveListenerInterface $listener
    ): void
    {
        # Compare the real directories, so that e.g. "./bob.txt" and "../dir/bob.txt" count as where the file already
        # is. Only the directory is resolved, so that a symlink is renamed rather than the file it points to.
        $currentPath = realpath(dirname($this->m_filepath)) . "/" . basename($this->m_filepath);
        $newPath = realpath(dirname($targetPath)) . "/" . basename($targetPath);

        if ($newPath !== $currentPath)
        {
            # A symlink counts as 0 bytes, as its target isn't moved.
            $bytes = is_link($currentPath) ? 0 : (int)filesize($currentPath);
            $progress = new MoveProgress(1, $bytes);

            # On a filesystem that ignores case, a new name that only differs by case is the same file, so just
            # rename it, rather than refusing because something "already exists" there.
            if (Filesystem::isCaseOnlyRename($currentPath, $newPath))
            {
                $listener?->onMoveStarting($progress);
                $listener?->onFileStarting($progress, $currentPath, $bytes);

                # Suppress the warning, as we throw an exception instead.
                if (!@rename($currentPath, $newPath))
                {
                    throw new FilesystemException("Failed to rename {$this->m_filepath} to {$newPath}.");
                }
            }
            else
            {
                # is_link() is needed too, as file_exists() is false for a broken symlink, which rename() would replace.
                $somethingExists = file_exists($newPath) || is_link($newPath);

                # is_dir() follows symlinks, so this also catches a symlink to a directory.
                if (is_dir($newPath))
                {
                    throw new FileAlreadyExistsException(
                        "Can't move {$this->m_filepath} to {$newPath}, as there is a directory there. Directories " .
                        "are never replaced."
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
                        "Can't move {$this->m_filepath} to {$newPath}, as they are the same file (one is a symlink " .
                        "to the other, or they are hard links), so replacing one with the other could lose its data."
                    );
                }

                # On Windows, readlink() gives the fully resolved absolute path rather than the link's own target (and
                # fails for a broken link), so whether a link is relative can't be told. So every link moved into
                # another directory is created again there, pointing at the same place.
                $isLink = is_link($currentPath);
                $linkTarget = $isLink ? @readlink($currentPath) : false;
                $onWindows = PHP_OS_FAMILY === "Windows";
                $isRelativeLink = $linkTarget !== false && !Filesystem::isAbsolutePath($linkTarget);
                $movesDirectory = dirname($newPath) !== dirname($currentPath);

                if ($isLink && $movesDirectory && $onWindows && $linkTarget === false)
                {
                    throw new FilesystemException(
                        "Can't move {$this->m_filepath} to {$newPath}, as it is a broken symlink, which has to be " .
                        "created again there, and Windows can't create a symlink to something that doesn't exist."
                    );
                }

                # Everything has been checked, so the move can start, which the listener can still cancel.
                $listener?->onMoveStarting($progress);
                $listener?->onFileStarting($progress, $currentPath, $bytes);

                if ($movesDirectory && ($isRelativeLink || ($isLink && $onWindows)))
                {
                    # Moving it as it is would leave a relative target pointing somewhere else, so recreate it with its
                    # target adjusted.
                    self::moveRelativeSymlink($currentPath, $newPath, $linkTarget, $somethingExists);
                }
                else
                {

                    self::moveFile(
                        $currentPath,
                        $newPath,
                        $overwriteExistingFile,
                        $preserveOwnership,
                        $listener,
                        $progress
                    );

                }
            }

            $moved = $progress->withFileMoved($bytes);
            $listener?->onFileMoved($moved, $currentPath);
            $listener?->onMoveFinished($moved);
        }

        $this->m_filepath = $newPath;
    }


    /**
     * Move a symlink with a relative target into a different directory, by creating a new symlink
     * there whose relative target points at the same place, and then removing the old one. On
     * Windows, the new symlink's target is absolute instead, as PHP can't create a relative one there,
     * and this is used for every symlink, as readlink() there gives the resolved absolute path, so a
     * relative one can't be told apart.
     * @param string $currentPath - the symlink, with its directory's real path.
     * @param string $newPath - where the new symlink goes, with its directory's real path.
     * @param string $linkTarget - the old symlink's relative target, e.g. "../shared/data.txt" (on
     * Windows, the absolute path readlink() gives).
     * @param bool $replaceExisting - whether something at $newPath (already checked to be a file or
     * symlink that may be replaced) should be removed first.
     * @return void
     * @throws FileAlreadyExistsException - if something was created at $newPath by another process
     * after it was checked, without $replaceExisting.
     * @throws FilesystemException - if the new symlink could not be created, the old one could not
     * be removed (in which case the new one is removed again, so nothing has changed), or on
     * Windows, the symlink is broken, so can't be created again (nothing is changed then).
     * @throws BadArgumentException - only if given relative paths, which the callers never do.
     */
    private static function moveRelativeSymlink(
        string $currentPath,
        string $newPath,
        string $linkTarget,
        bool $replaceExisting
    ): void
    {
        $absoluteTarget = Filesystem::resolveLinkTarget(dirname($currentPath), $linkTarget);
        $onWindows = PHP_OS_FAMILY === "Windows";

        # Windows can't create a symlink to something that doesn't exist, so refuse before anything is changed.
        if ($onWindows && !file_exists($absoluteTarget))
        {
            throw new FilesystemException(
                "Can't move {$currentPath} to {$newPath}, as it is a broken symlink with a relative target, which " .
                "has to be created again to point at the same place, and Windows can't create a symlink to " .
                "something that doesn't exist."
            );
        }

        # Windows can't create a relative symlink either, so point it at the same place absolutely, written with "\\",
        # which Windows needs.
        $newLinkTarget = $onWindows
            ? str_replace("/", "\\", $absoluteTarget)
            : Filesystem::relativePathBetween(dirname($newPath), $absoluteTarget);

        # Suppress the warnings, as we throw exceptions instead.
        if ($replaceExisting && !@unlink($newPath))
        {
            throw new FilesystemException("Failed to replace {$newPath} with a symlink to {$newLinkTarget}.");
        }

        # symlink() fails if anything is at $newPath, so something that appeared there since the checks isn't replaced.
        if (!@symlink($newLinkTarget, $newPath))
        {
            if (file_exists($newPath) || is_link($newPath))
            {
                throw new FileAlreadyExistsException(
                    "Can't move {$currentPath} to {$newPath}, as something was created there while it was being moved."
                );
            }

            throw new FilesystemException("Failed to create a symlink at {$newPath} to {$newLinkTarget}.");
        }

        if (!@unlink($currentPath))
        {
            # Don't leave two links behind.
            @unlink($newPath);
            throw new FilesystemException("Failed to remove the old symlink at {$currentPath}.");
        }
    }


    /**
     * Move a file (or a symlink, as itself) to a new path.
     * On the same filesystem, this renames it. Without $replace, it uses link() then unlink() rather
     * than rename(), as link() fails if anything (even a broken symlink) is at the new path, so
     * something another process creates there after it was checked is never replaced.
     * On another filesystem, it is copied to a hidden, randomly named file in the new directory
     * (e.g. ".corelibs-move-1a2b3c4d5e6f7a8b"), with its permissions and modification time, and its
     * owner and group as $preserveOwnership says. That is then put in place the same way, and only
     * once it is there is the original deleted. So if anything goes wrong part way through, the
     * original is still there, and only the copy needs removing.
     * Where hard links aren't allowed (e.g. on a FAT filesystem, or for a file another user owns,
     * with fs.protected_hardlinks), this falls back to rename(), which is only safe from that if
     * nothing appears at the new path in the moment between the checks and the move.
     * @param string $currentPath - the file to move.
     * @param string $newPath - where to move it.
     * @param bool $replace - whether to replace a file or symlink at $newPath.
     * @param PreserveOwnershipEnum $preserveOwnership - for a copy, how hard to try to keep the
     * owner and group (see move()).
     * @param MoveListenerInterface|null $listener - for a copy, what to tell after each chunk
     * copied (see copyFileForMove()), or null.
     * @param MoveProgress $progress - how far the move has got before this file.
     * @return void
     * @throws MoveCancelledException - if the listener cancelled the move part way through a copy
     * (the partial copy is removed, and the original is left as it was).
     * @throws FileAlreadyExistsException - without $replace, if something is at $newPath.
     * @throws BadFilePermissionsException - with YES, if a copy couldn't keep the owner and group.
     * This is checked before anything is copied.
     * @throws FilesystemException - if the file couldn't be moved. The original is left where it was
     * if this happens, and any copy is removed.
     * @throws BadArgumentException - only if given relative paths, which the callers never do.
     */
    private static function moveFile(
        string $currentPath,
        string $newPath,
        bool $replace,
        PreserveOwnershipEnum $preserveOwnership,
        ?MoveListenerInterface $listener,
        MoveProgress $progress
    ): void
    {
        if (Filesystem::isOnSameFilesystem(dirname($currentPath), dirname($newPath)))
        {
            self::placeFile($currentPath, $newPath, $replace, $currentPath);
        }
        else
        {
            if ($preserveOwnership === PreserveOwnershipEnum::YES)
            {
                self::checkCopyCanKeepOwnership($currentPath);
            }

            # Copied to the system's temporary directory if that is on the new filesystem, and otherwise beside where it
            # is going, so renaming it into place is instant either way (see chooseCopyDirectory()).
            $copyDirectory = self::chooseCopyDirectory(dirname($newPath));

            $copyPath = self::copyToTemporaryFile(
                $currentPath,
                $copyDirectory,
                $preserveOwnership,
                $listener,
                $progress
            );


            try
            {
                self::placeFile($copyPath, $newPath, $replace, $currentPath);
            }
            catch (FilesystemException | FileAlreadyExistsException $e)
            {
                @unlink($copyPath);
                self::forgetUnfinishedCopy($copyPath);
                throw $e;
            }

            self::forgetUnfinishedCopy($copyPath);

            # Only now that the copy is in place is the original deleted.
            if (!@unlink($currentPath))
            {
                # Don't leave it in both places.
                @unlink($newPath);

                throw new FilesystemException(
                    "Failed to move {$currentPath} to {$newPath}, as the original couldn't be deleted."
                );
            }
        }
    }


    /**
     * Rename a file to a new path on the same filesystem, for moveFile().
     * @param string $fromPath - the file to rename.
     * @param string $newPath - where to rename it to.
     * @param bool $replace - whether to replace something at $newPath.
     * @param string $originalPath - the path being moved, for the error messages.
     * @return void
     * @throws FileAlreadyExistsException - without $replace, if something is at $newPath.
     * @throws FilesystemException - if it couldn't be renamed. $fromPath is left as it was.
     */
    private static function placeFile(string $fromPath, string $newPath, bool $replace, string $originalPath): void
    {
        # On macOS and BSD, link() follows a symlink, and hard-links what it points to, rather than the link itself.
        $linkFollowsSymlinks = is_link($fromPath) && in_array(PHP_OS_FAMILY, ["Darwin", "BSD"], true);

        # Suppress the warnings, as we throw exceptions instead.
        if ($replace)
        {
            if (!@rename($fromPath, $newPath))
            {
                throw new FilesystemException("Failed to move {$originalPath} to {$newPath}.");
            }
        }
        elseif ($linkFollowsSymlinks)
        {
            self::placeSymlinkWithoutReplacing($fromPath, $newPath, $originalPath);
        }
        elseif (@link($fromPath, $newPath))
        {
            if (!@unlink($fromPath))
            {
                # Don't leave it under two names.
                @unlink($newPath);

                throw new FilesystemException(
                    "Failed to move {$originalPath} to {$newPath}, as the old name couldn't be removed."
                );
            }
        }
        elseif (file_exists($newPath) || is_link($newPath))
        {
            throw new FileAlreadyExistsException(
                "Can't move {$originalPath} to {$newPath}, as something was created there while it was being moved."
            );
        }
        elseif (!@rename($fromPath, $newPath))
        {
            # Hard links aren't allowed here, so rename() is all that's left, and that failed too.
            throw new FilesystemException("Failed to move {$originalPath} to {$newPath}.");
        }
    }


    /**
     * Move a symlink to a new path without replacing anything there, for placeFile() on systems where
     * link() follows symlinks (macOS and BSD). symlink() fails if anything is at the new path, so like
     * link(), it never replaces something created there after the checks. The new symlink belongs to
     * whoever runs this.
     * @param string $fromPath - the symlink to move.
     * @param string $newPath - where to move it, in the same directory or with an absolute target.
     * @param string $originalPath - the path being moved, for the error messages.
     * @return void
     * @throws FileAlreadyExistsException - if something is at $newPath.
     * @throws FilesystemException - if it couldn't be moved. $fromPath is left as it was.
     */
    private static function placeSymlinkWithoutReplacing(string $fromPath, string $newPath, string $originalPath): void
    {
        # Suppress the warnings, as we throw exceptions instead.
        $target = @readlink($fromPath);

        if ($target === false || !@symlink($target, $newPath))
        {
            if (file_exists($newPath) || is_link($newPath))
            {
                throw new FileAlreadyExistsException(
                    "Can't move {$originalPath} to {$newPath}, as something was created there while it was being " .
                    "moved."
                );
            }

            throw new FilesystemException("Failed to move {$originalPath} to {$newPath}.");
        }

        if (!@unlink($fromPath))
        {
            # Don't leave it under two names.
            @unlink($newPath);

            throw new FilesystemException(
                "Failed to move {$originalPath} to {$newPath}, as the old link couldn't be removed."
            );
        }
    }


    /**
     * Check, before a file is copied to another filesystem with PreserveOwnershipEnum::YES, that the
     * copy can keep its owner and group. A copy belongs to whoever makes it, and only root can give
     * a file to another user, so when not running as root, the file must already belong to the
     * current user, and to a group they are in.
     * @param string $path - the file (or symlink) that will be copied.
     * @return void
     * @throws BadFilePermissionsException - if the owner or group couldn't be kept, this is Windows
     * (where they can't be kept when copying to another drive), or PHP's posix extension (needed to
     * check) isn't installed.
     */
    private static function checkCopyCanKeepOwnership(string $path): void
    {
        if (PHP_OS_FAMILY === "Windows")
        {
            throw new BadFilePermissionsException(
                "Can't move {$path} to a different drive, as Windows can't keep a file's owner and ACLs when " .
                "copying it to another drive (PHP can't read or copy them). Pass PreserveOwnershipEnum::PREFERRED " .
                "or NO to allow its owner to change."
            );
        }

        if (!function_exists('posix_geteuid'))
        {
            throw new BadFilePermissionsException(
                "Moving {$path} to a different filesystem needs PHP's posix extension, to check that its owner and " .
                "group can be kept (or pass PreserveOwnershipEnum::PREFERRED or NO)."
            );
        }

        $details = lstat($path);
        $userId = posix_geteuid();
        $groupIds = array_merge([posix_getegid()], posix_getgroups());

        if ($userId !== 0 && $details['uid'] !== $userId)
        {
            throw new BadFilePermissionsException(
                "Can't move {$path} to a different filesystem, as it is owned by user {$details['uid']}, and only " .
                "root can give the copy to another user. Run this as root (e.g. with sudo) to keep its owner, or " .
                "pass PreserveOwnershipEnum::PREFERRED or NO to let the copy belong to the current user."
            );
        }

        if ($userId !== 0 && !in_array($details['gid'], $groupIds, true))
        {
            throw new BadFilePermissionsException(
                "Can't move {$path} to a different filesystem, as it belongs to group {$details['gid']}, which the " .
                "current user isn't in, so the copy can't be given it. Run this as root (e.g. with sudo), or as a " .
                "member of that group, to keep it, or pass PreserveOwnershipEnum::PREFERRED or NO to let the copy " .
                "have the current user's group."
            );
        }
    }


    /**
     * Copy a file to a new, hidden, randomly named file in a directory, for moveFile(), keeping its
     * permissions and modification time, and its owner and group as $preserveOwnership says. A
     * symlink is copied as a symlink with the same target.
     * @param string $path - the file to copy.
     * @param string $directory - the directory to copy it into.
     * @param PreserveOwnershipEnum $preserveOwnership - with NO, the copy belongs to whoever is
     * running this. Otherwise its owner and group are set to the original's where possible, and with
     * YES, it is an error if they couldn't be.
     * @param MoveListenerInterface|null $listener - what to tell after each chunk copied, or null.
     * @param MoveProgress $progress - how far the move has got before this file.
     * @return string - the path of the copy.
     * @throws MoveCancelledException - if the listener cancelled part way (the partial copy is
     * removed).
     * @throws FilesystemException - if it couldn't be copied (or, with YES, given its owner and
     * group). Any partial copy is removed.
     */
    private static function copyToTemporaryFile(
        string $path,
        string $directory,
        PreserveOwnershipEnum $preserveOwnership,
        ?MoveListenerInterface $listener,
        MoveProgress $progress
    ): string
    {
        try
        {
            $copyPath = "{$directory}/.corelibs-move-" . bin2hex(random_bytes(8));
        }
        catch (\Random\RandomException $e)
        {
            throw new FilesystemException("Failed to make a temporary name to move {$path} with.", 0, $e);
        }

        # Removed if PHP stops before the move finishes (e.g. at a timeout part way through a big file).
        self::rememberUnfinishedCopy($copyPath, false);
        $details = lstat($path);
        $isSymlink = is_link($path);

        # Suppress the warnings, as we throw an exception instead.
        if ($isSymlink)
        {
            $target = @readlink($path);
            $copied = ($target !== false) && @symlink($target, $copyPath);
        }
        else
        {
            try
            {
                $copied = self::copyFileForMove($path, $copyPath, $listener, $progress);
            }
            catch (\Throwable $e)
            {
                # The listener cancelled (or failed) part way through, so remove the partial copy.
                @unlink($copyPath);
                self::forgetUnfinishedCopy($copyPath);
                throw $e;
            }
        }

        # Set the owner and group before the permissions, as changing the owner clears the setuid and setgid bits.
        $ownershipKept = false;

        if ($copied && $preserveOwnership !== PreserveOwnershipEnum::NO)
        {
            $ownershipKept = self::giveOwnership($copyPath, $details, $isSymlink);
        }

        $ownershipFailed = !$ownershipKept && $preserveOwnership === PreserveOwnershipEnum::YES;

        # A symlink's own permissions are ignored, so only a file's are set.
        if ($copied && !$ownershipFailed && !$isSymlink)
        {
            $copied = @chmod($copyPath, $details['mode'] & 07777) && @touch($copyPath, $details['mtime']);
        }

        if (!$copied || $ownershipFailed)
        {
            @unlink($copyPath);
            self::forgetUnfinishedCopy($copyPath);

            throw new FilesystemException(
                "Failed to copy {$path} to {$directory} with its owner, group and permissions."
            );
        }

        return $copyPath;
    }


    /**
     * Try to give a copy its original's owner and group. Only root can set the owner, and anyone
     * else can only set a group they are in, so this tries, then checks.
     * @param string $path - the copy.
     * @param array $details - lstat() of the original.
     * @param bool $isSymlink - whether $path is a symlink, so the link itself is changed.
     * @return bool - true if the copy now has the original's owner and group.
     */
    private static function giveOwnership(string $path, array $details, bool $isSymlink): bool
    {
        $copyDetails = lstat($path);

        # Suppress the warnings, as whether it worked is checked below.
        if ($copyDetails['uid'] !== $details['uid'] && $isSymlink)
        {
            @lchown($path, $details['uid']);
        }
        elseif ($copyDetails['uid'] !== $details['uid'])
        {
            @chown($path, $details['uid']);
        }

        if ($copyDetails['gid'] !== $details['gid'] && $isSymlink)
        {
            @lchgrp($path, $details['gid']);
        }
        elseif ($copyDetails['gid'] !== $details['gid'])
        {
            @chgrp($path, $details['gid']);
        }

        clearstatcache(true, $path);
        $copyDetails = lstat($path);
        return ($copyDetails['uid'] === $details['uid']) && ($copyDetails['gid'] === $details['gid']);
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

        return (
               $stats1 !== false
            && $stats2 !== false
            && $stats1['dev'] === $stats2['dev']
            && $stats1['ino'] === $stats2['ino']
        );
    }


    # Accessors
    public function getFilepath() : string { return $this->m_filepath; }
}
