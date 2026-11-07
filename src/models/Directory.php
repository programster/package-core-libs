<?php

/*
 * A directory on disk, which can be renamed or moved (with everything in it), keeping track of
 * where it is. When it moves, the symlinks inside it are updated so that they keep pointing at the
 * same things. Symlinks elsewhere that point into it can't be found, so they will break. Moving it
 * to a different filesystem copies everything and then deletes the original (see move()).
 */

namespace Programster\CoreLibs\Models;


use Programster\CoreLibs\Exceptions\DirectoryNotMovableException;
use Programster\CoreLibs\Enums\DirectoryMoveProblemEnum;
use Programster\CoreLibs\Enums\PreserveOwnershipEnum;
use Programster\CoreLibs\Filesystem;
use Programster\CoreLibs\Exceptions\BadArgumentException;
use Programster\CoreLibs\Exceptions\FileAlreadyExistsException;
use Programster\CoreLibs\Exceptions\DirectoryDoesNotExistException;
use Programster\CoreLibs\Exceptions\FilesystemException;

class Directory
{
    # Moves stop looking for problems once they have found this many.
    private const MAX_PROBLEMS = 100;

    # Updated when the directory is renamed or moved.
    protected string $m_path;


    /**
     * Create an object for an existing directory.
     * @param string $path - the path to the directory. This is kept as given (e.g. relative, but
     * without a trailing slash) until the directory is renamed or moved, after which it is the
     * directory's absolute path.
     * @throws BadArgumentException - if $path is a symlink to a directory, rather than a directory.
     * Moving that would move the link, not the directory, so use File for the link instead.
     * @throws DirectoryDoesNotExistException - if there is no directory at $path.
     */
    public function __construct(string $path)
    {
        # Remove any trailing slash first, as is_link() is false for "link/" (it checks what it points to).
        $trimmedPath = ($path === "/") ? "/" : rtrim($path, "/");

        if (is_link($trimmedPath))
        {
            throw new BadArgumentException("{$path} is a symlink to a directory, rather than a directory.");
        }

        if (!is_dir($trimmedPath))
        {
            throw new DirectoryDoesNotExistException("There is no directory at {$path}.");
        }

        $this->m_path = $trimmedPath;
    }


    /**
     * Rename the directory. If given just a name (e.g. "archive"), it stays in the same parent
     * directory. If given a path, it is moved there as well. A relative path (e.g. "../archive") is
     * relative to the directory's current parent, rather than the current working directory, in the
     * same way as File::rename(). The parent it is moved to must already exist. Nothing that
     * already exists at $newPath is ever replaced. See move() for how the symlinks inside are
     * handled.
     * @param string $newPath - the new name or path for the directory.
     * @param bool $overrideUmask - when moving to a different filesystem, where everything has to
     * be copied: whether to give the copies exactly the original permissions, rather than reducing
     * them by the umask (and stopping the move if that can't be done). On the same filesystem,
     * nothing is copied, so permissions are always kept exactly and this has no effect. Defaults to
     * false.
     * @param PreserveOwnershipEnum $preserveOwnership - when moving to a different filesystem, where
     * everything has to be copied: how hard to try to keep everything's owner and group (only root
     * can give a copy to another user). YES (the default) checks first that they can all be kept,
     * and stops the move if not. PREFERRED keeps them where it can, and otherwise lets the copies
     * belong to whoever is running this. NO doesn't try, so everything copied belongs to whoever is
     * running this. On the same filesystem, nothing is copied, so ownership is always kept (apart
     * from the recreated symlinks described in move()) and this has no effect.
     * @return void
     * @throws BadArgumentException - if $newPath is empty, or is inside this directory.
     * @throws DirectoryDoesNotExistException - if the directory $newPath would be in doesn't exist.
     * @throws FileAlreadyExistsException - if something already exists at $newPath.
     * @throws DirectoryNotMovableException - if the checks find anything that would stop the move or
     * the symlink updates (see move()). getProblems() lists them. Nothing is moved if this happens.
     * @throws FilesystemException - if the move failed.
     */
    public function rename(
        string $newPath,
        bool $overrideUmask = false,
        PreserveOwnershipEnum $preserveOwnership = PreserveOwnershipEnum::YES
    ): void
    {
        if (rtrim($newPath, "/") === "")
        {
            throw new BadArgumentException("rename: \$newPath must be a name or path for the directory.");
        }

        $targetPath = $this->resolvePath(rtrim($newPath, "/"));

        if (!is_dir(dirname($targetPath)))
        {
            throw new DirectoryDoesNotExistException(
                "rename: Can't rename {$this->m_path} to {$targetPath}, as there is no directory at " .
                dirname($targetPath) . "."
            );
        }

        $this->moveTo($targetPath, null, $overrideUmask, $preserveOwnership);
    }


