<?php

/*
 * Exception thrown when a method is given an empty array, but needs at least one element to work on, such as
 * ArrayLib::getFirstElement().
 * This extends BadArgumentException, so it can be caught specifically, or more broadly.
 */

namespace Programster\CoreLibs\Exceptions;


class EmptyArrayException extends BadArgumentException
{
}
