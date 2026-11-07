<?php

/*
 * How hard to try to keep a file's owner and group when changing or moving it. Keeping them can
 * mean taking a slower route (e.g. copying data back into the original file, rather than renaming
 * a new file over it), especially when not running as root, as only root can give a file to
 * another user.
 *
 * On Windows, a copy on another drive always belongs to whoever makes it, with the ACLs of the
 * directory it is copied into, and PHP can't read or copy the original's. So there, YES refuses any
 * move that has to copy (i.e. to another drive), and PREFERRED and NO allow the owner to change.
 */

namespace Programster\CoreLibs\Enums;


enum PreserveOwnershipEnum
{
    # Don't worry about the owner or group: take the fastest route, even if the file ends up belonging to whoever is
    # running this.
    case NO;

    # Keep the owner and group if there is any way to, even if that is slower, but go ahead without keeping them if
    # there isn't.
    case PREFERRED;

    # Keep the owner and group, taking the fastest route that does. If there is no way to keep them, throw an
    # exception rather than changing anything.
    case YES;
}
