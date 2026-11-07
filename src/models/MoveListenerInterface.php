<?php

/*
 * Something told how a File or Directory move is going, e.g. to show a progress bar. Pass one as
 * the $listener of File::move(), File::rename(), Directory::move() or Directory::rename(). Extend
 * AbstractMoveListener rather than implementing this directly, so you only need the methods you
 * want, and your listener keeps working if more are added here.
 *
 * To cancel a move, throw a MoveCancelledException from any of these (apart from
 * onMoveFinished(), as the move has already happened by then). What was copied so far is removed,
 * the original is left as it was, and your exception is thrown on to whoever called the move.
 * Any other exception stops the move in the same way, but is wrapped in a FilesystemException
 * part way through a copy, like any other failure there.
 *
 * A move within one filesystem renames everything in one go, so only onMoveStarting() and
 * onMoveFinished() are called. A move to another filesystem copies each file, and calls
 * onFileStarting() and onFileMoved() for each one too, and onBytesCopied() during each big
 * file. With a listener, files are copied in chunks for that, which is about as fast as copying
 * them whole. Nothing is called if the path doesn't change.
 */

namespace Programster\CoreLibs\Models;


interface MoveListenerInterface
{
    /**
     * Called once everything has been checked, just before anything is changed.
     * @param MoveProgress $progress - the totals, with nothing moved yet.
     * @return void
     */
    public function onMoveStarting(MoveProgress $progress): void;


    /**
     * Called just before a file (or symlink) is copied.
     * @param MoveProgress $progress - how far the move has got, not counting this file.
     * @param string $path - the file's original path.
     * @param int $bytes - the file's size (0 for a symlink).
     * @return void
     */
    public function onFileStarting(MoveProgress $progress, string $path, int $bytes): void;


    /**
     * Called after each chunk (8 MiB) of a file is copied, so progress can be shown during a big
     * file. Only called when copying to another filesystem, and not after the last chunk, as
     * onFileMoved() is called then.
     *
     * @param MoveProgress $progress - how far the move has got, including the bytes of this file
     * copied so far.
     *
     * @param string $path - the file's original path.
     *
     * @return void
     */
    public function onBytesCopied(MoveProgress $progress, string $path): void;


    /**
     * Called once a file (or symlink) has been copied.
     * @param MoveProgress $progress - how far the move has got, counting this file.
     * @param string $path - the file's original path.
     * @return void
     */
    public function onFileMoved(MoveProgress $progress, string $path): void;


    /**
     * Called once the move is complete, and the original is gone.
     * @param MoveProgress $progress - the totals, with everything moved.
     * @return void
     */
    public function onMoveFinished(MoveProgress $progress): void;
}
