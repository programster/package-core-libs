<?php

/*
 * A library of functions that are only useful for CLI-based applications/scripts.
 */

namespace Programster\CoreLibs;

use Programster\CoreLibs\Exceptions\UnexpectedOutputException;
use Programster\CoreLibs\Exceptions\CommandFailedException;
use Programster\CoreLibs\Exceptions\UserCancelledException;
use Programster\CoreLibs\Exceptions\InputEndedException;

final class CliLib
{
    # The exit code PowerShell uses when the user cancels typing a password on Windows (see readPasswordOnWindows()).
    private const int WINDOWS_PASSWORD_CANCELLED = 2;


    /**
     * Display a progress bar in the CLI. This will dynamically take up the full width of the
     * terminal and if you keep calling this function, it will appear animated as the progress bar
     * keeps writing over the top of itself.
     *
     * @param float $percentage - the percentage completed. Values below 0 or above 100 are shown as
     * they are in the percentage text, but the bar itself stops at empty or full.
     *
     * @param int $numDecimalPlaces - the number of decimal places to show for percentage output
     * string
     *
     * @return void
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

        $percentageStringLength += 3; // add 3 for the () and a space before the bar starts.
        $terminalWidth = self::getTerminalWidth();

        # Subtract 2 for the [] around the bar. A terminal narrower than the percentage text leaves no room for a bar,
        # and a percentage outside 0 - 100 would give a negative number of bars, either of which would make
        # str_repeat() throw a ValueError, so keep both within range.
        $barWidth = max(0, $terminalWidth - $percentageStringLength - 2);
        $numBars = (int) max(0, min($barWidth, round($percentage / 100 * $barWidth)));
        $numEmptyBars = $barWidth - $numBars;

        $barsString = '[' . str_repeat("=", ($numBars)) . str_repeat(" ", ($numEmptyBars)) . ']';

        echo "($percentageString) " . $barsString . "\r";
    }


    /**
     * Script function (not for websites) Fetches the password from the shell without it being
     * displayed whilst being typed. On Linux and macOS this uses stty, and on Windows (which has no
     * stty) it uses PowerShell, which reads each key without showing it.
     * Backspace/Delete remove the last character typed. Ctrl+C or Ctrl+D cancel, which throws an
     * exception. The terminal's settings are always restored afterwards, even if the user cancels.
     * If the input is not a terminal (e.g. the password is piped in), then there is nothing to
     * hide, so a line is simply read from the input.
     *
     * @param bool $stars - (optional) set to false to stop outputting stars as user types password.
     * This prevents onlookers seeing the password length but does make it more difficult.
     *
     * @return string - the password that was typed in.
     *
     * @throws UserCancelledException - if the user pressed Ctrl+C or Ctrl+D.
     *
     * @throws CommandFailedException - if stty (or on Windows, PowerShell) failed. This is thrown
     * before anything is read, so the password can never be shown on screen by mistake.
     *
     * @throws InputEndedException - if the input ended before the password was entered.
     */
    public static function getPasswordFromUserInput(bool $stars = true): string
    {
        if (stream_isatty(STDIN) && PHP_OS_FAMILY === "Windows")
        {
            $password = self::readPasswordOnWindows($stars);
        }
        elseif (stream_isatty(STDIN))
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
     * Get the width of the terminal, in characters, for showProgressBar(): from "tput cols", or on
     * Windows (which has no tput) from "mode con". If it can't be worked out (e.g. when the output
     * isn't a terminal), this gives the traditional width of 80, rather than failing to show a bar.
     *
     * @return int - the width.
     */
    private static function getTerminalWidth(): int
    {
        $width = 80;

        try
        {
            $width = (PHP_OS_FAMILY === "Windows")
                ? self::parseModeConWidth(Core::runCommand("mode con"))
                : self::parseTputWidth(Core::runCommand("tput cols"));
        }
        catch (CommandFailedException | UnexpectedOutputException $e)
        {
            // Keep the fallback width.
        }

        return $width;
    }


    /**
     * Read the width from what "tput cols" printed, for getTerminalWidth().
     * @param string $output - what tput printed, e.g. "120".
     * @return int - the width.
     * @throws UnexpectedOutputException - if it isn't a positive number.
     */
    private static function parseTputWidth(string $output): int
    {
        $trimmed = trim($output);

        if (!ctype_digit($trimmed) || (int)$trimmed === 0)
        {
            throw new UnexpectedOutputException("Expected tput cols to give a width, but got: {$trimmed}");
        }

        return (int)$trimmed;
    }


    /**
     * Read the width from what Windows' "mode con" printed, for getTerminalWidth(). It lists the
     * console's settings as "name: value" lines, starting with the number of lines and then the
     * number of columns, e.g. "    Lines:          9001" and "    Columns:        120". The names
     * are translated into the system's language (e.g. "Spalten" in German), so this takes the
     * second number, rather than looking for the word "Columns".
     *
     * @param string $output - what mode con printed.
     *
     * @return int - the width.
     *
     * @throws UnexpectedOutputException - if it doesn't list at least two numbers.
     */
    private static function parseModeConWidth(string $output): int
    {
        preg_match_all('/^[^:\r\n]+:\s*([0-9]+)\s*$/m', $output, $matches);

        if (count($matches[1]) < 2 || (int)$matches[1][1] === 0)
        {
            throw new UnexpectedOutputException("Expected mode con to give the console's width, but got: {$output}");
        }

        return (int)$matches[1][1];
    }


    /**
     * Read a password typed on a Windows console, for getPasswordFromUserInput(), with PowerShell,
     * as Windows has no stty. PowerShell reads each key without showing it, writes any stars to the
     * console (through stderr, as stdout returns the password), and treats Ctrl+C as a key, so it
     * can cancel cleanly, rather than killing the script.
     *
     * @param bool $stars - whether to show a star for each character typed.
     *
     * @return string - the password that was typed in.
     *
     * @throws UserCancelledException - if the user pressed Ctrl+C or Ctrl+D.
     * @throws CommandFailedException - if PowerShell couldn't be run, or failed.
     */
    private static function readPasswordOnWindows(bool $stars): string
    {
        # The console is shared, so PowerShell reads from it directly, and writes stars to it through stderr.
        $descriptors = [0 => STDIN, 1 => ['pipe', 'w'], 2 => STDERR];
        $command = ["powershell", "-NoProfile", "-EncodedCommand", self::buildWindowsPasswordScript($stars)];
        $process = @proc_open($command, $descriptors, $pipes);

        if ($process === false)
        {
            throw new CommandFailedException("Failed to start PowerShell to read the password.");
        }

        $output = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $exitCode = proc_close($process);
        fwrite(STDOUT, PHP_EOL);

        if ($exitCode === self::WINDOWS_PASSWORD_CANCELLED)
        {
            throw new UserCancelledException("The user cancelled entering their password.");
        }

        # The password comes back as base64, so that no character can be mangled on the way.
        $password = ($exitCode === 0) ? base64_decode(trim((string)$output), true) : false;

        if ($password === false)
        {
            throw new CommandFailedException("PowerShell failed to read the password (exit code {$exitCode}).");
        }

        return $password;
    }


    /**
     * Build the PowerShell script readPasswordOnWindows() runs, encoded as PowerShell's
     * -EncodedCommand wants (base64 of UTF-16LE), so nothing in it needs quoting for the shell.
     *
     * @param bool $stars - whether to show a star for each character typed.
     *
     * @return string - the encoded script.
     */
    private static function buildWindowsPasswordScript(bool $stars): string
    {
        $showStars = $stars ? '$true' : '$false';
        $cancelled = self::WINDOWS_PASSWORD_CANCELLED;

        # Enter finishes, Ctrl+C or Ctrl+D cancels, Backspace removes the last character, and other control keys
        # (e.g. tab, or arrows, which have no character) are ignored, as on Linux.
        $script = <<<POWERSHELL
            [Console]::TreatControlCAsInput = \$true
            \$showStars = {$showStars}
            \$password = New-Object System.Text.StringBuilder
            while (\$true) {
                \$key = [Console]::ReadKey(\$true)
                \$isControl = (\$key.Modifiers -band [ConsoleModifiers]::Control) -ne 0
                if (\$key.Key -eq 'Enter') { break }
                if (\$isControl -and (\$key.Key -eq 'C' -or \$key.Key -eq 'D')) { exit {$cancelled} }
                if (\$key.Key -eq 'Backspace') {
                    if (\$password.Length -gt 0) {
                        [void]\$password.Remove(\$password.Length - 1, 1)
                        if (\$showStars) { [Console]::Error.Write("`b `b") }
                    }
                    continue
                }
                if ([int]\$key.KeyChar -lt 32) { continue }
                [void]\$password.Append(\$key.KeyChar)
                if (\$showStars) { [Console]::Error.Write('*') }
            }
            [Console]::Out.Write([Convert]::ToBase64String([Text.Encoding]::UTF8.GetBytes(\$password.ToString())))
            POWERSHELL;

        # The script is plain ASCII, so UTF-16LE is each byte followed by a zero byte. (This avoids needing mbstring.)
        return base64_encode(preg_replace('/(.)/s', "\$1\0", $script));
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
     * Remove the last character from a string, treating multi-byte UTF-8 characters such as "é" as
     * one character.
     *
     * @param string $input - the string to remove the last character from.
     *
     * @return string - the string without its last character.
     */
    private static function removeLastCharacter(string $input): string
    {
        # If the string isn't valid UTF-8, preg_replace() returns null, in which case just remove the last byte.
        $result = preg_replace('/.\z/su', '', $input) ?? substr($input, 0, -1);
        return $result;
    }


    /**
     * Read and discard the rest of a terminal escape sequence, after its starting escape character
     * has been read. Keys such as the arrow keys send these, e.g. "\e[A" for the up arrow.
     *
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