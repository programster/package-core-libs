<?php

/*
 * A MoveListenerInterface whose methods do nothing, to extend, so that a listener only needs the
 * methods it wants (e.g. just onFileMoved() for a progress bar), and keeps working if more are
 * added to the interface. See MoveListenerInterface for when each is called, and how to cancel a
 * move.
 */

namespace Programster\CoreLibs\Models;


abstract class AbstractMoveListener implements MoveListenerInterface
{
    /**
     * Called once everything has been checked, just before anything is changed.
     * @param MoveProgress $progress - the totals, with nothing moved yet.
     * @return void
     */
    public function onMoveStarting(MoveProgress $progress): void
    {
        # Nothing, unless overridden.
    }


    /**
     * Called just before a file (or symlink) is copied.
     * @param MoveProgress $progress - how far the move has got, not counting this file.
     * @param string $path - the file's original path.
     * @param int $bytes - the file's size (0 for a symlink).
     * @return void
     */
    public function onFileStarting(MoveProgress $progress, string $path, int $bytes): void
    {
        # Nothing, unless overridden.
    }


    /**
     * Called after each chunk (8 MiB) of a file is copied (see MoveListenerInterface).
     * @param MoveProgress $progress - how far the move has got, including the bytes of this file
     * copied so far.
     * @param string $path - the file's original path.
     * @return void
     */
    public function onBytesCopied(MoveProgress $progress, string $path): void
    {
        # Nothing, unless overridden.
    }


    /**
     * Called once a file (or symlink) has been copied.
     * @param MoveProgress $progress - how far the move has got, counting this file.
     * @param string $path - the file's original path.
     * @return void
     */
    public function onFileMoved(MoveProgress $progress, string $path): void
    {
        # Nothing, unless overridden.
    }


    /**
     * Called once the move is complete, and the original is gone.
     * @param MoveProgress $progress - the totals, with everything moved.
     * @return void
     */
    public function onMoveFinished(MoveProgress $progress): void
    {
        # Nothing, unless overridden.
    }
}