    /**
     * Move the directory, and everything in it, into another directory, keeping its name. A
     * relative path is relative to the directory's current parent, in the same way as rename().
     * Nothing that already exists there with this directory's name is ever replaced. The symlinks
     * inside are updated so that they keep pointing at the same things: one that points inside this
     * directory is changed to point at the new location, and one that points outside it keeps
     * pointing at the same place. Relative symlinks stay relative, and absolute ones stay absolute.
     * A relative symlink that points inside this directory, and still works from the new location,
     * is left exactly as it is. A symlink that needs updating is deleted and created again with its
     * new target, so (unlike everything else, on the same filesystem) it ends up owned by whoever
     * runs this, and their group. A symlink's owner rarely matters, as its permissions are ignored,
     * but it does in a sticky directory (e.g. /tmp), where only its owner (or the directory's) can
     * delete or rename it, and in a sticky, world-writable directory with Linux's
     * protected_symlinks setting (on by default), where it is only followed by its owner (or the
     * directory's). Everything is checked before anything is moved: that the destination is free
     * and writable, and that every subdirectory can be read, and every symlink that needs updating
     * can be. Moving to a different filesystem can't be done in one step, so everything is copied
     * there, and the original is only deleted once the whole copy has worked (if the copy fails, it
     * is removed, and the original is left as it was). So that the copy is the same as the
     * original, these are also checked first, and nothing is copied unless they all pass:
     *  - with $preserveOwnership set to YES (the default), every file, directory and symlink keeps
     *    its owner and group. A copy is owned by whoever makes it, and only root can give a file to
     *    another user, so when not running as root, everything must already belong to the current
     *    user, and to a group they are in.
     *  - there are no hard links (files with more than one name), which would become separate
     *    copies, and no special files (e.g. pipes or sockets), which can't be copied.
     *  - every directory can be written to, so that the original can be deleted after copying.
     *  - there is enough free space at the destination.
     * A copy's permissions are the original's, reduced by the umask as for any new file (e.g. 0664
     * becomes 0644 with a umask of 022). With $overrideUmask, they are set to exactly the
     * original's instead, and if that can't be done for any of them, the copy is stopped and
     * removed, and the original is left as it was. Modification times are copied too. Extended
     * attributes and ACLs can't be copied by PHP, so are lost. With $preserveOwnership set to YES,
     * this needs PHP's posix extension, to check who owns what. Symlinks outside this directory
     * that point into it can't be found, so they will break.
     * @param string $directory - the path to the directory to move this one into.
     * @param bool $createIfNotExists - whether to create $directory (and any parent directories) if
     * it doesn't exist. Defaults to false, in which case it must already exist.
     * @param bool $overrideUmask - when moving to a different filesystem, where everything has to
     * be copied: whether to give the copies exactly the original permissions, rather than reducing
     * them by the umask (and stopping the move if that can't be done). On the same filesystem,
     * nothing is copied, so permissions are always kept exactly and this has no effect. Defaults to
     * false.
     * @param PreserveOwnershipEnum $preserveOwnership - when moving to a different filesystem, where
     * everything has to be copied: how hard to try to keep everything's owner and group (only root
     * can give a copy to another user). YES (the default) checks first that they can all be kept,
     * and stops the move if not. PREFERRED keeps them where it can, and otherwise lets the copies
     * belong to whoever is running this. NO doesn't try, so everything copied belongs to whoever is
     * running this. On the same filesystem, nothing is copied, so ownership is always kept (apart
     * from the recreated symlinks described in move()) and this has no effect.
     * @return void
     * @throws BadArgumentException - if $directory is empty, isn't a directory (e.g. is a file), or
     * is this directory or inside it.
     * @throws DirectoryDoesNotExistException - if $directory doesn't exist, and $createIfNotExists
     * is false.
     * @throws FileAlreadyExistsException - if $directory already has something with this
     * directory's name in it.
     * @throws DirectoryNotMovableException - if the checks find anything that would stop the move,
     * the copy, or the symlink updates. getProblems() lists them, as does the message (stopping at
     * 100). Nothing is moved if this happens. This extends BadFilePermissionsException.
     * @throws FilesystemException - if the move or copy failed, a symlink couldn't be updated, or
     * (after a copy) the original couldn't be deleted, despite the checks.
     */
    public function move(
        string $directory,
        bool $createIfNotExists = false,
        bool $overrideUmask = false,
        PreserveOwnershipEnum $preserveOwnership = PreserveOwnershipEnum::YES
    ): void
    {
        if ($directory === "")
        {
            throw new BadArgumentException("move: \$directory must not be empty.");
        }

        $targetDirectory = $this->resolvePath($directory);
        $directoryToCreate = null;

        if (!is_dir($targetDirectory))
        {
            if (file_exists($targetDirectory) || is_link($targetDirectory))
            {
                throw new BadArgumentException(
                    "move: Can't move {$this->m_path} into {$targetDirectory}, as it isn't a directory."
                );
            }
            elseif (!$createIfNotExists)
            {
                throw new DirectoryDoesNotExistException(
                    "move: Can't move {$this->m_path} into {$targetDirectory}, as there is no directory there. " .
                    "Set \$createIfNotExists to true to create it."
                );
            }

            # It is only created once everything has been checked, so a refused move doesn't leave it behind.
            $directoryToCreate = $targetDirectory;
        }

        $targetPath = rtrim($targetDirectory, "/") . "/" . basename($this->m_path);
        $this->moveTo($targetPath, $directoryToCreate, $overrideUmask, $preserveOwnership);
    }


