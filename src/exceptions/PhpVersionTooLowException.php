<?php

/*
 * Exception thrown when the version of PHP being run is lower than the version that is required.
 */

namespace Programster\CoreLibs\Exceptions;


class PhpVersionTooLowException extends CoreLibsException
{
    private readonly string $m_requiredVersion;
    private readonly string $m_currentVersion;


    /**
     * Create the exception.
     * @param string $message - the message for the exception.
     * @param string $requiredVersion - the minimum version of PHP that is required, e.g. "8.5.0".
     * @param string $currentVersion - the version of PHP being run.
     */
    public function __construct(string $message, string $requiredVersion, string $currentVersion)
    {
        parent::__construct($message);
        $this->m_requiredVersion = $requiredVersion;
        $this->m_currentVersion = $currentVersion;
    }


    # Accessors
    public function getRequiredVersion(): string { return $this->m_requiredVersion; }
    public function getCurrentVersion(): string { return $this->m_currentVersion; }
}
