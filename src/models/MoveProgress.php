<?php

/*
 * How far a File or Directory move has got, given to each method of a MoveListenerInterface. Files
 * and symlinks count as files, but directories don't, and the bytes are the files' sizes (a
 * symlink counts as 0). This never changes: as the move goes on, each event gets a new one.
 */

namespace Programster\CoreLibs\Models;


final readonly class MoveProgress
{
    private int $m_totalFiles;
    private int $m_totalBytes;
    private int $m_filesMoved;
    private int $m_bytesMoved;


    /**
     * Create the progress of a move.
     * @param int $totalFiles - how many files (and symlinks) are being moved.
     * @param int $totalBytes - how many bytes they add up to.
     * @param int $filesMoved - how many of them have been moved so far.
     * @param int $bytesMoved - how many bytes of them have been moved so far.
     */
    public function __construct(int $totalFiles, int $totalBytes, int $filesMoved = 0, int $bytesMoved = 0)
    {
        $this->m_totalFiles = $totalFiles;
        $this->m_totalBytes = $totalBytes;
        $this->m_filesMoved = $filesMoved;
        $this->m_bytesMoved = $bytesMoved;
    }


    /**
     * Get how much of the move is done, as a percentage: of the bytes, or if there are none (e.g.
     * only empty files or symlinks), of the files. A move of nothing at all counts as 100% done.
     * @return float - from 0 to 100.
     */
    public function getPercentMoved(): float
    {
        $percent = match (true) {
            $this->m_totalBytes > 0 => $this->m_bytesMoved / $this->m_totalBytes * 100,
            $this->m_totalFiles > 0 => $this->m_filesMoved / $this->m_totalFiles * 100,
            default => 100,
        };

        return (float)$percent;
    }


    /**
     * Get the progress after one more file has been moved.
     * @param int $bytes - the size of the file that was moved (0 for a symlink).
     * @return MoveProgress - the new progress. This one is unchanged.
     */
    public function withFileMoved(int $bytes): MoveProgress
    {
        return new MoveProgress(
            $this->m_totalFiles,
            $this->m_totalBytes,
            $this->m_filesMoved + 1,
            $this->m_bytesMoved + $bytes
        );
    }


    /**
     * Get the progress after more bytes of a file have been copied, part way through it.
     * @param int $bytes - how many more bytes have been copied.
     * @return MoveProgress - the new progress. This one is unchanged.
     */
    public function withBytesCopied(int $bytes): MoveProgress
    {
        return new MoveProgress(
            $this->m_totalFiles,
            $this->m_totalBytes,
            $this->m_filesMoved,
            $this->m_bytesMoved + $bytes
        );
    }


    /**
     * Get the progress once everything has been moved, for a move that does it all in one go (e.g.
     * a Directory renamed on the same filesystem).
     * @return MoveProgress - the new progress. This one is unchanged.
     */
    public function withEverythingMoved(): MoveProgress
    {
        return new MoveProgress($this->m_totalFiles, $this->m_totalBytes, $this->m_totalFiles, $this->m_totalBytes);
    }


    # Accessors
    public function getTotalFiles() : int { return $this->m_totalFiles; }
    public function getTotalBytes() : int { return $this->m_totalBytes; }
    public function getFilesMoved() : int { return $this->m_filesMoved; }
    public function getBytesMoved() : int { return $this->m_bytesMoved; }
}
