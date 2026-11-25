<?php

/*
 * How Filesystem::zipDir() compresses the files it puts in a zip. STORE and DEFLATE can always be used, and can be read
 * by every zip tool. BZIP2, XZ and ZSTD compress better, but can only be used if PHP's zip extension was built with
 * them (see isSupported()), and many tools can't read them (e.g. Windows' and macOS's built-in zip support), so only
 * use them where you know what will extract the zip. To use the first one that's available, pass a
 * ZipCompressionEnumCollection of them in order, e.g. ending with DEFLATE.
 */

namespace Programster\CoreLibs\Enums;

use Programster\CoreLibs\Exceptions\UnsupportedPlatformException;
use ZipArchive;


enum ZipCompressionEnum
{
    # Compress each file, which is what zips normally do, and what every zip tool can read.
    case DEFLATE;

    # Store each file as it is, without compressing it. Much quicker, and the zip is barely bigger, for files that are
    # already compressed, such as images, videos, or .gz and .zip files.
    case STORE;

    # Bzip2: usually smaller than DEFLATE, but slower, and fewer tools can read it.
    case BZIP2;

    # XZ (LZMA2): usually the smallest, but the slowest, and few tools can read it.
    case XZ;

    # Zstandard: smaller than DEFLATE, and fast, but few tools can read it yet.
    case ZSTD;


    /**
     * Get whether this PHP's zip extension can compress with this. STORE and DEFLATE always can. Needs the zip
     * extension.
     */
    public function isSupported(): bool
    {
        $constantName = $this->getConstantName();
        $isAlwaysSupported = ($this === self::STORE || $this === self::DEFLATE);

        $canCheck = (
               defined($constantName)
            && method_exists(ZipArchive::class, 'isCompressionMethodSupported')
        );

        return (
               $isAlwaysSupported
            || ($canCheck && ZipArchive::isCompressionMethodSupported(constant($constantName), true))
        );
    }


    /**
     * Get the ZipArchive::CM_* constant for this.
     * @throws UnsupportedPlatformException - if this PHP's zip extension can't compress with it (see isSupported()).
     */
    public function getZipArchiveMethod(): int
    {
        if (!$this->isSupported())
        {
            throw new UnsupportedPlatformException(
                "This PHP's zip extension wasn't built with {$this->name} compression, so it can't be used. Use " .
                "DEFLATE (which every zip tool can read), or a ZipCompressionEnumCollection that falls back to it."
            );
        }

        return constant($this->getConstantName());
    }


    /**
     * Get the name of the ZipArchive::CM_* constant for this, which may not exist, depending on how PHP was built.
     */
    private function getConstantName(): string
    {
        return "ZipArchive::CM_" . $this->name;
    }
}
