<?php

/*
 * Exception thrown when something won't fit in the free space on a disk, e.g. a zip that unzip() would extract, so it
 * is refused before anything is written, rather than filling the disk part way through.
 */

namespace Programster\CoreLibs\Exceptions;

use Programster\CoreLibs\Enums\DataSizeUnitEnum;


class NotEnoughDiskSpaceException extends FilesystemException
{
    private readonly string $m_path;
    private readonly int $m_neededBytes;
    private readonly int $m_freeBytes;


    /**
     * @param string $path - where it would have been written.
     * @param int $neededBytes - how much space it needs, in bytes.
     * @param int $freeBytes - how much space is free there, in bytes.
     */
    public function __construct(string $path, int $neededBytes, int $freeBytes)
    {
        $needed = DataSizeUnitEnum::formatBytes($neededBytes);
        $free = DataSizeUnitEnum::formatBytes($freeBytes);
        parent::__construct("There isn't enough free space at {$path}: {$needed} is needed, but only {$free} is free.");
        $this->m_path = $path;
        $this->m_neededBytes = $neededBytes;
        $this->m_freeBytes = $freeBytes;
    }


    # Accessors
    public function getPath(): string { return $this->m_path; }
    public function getNeededBytes(): int { return $this->m_neededBytes; }
    public function getFreeBytes(): int { return $this->m_freeBytes; }
}
