<?php

/*
 * Exception to throw from a MoveListenerInterface method to cancel a File or Directory move. What
 * was copied so far is removed, the original is left as it was, and this same exception is then
 * thrown on to whoever called the move, rather than being wrapped in another one.
 */

namespace Programster\CoreLibs\Exceptions;


class MoveCancelledException extends CoreLibsException
{
}
