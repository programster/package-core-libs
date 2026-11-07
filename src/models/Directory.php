<?php

/*
 * A directory on disk, which can be renamed or moved (with everything in it), keeping track of
 * where it is. When it moves, the symlinks inside it are updated so that they keep pointing at the
 * same things. Symlinks elsewhere that point into it can't be found, so they will break. Moving it
 * to a different filesystem copies everything and then deletes the original (see move()).
 */

namespace Programster\CoreLibs\Models;


use Programster\CoreLibs\Exceptions\CoreLibsException;
use Programster\CoreLibs\Exceptions\DirectoryNotMovableException;
use Programster\CoreLibs\Enums\DirectoryMoveProblemEnum;
use Programster\CoreLibs\Enums\PreserveOwnershipEnum;
use Programster\CoreLibs\Filesystem;
use Programster\CoreLibs\Exceptions\BadArgumentException;
use Programster\CoreLibs\Exceptions\FileAlreadyExistsException;
use Programster\CoreLibs\Exceptions\DirectoryDoesNotExistException;
use Programster\CoreLibs\Exceptions\MoveCancelledException;
use Programster\CoreLibs\Exceptions\DirectoryNotDeletableException;
use Programster\CoreLibs\Exceptions\FilesystemException;

class Directory
{
    use MoveCopyingTrait;

    # Moves stop looking for problems once they have found this many.
    private const MAX_PROBLEMS = 100;

    # Updated when the directory is renamed or moved.
    protected string $m_path;


    /**
     * Create an object for an existing directory.
     *
     * @param string $path - the path to the directory. This is kept as given (e.g. relative, but
     * without a trailing slash) until the directory is renamed or moved, after which it is the
     * directory's absolute path.
     *
     * @throws BadArgumentException - if $path is a symlink to a directory, rather than a directory.
     * Moving that would move the link, not the directory, so use File for the link instead.
     *
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
     * Rename the directory, keeping it in the same parent directory. To put it somewhere else, use
     * move(), which can also give it a new name there. Nothing that already exists with the new name
     * is ever replaced. As it stays in the same parent directory (so on the same filesystem), it is
     * renamed in one step, and nothing is copied. Symlinks inside that pointed at it by its old path
     * are updated, as in move().
     *
     * @param string $newName - the new name, e.g. "archive". It must be just a name, not a path.
     *
     * @return void
     *
     * @throws BadArgumentException - if $newName isn't just a name (e.g. it has a "/" in it).
     * @throws FileAlreadyExistsException - if something already exists with the new name.
     *
     * @throws DirectoryNotMovableException - if anything would stop the rename or the symlink
     * updates. getProblems() lists them. Nothing is renamed if this happens.
     *
     * @throws FilesystemException - if the rename failed, or a symlink couldn't be updated.
     *
     * @throws DirectoryDoesNotExistException - if a directory inside disappears while it is being
     * checked.
     */
    public function rename(string $newName): void
    {
        self::checkIsName($newName, "rename");
        $targetPath = dirname($this->m_path) . "/" . $newName;

        # Within one directory, nothing is copied, so the copying settings make no difference.
        $moveSettings = [
            'overrideUmask' => false,
            'preserveOwnership' => PreserveOwnershipEnum::YES,
            'listener' => null,
            'resumable' => false,
        ];

        $this->moveTo($targetPath, null, $moveSettings);
    }


    /**
     * Move the directory, and everything in it, into another directory, keeping its name (or
     * giving it $newName there). A relative path is relative to the directory's current parent.
     * Nothing that already exists there with that name is ever replaced. The symlinks
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
     *    user, and to a group they are in. On Windows, the copies are given their originals' owners
     *    and permissions (ACLs) with PowerShell once they are in place, and with YES, the move is
     *    undone if any can't be (setting another user as the owner needs administrator rights).
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
     *
     * @param string $directory - the path to the directory to move this one into.
     *
     * @param bool $createIfNotExists - whether to create $directory (and any parent directories) if
     * it doesn't exist. Defaults to false, in which case it must already exist.
     *
     * @param bool $overrideUmask - when moving to a different filesystem, where everything has to
     * be copied: whether to give the copies exactly the original permissions, rather than reducing
     * them by the umask (and stopping the move if that can't be done). On the same filesystem,
     * nothing is copied, so permissions are always kept exactly and this has no effect. Defaults to
     * false.
     *
     * @param PreserveOwnershipEnum $preserveOwnership - when moving to a different filesystem, where
     * everything has to be copied: how hard to try to keep everything's owner and group (only root
     * can give a copy to another user). YES (the default) checks first that they can all be kept,
     * and stops the move if not. PREFERRED keeps them where it can, and otherwise lets the copies
     * belong to whoever is running this. NO doesn't try, so everything copied belongs to whoever is
     * running this. On the same filesystem, nothing is copied, so ownership is always kept (apart
     * from the recreated symlinks described in move()) and this has no effect.
     *
     * @param MoveListenerInterface|null $listener - optionally, something to tell how the move is
     * going, which can also cancel it (see MoveListenerInterface). Defaults to null.
     *
     * @param bool $resumable - for a move to a different filesystem: whether to keep what was copied
     * if the copy fails, is cancelled, or PHP stops part way (e.g. at a timeout), so that making the
     * same move again carries on from there. Files already copied are only copied again if they
     * have changed (by their size and modification time), a file copied part way is copied again
     * from the start, and anything since removed from the original is removed from the copy. A
     * copy that is never resumed stays, under a hidden name beside the destination or in the
     * system's temporary directory, until deleted. Defaults to false, which removes a partial copy.
     *
     * @param string|null $newName - optionally, a new name to give it in $directory, e.g. for when
     * something there already has its current name. It must be just a name, not a path. Defaults
     * to null, which keeps its current name.
     *
     * @return void
     *
     * @throws MoveCancelledException - if $listener cancelled the move. Anything copied so far is
     * removed, and the original is left as it was.
     *
     * @throws BadArgumentException - if $newName isn't just a name, or $directory is empty, isn't a
     * directory (e.g. is a file), or is this directory or inside it.
     *
     * @throws DirectoryDoesNotExistException - if $directory doesn't exist, and $createIfNotExists
     * is false.
     *
     * @throws FileAlreadyExistsException - if $directory already has something with this
     * directory's name in it.
     *
     * @throws DirectoryNotMovableException - if the checks find anything that would stop the move,
     * the copy, or the symlink updates. getProblems() lists them, as does the message (stopping at
     * 100). Nothing is moved if this happens. This extends BadFilePermissionsException.
     *
     * @throws FilesystemException - if the move or copy failed, a symlink couldn't be updated, or
     * (after a copy) the original couldn't be deleted, despite the checks.
     */
    public function move(
        string $directory,
        bool $createIfNotExists = false,
        bool $overrideUmask = false,
        PreserveOwnershipEnum $preserveOwnership = PreserveOwnershipEnum::YES,
        ?MoveListenerInterface $listener = null,
        bool $resumable = false,
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

        $targetPath = rtrim($targetDirectory, "/") . "/" . ($newName ?? basename($this->m_path));

        $moveSettings = [
            'overrideUmask' => $overrideUmask,
            'preserveOwnership' => $preserveOwnership,
            'listener' => $listener,
            'resumable' => $resumable,
        ];

        $this->moveTo($targetPath, $directoryToCreate, $moveSettings);
    }