    /**
     * Turn a path given to rename() or move() into an absolute one. A relative path is made
     * relative to this directory's parent.
     * @param string $path - the absolute or relative path.
     * @return string - the absolute path.
     */
    private function resolvePath(string $path): string
    {
        $parent = dirname($this->m_path);
        $resolved = Filesystem::isAbsolutePath($path) ? $path : "{$parent}/{$path}";
        return Filesystem::isAbsolutePath($resolved) ? $resolved : getcwd() . "/" . $resolved;
    }


    /**
     * Check everything, then move the directory to $targetPath, and update the symlinks inside it.
     * On a different filesystem, this copies everything and then deletes the original instead.
     * @param string $targetPath - the absolute path the directory will have.
     * @param string|null $directoryToCreate - a directory to create first (for move()'s
     * $createIfNotExists), once everything has been checked, or null.
     * @param bool $overrideUmask - for a copy, whether to copy permissions exactly (see move()).
     * @param PreserveOwnershipEnum $preserveOwnership - for a copy, how hard to try to keep owners
     * and groups (see move()).
     * @return void
     * @throws BadArgumentException - if $targetPath is inside this directory.
     * @throws FileAlreadyExistsException - if something already exists at $targetPath.
     * @throws DirectoryNotMovableException - if anything would stop the move, copy or symlink
     * updates.
     * @throws FilesystemException - if the move or copy failed, a symlink couldn't be updated, or
     * the original couldn't be deleted after a copy.
     */
    private function moveTo(
        string $targetPath,
        ?string $directoryToCreate,
        bool $overrideUmask,
        PreserveOwnershipEnum $preserveOwnership
    ): void
    {
        $currentPath = realpath($this->m_path);

        # Resolve the destination's parent where it exists, so symlinks in its path are followed as the system would.
        $realParent = realpath(dirname($targetPath));
        $newParent = ($realParent !== false) ? $realParent : Filesystem::normalisePath(dirname($targetPath));
        $newPath = rtrim($newParent, "/") . "/" . basename($targetPath);

        if ($newPath !== $currentPath)
        {
            $crossesFilesystem = $this->checkCanMoveTo($currentPath, $newPath);

            # Check everything before anything is changed.
            $symlinkUpdates = [];
            $problems = [];

            if ($crossesFilesystem)
            {
                self::planCopy($currentPath, $newPath, $overrideUmask, $preserveOwnership, $problems);
            }
            else
            {
                self::planSymlinkUpdates($currentPath, $currentPath, $newPath, $symlinkUpdates, $problems);
            }

            self::throwIfProblems($currentPath, $problems);

            if ($directoryToCreate !== null)
            {
                Filesystem::mkdir($directoryToCreate, recursive: true);
            }

            if ($crossesFilesystem)
            {
                self::copyThenDelete($currentPath, $newPath, $overrideUmask, $preserveOwnership);
            }
            elseif (!@rename($currentPath, $newPath))
            {
                # Suppress the warning, as we throw an exception instead.
                throw new FilesystemException("Failed to move {$currentPath} to {$newPath}.");
            }
            else
            {
                self::applySymlinkUpdates($currentPath, $newPath, $symlinkUpdates);
            }
        }

        $this->m_path = $newPath;
    }


