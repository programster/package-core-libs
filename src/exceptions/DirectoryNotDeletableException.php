<?php

/*
 * Exception thrown by Filesystem::deleteDir() and emptyDir() when they find, before deleting
 * anything, that some of the directory can't be deleted. Nothing has been deleted when this is
 * thrown. What can't be deleted, and why, can be got with getProblems().
 */

namespace Programster\CoreLibs\Exceptions;


use Programster\CoreLibs\Models\FileDeletionProblem;

class DirectoryNotDeletableException extends BadFilePermissionsException
{
    # A list of FileDeletionProblem objects.
    private readonly array $m_problems;


    /**
     * Create the exception.
     *
     * @param string $message - the message for the exception.
     *
     * @param array $problems - a list of FileDeletionProblem objects, one for each thing that can't
     * be deleted.
     *
     * @param int $code - optionally specify the exception code.
     *
     * @param \Throwable|null $previous - optionally specify the previous exception, if this was
     * caused by another.
     */
    public function __construct(string $message, array $problems, int $code = 0, ?\Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
        $this->m_problems = $problems;
    }


    # Accessors
    # A list of FileDeletionProblem objects, one for each thing that can't be deleted (stopping at 100).
    public function getProblems(): array { return $this->m_problems; }
}