    /**
     * Turn a path given to rename() or move() into an absolute one. A relative path is made
     * relative to this directory's parent.
     *
     * @param string $path - the absolute or relative path.
     *
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
     *
     * @param string $targetPath - the absolute path the directory will have.
     *
     * @param string|null $directoryToCreate - a directory to create first (for move()'s
     * $createIfNotExists), once everything has been checked, or null.
     *
     * @param array $moveSettings - see copyThenDelete(). Only "listener" and "resumable" matter
     * on the same filesystem.
     *
     * @return void
     *
     * @throws MoveCancelledException - if $listener cancelled the move.
     * @throws BadArgumentException - if $targetPath is inside this directory.
     * @throws FileAlreadyExistsException - if something already exists at $targetPath.
     *
     * @throws DirectoryNotMovableException - if anything would stop the move, copy or symlink
     * updates.
     *
     * @throws FilesystemException - if the move or copy failed, a symlink couldn't be updated, or
     * the original couldn't be deleted after a copy.
     *
     * @throws DirectoryDoesNotExistException - if a directory inside disappears while the move is
     * being checked or done.
     */
    private function moveTo(string $targetPath, ?string $directoryToCreate, array $moveSettings): void
    {
        $listener = $moveSettings['listener'];

        # Tidied, as on Windows realpath() gives "\\", while the destination is worked out with "/", so the same path
        # could otherwise look like a different one (e.g. "inside itself").
        $currentPath = Filesystem::normalisePath(realpath($this->m_path));

        # Resolve the destination's parent, following the symlinks in the part of it that exists, as the system would,
        # even if it doesn't exist yet (for $createIfNotExists). Otherwise it could be compared with the real path of
        # the directory, e.g. macOS's /var/... with its real /private/var/..., and the symlinks worked out wrong.
        $newParent = Filesystem::resolvePath(dirname($targetPath));
        $newPath = rtrim($newParent, "/") . "/" . basename($targetPath);

        if ($newPath !== $currentPath)
        {
            # On a filesystem that ignores case, a new name that only differs by case is the same directory, so just
            # rename it, rather than refusing because something "already exists" there.
            if (Filesystem::isCaseOnlyRename($currentPath, $newPath))
            {
                # Nothing else needs checking, so the totals are only worked out for a listener.
                $progress = ($listener === null) ? new MoveProgress(0, 0) : self::countContents($currentPath);
                $listener?->onMoveStarting($progress);

                # Suppress the warning, as we throw an exception instead.
                if (!@rename($currentPath, $newPath))
                {
                    throw new FilesystemException("Failed to rename {$currentPath} to {$newPath}.");
                }

                $listener?->onMoveFinished($progress->withEverythingMoved());
            }
            else
            {
                $crossesFilesystem = $this->checkCanMoveTo($currentPath, $newPath, $moveSettings['resumable']);

                # Check everything before anything is changed. Both walks also count the totals for the listener.
                $symlinkUpdates = [];
                $problems = [];
                $totals = ['files' => 0, 'bytes' => 0];

                if ($crossesFilesystem)
                {
                    $overrideUmask = $moveSettings['overrideUmask'];
                    $preserveOwnership = $moveSettings['preserveOwnership'];
                    $progress = self::planCopy($currentPath, $newPath, $overrideUmask, $preserveOwnership, $problems);
                }
                else
                {
                    self::planSymlinkUpdates($currentPath, $currentPath, $newPath, $symlinkUpdates, $problems, $totals);
                    $progress = new MoveProgress($totals['files'], $totals['bytes']);
                }

                self::throwIfProblems($currentPath, $problems);
                $listener?->onMoveStarting($progress);

                if ($directoryToCreate !== null)
                {
                    Filesystem::mkdir($directoryToCreate, recursive: true);
                }

                if ($crossesFilesystem)
                {
                    $finished = self::copyThenDelete($currentPath, $newPath, $moveSettings, $progress);
                }
                elseif (!@rename($currentPath, $newPath))
                {
                    # Suppress the warning, as we throw an exception instead.
                    throw new FilesystemException("Failed to move {$currentPath} to {$newPath}.");
                }
                else
                {
                    self::applySymlinkUpdates($currentPath, $newPath, $symlinkUpdates);
                    $finished = $progress->withEverythingMoved();
                }

                $listener?->onMoveFinished($finished);
            }
        }

        $this->m_path = $newPath;
    }


