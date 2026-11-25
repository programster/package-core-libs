<?php

/*
 * Exception thrown when a zip would extract to more than the most that unzip() was told to allow, so it is refused
 * before anything is written. A small zip can extract to something far larger (a "zip bomb").
 */

namespace Programster\CoreLibs\Exceptions;

use Programster\CoreLibs\Enums\DataSizeUnitEnum;


class ZipTooLargeException extends FilesystemException
{
    private readonly string $m_zipPath;
    private readonly int $m_extractedBytes;
    private readonly int $m_maxBytes;


    /**
     * @param string $zipPath - the zip.
     * @param int $extractedBytes - how big its contents would be once extracted, in bytes.
     * @param int $maxBytes - the most that was allowed, in bytes.
     */
    public function __construct(string $zipPath, int $extractedBytes, int $maxBytes)
    {
        $extracted = DataSizeUnitEnum::formatBytes($extractedBytes);
        $max = DataSizeUnitEnum::formatBytes($maxBytes);
        parent::__construct("{$zipPath} would extract to {$extracted}, which is more than the {$max} allowed.");
        $this->m_zipPath = $zipPath;
        $this->m_extractedBytes = $extractedBytes;
        $this->m_maxBytes = $maxBytes;
    }


    # Accessors
    public function getZipPath(): string { return $this->m_zipPath; }
    public function getExtractedBytes(): int { return $this->m_extractedBytes; }
    public function getMaxBytes(): int { return $this->m_maxBytes; }
}
