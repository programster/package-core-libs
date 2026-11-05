<?php

/*
 * Exception thrown when a shell command could not be run, or exited with a non-zero exit code.
 */

namespace Programster\CoreLibs\Exceptions;


class ExceptionCommandFailed extends \Exception
{
    private readonly string $m_command;
    private readonly int $m_exitCode;
    private readonly string $m_errorOutput;


    /**
     * Create the exception.
     * @param string $command - the command that failed.
     * @param int $exitCode - the exit code the command returned, or -1 if it could not be run at all.
     * @param string $errorOutput - anything the command wrote to stderr.
     */
    public function __construct(string $command, int $exitCode, string $errorOutput)
    {
        $message = ($exitCode === -1)
            ? "Failed to run command: {$command}"
            : "Command exited with code {$exitCode}: {$command}";

        if (trim($errorOutput) !== "")
        {
            $message .= PHP_EOL . trim($errorOutput);
        }

        parent::__construct($message);
        $this->m_command = $command;
        $this->m_exitCode = $exitCode;
        $this->m_errorOutput = $errorOutput;
    }


    # Accessors
    public function getCommand() : string { return $this->m_command; }
    public function getExitCode() : int { return $this->m_exitCode; }
    public function getErrorOutput() : string { return $this->m_errorOutput; }
}
