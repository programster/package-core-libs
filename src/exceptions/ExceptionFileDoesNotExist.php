<?php

/*
 * An exception to throw if a file does not exist.
 */

namespace Programster\CoreLibs\Exceptions;

class ExceptionFileDoesNotExist extends Exception
{
    private string $m_filepath;


    public function __construct(string $filepath, string $message = "", int $code = 0, ?Throwable $previous = null)
    {
        $this->m_filepath = $filepath;

        if ($message === "")
        {
            $message = "The file at '{$filepath}' could not be found.";
        }

        parent::__construct($message, $code, $previous);
    }

    
    # Accessors
    public function getFilepath() : string { return $this->m_filepath; }
}

