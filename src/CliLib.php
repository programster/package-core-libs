<?php

namespace iRAP\CoreLibs;

/*
 * A library of functions that are only useful for CLI-based applications/scripts.
 */

class CliLib
{
    /**
     * Display a progress bar in the CLI. This will dynamically take up the full width of the 
     * terminal and if you keep calling this function, it will appear animated as the progress bar
     * keeps writing over the top of itself.
     * @param float $percentage - the percentage completed.
     * @param int $numDecimalPlaces - the number of decimal places to show for percentage output string
     */
    public static function showProgressBar($percentage, int $numDecimalPlaces)
    {
        $percentageStringLength = 4;
        if ($numDecimalPlaces > 0)
        {
            $percentageStringLength += ($numDecimalPlaces + 1);
        }
        
        $percentageString = number_format($percentage, $numDecimalPlaces) . '%';
        $percentageString = str_pad($percentageString, $percentageStringLength, " ", STR_PAD_LEFT);
        
        $percentageStringLength += 3; // add 2 for () and a space before bar starts.
        
        $terminalWidth = `tput cols`;
        $barWidth = $terminalWidth - ($percentageStringLength) - 2; // subtract 2 for [] around bar
        $numBars = round(($percentage) / 100 * ($barWidth));
        $numEmptyBars = $barWidth - $numBars;
        
        $barsString = '[' . str_repeat("=", ($numBars)) . str_repeat(" ", ($numEmptyBars)) . ']';
        
        echo "($percentageString) " . $barsString . "\r";
    }
}