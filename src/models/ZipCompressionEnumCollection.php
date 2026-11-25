<?php

/*
 * Compression methods for Filesystem::zipDir() to try in order, using the first one this PHP's zip extension can,
 * e.g. new ZipCompressionEnumCollection(ZipCompressionEnum::ZSTD, ZipCompressionEnum::DEFLATE) to use Zstandard where
 * it's available, and otherwise DEFLATE. It always has at least one, so it can't be made empty by mistake.
 */

namespace Programster\CoreLibs\Models;

use ArrayIterator;
use Countable;
use IteratorAggregate;
use Programster\CoreLibs\Enums\ZipCompressionEnum;
use Programster\CoreLibs\Exceptions\UnsupportedPlatformException;
use Traversable;


final readonly class ZipCompressionEnumCollection implements IteratorAggregate, Countable
{
    /** @var ZipCompressionEnum[] */
    private array $m_methods;


    /**
     * @param ZipCompressionEnum $first - the method to use if it can be.
     * @param ZipCompressionEnum ...$fallbacks - the ones to try after it, in order.
     */
    public function __construct(ZipCompressionEnum $first, ZipCompressionEnum ...$fallbacks)
    {
        $this->m_methods = [$first, ...$fallbacks];
    }


    /**
     * Get the first method this PHP's zip extension can compress with. Needs the zip extension.
     * @throws UnsupportedPlatformException - if it can't use any of them, listing them.
     */
    public function getFirstSupported(): ZipCompressionEnum
    {
        $supported = null;

        foreach ($this->m_methods as $method)
        {
            if ($supported === null && $method->isSupported())
            {
                $supported = $method;
            }
        }

        if ($supported === null)
        {
            $names = [];

            foreach ($this->m_methods as $method)
            {
                $names[] = $method->name;
            }

            throw new UnsupportedPlatformException(
                "This PHP's zip extension wasn't built with any of these compression methods: " .
                implode(", ", $names) . ". End the list with DEFLATE, which can always be used."
            );
        }

        return $supported;
    }


    /**
     * @return Traversable<int, ZipCompressionEnum>
     */
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->m_methods);
    }


    public function count(): int
    {
        return count($this->m_methods);
    }
}
