<?php

/*
 * Exception thrown when a string could not be converted, such as when it could not be decoded.
 */

namespace Programster\CoreLibs\Exceptions;


class StringConversionFailedException extends CoreLibsException
{
    private readonly string $m_failedString;


    /**
     * Create the exception. The string that failed is deliberately kept out of the exception's
     * message, as it could be sensitive (e.g. a token), and messages commonly end up in logs.
     * Developers who need it can get it with getFailedString().
     *
     * @param string $message - the message for the exception. This should NOT include the string
     * that failed.
     *
     * @param string $failedString - the string that could not be converted.
     *
     * @param int $code - optionally specify the exception code.
     *
     * @param \Throwable|null $previous - optionally specify the previous exception, if this was
     * caused by another.
     */
    public function __construct(
        string $message,
        #[\SensitiveParameter] string $failedString,
        int $code = 0,
        ?\Throwable $previous = null
    )
    {
        parent::__construct($message, $code, $previous);
        $this->m_failedString = $failedString;
    }


    /**
     * Control what var_dump() and print_r() show for this exception, so that the string that failed
     * doesn't accidentally end up in logs or debug output, as it could be sensitive.
     *
     * @return array - the details to show when this exception is dumped.
     */
    public function __debugInfo(): array
    {
        $debugInfo = [
            'message' => $this->getMessage(),
            'code' => $this->getCode(),
            'file' => $this->getFile(),
            'line' => $this->getLine(),
            'failedString' => '[hidden - use getFailedString() if you need it]',
            'previous' => $this->getPrevious(),
        ];

        return $debugInfo;
    }


    # Accessors
    public function getFailedString(): string { return $this->m_failedString; }
}
