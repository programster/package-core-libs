<?php

/*
 * The kind of advisory lock Filesystem::lockFile() and tryLockFile() take on a file.
 */

namespace Programster\CoreLibs\Enums;


enum FileLockTypeEnum
{
    # Only one process at a time can have it, and not while any other has a shared lock: for writing the file, or for
    # making sure only one copy of something runs at once.
    case EXCLUSIVE;

    # Any number of processes can have it at once, but not while one has an exclusive lock: for reading the file
    # without anyone writing to it in the meantime.
    case SHARED;
}
