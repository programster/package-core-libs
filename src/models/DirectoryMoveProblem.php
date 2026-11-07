<?php

/*
 * Something that stops a directory being moved, and why. See
 * DirectoryNotMovableException::getProblems().
 */

namespace Programster\CoreLibs\Models;


use Programster\CoreLibs\Enums\DirectoryMoveProblemEnum;

final readonly class DirectoryMoveProblem
{
    private string $m_path;
    private DirectoryMoveProblemEnum $m_reason;
    private string $m_description;


    /**
     * Create the details of something that stops a directory being moved.
     * @param string $path - the file or directory with the problem (for NOT_ENOUGH_SPACE, the
     * directory on the other filesystem whose free space was checked).
     * @param DirectoryMoveProblemEnum $reason - why it stops the move.
     * @param string $description - a description of the problem, for people, e.g. "/srv/a.txt is
     * owned by user 0, so a copy (owned by user 1000) would change its owner".
     */
    public function __construct(string $path, DirectoryMoveProblemEnum $reason, string $description)
    {
        $this->m_path = $path;
        $this->m_reason = $reason;
        $this->m_description = $description;
    }


    # Accessors
    public function getPath() : string { return $this->m_path; }
    public function getReason() : DirectoryMoveProblemEnum { return $this->m_reason; }
    public function getDescription() : string { return $this->m_description; }
}
