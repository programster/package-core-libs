<?php

/*
 * Path helpers for working out where symlinks point, shared by File and Directory, which both have
 * to keep symlinks pointing at the right place when they move them.
 */

namespace Programster\CoreLibs\Models;


trait SymlinkPathsTrait
{
    /**
     * Work out the absolute path a symlink target points to, as the operating system would. The
     * directory part is resolved with realpath() where it exists, as the system follows any
     * symlinks in it before applying "..". Where it doesn't exist (e.g. for a broken link), it is
     * worked out from the path alone.
     * @param string $linkDirectory - the real path of the directory the symlink is in.
     * @param string $linkTarget - the symlink's target, e.g. "../shared/data.txt". An absolute
     * target is resolved in the same way, ignoring $linkDirectory.
     * @return string - the absolute path it points to, e.g. "/srv/shared/data.txt".
     */
    private static function resolveLinkTarget(string $linkDirectory, string $linkTarget): string
    {
        # Don't tidy the ".." parts out first: for "sub/../x.txt" where "sub" is itself a symlink, the
        # system goes up from wherever "sub" leads, which only realpath() on the path as written gets right.
        $joined = str_starts_with($linkTarget, "/") ? $linkTarget : "{$linkDirectory}/{$linkTarget}";
        $realParent = realpath(dirname($joined));

        return ($realParent === false)
            ? self::normalisePath($joined)
            : self::normalisePath($realParent . "/" . basename($joined));
    }


    /**
     * Build the relative path from one directory to a path, e.g. from "/srv/a/b" to "/srv/c/d.txt"
     * gives "../../c/d.txt".
     * @param string $fromDirectory - the absolute path of the directory to start from.
     * @param string $toPath - the absolute path to get to.
     * @return string - the relative path, or "." if they are the same.
     */
    private static function relativePathBetween(string $fromDirectory, string $toPath): string
    {
        $fromParts = array_values(array_filter(explode("/", self::normalisePath($fromDirectory)), 'strlen'));
        $toParts = array_values(array_filter(explode("/", self::normalisePath($toPath)), 'strlen'));
        $commonCount = 0;

        while (
            $commonCount < count($fromParts)
            && $commonCount < count($toParts)
            && $fromParts[$commonCount] === $toParts[$commonCount]
        )
        {
            $commonCount++;
        }

        $upParts = array_fill(0, count($fromParts) - $commonCount, "..");
        $relativeParts = array_merge($upParts, array_slice($toParts, $commonCount));

        return (count($relativeParts) === 0) ? "." : implode("/", $relativeParts);
    }


    /**
     * Tidy an absolute path from the path alone (without checking the filesystem), removing "."
     * parts, applying ".." parts, and removing repeated or trailing slashes, e.g. "/a/./b/../c/"
     * gives "/a/c".
     * @param string $path - the absolute path to tidy.
     * @return string - the tidied path.
     */
    private static function normalisePath(string $path): string
    {
        $parts = [];

        foreach (explode("/", $path) as $part)
        {
            if ($part === ".." && count($parts) > 0)
            {
                array_pop($parts);
            }
            elseif ($part !== "" && $part !== "." && $part !== "..")
            {
                $parts[] = $part;
            }
        }

        return "/" . implode("/", $parts);
    }


    /**
     * Check whether a path is a directory, or is inside it, from the paths alone.
     * @param string $path - the absolute path to check.
     * @param string $directory - the absolute path of the directory.
     * @return bool - true if $path is $directory, or anywhere inside it.
     */
    private static function isWithinDirectory(string $path, string $directory): bool
    {
        # Compare with a trailing slash, so that e.g. /a/bc doesn't count as being inside /a/b.
        $path = self::normalisePath($path);
        $directory = self::normalisePath($directory);

        return $path === $directory || str_starts_with($path . "/", rtrim($directory, "/") . "/");
    }
}
