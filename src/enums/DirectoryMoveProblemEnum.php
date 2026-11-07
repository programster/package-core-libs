<?php

/*
 * Why a directory can't be moved, as given by DirectoryMoveProblem::getReason() when a Directory
 * move throws a DirectoryNotMovableException.
 */

namespace Programster\CoreLibs\Enums;


enum DirectoryMoveProblemEnum : string
{
    # A directory that has to be written to can't be: the one the directory is in, the one it is moving into, or (when
    # moving to a new parent) the directory itself, whose ".." entry is updated.
    case DIRECTORY_NOT_WRITABLE = 'directory_not_writable';

    # A directory can't be listed, so what is in it can't be checked.
    case DIRECTORY_NOT_LISTABLE = 'directory_not_listable';

    # A symlink inside needs its target updating, but the directory it is in can't be written to.
    case SYMLINK_NOT_UPDATABLE = 'symlink_not_updatable';

    # Moving to another filesystem (which means copying) with PreserveOwnershipEnum::YES needs PHP's posix extension,
    # to check who owns what.
    case POSIX_EXTENSION_MISSING = 'posix_extension_missing';

    # Something is a special file (e.g. a pipe, socket or device), which can't be copied to another filesystem.
    case SPECIAL_FILE = 'special_file';

    # A file has more than one name (hard links), so copying it to another filesystem would make separate copies.
    case HARD_LINK = 'hard_link';

    # Something belongs to another user, so a copy on another filesystem would change its owner (only root can keep
    # it). Only checked with PreserveOwnershipEnum::YES.
    case OWNED_BY_ANOTHER_USER = 'owned_by_another_user';

    # Something belongs to a group the current user isn't in, so a copy on another filesystem would change its group.
    # Only checked with PreserveOwnershipEnum::YES.
    case GROUP_NOT_A_MEMBER_OF = 'group_not_a_member_of';

    # Something can't be read, so it can't be copied to another filesystem.
    case NOT_READABLE = 'not_readable';

    # A directory can't be written to, so after copying it to another filesystem, the original couldn't be deleted.
    case ORIGINAL_NOT_DELETABLE = 'original_not_deletable';

    # On Windows, a symlink that has to be created again (to update its target, or when copying to another drive) is
    # broken, and Windows can't create a symlink to something that doesn't exist.
    case SYMLINK_NOT_CREATABLE_ON_WINDOWS = 'symlink_not_creatable_on_windows';

    # The other filesystem doesn't have enough free space for the copy.
    case NOT_ENOUGH_SPACE = 'not_enough_space';
}