    /**
     * Check that the directory can be moved to $newPath, apart from the symlinks inside it.
     * @param string $currentPath - the directory's real path.
     * @param string $newPath - the absolute path it will have.
     * @return void
     * @throws BadArgumentException - if $newPath is inside the directory.
     * @throws FileAlreadyExistsException - if something already exists at $newPath.
     * @return bool - true if $newPath is on a different filesystem, so the directory has to be
     * copied there, rather than moved in one step.
     * @throws DirectoryNotMovableException - if the directory can't be removed from its parent, or
     * added to its new one.
     */
    private function checkCanMoveTo(string $currentPath, string $newPath): bool
    {
        if (Filesystem::isWithinDirectory($newPath, $currentPath))
        {
            throw new BadArgumentException("Can't move {$currentPath} to {$newPath}, as that is inside itself.");
        }

        # is_link() is needed too, as file_exists() is false for a broken symlink.
        if (file_exists($newPath) || is_link($newPath))
        {
            throw new FileAlreadyExistsException(
                "Can't move {$currentPath} to {$newPath}, as something already exists there. Nothing is ever " .
                "replaced by moving a directory."
            );
        }

        # The new parent may not exist yet (for move()'s $createIfNotExists), so check the nearest one that does.
        $existingAncestor = dirname($newPath);

        while (!is_dir($existingAncestor))
        {
            $existingAncestor = dirname($existingAncestor);
        }

        $currentParent = dirname($currentPath);
        $isNewParent = $currentParent !== dirname($newPath);

        # Moving a directory to a new parent also updates its ".." entry, so the directory itself must be writable.
        $mustBeWritable = $isNewParent
            ? [$currentParent, $existingAncestor, $currentPath]
            : [$currentParent, $existingAncestor];

        $problems = [];

        foreach (array_unique($mustBeWritable) as $directory)
        {
            if (!is_writable($directory))
            {
                $problems[] = new DirectoryMoveProblem(
                    $directory,
                    DirectoryMoveProblemEnum::DIRECTORY_NOT_WRITABLE,
                    "{$directory} can't be written to, which moving {$currentPath} to {$newPath} needs."
                );
            }
        }

        self::throwIfProblems($currentPath, $problems);

        return !Filesystem::isOnSameFilesystem($currentPath, $existingAncestor);
    }


    /**
     * Walk the directory (without following symlinks), working out the new target of every symlink
     * in it that needs changing, and checking that each can be changed.
     * @param string $directory - the real path of the directory to walk.
     * @param string $oldRoot - the real path of the directory being moved.
     * @param string $newRoot - the absolute path it will have.
     * @param array $symlinkUpdates - [symlink path, new target] pairs, which this adds to.
     * @param array $problems - descriptions of anything that would stop it, which this adds to.
     * @return void
     */
    private static function planSymlinkUpdates(
        string $directory,
        string $oldRoot,
        string $newRoot,
        array &$symlinkUpdates,
        array &$problems
    ): void
    {
        if (count($problems) < self::MAX_PROBLEMS)
        {
            # A directory that can't be listed is reported as a problem.
            if (!Filesystem::canListDirectory($directory))
            {
                $problems[] = new DirectoryMoveProblem(
                    $directory,
                    DirectoryMoveProblemEnum::DIRECTORY_NOT_LISTABLE,
                    "{$directory} can't be listed, so the symlinks in it can't be checked."
                );
            }
            else
            {
                $entries = Filesystem::getDirContents($directory, false, false, false);

                for ($i = 0; $i < count($entries) && count($problems) < self::MAX_PROBLEMS; $i++)
                {
                    $path = "{$directory}/{$entries[$i]}";

                    if (is_link($path))
                    {
                        $linkTarget = readlink($path);
                        $newTarget = self::newSymlinkTarget($directory, $linkTarget, $oldRoot, $newRoot);

                        if ($newTarget !== $linkTarget)
                        {
                            if (!is_writable($directory) || !Filesystem::canEnterDirectory($directory))
                            {
                                $problems[] = new DirectoryMoveProblem(
                                    $path,
                                    DirectoryMoveProblemEnum::SYMLINK_NOT_UPDATABLE,
                                    "{$path} needs updating, but {$directory} can't be written to."
                                );
                            }

                            $symlinkUpdates[] = [$path, $newTarget];
                        }
                    }
                    elseif (is_dir($path))
                    {
                        self::planSymlinkUpdates($path, $oldRoot, $newRoot, $symlinkUpdates, $problems);
                    }
                }
            }
        }
    }


    /**
     * Work out what a symlink's target should be once the directory has moved, so that it points at
     * the same thing: anything inside the directory moves with it, and anything outside stays where
     * it is. Relative targets stay relative, and absolute ones stay absolute.
     * @param string $linkDirectory - the real path of the directory the symlink is in now.
     * @param string $linkTarget - the symlink's current target.
     * @param string $oldRoot - the real path of the directory being moved.
     * @param string $newRoot - the absolute path it will have.
     * @return string - the target it should have, which is $linkTarget if it doesn't need to
     * change.
     */
    private static function newSymlinkTarget(
        string $linkDirectory,
        string $linkTarget,
        string $oldRoot,
        string $newRoot
    ): string
    {
        $isAbsolute = Filesystem::isAbsolutePath($linkTarget);
        $pointsAt = Filesystem::resolveLinkTarget($linkDirectory, $linkTarget);
        # Compare the target as it is, without following it: a link to another link inside counts as inside, even if
        # that one points outside.
        $isInside = Filesystem::isWithinDirectory($pointsAt, $oldRoot, false);

        # Where it should point once the directory has moved.
        $shouldPointAt = $isInside ? $newRoot . substr($pointsAt, strlen($oldRoot)) : $pointsAt;
        $newLinkDirectory = $newRoot . substr($linkDirectory, strlen($oldRoot));

        if ($isAbsolute)
        {
            # An absolute target outside the directory is unaffected by the move, so is left exactly as it is.
            $newTarget = $isInside ? $shouldPointAt : $linkTarget;
        }
        else
        {
            # Keep a relative target as it is if it still works from the new location, otherwise work it out again.
            $wouldPointAt = Filesystem::normalisePath("{$newLinkDirectory}/{$linkTarget}");

            $newTarget = ($wouldPointAt === $shouldPointAt)
                ? $linkTarget
                : Filesystem::relativePathBetween($newLinkDirectory, $shouldPointAt);
        }

        return $newTarget;
    }


