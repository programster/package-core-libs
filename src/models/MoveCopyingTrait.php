<?php

/*
 * What File and Directory share for moves to another filesystem, where everything has to be
 * copied: where to copy it to, so that it only has to be copied once, and removing an unfinished
 * copy if PHP stops part way (e.g. at a timeout). These are private to each class that uses this.
 */

namespace Programster\CoreLibs\Models;

use Programster\CoreLibs\Exceptions\BadArgumentException;
use Programster\CoreLibs\Exceptions\MoveCancelledException;
use Programster\CoreLibs\Filesystem;


trait MoveCopyingTrait
{
    # The unfinished copies to remove if PHP stops before the move finishes, as path => whether to only remove it if it
    # is empty (for the empty folder that holds the destination's name until the copy is renamed into place).
    private static array $s_unfinishedCopies = [];
    private static bool $s_isCleanUpRegistered = false;

    # With a listener, a file is copied in chunks of this many bytes, telling it after each one.
    private const int COPY_CHUNK_BYTES = 8 * 1024 * 1024;


    /**
     * Check a new name for rename() or move()'s $newName: it must be just a name, not a path, as
     * rename() only changes the name, and move() says where it goes.
     * @param string $name - the new name.
     * @param string $method - the method it was given to, for the message.
     * @return void
     * @throws BadArgumentException - if it is empty, ".", "..", or has a "/" in it (or a "\\" on
     * Windows, where that is a separator too), or a null byte.
     */
    private static function checkIsName(string $name, string $method): void
    {
        $hasSeparator = str_contains($name, "/") || (PHP_OS_FAMILY === "Windows" && str_contains($name, "\\"));

        if ($name === "" || $name === "." || $name === ".." || $hasSeparator || str_contains($name, "\0"))
        {
            throw new BadArgumentException(
                "{$method}: \"{$name}\" must be just a new name, not a path. To put it in another directory, use " .
                "move(), which can also give it a new name there."
            );
        }
    }


    /**
     * Choose where to copy something being moved to another filesystem, before it is renamed into
     * place: the system's temporary directory (e.g. /tmp) if it is on the destination's
     * filesystem, so nothing appears in the destination until it is complete, and otherwise
     * hidden beside the destination. Either way, the copy is on the destination's filesystem, so
     * renaming it into place is instant, and it is only ever copied once. Never the system's
     * temporary directory with SELinux enabled (a file renamed from there would keep its label,
     * so could be refused access where it ends up), or on Windows (where it would keep the
     * temporary directory's permissions, rather than getting the destination's, as a copy does).
     * @param string $destinationDirectory - the real path of the directory it is being moved into.
     * @return string - the directory to copy it into.
     * @throws BadArgumentException - only if given a relative path, which the callers never do.
     */
    private static function chooseCopyDirectory(string $destinationDirectory): string
    {
        $systemTmpDirectory = sys_get_temp_dir();

        $useSystemTmpDirectory = (
               PHP_OS_FAMILY !== "Windows"
            && is_writable($systemTmpDirectory)
            && Filesystem::canEnterDirectory($systemTmpDirectory)
            && !Filesystem::isSelinuxEnabled()
            && Filesystem::isOnSameFilesystem($systemTmpDirectory, $destinationDirectory)
        );

        return $useSystemTmpDirectory ? rtrim($systemTmpDirectory, "/") : $destinationDirectory;
    }


    /**
     * Copy a file for a move to another filesystem. Without a listener, this is plain copy(), as it
     * always was. With one, the file is copied in chunks (see COPY_CHUNK_BYTES), and the listener
     * told after each one but the last (onBytesCopied()), so it can show progress during a big file,
     * or cancel part way. PHP still uses its fast copying (e.g. copy_file_range() on Linux) for each
     * chunk, so this is about as fast. Anything already at $to is replaced, as copy() does.
     * @param string $from - the file to copy.
     * @param string $to - where to copy it.
     * @param MoveListenerInterface|null $listener - what to tell, or null.
     * @param MoveProgress $progress - how far the move has got before this file.
     * @return bool - true if it was copied (a partial copy may be left if not, for the caller to
     * remove).
     * @throws MoveCancelledException - if the listener cancelled the move part way through the file
     * (the partial copy is left for the caller to remove).
     */
    private static function copyFileForMove(
        string $from,
        string $to,
        ?MoveListenerInterface $listener,
        MoveProgress $progress
    ): bool
    {
        $copied = false;

        if ($listener === null)
        {
            # Suppress the warning, as the callers throw an exception instead.
            $copied = @copy($from, $to);
        }
        else
        {
            # Suppress the warnings, as the callers throw an exception instead.
            $source = @fopen($from, "rb");
            $destination = ($source === false) ? false : @fopen($to, "wb");

            try
            {
                $copied = ($source !== false && $destination !== false);
                $copiedBytes = 0;
                $size = (int)filesize($from);

                while ($copied && $copiedBytes < $size)
                {
                    $chunk = stream_copy_to_stream($source, $destination, self::COPY_CHUNK_BYTES);
                    $copied = ($chunk !== false && $chunk > 0);
                    $copiedBytes += ($chunk === false) ? 0 : $chunk;

                    # After the last chunk, the caller says the file has been moved instead.
                    if ($copied && $copiedBytes < $size)
                    {
                        $listener->onBytesCopied($progress->withBytesCopied($copiedBytes), $from);
                    }
                }

                $copied = $copied && fflush($destination);
            }
            finally
            {
                if ($destination !== false)
                {
                    $copied = fclose($destination) && $copied;
                }

                if ($source !== false)
                {
                    fclose($source);
                }
            }
        }

        return $copied;
    }


    /**
     * Remember an unfinished copy, so it is removed if PHP stops before the move finishes (e.g.
     * at a timeout, as PHP still runs shutdown functions then). This can't help if the process is
     * killed (e.g. with Ctrl+C), but the copy has a hidden name, so it can't be mistaken for a
     * finished one.
     * @param string $path - the unfinished copy (a file, symlink or directory).
     * @param bool $onlyIfEmpty - true to only remove it if it is an empty directory.
     * @return void
     */
    private static function rememberUnfinishedCopy(string $path, bool $onlyIfEmpty): void
    {
        if (!self::$s_isCleanUpRegistered)
        {
            register_shutdown_function(self::removeUnfinishedCopies(...));
            self::$s_isCleanUpRegistered = true;
        }

        self::$s_unfinishedCopies[$path] = $onlyIfEmpty;
    }


    /**
     * Stop remembering a copy, once it is finished (or has been removed some other way).
     * @param string $path - the copy.
     * @return void
     */
    private static function forgetUnfinishedCopy(string $path): void
    {
        unset(self::$s_unfinishedCopies[$path]);
    }


    /**
     * Remove the copies that were never finished, when PHP stops. This is best effort: if PHP
     * stopped at a timeout during a very large copy, it may not have time to remove it all.
     * @return void
     */
    private static function removeUnfinishedCopies(): void
    {
        foreach (self::$s_unfinishedCopies as $path => $onlyIfEmpty)
        {
            # Suppress the warnings, as nothing can be reported this late.
            if ($onlyIfEmpty)
            {
                @rmdir($path);
            }
            elseif (is_dir($path) && !is_link($path))
            {
                try
                {
                    Filesystem::deleteDir($path);
                }
                catch (\Throwable $e)
                {
                    # Nothing can be reported this late, and the copy's name is hidden.
                }
            }
            else
            {
                @unlink($path);
            }
        }

        self::$s_unfinishedCopies = [];
    }
}
