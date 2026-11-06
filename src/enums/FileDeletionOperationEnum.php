<?php

/*
 * The ways something can be deleted, used in the deletion plans that Filesystem::deleteDir() and emptyDir() build
 * before deleting anything. This is mainly for the library's own use.
 */

namespace Programster\CoreLibs\Enums;


enum FileDeletionOperationEnum
{
    # Delete a file or a symlink (without following it), with unlink().
    case UNLINK;

    # Delete a directory, once everything in it has been deleted, with rmdir().
    case RMDIR;
}