    /**
     * After the directory has moved, give each symlink that needs it its new target.
     * @param string $oldRoot - the directory's real path before the move.
     * @param string $newRoot - its path after the move.
     * @param array $symlinkUpdates - [symlink path before the move, new target] pairs.
     * @return void
     * @throws FilesystemException - listing any symlinks that couldn't be updated. The directory
     * has already been moved if this happens.
     */
    private static function applySymlinkUpdates(string $oldRoot, string $newRoot, array $symlinkUpdates): void
    {
        $failed = [];

        foreach ($symlinkUpdates as [$oldLinkPath, $newTarget])
        {
            $linkPath = $newRoot . substr($oldLinkPath, strlen($oldRoot));

            # Suppress the warnings, as we report the failures together instead.
            if (!@unlink($linkPath) || !@symlink($newTarget, $linkPath))
            {
                $failed[] = "{$linkPath} (should point to {$newTarget})";
            }
        }

        if (count($failed) > 0)
        {
            throw new FilesystemException(
                "Moved {$oldRoot} to {$newRoot}, but failed to update " . count($failed) . " symlink(s) in it, " .
                "despite checking they could be:\n - " . implode("\n - ", $failed)
            );
        }
    }


    /**
     * Check everything that would stop the directory being copied to another filesystem exactly as
     * it is (see move()), adding a description of each problem to $problems.
     * @param string $oldRoot - the directory's real path.
     * @param string $newPath - the absolute path the copy will have.
     * @param bool $overrideUmask - whether permissions will be copied exactly, rather than reduced
     * by the umask.
     * @param PreserveOwnershipEnum $preserveOwnership - with YES, owners and groups are checked too.
     * @param array $problems - descriptions of the problems found, which this adds to.
     * @return void
     */
    private static function planCopy(
        string $oldRoot,
        string $newPath,
        bool $overrideUmask,
        PreserveOwnershipEnum $preserveOwnership,
        array &$problems
    ): void
    {
        $checkOwnership = $preserveOwnership === PreserveOwnershipEnum::YES;

        if ($checkOwnership && !function_exists('posix_geteuid'))
        {
            $problems[] = new DirectoryMoveProblem(
                $oldRoot,
                DirectoryMoveProblemEnum::POSIX_EXTENSION_MISSING,
                "Moving {$oldRoot} to a different filesystem needs PHP's posix extension, to check who owns what, " .
                "so that ownership isn't silently changed (or pass PreserveOwnershipEnum::PREFERRED or NO)."
            );
        }
        else
        {
            $totalBytes = 0;
            self::planCopyEntry($oldRoot, $overrideUmask, $checkOwnership, $problems, $totalBytes);

            # Check the space at the nearest existing directory, as the destination may not exist yet.
            $existingAncestor = dirname($newPath);

            while (!is_dir($existingAncestor))
            {
                $existingAncestor = dirname($existingAncestor);
            }

            $freeBytes = @disk_free_space($existingAncestor);

            if ($freeBytes !== false && $freeBytes < $totalBytes && count($problems) < self::MAX_PROBLEMS)
            {
                $problems[] = new DirectoryMoveProblem(
                    $existingAncestor,
                    DirectoryMoveProblemEnum::NOT_ENOUGH_SPACE,
                    "{$existingAncestor} only has {$freeBytes} bytes free, but copying needs {$totalBytes}."
                );
            }
        }
    }


