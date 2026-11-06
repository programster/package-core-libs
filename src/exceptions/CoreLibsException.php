<?php

/*
 * The base class for every exception that this library throws, so that they can all be caught at
 * once, e.g. catch (CoreLibsException $e). It extends \Exception, so existing catch (\Exception $e)
 * blocks still work.
 */

namespace Programster\CoreLibs\Exceptions;


abstract class CoreLibsException extends \Exception
{
}
