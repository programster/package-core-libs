<?php

/*
 * Where Filesystem::createReplacementFile() puts the temporary file that a file's new contents are
 * written to, before they replace the original.
 */

namespace Programster\CoreLibs\Enums;


enum UseSystemTempDirEnum
{
    # Always use the system's temporary directory (e.g. /tmp). Nothing appears next to the original while it is being
    # written. If it is on the same filesystem as the original, Filesystem::replaceWithFile() can rename it over the
    # original where that is safe (not with SELinux enabled), and otherwise copies the new contents back into the
    # original, which takes as long as writing them did.
    case ALWAYS;

    # Never use the system's temporary directory: put the temporary file next to the original, as a hidden file. This
    # lets Filesystem::replaceWithFile() rename it over the original, which is instant and atomic, where that is safe.
    # It is briefly visible in that directory, so avoid this for a directory that is served to the public.
    case NEVER;

    # Use the system's temporary directory if it is on the same filesystem as the original, and otherwise put the
    # temporary file next to the original (as for NEVER), e.g. so a very large file isn't written to another disk.
    case WHEN_SAME_FS;

    # Choose for you: first whatever will work, then whatever is fastest, then the system's temporary directory if
    # that makes no difference to either. So it uses the system's temporary directory if it is held in memory (a tmpfs,
    # e.g. /tmp on Debian 13) and there is room for it, as copying back from there is about as fast as renaming, or if
    # it is on the same filesystem as the original and the file could be renamed from there. Otherwise it goes next to
    # the original if Filesystem::replaceWithFile() could then rename it over the original (the fastest way), and
    # otherwise in the system's temporary directory, unless that hasn't enough room (judged by the original's current
    # size), in which case next to the original again.
    case AUTO;
}
