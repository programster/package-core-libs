<?php

/*
 * Units that a size of data can be expressed in, such as an amount of memory or the size of a file. Includes both
 * the binary (base 2, e.g. MiB) units and the decimal (base 10, e.g. MB) units.
 */

namespace Programster\CoreLibs;


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
     * Get the number of bytes in one of this unit.
     * @return int - the number of bytes in one of this unit. E.g. 1024 for KiB, or 1000 for kB.
     */
    public function getNumBytes() : int
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