    /**
     * Check one thing (and, for a directory, everything in it) can be copied exactly: see
     * planCopy().
     * @param string $path - the real path of the thing to check. Symlinks in it aren't followed.
     * @param bool $overrideUmask - whether permissions will be copied exactly.
     * @param bool $checkOwnership - whether to check every owner and group can be kept.
     * @param array $problems - descriptions of the problems found, which this adds to.
     * @param int $totalBytes - the total size of the files found, which this adds to.
     * @return void
     */
    private static function planCopyEntry(
        string $path,
        bool $overrideUmask,
        bool $checkOwnership,
        array &$problems,
        int &$totalBytes
    ): void
    {
        if (count($problems) < self::MAX_PROBLEMS)
        {
            # lstat() describes a symlink itself, rather than what it points to.
            $stats = lstat($path);
            $type = $stats['mode'] & 0170000;
            $isDirectory = $type === 0040000;
            $isSymlink = $type === 0120000;
            $userId = $checkOwnership ? posix_geteuid() : 0;
            $groupIds = $checkOwnership ? array_merge([posix_getegid()], posix_getgroups()) : [];

            if (!$isDirectory && !$isSymlink && $type !== 0100000)
            {
                $problems[] = new DirectoryMoveProblem(
                    $path,
                    DirectoryMoveProblemEnum::SPECIAL_FILE,
                    "{$path} is a special file (e.g. a pipe, socket or device), which can't be copied."
                );
            }
            elseif ($type === 0100000 && $stats['nlink'] > 1)
            {
                $problems[] = new DirectoryMoveProblem(
                    $path,
                    DirectoryMoveProblemEnum::HARD_LINK,
                    "{$path} is a hard link (it has {$stats['nlink']} names), which would become a separate copy."
                );
            }
            elseif ($checkOwnership && $userId !== 0 && $stats['uid'] !== $userId)
            {
                $problems[] = new DirectoryMoveProblem(
                    $path,
                    DirectoryMoveProblemEnum::OWNED_BY_ANOTHER_USER,
                    "{$path} is owned by user {$stats['uid']}, so a copy (owned by user {$userId}) would change " .
                    "its owner. Only root can keep it."
                );
            }
            elseif ($checkOwnership && $userId !== 0 && !in_array($stats['gid'], $groupIds, true))
            {
                $problems[] = new DirectoryMoveProblem(
                    $path,
                    DirectoryMoveProblemEnum::GROUP_NOT_A_MEMBER_OF,
                    "{$path} belongs to group {$stats['gid']}, which the current user isn't in, so a copy would " .
                    "change its group."
                );
            }
            elseif (!$isSymlink && !is_readable($path))
            {
                $problems[] = new DirectoryMoveProblem(
                    $path,
                    DirectoryMoveProblemEnum::NOT_READABLE,
                    "{$path} can't be read, so it can't be copied."
                );
            }
            elseif ($isDirectory && (!is_writable($path) || !Filesystem::canEnterDirectory($path)))
            {
                # The original is deleted after copying, which means removing everything in each directory.
                $problems[] = new DirectoryMoveProblem(
                    $path,
                    DirectoryMoveProblemEnum::ORIGINAL_NOT_DELETABLE,
                    "{$path} can't be written to, so the original couldn't be deleted after copying."
                );
            }

            if ($type === 0100000)
            {
                $totalBytes += $stats['size'];
            }

            if ($isDirectory && Filesystem::canListDirectory($path))
            {
                $entries = Filesystem::getDirContents($path, false, false, false);

                for ($i = 0; $i < count($entries) && count($problems) < self::MAX_PROBLEMS; $i++)
                {
                    $entryPath = "{$path}/{$entries[$i]}";
                    self::planCopyEntry($entryPath, $overrideUmask, $checkOwnership, $problems, $totalBytes);
                }
            }
            elseif ($isDirectory && is_readable($path))
            {
                $problems[] = new DirectoryMoveProblem(
                    $path,
                    DirectoryMoveProblemEnum::DIRECTORY_NOT_LISTABLE,
                    "{$path} can't be opened, so the things in it can't be copied."
                );
            }
        }
    }


    /**
     * Copy the directory to a different filesystem, then delete the original. If the copy fails
     * part way through, the partial copy is removed and the original is left as it was.
     * @param string $oldRoot - the directory's real path.
     * @param string $newRoot - the absolute path the copy will have.
     * @param bool $overrideUmask - whether to copy permissions exactly, rather than reducing them
     * by the umask.
     * @param PreserveOwnershipEnum $preserveOwnership - how hard to try to keep owners and groups.
     * @return void
     * @throws FilesystemException - if the copy failed (the original is untouched), or the original
     * couldn't be deleted afterwards (in which case both now exist).
     */
    private static function copyThenDelete(
        string $oldRoot,
        string $newRoot,
        bool $overrideUmask,
        PreserveOwnershipEnum $preserveOwnership
    ): void
    {
        # Directories get their real owner, permissions and times only once everything is copied, so that a
        # read-only one can't stop the copy, or stop a failed copy being removed.
        $directoriesToFinish = [];

        try
        {
            $copySettings = ['overrideUmask' => $overrideUmask, 'preserveOwnership' => $preserveOwnership];
            self::copyTree($oldRoot, $newRoot, $oldRoot, $newRoot, $copySettings, $directoriesToFinish);

            # The deepest directories come last in the list, and must be finished first.
            foreach (array_reverse($directoriesToFinish) as [$directoryPath, $stats])
            {
                self::restoreOwnership($directoryPath, $stats, false, $preserveOwnership);
                self::setPermissions($directoryPath, $stats, $overrideUmask);
                self::setModificationTime($directoryPath, $stats);
            }
        }
        catch (\Throwable $e)
        {
            # Remove the partial copy, leaving the original as it was.
            if (is_dir($newRoot))
            {
                try
                {
                    Filesystem::deleteDir($newRoot);
                }
                catch (\Throwable $cleanUpError)
                {
                    # Report the copy failing, as that is the cause. The message says what may be left behind.
                }
            }

            throw new FilesystemException(
                "Failed to copy {$oldRoot} to {$newRoot}, so the original was left as it was (and anything " .
                "copied so far was removed, if it could be): " . $e->getMessage(),
                0,
                $e
            );
        }

        try
        {
            Filesystem::deleteDir($oldRoot);
        }
        catch (\Throwable $e)
        {
            throw new FilesystemException(
                "Copied {$oldRoot} to {$newRoot}, but failed to delete the original, so both now exist: " .
                $e->getMessage(),
                0,
                $e
            );
        }
    }