    /**
     * Check that the directory can be moved to $newPath, apart from the symlinks inside it.
     *
     * @param string $currentPath - the directory's real path.
     *
     * @param string $newPath - the absolute path it will have.
     *
     * @param bool $resumable - whether an empty directory an earlier resumable attempt left at
     * $newPath is allowed (see move()).
     *
     * @return void
     *
     * @throws BadArgumentException - if $newPath is inside the directory.
     * @throws FileAlreadyExistsException - if something already exists at $newPath.
     *
     * @return bool - true if $newPath is on a different filesystem, so the directory has to be
     * copied there, rather than moved in one step.
     *
     * @throws DirectoryNotMovableException - if the directory can't be removed from its parent, or
     * added to its new one.
     */
    private function checkCanMoveTo(string $currentPath, string $newPath, bool $resumable): bool
    {
        if (Filesystem::isWithinDirectory($newPath, $currentPath))
        {
            throw new BadArgumentException("Can't move {$currentPath} to {$newPath}, as that is inside itself.");
        }

        # When resuming, the empty directory an earlier attempt made to hold the new name may still be there, which is
        # fine. Anything else there (including someone else's empty directory) is refused, as usual. is_link() is
        # needed too, as file_exists() is false for a broken symlink.
        $isEarlierAttempts = (
               $resumable
            && is_dir($newPath)
            && !Filesystem::isOnSameFilesystem($currentPath, $newPath)
            && self::isResumablePlaceholder($newPath, self::findResumableCopyPath($currentPath, $newPath) . ".state")
        );

        if ((file_exists($newPath) || is_link($newPath)) && !$isEarlierAttempts)
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
            if (!Filesystem::canWriteToDirectory($directory))
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
     *
     * @param string $directory - the real path of the directory to walk.
     *
     * @param string $oldRoot - the real path of the directory being moved.
     *
     * @param string $newRoot - the absolute path it will have.
     *
     * @param array $symlinkUpdates - [symlink path, new target] pairs, which this adds to.
     *
     * @param array $problems - descriptions of anything that would stop it, which this adds to.
     *
     * @param array{files: int, bytes: int} $totals - how many files (and symlinks) there are, and
     * their size, for a MoveProgress, which this adds to.
     *
     * @return void
     *
     * @throws DirectoryDoesNotExistException - if a directory disappears while it is being checked.
     * @throws FilesystemException - if a directory that could be listed can't be opened after all.
     * @throws BadArgumentException - only if given relative paths, which the callers never do.
     */
    private static function planSymlinkUpdates(
        string $directory,
        string $oldRoot,
        string $newRoot,
        array &$symlinkUpdates,
        array &$problems,
        array &$totals
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
                        $totals['files']++;

                        # On Windows, readlink() gives the fully resolved absolute path rather than the link's own
                        # target, so whether a link is relative can't be told. So every link is created again,
                        # pointing at the same place absolutely (see newSymlinkTarget()). A broken one can't be read
                        # or created there, so is left as it is, as it is broken either way.
                        $onWindows = PHP_OS_FAMILY === "Windows";
                        $linkTarget = @readlink($path);
                        $isReadable = $linkTarget !== false;

                        $newTarget = $isReadable
                            ? self::newSymlinkTarget($directory, $linkTarget, $oldRoot, $newRoot, $onWindows)
                            : $linkTarget;

                        if ($isReadable && ($newTarget !== $linkTarget || $onWindows))
                        {
                            $canUpdate = Filesystem::canWriteToDirectory($directory);

                            if (!$canUpdate || !Filesystem::canEnterDirectory($directory))
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
                        self::planSymlinkUpdates($path, $oldRoot, $newRoot, $symlinkUpdates, $problems, $totals);
                    }
                    else
                    {
                        $totals['files']++;
                        $totals['bytes'] += (int)filesize($path);
                    }
                }
            }
        }
    }


    /**
     * Work out what a symlink's target should be once the directory has moved, so that it points at
     * the same thing: anything inside the directory moves with it, and anything outside stays where
     * it is. Relative targets stay relative, and absolute ones stay absolute.
     *
     * @param string $linkDirectory - the real path of the directory the symlink is in now.
     *
     * @param string $linkTarget - the symlink's current target.
     *
     * @param string $oldRoot - the real path of the directory being moved.
     *
     * @param string $newRoot - the absolute path it will have.
     *
     * @param bool $mustBeAbsolute - true on Windows, where PHP can't create a relative symlink, so a
     * relative one that has to be created gets an absolute target instead.
     *
     * @param bool $isRecreated - true if the symlink is being created again anyway (when copying to
     * another filesystem), so with $mustBeAbsolute, even a relative one that would still work gets an
     * absolute target.
     *
     * @return string - the target it should have, which is $linkTarget if it doesn't need to
     * change.
     *
     * @throws BadArgumentException - only if given a relative path for a directory, which the
     * callers never do.
     */
    private static function newSymlinkTarget(
        string $linkDirectory,
        string $linkTarget,
        string $oldRoot,
        string $newRoot,
        bool $mustBeAbsolute = false,
        bool $isRecreated = false
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
        $wouldStillWork = Filesystem::normalisePath("{$newLinkDirectory}/{$linkTarget}") === $shouldPointAt;

        if ($isAbsolute)
        {
            # An absolute target outside the directory is unaffected by the move, so is left exactly as it is.
            $newTarget = $isInside ? $shouldPointAt : $linkTarget;
        }
        elseif ($mustBeAbsolute && ($isRecreated || $wouldStillWork === false))
        {
            # Windows can't create a relative symlink, so one that has to be created points at the same place
            # absolutely.
            $newTarget = $shouldPointAt;
        }
        else
        {
            # Keep a relative target as it is if it still works from the new location, otherwise work it out again.
            $newTarget = $wouldStillWork
                ? $linkTarget
                : Filesystem::relativePathBetween($newLinkDirectory, $shouldPointAt);
        }

        return $newTarget;
    }


    /**
     * After the directory has moved, give each symlink that needs it its new target.
     *
     * @param string $oldRoot - the directory's real path before the move.
     *
     * @param string $newRoot - its path after the move.
     *
     * @param array $symlinkUpdates - [symlink path before the move, new target] pairs.
     *
     * @return void
     *
     * @throws FilesystemException - listing any symlinks that couldn't be updated. The directory
     * has already been moved if this happens.
     */
    private static function applySymlinkUpdates(string $oldRoot, string $newRoot, array $symlinkUpdates): void
    {
        $failed = [];

        foreach ($symlinkUpdates as [$oldLinkPath, $newTarget])
        {
            $linkPath = $newRoot . substr($oldLinkPath, strlen($oldRoot));

            # Suppress the warning, as we report the failures together instead.
            $replaced = false;

            try
            {
                Filesystem::removeSymlink($linkPath);
                $replaced = self::createSymlink($newTarget, $linkPath);
            }
            catch (CoreLibsException $e)
            {
                # Reported with the others below.
            }

            if (!$replaced)
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
     *
     * @param string $oldRoot - the directory's real path.
     *
     * @param string $newPath - the absolute path the copy will have.
     *
     * @param bool $overrideUmask - whether permissions will be copied exactly, rather than reduced
     * by the umask.
     *
     * @param PreserveOwnershipEnum $preserveOwnership - with YES, owners and groups are checked too.
     *
     * @param array $problems - descriptions of the problems found, which this adds to.
     *
     * @return MoveProgress - how many files (and symlinks) there are to copy, and their size, with
     * nothing moved yet. These may be short if there were problems.
     *
     * @throws DirectoryDoesNotExistException - if a directory disappears while it is being checked.
     * @throws FilesystemException - if a directory that could be listed can't be opened after all.
     */
    private static function planCopy(
        string $oldRoot,
        string $newPath,
        bool $overrideUmask,
        PreserveOwnershipEnum $preserveOwnership,
        array &$problems
    ): MoveProgress
    {
        # Unix owners and groups are checked here. On Windows, owners and permissions are copied after the copy is in
        # place (see copyWindowsSecurity()), which with YES undoes the move if any can't be kept.
        $checkOwnership = $preserveOwnership === PreserveOwnershipEnum::YES && PHP_OS_FAMILY !== "Windows";
        $totals = ['files' => 0, 'bytes' => 0];

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
            self::planCopyEntry($oldRoot, $overrideUmask, $checkOwnership, $problems, $totals);

            # Check the space at the nearest existing directory, as the destination may not exist yet.
            $existingAncestor = dirname($newPath);

            while (!is_dir($existingAncestor))
            {
                $existingAncestor = dirname($existingAncestor);
            }

            $freeBytes = @disk_free_space($existingAncestor);

            if ($freeBytes !== false && $freeBytes < $totals['bytes'] && count($problems) < self::MAX_PROBLEMS)
            {
                $problems[] = new DirectoryMoveProblem(
                    $existingAncestor,
                    DirectoryMoveProblemEnum::NOT_ENOUGH_SPACE,
                    "{$existingAncestor} only has {$freeBytes} bytes free, but copying needs {$totals['bytes']}."
                );
            }
        }

        return new MoveProgress($totals['files'], $totals['bytes']);
    }


    /**
     * Check one thing (and, for a directory, everything in it) can be copied exactly: see
     * planCopy().
     *
     * @param string $path - the real path of the thing to check. Symlinks in it aren't followed.
     *
     * @param bool $overrideUmask - whether permissions will be copied exactly.
     *
     * @param bool $checkOwnership - whether to check every owner and group can be kept.
     *
     * @param array $problems - descriptions of the problems found, which this adds to.
     *
     * @param array{files: int, bytes: int} $totals - how many files (and symlinks) were found, and
     * the total size of the files, which this adds to.
     *
     * @return void
     *
     * @throws DirectoryDoesNotExistException - if a directory disappears while it is being checked.
     * @throws FilesystemException - if a directory that could be listed can't be opened after all.
     */
    private static function planCopyEntry(
        string $path,
        bool $overrideUmask,
        bool $checkOwnership,
        array &$problems,
        array &$totals
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

            if ($isSymlink && PHP_OS_FAMILY === "Windows" && !file_exists($path))
            {
                $problems[] = new DirectoryMoveProblem(
                    $path,
                    DirectoryMoveProblemEnum::SYMLINK_NOT_CREATABLE_ON_WINDOWS,
                    "{$path} is a broken symlink, which can't be copied, as Windows can't create a broken symlink."
                );
            }
            elseif (!$isDirectory && !$isSymlink && $type !== 0100000)
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
            elseif ($isDirectory && (!Filesystem::canWriteToDirectory($path) || !Filesystem::canEnterDirectory($path)))
            {
                # The original is deleted after copying, which means removing everything in each directory.
                $problems[] = new DirectoryMoveProblem(
                    $path,
                    DirectoryMoveProblemEnum::ORIGINAL_NOT_DELETABLE,
                    "{$path} can't be written to, so the original couldn't be deleted after copying."
                );
            }

            # Files and symlinks count as files for a MoveProgress, and only files have a size worth counting.
            if (!$isDirectory)
            {
                $totals['files']++;
                $totals['bytes'] += ($type === 0100000) ? $stats['size'] : 0;
            }

            if ($isDirectory && Filesystem::canListDirectory($path))
            {
                $entries = Filesystem::getDirContents($path, false, false, false);

                for ($i = 0; $i < count($entries) && count($problems) < self::MAX_PROBLEMS; $i++)
                {
                    $entryPath = "{$path}/{$entries[$i]}";
                    self::planCopyEntry($entryPath, $overrideUmask, $checkOwnership, $problems, $totals);
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
     * Copy the directory to a different filesystem, then delete the original. It is copied under a
     * hidden name, to the system's temporary directory if that is on the new filesystem and
     * otherwise beside where it is going (see chooseCopyDirectory()), while an empty directory
     * holds its new name, so nothing else can take it. Only once the copy is complete is it renamed
     * into place (which is instant, as it is on the same filesystem), and only then is the
     * original deleted. If the copy fails or is cancelled part way through, the partial copy is
     * removed and the original is left as it was. If PHP stops part way (e.g. at a timeout), a
     * shutdown function removes the partial copy, as far as it can.
     *
     * @param string $oldRoot - the directory's real path.
     *
     * @param string $newRoot - the absolute path the copy will have.
     *
     * @param array $moveSettings - "overrideUmask" (bool): whether to copy permissions exactly,
     * rather than reducing them by the umask. "preserveOwnership" (PreserveOwnershipEnum): how hard
     * to try to keep owners and groups. "listener" (?MoveListenerInterface): what to tell how the
     * copy is going. "resumable" (bool): whether to keep a partial copy, and carry on from one an
     * earlier attempt kept (see move()).
     *
     * @param MoveProgress $progress - the totals, with nothing moved yet.
     *
     * @return MoveProgress - the progress once everything has been copied.
     *
     * @throws MoveCancelledException - if the listener cancelled the move (the original is
     * untouched).
     *
     * @throws FilesystemException - if the copy failed (the original is untouched), or the original
     * couldn't be deleted afterwards (in which case both now exist).
     */
    private static function copyThenDelete(
        string $oldRoot,
        string $newRoot,
        array $moveSettings,
        MoveProgress $progress
    ): MoveProgress
    {
        $resumable = $moveSettings['resumable'];

        # A resumable copy's name comes from the paths, so a later attempt can find it. Otherwise it is random.
        if ($resumable)
        {
            $copyRoot = self::findResumableCopyPath($oldRoot, $newRoot);
        }
        else
        {
            try
            {
                $copyName = ".corelibs-move-" . bin2hex(random_bytes(8));
                $copyRoot = self::chooseCopyDirectory(dirname($newRoot)) . "/{$copyName}";
            }
            catch (\Random\RandomException $e)
            {
                throw new FilesystemException("Failed to make a temporary name to copy {$oldRoot} to.", 0, $e);
            }
        }

        # Hold the new name with an empty directory until the copy is renamed over it, unless an earlier resumable
        # attempt left its own one there. Suppress the warning, as we throw an exception instead.
        $stateFile = "{$copyRoot}.state";

        if (!($resumable && self::isResumablePlaceholder($newRoot, $stateFile)))
        {
            if (!@mkdir($newRoot, 0700))
            {
                throw new FilesystemException("Failed to create {$newRoot}.");
            }

            if ($resumable)
            {
                self::writeResumableState($newRoot, $stateFile);
            }
        }

        # A resumable copy is kept if PHP stops, so it can be resumed. The empty directory holding the name is removed.
        self::rememberUnfinishedCopy($newRoot, true);

        if (!$resumable)
        {
            self::rememberUnfinishedCopy($copyRoot, false);
        }

        # Directories get their real owner, permissions and times only once everything is copied, so that a
        # read-only one can't stop the copy, or stop a failed copy being removed.
        $directoriesToFinish = [];
        $isRenamed = false;
        $current = $progress;

        try
        {
            $symlinksToCreate = [];

            self::copyTree(
                $oldRoot,
                $copyRoot,
                $oldRoot,
                $newRoot,
                $moveSettings,
                $directoriesToFinish,
                $symlinksToCreate,
                $current
            );

            # On Windows, a symlink can only be created once its target exists, and the targets are where everything
            # will be once it is renamed into place, so they are created after that. Elsewhere, they are created now.
            $onWindows = PHP_OS_FAMILY === "Windows";

            if (!$onWindows)
            {
                self::createCopiedSymlinks($symlinksToCreate, $moveSettings, $current);
            }

            # The deepest directories come last in the list, and must be finished first. The top one is finished once
            # it is in place, as renaming a directory into another one needs it to be writable, and can change its
            # modification time.
            $topStats = $directoriesToFinish[0][1];

            foreach (array_reverse(array_slice($directoriesToFinish, 1)) as [$directoryPath, $stats])
            {
                self::finishDirectory($directoryPath, $stats, $moveSettings);
            }

            self::renameIntoPlace($copyRoot, $newRoot);
            $isRenamed = true;

            if ($resumable)
            {
                # Suppress the warning, as a state file left behind changes nothing.
                @unlink($stateFile);
            }

            self::forgetUnfinishedCopy($copyRoot);
            self::forgetUnfinishedCopy($newRoot);

            if ($onWindows)
            {
                $placedSymlinks = [];

                foreach ($symlinksToCreate as [$linkPath, $target, $stats, $originalPath])
                {
                    $placedPath = $newRoot . substr($linkPath, strlen($copyRoot));
                    $placedSymlinks[] = [$placedPath, $target, $stats, $originalPath];
                }

                self::createCopiedSymlinks($placedSymlinks, $moveSettings, $current);

                # Copied once it is all in place, so an owner that can't be changed afterwards can't stop the rename.
                $securityPairs = self::windowsSecurityPairs($oldRoot, $newRoot);
                self::copyWindowsSecurity($securityPairs, $moveSettings['preserveOwnership']);
            }

            self::finishDirectory($newRoot, $topStats, $moveSettings);
        }
        catch (\Throwable $e)
        {
            # Before the rename, the copy is under its hidden name, with the empty directory holding the new name. A
            # resumable copy is kept there, unless it was already renamed into place.
            $keepsCopy = $resumable && !$isRenamed;

            if (!$keepsCopy)
            {
                self::removePartialCopy($isRenamed ? $newRoot : $copyRoot);
            }

            if (!$isRenamed)
            {
                # Suppress the warning, as it is only removed if it is still the empty one this made.
                @rmdir($newRoot);
                self::forgetUnfinishedCopy($newRoot);
            }

            # A cancelled move is passed on as it is, so the caller can tell it apart from a failure.
            if ($e instanceof MoveCancelledException)
            {
                throw $e;
            }

            $whatWasCopied = $keepsCopy
                ? "what was copied so far was kept at {$copyRoot}, so moving it again with \$resumable continues it"
                : "anything copied so far was removed, if it could be";

            throw new FilesystemException(
                "Failed to copy {$oldRoot} to {$newRoot}, so the original was left as it was ({$whatWasCopied}): " .
                $e->getMessage(),
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

        return $current;
    }


    /**
     * Copy a directory and everything in it, without following symlinks. Files and symlinks are
     * given their original owner, group, permissions and modification time as they are copied.
     * Directories are created private (0700), and added to $directoriesToFinish, to be given theirs
     * at the end.
     *
     * @param string $source - the real path of the directory to copy.
     *
     * @param string $destination - where to copy it to.
     *
     * @param string $oldRoot - the real path of the directory being moved.
     *
     * @param string $newRoot - the path it is being moved to.
     *
     * @param array $copySettings - see copyThenDelete()'s $moveSettings. With "resumable", what an
     * earlier attempt copied to $destination is reused where it is finished and still matches the
     * original, and anything else there is replaced or removed.
     *
     * @param array $directoriesToFinish - [path, lstat() of the original] pairs, which this adds
     * to.
     *
     * @param array $symlinksToCreate - [path, target, lstat() of the original, original path] for
     * each symlink, which this adds to, rather than creating them, as on Windows a symlink's target
     * has to exist before it can be created (see createCopiedSymlinks()).
     *
     * @param MoveProgress $progress - how far the move has got, which this moves on for each file it
     * copies, telling the listener.
     *
     * @return void
     *
     * @throws MoveCancelledException - if the listener cancelled the move.
     *
     * @throws DirectoryNotDeletableException - when resuming, if something an earlier attempt copied
     * that is in the way can't be removed.
     *
     * @throws FilesystemException - if anything couldn't be copied.
     * @throws DirectoryDoesNotExistException - if a directory disappears while it is being copied.
     * @throws BadArgumentException - only if given relative paths, which the callers never do.
     */
    private static function copyTree(
        string $source,
        string $destination,
        string $oldRoot,
        string $newRoot,
        array $copySettings,
        array &$directoriesToFinish,
        array &$symlinksToCreate,
        MoveProgress &$progress
    ): void
    {
        $resumable = $copySettings['resumable'];

        # When resuming, a directory the earlier attempt copied is reused. It is made private and writable again, as it
        # may already have been given its original's permissions before that attempt stopped.
        $isResumed = $resumable && is_dir($destination) && !is_link($destination);

        if ($isResumed)
        {
            # Suppress the warning, as failing just means copying into it fails, which says why.
            @chmod($destination, 0700);
        }
        else
        {
            if ($resumable)
            {
                self::clearForCopy($destination);
            }

            # Suppress the warnings, as we throw exceptions instead.
            if (!@mkdir($destination, 0700))
            {
                throw new FilesystemException("Failed to create {$destination}.");
            }
        }

        $directoriesToFinish[] = [$destination, lstat($source)];
        $entries = Filesystem::getDirContents($source, false, false, false);

        # Anything the earlier attempt copied that is no longer in the original is removed.
        foreach ($isResumed ? Filesystem::getDirContents($destination, false, false, false) : [] as $copiedEntry)
        {
            if (!in_array($copiedEntry, $entries, true))
            {
                self::clearForCopy("{$destination}/{$copiedEntry}");
            }
        }

        foreach ($entries as $entry)
        {
            $from = "{$source}/{$entry}";
            $to = "{$destination}/{$entry}";
            $stats = lstat($from);
            $type = $stats['mode'] & 0170000;

            if ($type === 0120000)
            {
                # Recreate the symlink, pointing at the same thing (see newSymlinkTarget()), once everything else is
                # copied, as on Windows, its target has to exist first. One from an earlier attempt is made again.
                if ($resumable)
                {
                    self::clearForCopy($to);
                }

                $onWindows = PHP_OS_FAMILY === "Windows";
                $newTarget = self::newSymlinkTarget($source, readlink($from), $oldRoot, $newRoot, $onWindows, true);
                $symlinksToCreate[] = [$to, $newTarget, $stats, $from];
            }
            elseif ($type === 0040000)
            {
                self::copyTree(
                    $from,
                    $to,
                    $oldRoot,
                    $newRoot,
                    $copySettings,
                    $directoriesToFinish,
                    $symlinksToCreate,
                    $progress
                );
            }
            else
            {
                $copySettings['listener']?->onFileStarting($progress, $from, $stats['size']);

                # A file the earlier attempt finished has the original's size and modification time, as that is only
                # set once the whole file has been copied. Anything else there (e.g. half a file) is copied again.
                $copiedStats = ($resumable && is_file($to) && !is_link($to)) ? lstat($to) : false;

                $isAlreadyCopied = (
                       $copiedStats !== false
                    && $copiedStats['size'] === $stats['size']
                    && $copiedStats['mtime'] === $stats['mtime']
                );

                if (!$isAlreadyCopied)
                {
                    if ($resumable)
                    {
                        self::clearForCopy($to);
                    }

                    if (!self::copyFileForMove($from, $to, $copySettings['listener'], $progress))
                    {
                        throw new FilesystemException("Failed to copy {$from} to {$to}.");
                    }
                }

                # Permissions come after ownership, as changing the owner clears the setuid and setgid bits. They are
                # set again for a file already copied, in case they have changed since.
                self::restoreOwnership($to, $stats, false, $copySettings['preserveOwnership']);
                self::setPermissions($to, $stats, $copySettings['overrideUmask']);
                self::setModificationTime($to, $stats);
                $progress = $progress->withFileMoved($stats['size']);
                $copySettings['listener']?->onFileMoved($progress, $from);
            }
        }
    }


    /**
     * Work out where a resumable copy goes, for copyThenDelete(): a hidden name made from the two
     * paths, so that a later attempt to make the same move finds it. One an earlier attempt left in
     * either place a copy can go (see chooseCopyDirectory()) is reused, as long as it is still on
     * the new filesystem, and otherwise it goes where chooseCopyDirectory() says.
     *
     * @param string $oldRoot - the directory's real path.
     *
     * @param string $newRoot - the absolute path the copy will have.
     *
     * @return string - the path for the copy.
     *
     * @throws BadArgumentException - only if given relative paths, which the callers never do.
     */
    private static function findResumableCopyPath(string $oldRoot, string $newRoot): string
    {
        $name = ".corelibs-move-" . substr(hash("sha256", "{$oldRoot}\0{$newRoot}"), 0, 32);
        $newParent = dirname($newRoot);
        $chosen = self::chooseCopyDirectory($newParent) . "/{$name}";
        $candidates = [$chosen, "{$newParent}/{$name}", rtrim(sys_get_temp_dir(), "/") . "/{$name}"];
        $found = "";

        for ($i = 0; $i < count($candidates) && $found === ""; $i++)
        {
            $candidate = $candidates[$i];
            $isUsable = is_dir($candidate) && !is_link($candidate);

            if ($isUsable && Filesystem::isOnSameFilesystem(dirname($candidate), $newParent))
            {
                $found = $candidate;
            }
        }

        return ($found === "") ? $chosen : $found;
    }


    /**
     * Check whether $newRoot is the empty directory an earlier resumable attempt made to hold the
     * name, rather than one someone else made, by comparing its device and inode numbers with
     * those that attempt recorded in its state file.
     *
     * @param string $newRoot - the path the copy will have.
     *
     * @param string $stateFile - the state file of the resumable copy.
     *
     * @return bool - true if it is that empty directory.
     */
    private static function isResumablePlaceholder(string $newRoot, string $stateFile): bool
    {
        $isEmptyDirectory = is_dir($newRoot) && !is_link($newRoot) && count(scandir($newRoot) ?: []) === 2;
        $state = is_file($stateFile) ? json_decode((string)file_get_contents($stateFile), true) : null;
        $details = $isEmptyDirectory ? lstat($newRoot) : false;

        return (
               is_array($state)
            && $details !== false
            && ($state['device'] ?? null) === $details['dev']
            && ($state['inode'] ?? null) === $details['ino']
        );
    }


    /**
     * Record which empty directory holds a resumable copy's new name (by its device and inode
     * numbers), so a later attempt can tell it is the one it made (see isResumablePlaceholder()).
     *
     * @param string $newRoot - the empty directory holding the name.
     *
     * @param string $stateFile - where to record it.
     *
     * @return void
     *
     * @throws FilesystemException - if it couldn't be recorded (the empty directory is removed again).
     */
    private static function writeResumableState(string $newRoot, string $stateFile): void
    {
        $details = lstat($newRoot);
        $state = json_encode(["device" => $details['dev'], "inode" => $details['ino']]);

        # Suppress the warning, as we throw an exception instead.
        if (@file_put_contents($stateFile, $state) === false)
        {
            @rmdir($newRoot);
            throw new FilesystemException("Failed to record the state of the resumable copy in {$stateFile}.");
        }
    }


    /**
     * Remove whatever is at a path in a resumable copy, so something else can be copied there.
     * Nothing there is fine.
     *
     * @param string $path - the path in the copy.
     *
     * @return void
     *
     * @throws FilesystemException - if it couldn't be removed.
     * @throws BadArgumentException - never, as a symlink is removed as a link.
     * @throws DirectoryDoesNotExistException - if a directory disappears while it is being removed.
     * @throws DirectoryNotDeletableException - if something in a directory there can't be removed.
     */
    private static function clearForCopy(string $path): void
    {
        if (is_dir($path) && !is_link($path))
        {
            Filesystem::deleteDir($path);
        }
        elseif ((file_exists($path) || is_link($path)) && !@unlink($path))
        {
            # Suppress the warning, as we throw an exception instead.
            throw new FilesystemException("Failed to remove {$path}, to copy over it.");
        }
    }


    /**
     * List each thing in a directory being moved on Windows with where its copy is, for
     * copyWindowsSecurity(): the directory itself, and everything in it apart from symlinks (whose
     * security rarely matters, and which can't be read without following them).
     *
     * @param string $oldRoot - the directory's real path.
     *
     * @param string $newRoot - where its copy is.
     *
     * @return array<array{0: string, 1: string}> - each original and its copy.
     *
     * @throws FilesystemException - if a directory in it can't be opened.
     * @throws DirectoryDoesNotExistException - if a directory disappears while it is being listed.
     */
    private static function windowsSecurityPairs(string $oldRoot, string $newRoot): array
    {
        $pairs = [[$oldRoot, $newRoot]];

        foreach (Filesystem::getDirContents($oldRoot, true, true, false) as $path)
        {
            if (!is_link($path))
            {
                $pairs[] = [$path, $newRoot . substr($path, strlen($oldRoot))];
            }
        }

        return $pairs;
    }


    /**
     * Give a copied directory its original's owner, group, permissions and modification time, for
     * copyThenDelete(), once everything in it has been copied.
     *
     * @param string $path - the copied directory.
     *
     * @param array $stats - lstat() of the original.
     *
     * @param array $moveSettings - see copyThenDelete().
     *
     * @return void
     *
     * @throws BadFilePermissionsException - with PreserveOwnershipEnum::YES, if its owner or group
     * couldn't be kept.
     *
     * @throws FilesystemException - if its permissions or modification time couldn't be set.
     */
    private static function finishDirectory(string $path, array $stats, array $moveSettings): void
    {
        # Permissions come after ownership, as changing the owner clears the setuid and setgid bits.
        self::restoreOwnership($path, $stats, false, $moveSettings['preserveOwnership']);
        self::setPermissions($path, $stats, $moveSettings['overrideUmask']);
        self::setModificationTime($path, $stats);
    }


    /**
     * Rename a finished copy into place, over the empty directory holding its name, for
     * copyThenDelete(). Elsewhere, rename() replaces an empty directory in one step (and fails if
     * anything was put in it). Windows can't rename over a directory, so the empty one is removed
     * first there.
     *
     * @param string $copyRoot - the finished copy, under its hidden name.
     *
     * @param string $newRoot - where it goes.
     *
     * @return void
     *
     * @throws FilesystemException - if it couldn't be renamed, e.g. because something was put in the
     * directory holding the name.
     */
    private static function renameIntoPlace(string $copyRoot, string $newRoot): void
    {
        # Suppress the warnings, as we throw an exception instead.
        $cleared = (PHP_OS_FAMILY !== "Windows") || @rmdir($newRoot);

        if (!$cleared || !@rename($copyRoot, $newRoot))
        {
            throw new FilesystemException(
                "Failed to rename the finished copy {$copyRoot} to {$newRoot} (e.g. because something was put in " .
                "{$newRoot} while it was being copied)."
            );
        }
    }


    /**
     * Remove a partial copy, for copyThenDelete(), and stop remembering it for the shutdown
     * function (see MoveCopyingTrait).
     *
     * @param string $path - the partial copy.
     *
     * @return void
     */
    private static function removePartialCopy(string $path): void
    {
        if (is_dir($path))
        {
            try
            {
                Filesystem::deleteDir($path);
            }
            catch (\Throwable $cleanUpError)
            {
                # Report the copy failing, as that is the cause. The message says what may be left behind.
            }
        }

        self::forgetUnfinishedCopy($path);
    }


    /**
     * Count the files (and symlinks) in a directory and their size, for a MoveProgress, for a move
     * that doesn't otherwise walk through it.
     *
     * @param string $directory - the directory.
     *
     * @return MoveProgress - the totals, with nothing moved yet.
     *
     * @throws FilesystemException - if a directory in it can't be opened.
     * @throws DirectoryDoesNotExistException - if a directory disappears while it is being counted.
     */
    private static function countContents(string $directory): MoveProgress
    {
        $files = 0;
        $bytes = 0;
        $toCount = [$directory];

        while (count($toCount) > 0)
        {
            $current = array_pop($toCount);

            foreach (Filesystem::getDirContents($current, false, false, false) as $entry)
            {
                $path = "{$current}/{$entry}";

                if (is_dir($path) && !is_link($path))
                {
                    $toCount[] = $path;
                }
                else
                {
                    $files++;
                    $bytes += is_link($path) ? 0 : (int)filesize($path);
                }
            }
        }

        return new MoveProgress($files, $bytes);
    }


    /**
     * Create the symlinks copyTree() put off until everything else was copied, for copyThenDelete().
     * On Windows, a symlink can only be created once its target exists, so one pointing at another
     * symlink waits until that one exists. Elsewhere, any symlink can be created, so they are all
     * created in one go.
     *
     * @param array<array{0: string, 1: string, 2: array, 3: string}> $symlinks - each symlink's path,
     * target, lstat() of the original, and the original's path.
     *
     * @param array $moveSettings - see copyThenDelete().
     *
     * @param MoveProgress $progress - how far the move has got, which this moves on for each symlink,
     * telling the listener.
     *
     * @return void
     *
     * @throws MoveCancelledException - if the listener cancelled the move.
     *
     * @throws FilesystemException - if one couldn't be created, or (on Windows) its target never
     * appears.
     */
    private static function createCopiedSymlinks(array $symlinks, array $moveSettings, MoveProgress &$progress): void
    {
        $toCreate = $symlinks;
        $madeProgress = true;

        while (count($toCreate) > 0 && $madeProgress)
        {
            $madeProgress = false;

            foreach ($toCreate as $index => [$linkPath, $target, $stats, $originalPath])
            {
                $targetPath = Filesystem::isAbsolutePath($target) ? $target : dirname($linkPath) . "/" . $target;

                if (PHP_OS_FAMILY !== "Windows" || file_exists($targetPath))
                {
                    $moveSettings['listener']?->onFileStarting($progress, $originalPath, 0);

                    if (!self::createSymlink($target, $linkPath))
                    {
                        throw new FilesystemException("Failed to create the symlink {$linkPath}.");
                    }

                    self::restoreOwnership($linkPath, $stats, true, $moveSettings['preserveOwnership']);
                    unset($toCreate[$index]);
                    $madeProgress = true;
                    $progress = $progress->withFileMoved(0);
                    $moveSettings['listener']?->onFileMoved($progress, $originalPath);
                }
            }
        }

        if (count($toCreate) > 0)
        {
            $paths = [];

            foreach ($toCreate as [$linkPath])
            {
                $paths[] = $linkPath;
            }

            throw new FilesystemException(
                "Failed to create these symlinks, as their targets don't exist: " . implode(", ", $paths)
            );
        }
    }


    /**
     * Create a symlink. On Windows, the target is given with "\\", which it needs.
     * @param string $target - where it points.
     * @param string $linkPath - where to create it.
     * @return bool - true if it was created.
     */
    private static function createSymlink(string $target, string $linkPath): bool
    {
        $systemTarget = (PHP_OS_FAMILY === "Windows") ? str_replace("/", "\\", $target) : $target;

        # Suppress the warning, as the callers throw an exception instead.
        return @symlink($systemTarget, $linkPath);
    }


    /**
     * Give a copy its original's owner and group. Only root can set the owner, and anyone else can
     * only set a group they are in, so this tries, then checks.
     *
     * @param string $path - the copy.
     *
     * @param array $stats - lstat() of the original.
     *
     * @param bool $isSymlink - whether $path is a symlink, so the link itself is changed, rather
     * than what it points to.
     *
     * @param PreserveOwnershipEnum $preserveOwnership - with NO, nothing is done. With PREFERRED, a
     * failure is ignored, leaving the copy owned by whoever is running this. With YES, a failure
     * throws (the checks should have made sure it can't happen).
     *
     * @return void
     *
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
     *
     * @param string $path - the copy.
     *
     * @param array $stats - lstat() of the original.
     *
     * @param bool $overrideUmask - whether to copy the permissions exactly.
     *
     * @return void
     *
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
     *
     * @param array $stats - lstat() of the original.
     *
     * @param bool $overrideUmask - whether to copy them exactly, rather than reducing them by the
     * umask.
     *
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
