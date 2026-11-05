<?php

/*
 * Exception thrown when something could not be decrypted, e.g. because the key is wrong, or the encrypted data has been
 * tampered with or damaged.
 */

namespace Programster\CoreLibs\Exceptions;


class DecryptionFailedException extends CoreLibsException
{
}
