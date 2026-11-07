<?php

/*
 * Exception thrown by Directory::rename() and move() (and Filesystem::moveDir()) when the checks
 * they make before moving anything find something that would stop the move. Nothing has been moved
 * when this is thrown. What the problems are can be got with getProblems(). This extends
 * BadFilePermissionsException, as most of the problems are about permissions, and so that code
 * written to catch that still catches this.
 */

namespace Programster\CoreLibs\Exceptions;


use Programster\CoreLibs\Models\DirectoryMoveProblem;

class DirectoryNotMovableException extends BadFilePermissionsException
{
    # A list of DirectoryMoveProblem objects.
    private readonly array $m_problems;


    /**
     * Create the exception.
     * @param string $message - the message for the exception.
     * @param array<DirectoryMoveProblem> $problems - one for each problem found.
     * @param int $code - optionally specify the exception code.
     * @param \Throwable|null $previous - optionally specify the previous exception, if this was
     * caused by another.
     */
    public function __construct(string $message, array $problems, int $code = 0, ?\Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
        $this->m_problems = $problems;
    }


    # Accessors
    # A list of DirectoryMoveProblem objects, one for each problem found (stopping at 100).
    public function getProblems(): array { return $this->m_problems; }
}