    /**
     * Copy a directory and everything in it, without following symlinks. Files and symlinks are
     * given their original owner, group, permissions and modification time as they are copied.
     * Directories are created private (0700), and added to $directoriesToFinish, to be given theirs
     * at the end.
     * @param string $source - the real path of the directory to copy.
     * @param string $destination - where to copy it to.
     * @param string $oldRoot - the real path of the directory being moved.
     * @param string $newRoot - the path it is being moved to.
     * @param array{overrideUmask: bool, preserveOwnership: PreserveOwnershipEnum} $copySettings -
     * whether to copy permissions exactly, and how hard to try to keep owners and groups.
     * @param array $directoriesToFinish - [path, lstat() of the original] pairs, which this adds
     * to.
     * @return void
     * @throws FilesystemException - if anything couldn't be copied.
     */
    private static function copyTree(
        string $source,
        string $destination,
        string $oldRoot,
        string $newRoot,
        array $copySettings,
        array &$directoriesToFinish
    ): void
    {
        # Suppress the warnings, as we throw exceptions instead.
        if (!@mkdir($destination, 0700))
        {
            throw new FilesystemException("Failed to create {$destination}.");
        }

        $directoriesToFinish[] = [$destination, lstat($source)];

        foreach (Filesystem::getDirContents($source, false, false, false) as $entry)
        {
            $from = "{$source}/{$entry}";
            $to = "{$destination}/{$entry}";
            $stats = lstat($from);
            $type = $stats['mode'] & 0170000;

            if ($type === 0120000)
            {
                # Recreate the symlink, pointing at the same thing (see newSymlinkTarget()).
                $newTarget = self::newSymlinkTarget($source, readlink($from), $oldRoot, $newRoot);

                if (!@symlink($newTarget, $to))
                {
                    throw new FilesystemException("Failed to create the symlink {$to}.");
                }

                self::restoreOwnership($to, $stats, true, $copySettings['preserveOwnership']);
            }
            elseif ($type === 0040000)
            {
                self::copyTree($from, $to, $oldRoot, $newRoot, $copySettings, $directoriesToFinish);
            }
            else
            {
                if (!@copy($from, $to))
                {
                    throw new FilesystemException("Failed to copy {$from} to {$to}.");
                }

                # Permissions come after ownership, as changing the owner clears the setuid and setgid bits.
                self::restoreOwnership($to, $stats, false, $copySettings['preserveOwnership']);
                self::setPermissions($to, $stats, $copySettings['overrideUmask']);
                self::setModificationTime($to, $stats);
            }
        }
    }


    /**
     * Give a copy its original's owner and group. Only root can set the owner, and anyone else can
     * only set a group they are in, so this tries, then checks.
     * @param string $path - the copy.
     * @param array $stats - lstat() of the original.
     * @param bool $isSymlink - whether $path is a symlink, so the link itself is changed, rather
     * than what it points to.
     * @param PreserveOwnershipEnum $preserveOwnership - with NO, nothing is done. With PREFERRED, a
     * failure is ignored, leaving the copy owned by whoever is running this. With YES, a failure
     * throws (the checks should have made sure it can't happen).
     * @return void
     * @throws FilesystemException - with YES, if the owner or group couldn't be set.
     */
    private static function restoreOwnership(
        string $path,
        array $stats,
        bool $isSymlink,
        PreserveOwnershipEnum $preserveOwnership
    ): void
    {
        if ($preserveOwnership !== PreserveOwnershipEnum::NO)
        {
            $copyStats = lstat($path);

            # Suppress the warnings, as whether it worked is checked below.
            if ($copyStats['uid'] !== $stats['uid'] && $isSymlink)
            {
                @lchown($path, $stats['uid']);
            }
            elseif ($copyStats['uid'] !== $stats['uid'])
            {
                @chown($path, $stats['uid']);
            }

            if ($copyStats['gid'] !== $stats['gid'] && $isSymlink)
            {
                @lchgrp($path, $stats['gid']);
            }
            elseif ($copyStats['gid'] !== $stats['gid'])
            {
                @chgrp($path, $stats['gid']);
            }

            clearstatcache(true, $path);
            $copyStats = lstat($path);
            $isKept = ($copyStats['uid'] === $stats['uid']) && ($copyStats['gid'] === $stats['gid']);

            if (!$isKept && $preserveOwnership === PreserveOwnershipEnum::YES)
            {
                throw new FilesystemException(
                    "Failed to give {$path} its original owner (user {$stats['uid']}) and group " .
                    "(group {$stats['gid']})."
                );
            }
        }
    }


