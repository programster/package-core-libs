<?php

/*
 * A library of functions that are only useful for CLI-based applications/scripts.
 */

namespace Programster\CoreLibs;

use Programster\CoreLibs\Exceptions\CommandFailedException;
use Programster\CoreLibs\Exceptions\UserCancelledException;
use Programster\CoreLibs\Exceptions\InputEndedException;

final class CliLib
{
    /**
     * Display a progress bar in the CLI. This will dynamically take up the full width of the
     * terminal and if you keep calling this function, it will appear animated as the progress bar
     * keeps writing over the top of itself.
     * @param float $percentage - the percentage completed.
     * @param int $numDecimalPlaces - the number of decimal places to show for percentage output string
     */
    public static function showProgressBar(float $percentage, int $numDecimalPlaces): void
    {
        $percentageStringLength = 4;

        if ($numDecimalPlaces > 0)
        {
            $percentageStringLength += ($numDecimalPlaces + 1);
        }

        $percentageString = number_format($percentage, $numDecimalPlaces) . '%';
        $percentageString = str_pad($percentageString, $percentageStringLength, " ", STR_PAD_LEFT);

        $percentageStringLength += 3; // add 2 for () and a space before bar starts.

        # Fall back to the traditional terminal width if we can't work it out, rather than failing to show the bar.
        $terminalWidth = 80;

        try
        {
            $tputOutput = trim(Core::runCommand("tput cols"));

            if (ctype_digit($tputOutput) && (int) $tputOutput > 0)
            {
                $terminalWidth = (int) $tputOutput;
            }
        }
        catch (CommandFailedException $e)
        {
            // Keep the fallback width.
        }

        $barWidth = $terminalWidth - ($percentageStringLength) - 2; // subtract 2 for [] around bar
        $numBars = round(($percentage) / 100 * ($barWidth));
        $numEmptyBars = $barWidth - $numBars;

        $barsString = '[' . str_repeat("=", ($numBars)) . str_repeat(" ", ($numEmptyBars)) . ']';

        echo "($percentageString) " . $barsString . "\r";
    }


    /**
     * Script function (not for websites) Fetches the password from the shell
     * without it being displayed whilst being typed. Only works on *nix systems,
     * as it requires stty.
     * Backspace/Delete remove the last character typed. Ctrl+C or Ctrl+D cancel, which throws an exception. The
     * terminal's settings are always restored afterwards, even if the user cancels.
     * If the input is not a terminal (e.g. the password is piped in), then there is nothing to hide, so a line is
     * simply read from the input.
     *
     * @param bool $stars - (optional) set to false to stop outputting stars as
     *                      user types password. This prevents onlookers seeing
     *                      the password length but does make it more difficult.
     *
     * @return string - the password that was typed in.
     * @throws UserCancelledException - if the user pressed Ctrl+C or Ctrl+D.
     * @throws CommandFailedException - if stty failed, such as on Windows where it doesn't exist. This is thrown before
     *                                  anything is read, so the password can never be shown on screen by mistake.
     * @throws InputEndedException - if the input ended before the password was entered.
     */
    public static function getPasswordFromUserInput(bool $stars = true): string
    {
        if (stream_isatty(STDIN))
        {
            $password = self::readPasswordFromTerminal($stars);
        }
        else
        {
            $line = fgets(STDIN);

            if ($line === false)
            {
                throw new InputEndedException("The input ended before a password was provided.");
            }

            $password = rtrim($line, "\r\n");
        }

        return $password;
    }


    /**
     * Read a password from the terminal one key at a time, without showing what is typed.
     * @param bool $stars - whether to output a star for each character typed.
     * @return string - the password that was typed in.
     * @throws UserCancelledException - if the user pressed Ctrl+C or Ctrl+D.
     * @throws CommandFailedException - if stty failed.
     * @throws InputEndedException - if the input ended before the password was entered.
     */
    private static function readPasswordFromTerminal(bool $stars): string
    {
        $originalSettings = trim(Core::runCommand('stty -g'));

        # -icanon: give us each key as it is pressed, rather than a line at a time.
        # -echo: don't show what is typed.
        # -isig: send Ctrl+C to us as a normal key, rather than killing the script, as that would leave the terminal
        #        with typing hidden.
        Core::runCommand('stty -icanon -echo -isig min 1 time 0');

        try
        {
            $password = '';
            $isFinished = false;

            while (!$isFinished)
            {
                $char = fgetc(STDIN);

                if ($char === false)
                {
                    throw new InputEndedException("The input ended before the password was entered.");
                }
                elseif ($char === "\n" || $char === "\r")
                {
                    $isFinished = true;
                }
                elseif ($char === "\x03" || $char === "\x04")
                {
                    # Ctrl+C or Ctrl+D
                    throw new UserCancelledException("The user cancelled entering their password.");
                }
                elseif ($char === "\x7F" || $char === "\x08")
                {
                    # Backspace/Delete (terminals send one or the other) removes the last character typed.
                    if ($password !== '')
                    {
                        $password = self::removeLastCharacter($password);

                        if ($stars)
                        {
                            fwrite(STDOUT, "\x08 \x08");
                        }
                    }
                }
                elseif ($char === "\x1B")
                {
                    # The start of a key such as an arrow key, which isn't part of the password.
                    self::skipEscapeSequence();
                }
                elseif (ord($char) < 0x20)
                {
                    # Ignore any other control characters (e.g. tab), as they aren't part of the password.
                }
                else
                {
                    $password .= $char;

                    # Characters such as "é" are made of multiple bytes. Only output a star for the first byte, so
                    # that each character gets one star. (Continuation bytes are 0x80 - 0xBF.)
                    $isContinuationByte = (ord($char) & 0xC0) === 0x80;

                    if ($stars && !$isContinuationByte)
                    {
                        fwrite(STDOUT, "*");
                    }
                }
            }
        }
        finally
        {
            # Always restore the terminal, even if the user cancelled.
            Core::runCommand('stty ' . escapeshellarg($originalSettings));
            fwrite(STDOUT, PHP_EOL);
        }

        return $password;
    }


    /**
     * Remove the last character from a string, treating multi-byte UTF-8 characters such as "é" as one character.
     * @param string $input - the string to remove the last character from.
     * @return string - the string without its last character.
     */
    private static function removeLastCharacter(string $input): string
    {
        # If the string isn't valid UTF-8, preg_replace() returns null, in which case just remove the last byte.
        $result = preg_replace('/.\z/su', '', $input) ?? substr($input, 0, -1);
        return $result;
    }


    /**
     * Read and discard the rest of a terminal escape sequence, after its starting escape character has been read.
     * Keys such as the arrow keys send these, e.g. "\e[A" for the up arrow.
     * @return void
     */
    private static function skipEscapeSequence(): void
    {
        $char = fgetc(STDIN);

        if ($char === "[")
        {
            # A control sequence, which ends with a byte between 0x40 and 0x7E (e.g. the "A" in "\e[A").
            do
            {
                $char = fgetc(STDIN);
            } while ($char !== false && (ord($char) < 0x40 || ord($char) > 0x7E));
        }
        elseif ($char === "O")
        {
            # Some terminals send arrow keys as "\eOA" instead, which is always one more byte.
            fgetc(STDIN);
        }
        else
        {
            # Alt+key, which sends the escape character followed by the key, so the key has already been discarded.
        }
    }
}