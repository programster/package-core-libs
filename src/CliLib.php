<?php

/*
 * A library of functions that are only useful for CLI-based applications/scripts.
 */

namespace Programster\CoreLibs;


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

        $terminalWidth = intval(shell_exec("tput cols"));
        $barWidth = $terminalWidth - ($percentageStringLength) - 2; // subtract 2 for [] around bar
        $numBars = round(($percentage) / 100 * ($barWidth));
        $numEmptyBars = $barWidth - $numBars;

        $barsString = '[' . str_repeat("=", ($numBars)) . str_repeat(" ", ($numEmptyBars)) . ']';

        echo "($percentageString) " . $barsString . "\r";
    }


    /**
     * Script function (not for websits) Fetches the password from the shell
     * without it being displayed whilst being typed. Only works on *nix systems
     * and requires shell_exec and stty.
     *
     * @param bool stars - (optional) set to false to stop outputting stars as
     *                     user types password. This prevents onlookers seeing
     *                     the password length but does make more difficult.
     *
     * @return string - the password that was typed in.
     */
    public static function getPasswordFromUserInput(bool $stars = true) : string
    {
        // Get current style
        $oldStyle = shell_exec('stty -g');

        if ($stars === false)
        {
            shell_exec('stty -echo');
            $password = rtrim(fgets(STDIN), "\n");
        }
        else
        {
            shell_exec('stty -icanon -echo min 1 time 0');

            $password = '';
            while (true)
            {
                $char = fgetc(STDIN);

                if ($char === "\n")
                {
                    break;
                }
                else if (ord($char) === 127)
                {
                    if (strlen($password) > 0)
                    {
                        fwrite(STDOUT, "\x08 \x08");
                        $password = substr($password, 0, -1);
                    }
                }
                else
                {
                    fwrite(STDOUT, "*");
                    $password .= $char;
                }
            }
        }

        // Reset old style
        shell_exec('stty ' . $oldStyle);
        print PHP_EOL;

        // Return the password
        return $password;
    }
}