    /**
     * Give a copy its original's permissions (reduced by the umask, unless $overrideUmask).
     * @param string $path - the copy.
     * @param array $stats - lstat() of the original.
     * @param bool $overrideUmask - whether to copy the permissions exactly.
     * @return void
     * @throws FilesystemException - if the permissions couldn't be set, or (with $overrideUmask)
     * the copy didn't end up with exactly the original's permissions.
     */
    private static function setPermissions(string $path, array $stats, bool $overrideUmask): void
    {
        $permissions = self::permissionsFor($stats, $overrideUmask);

        # Suppress the warning, as we throw an exception instead.
        if (!@chmod($path, $permissions))
        {
            throw new FilesystemException(sprintf("Failed to set the permissions of %s to %04o.", $path, $permissions));
        }

        if ($overrideUmask)
        {
            # chmod() can report success while quietly leaving bits off (e.g. the system drops the setgid bit if the
            # current user isn't in the file's group), so check the permissions really are the original's.
            clearstatcache(true, $path);
            $actualPermissions = fileperms($path) & 07777;

            if ($actualPermissions !== $permissions)
            {
                throw new FilesystemException(sprintf(
                    "Failed to give %s exactly the original permissions, %04o, as it ended up with %04o.",
                    $path,
                    $permissions,
                    $actualPermissions
                ));
            }
        }
    }


    /**
     * Work out the permissions a copy should have.
     * @param array $stats - lstat() of the original.
     * @param bool $overrideUmask - whether to copy them exactly, rather than reducing them by the
     * umask.
     * @return int - the permissions, e.g. 0755.
     */
    private static function permissionsFor(array $stats, bool $overrideUmask): int
    {
        $permissions = $stats['mode'] & 07777;

        return $overrideUmask ? $permissions : ($permissions & ~umask());
    }


    /**
     * Give a copy its original's modification and access times.
     * @param string $path - the copy (not a symlink, as touch() would change what it points to).
     * @param array $stats - lstat() of the original.
     * @return void
     * @throws FilesystemException - if the times couldn't be set.
     */
    private static function setModificationTime(string $path, array $stats): void
    {
        # Suppress the warning, as we throw an exception instead.
        if (!@touch($path, $stats['mtime'], $stats['atime']))
        {
            throw new FilesystemException("Failed to set the modification time of {$path}.");
        }
    }


    /**
     * Throw if the checks before a move found anything that would stop it.
     * @param string $path - the directory being moved, for the message.
     * @param array<DirectoryMoveProblem> $problems - the problems found.
     * @return void
     * @throws DirectoryNotMovableException - if there are any problems.
     */
    private static function throwIfProblems(string $path, array $problems): void
    {
        if (count($problems) > 0)
        {
            $maxListed = 20;

            $countText = (count($problems) >= self::MAX_PROBLEMS)
                ? self::MAX_PROBLEMS . " or more (stopped counting at " . self::MAX_PROBLEMS . ") problems"
                : count($problems) . " problem(s)";

            $describe = function (DirectoryMoveProblem $problem): string {
                return $problem->getDescription();
            };

            $message = "Can't move {$path}, as {$countText} were found, so nothing was moved:\n - " .
                implode("\n - ", array_map($describe, array_slice($problems, 0, $maxListed)));

            if (count($problems) > $maxListed)
            {
                $message .= "\n - and " . (count($problems) - $maxListed) . " more.";
            }

            # Say once, after them, how ownership problems can be avoided.
            $isOwnershipProblem = function (DirectoryMoveProblem $problem): bool {
                return in_array(
                    $problem->getReason(),
                    [DirectoryMoveProblemEnum::OWNED_BY_ANOTHER_USER, DirectoryMoveProblemEnum::GROUP_NOT_A_MEMBER_OF],
                    true
                );
            };

            if (count(array_filter($problems, $isOwnershipProblem)) > 0)
            {
                $message .= "\nTo keep these owners and groups, run this as root (e.g. with sudo), or pass " .
                    "PreserveOwnershipEnum::PREFERRED or NO to let the copies belong to the current user.";
            }

            throw new DirectoryNotMovableException($message, $problems);
        }
    }


    # Accessors
    public function getPath() : string { return $this->m_path; }
}
