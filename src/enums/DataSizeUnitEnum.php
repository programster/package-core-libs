<?php

/*
 * Units that a size of data can be expressed in, such as an amount of memory or the size of a file.
 * Includes both the binary (base 2, e.g. MiB) units and the decimal (base 10, e.g. MB) units.
 */

namespace Programster\CoreLibs\Enums;


enum DataSizeUnitEnum : string
{
    case BYTES = 'B';

    # Binary (base 2) units. This is how memory is normally measured (e.g. what the "free" command reports), and what
    # many operating systems use for file sizes.
    case KIB = 'KiB';
    case MIB = 'MiB';
    case GIB = 'GiB';
    case TIB = 'TiB';
    case PIB = 'PiB';
    case EIB = 'EiB';

    # Decimal (base 10) units. This is how storage manufacturers normally measure disk sizes.
    case KB = 'kB';
    case MB = 'MB';
    case GB = 'GB';
    case TB = 'TB';
    case PB = 'PB';
    case EB = 'EB';


    /**
     * Format a number of bytes as a human readable string, automatically picking the most suitable
     * unit, e.g. 1992294 becomes "1.9 MiB". The largest unit that gives a value of at least 1 is
     * used.
     * @param float $numBytes - the number of bytes to format, e.g. from Filesystem::getFileSize().
     * Negative values (e.g. a difference in sizes) are supported.
     * @param int $decimalPlaces - optionally specify the number of decimal places to show. Plain
     * bytes are always shown without decimal places, e.g. "512 B".
     * @param bool $useBinaryUnits - optionally set to false to use decimal (base 10) units such as
     * MB, rather than binary (base 2) units such as MiB.
     * @return string - the formatted size, e.g. "1.9 MiB".
     */
    public static function formatBytes(float $numBytes, int $decimalPlaces = 1, bool $useBinaryUnits = true): string
    {
        $units = ($useBinaryUnits)
            ? [self::BYTES, self::KIB, self::MIB, self::GIB, self::TIB, self::PIB, self::EIB]
            : [self::BYTES, self::KB, self::MB, self::GB, self::TB, self::PB, self::EB];

        # Find the largest unit that gives a value of at least 1.
        $unitIndex = 0;

        while ($unitIndex < count($units) - 1 && abs($numBytes) >= $units[$unitIndex + 1]->getNumBytes())
        {
            $unitIndex++;
        }

        $places = ($unitIndex === 0) ? 0 : $decimalPlaces;
        $value = round($numBytes / $units[$unitIndex]->getNumBytes(), $places);

        # Rounding can take the value up to the next unit (e.g. 1023.96 KiB rounds to 1024.0 KiB), in which case use the
        # next unit instead, so that this shows as 1.0 MiB.
        $base = ($useBinaryUnits) ? 1024 : 1000;

        if (abs($value) >= $base && $unitIndex < count($units) - 1)
        {
            $unitIndex++;
            $places = $decimalPlaces;
            $value = round($numBytes / $units[$unitIndex]->getNumBytes(), $places);
        }

        $formatted = number_format($value, $places, '.', '') . ' ' . $units[$unitIndex]->value;
        return $formatted;
    }


    /**
     * Convert a number of bytes into this unit.
     * @param int $numBytes - the number of bytes to convert.
     * @return float - the equivalent amount in this unit, e.g. 1.5 for 1536 bytes in KiB.
     */
    public function convertFromBytes(int $numBytes): float
    {
        $converted = ((float) $numBytes) / $this->getNumBytes();
        return $converted;
    }


    /**
     * Get the number of bytes in one of this unit.
     * @return int - the number of bytes in one of this unit. E.g. 1024 for KiB, or 1000 for kB.
     */
    public function getNumBytes(): int
    {
        return match ($this) {
            DataSizeUnitEnum::BYTES => 1,
            DataSizeUnitEnum::KIB => 1024,
            DataSizeUnitEnum::MIB => 1024 ** 2,
            DataSizeUnitEnum::GIB => 1024 ** 3,
            DataSizeUnitEnum::TIB => 1024 ** 4,
            DataSizeUnitEnum::PIB => 1024 ** 5,
            DataSizeUnitEnum::EIB => 1024 ** 6,
            DataSizeUnitEnum::KB => 1000,
            DataSizeUnitEnum::MB => 1000 ** 2,
            DataSizeUnitEnum::GB => 1000 ** 3,
            DataSizeUnitEnum::TB => 1000 ** 4,
            DataSizeUnitEnum::PB => 1000 ** 5,
            DataSizeUnitEnum::EB => 1000 ** 6,
        };
    }
}
