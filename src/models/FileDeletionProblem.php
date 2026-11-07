<?php

/*
 * Something that stops a directory being deleted, and why. See
 * DirectoryNotDeletableException::getProblems(). This only holds those details, so its properties
 * are public.
 */

namespace Programster\CoreLibs\Models;


use Programster\CoreLibs\Enums\FileDeletionProblemEnum;

final readonly class FileDeletionProblem
{
    # The file or directory that can't be deleted. For DIRECTORY_NOT_LISTABLE, this is the directory that can't be
    # listed, so its contents can't be deleted.
    public string $path;

    # What has the permissions that cause the problem: the directory $path is in, or $path itself for
    # DIRECTORY_NOT_LISTABLE and READ_ONLY_ON_WINDOWS. This is usually the one to change the permissions of to fix it.
    public string $directory;

    # Why it can't be deleted.
    public FileDeletionProblemEnum $reason;


    /**
     * Create the details of something that can't be deleted.
     * @param string $path - the file or directory that can't be deleted (or listed).
     * @param string $directory - the directory whose permissions cause the problem.
     * @param FileDeletionProblemEnum $reason - why it can't be deleted.
     */
    public function __construct(string $path, string $directory, FileDeletionProblemEnum $reason)
    {
        $this->path = $path;
        $this->directory = $directory;
        $this->reason = $reason;
    }
}
