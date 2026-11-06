<?php

/*
 * Why something can't be deleted, as given by FileDeletionProblem::$reason when Filesystem::deleteDir() or
 * emptyDir() throws a DirectoryNotDeletableException.
 */

namespace Programster\CoreLibs\Enums;


enum FileDeletionProblemEnum : string
{
    # The directory can't be read or opened, so its contents can't be found to delete them.
    case DIRECTORY_NOT_LISTABLE = 'directory_not_listable';

    # The directory the path is in can't be written to, and removing something from a directory means writing to it.
    case DIRECTORY_NOT_WRITABLE = 'directory_not_writable';

    # The directory the path is in is "sticky" (e.g. /tmp), so only the path's owner, the directory's owner, or root
    # can delete it, and the current user is none of those.
    case NOT_OWNED_IN_STICKY_DIRECTORY = 'not_owned_in_sticky_directory';
}
