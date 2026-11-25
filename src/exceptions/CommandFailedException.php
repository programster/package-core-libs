<?php

/*
 * Exception thrown when a shell command could not be run, or exited with a non-zero exit code.
 */

namespace Programster\CoreLibs\Exceptions;


class CommandFailedException extends CoreLibsException
{
    private readonly string $m_command;
    private readonly int $m_exitCode;
    private readonly string $m_errorOutput;
    private readonly string $m_output;


    /**
     * Create the exception. What the command wrote to stdout is deliberately kept out of the message (and out of
     * var_dump() and print_r()), as it could be sensitive (e.g. a password or a token), and messages commonly end up
     * in logs. Developers who need it, e.g. because the command reports its errors on stdout, as PHP's command line
     * does, can get it with getOutput().
     *
     * @param string $command - the command that failed.
     *
     * @param int $exitCode - the exit code the command returned, or -1 if it could not be run at all.
     *
     * @param string $errorOutput - anything the command wrote to stderr.
     *
     * @param string $output - anything the command wrote to stdout before it failed. Defaults to "".
     */
    public function __construct(
        string $command,
        int $exitCode,
        string $errorOutput,
        #[\SensitiveParameter] string $output = ""
    )
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
        $this->m_output = $output;
    }


    /**
     * Control what var_dump() and print_r() show for this exception, so that what the command wrote to stdout doesn't
     * accidentally end up in logs or debug output, as it could be sensitive.
     *
     * @return array<string, mixed> - the details to show when this exception is dumped.
     */
    public function __debugInfo(): array
    {
        $debugInfo = [
            'message' => $this->getMessage(),
            'code' => $this->getCode(),
            'file' => $this->getFile(),
            'line' => $this->getLine(),
            'command' => $this->m_command,
            'exitCode' => $this->m_exitCode,
            'errorOutput' => $this->m_errorOutput,
            'output' => '[hidden - use getOutput() if you need it]',
            'previous' => $this->getPrevious(),
        ];

        return $debugInfo;
    }


    # Accessors
    public function getCommand(): string { return $this->m_command; }
    public function getExitCode(): int { return $this->m_exitCode; }
    public function getErrorOutput(): string { return $this->m_errorOutput; }
    public function getOutput(): string { return $this->m_output; }
}
