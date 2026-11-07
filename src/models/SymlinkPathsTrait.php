<?php

/*
 * Path helpers for working out where paths and symlinks point, shared by File, Directory and
 * Filesystem, which have to keep symlinks pointing at the right place when they move them.
 */

namespace Programster\CoreLibs\Models;


trait SymlinkPathsTrait
{
    /**
     * Check whether a path is absolute, rather than relative to some other directory: one starting
     * with "/". On Windows, this also accepts the absolute forms of a Windows path: one starting
     * with a drive letter (e.g. "C:\data" or "C:/data"), a UNC path (e.g. "\\server\share"), or
     * one starting with "\" (the root of the current drive). A drive letter without a slash
     * after it (e.g. "C:data") is relative to that drive's current directory, so isn't absolute.
     * Elsewhere, "C:\data" and "\data" are just unusual relative names, so they aren't absolute.
     * @param string $path - the path to check.
     * @return bool - true if it is absolute.
     */
    private static function isAbsolutePath(string $path): bool
    {
        $isWindowsAbsolute = PHP_OS_FAMILY === "Windows" && self::isWindowsAbsolutePath($path);
        return str_starts_with($path, "/") || $isWindowsAbsolute;
    }


    /**
     * Check whether a path is in one of the absolute forms of a Windows path (see isAbsolutePath()),
     * whatever system this is running on.
     * @param string $path - the path to check.
     * @return bool - true if it starts with a drive letter and a slash, or with "\".
     */
    private static function isWindowsAbsolutePath(string $path): bool
    {
        return str_starts_with($path, "\\") || preg_match('/^[A-Za-z]:[\\\\\/]/', $path) === 1;
    }


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
        $joined = self::isAbsolutePath($linkTarget) ? $linkTarget : "{$linkDirectory}/{$linkTarget}";
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
