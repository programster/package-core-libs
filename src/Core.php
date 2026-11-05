<?php

/*
 * This is a general library that can be used to "extend" the standard library.
 * Only functions that are used frequently and are completely generic should
 * be placed here. Wherever possible, please use more specific libraries such
 * as the StringLib, Filesystem, and HtmlGenerator
 */

namespace Programster\CoreLibs;

use Programster\CoreLibs\Exceptions\ExceptionCommandFailed;
use Programster\CoreLibs\Exceptions\FilesystemException;

class Core
{
    /**
     * Function that wraps around throwing an exception so that it
     * can be used in "or" statements.
     * @param string $msg - message to raise in exception
     */
    public static function throwException(string $msg)
    {
        throw new \Exception($msg);
    }


    /**
     * Determines whether PHP is running as a CLI script or a website.
     * @param void
     * @return result (boolean) - true if CLI false if website.
     */
    public static function isCli() : bool
    {
        $result = false;

        if (defined('STDIN') )
        {
            $result = true;
        }

        return $result;
    }


    /**
     * Make PHP slightly more like Java and allow printing a line. If this is
     * in a web app then it will use <br /> as well.
     * @param String $message - the string to print out.
     * @return void - prints out immediately.
     */
    public static function println(string $message)
    {
        if (!self::isCli())
        {
            $message .= '<br />';
        }

        print $message . PHP_EOL;
    }


    /**
     * Tiny helper function to help ensure that exit is always called after
     * redirection and allows the developer to only have to remember the
     * location they want. (won't forget 'location:')
     *
     * @param location - the location/address/URL you want to redirect to.
     *
     * @return void - redirects the user and quits.
     */
    public static function redirectUser(string $location)
    {
        header("location: " . $location);
        exit();
    }


    /**
     * Sets the title of the process and will append the appropriate number of
     * already existing processes with the same title.
     * WARNING - this will fail and return FALSE if you are on Windows
     * @param string $nameingPrefix - the name to give the process.
     * @return boolean - true if successfully set the title, false if not.
     */
    public static function setCliTitle(string $nameingPrefix) : bool
    {
        $succeeded = false;
        $num_running = self::getNumProcRunning($nameingPrefix);

        if (function_exists('cli_set_process_title'))
        {
            cli_set_process_title($nameingPrefix . $num_running);
            $succeeded = true;
        }

        return $succeeded;
    }


    /**
     * Fetches the number of processes running with the given search name
     * (have it in their name)
     * @param String $title - the string to search for a process by
     *                        (e.g. its name/title)
     * @return int - the number of processes running with that title.
     * @throws ExceptionCommandFailed - if we failed to get the list of processes.
     */
    public static function getNumProcRunning(string $title) : int
    {
        # Get each process's full command line (which is what cli_set_process_title() changes). We deliberately don't
        # use a pipeline here, as a pipeline's exit code is only that of its last command, which would hide ps failing.
        $processes = explode("\n", self::runCommand("ps -eo args="));
        $numRunning = 0;

        # starts with our title and has one or more numbers afterwards.
        $regExp = "/^" . preg_quote($title, "/") . "[0-9]+/";

        foreach ($processes as $processName)
        {
            if (preg_match($regExp, trim($processName)))
            {
                $numRunning++;
            }
        }

        return $numRunning;
    }


    /**
     * Fetches the specified list of arguments from $_REQUEST. This will return
     * false if any parameters could not be found.
     *
     * @param array $args - array of all the argument names.
     * @param array $input_array - array from which we are pulling the required
     *                             args.
     *
     * @return result - false if any parameters could not be found.
     */
    public static function fetchReqArgsFromArray(array $args, array $input_array)
    {
        $values = array();

        foreach ($args as $arg)
        {
            if (isset($input_array[$arg]))
            {
                $values[$arg] = $input_array[$arg];
            }
            else
            {
                $errMsg = "Required parameter: " . $arg . " not specified";
                throw new \Exception($errMsg);
                break;
            }
        }

        return $values;
    }


    /**
     * Fetches as many of the specified list of arguments from $_REQUEST that
     * it can retrieve.
     * This will NOT throw an exception or return false if it fails to find one.
     *
     * @param array $args - array of all the argument names.
     * @param array $input_array - array from which we are pulling the optional args.
     *
     * @return values - array of retrieved values
     */
    public static function fetchOptionalArgsFromArray($args, $input_array)
    {
        $values = array();

        foreach ($args as $arg)
        {
            if (isset($input_array[$arg]))
            {
                $values[$arg] = $input_array[$arg];
            }
        }

        return $values;
    }


    /**
     * Builds the URL of the current page, excluding any ?=&stuff,
     * @param void
     * @return pageURL - full page URL of the current page
     *                   e.g. https://www.google.com/some-page
     */
    public static function getCurrentUrl() : string
    {
        $pageURL = 'http';

        if (isset($_SERVER["HTTPS"]))
        {
            $pageURL .= "s";
        }

        $pageURL .= "://";

        if ($_SERVER["SERVER_PORT"] != "80")
        {
            $pageURL .= $_SERVER["SERVER_NAME"] . ":" .
                        $_SERVER["SERVER_PORT"] . $_SERVER["REQUEST_URI"];
        }
        else
        {
            $pageURL .= $_SERVER["SERVER_NAME"] . $_SERVER["REQUEST_URI"];
        }

        return $pageURL;
    }


    /**
     * Ensures that a given value is within the given range and if not, moves
     * it to the boundary.
     * Note that this can work for objects if you install the following
     * extension: http://pecl.php.net/package/operator
     *
     * @param mixed $value - the variable to make sure is within range.
     * @param mixed $max   - the max allowed value.
     * @param mixed $min   - the minimum allowed value
     *
     * @return $value - the clamped input.
     */
    public static function clampValue($value, $max, $min)
    {
        if ($value > $max)
        {
            $value = $max;
        }
        elseif ($value < $min)
        {
            $value = $min;
        }

        return $value;
    }


    /**
     * Generates a string of yes or no based on the input variable.
     * Note that this will consider string 0 or a 0 integer as 'false' values.
     * @param input - the input variable to decide whether to output yes/no on.
     * @return result - string of 'Yes' or 'No'
     */
    public static function generateYesNoString($input) : string
    {
        return ($input === "0" || $input === 0 || $input === false) ? "No" : "Yes";
    }


    /**
     * Generates a string 'True' or 'False' based on whether the value passed
     * in. This will consider string 0 or a 0 integer as 'false' values.
     * @param mixed input - the input variable to decide whether to output true
     *                      or false on.
     * @return string - "True" or "False"
     */
    public static function generateTrueFalseString($input) : string
    {
        return ($input === "0" || $input === 0 || $input === false) ? "True" : "False";
    }


    /**
     * Implement a version guard. This will throw an exception if we do not
     * have the required version of PHP that is specified.
     * @param String $reqVersion - required version of PHP, e.g. '5.4.0'
     * @throws an exception if we do not meet the required PHP
     *                version.
     */
    public static function versionGuard($reqVersion, $errMsg='')
    {
        if (version_compare(PHP_VERSION, $reqVersion) == -1)
        {
            if ($errMsg == '')
            {
                $errMsg = 'Required PHP version: ' . $reqVersion .
                                ', current Version: ' . PHP_VERSION;
            }

            die($errMsg);
        }
    }


    /**
     * Fetches the number of vCPU on a Linux machine. I state vCPU instead of
     * CPU as this includes all hyperthreads/cores. (e.g. an i7 will show up as
     * 8 even though there is only 1 physical processor)
     * @return int - the number of threads this machine can concurrently run.
     * @throws ExceptionCommandFailed - if the nproc command failed.
     * @throws \Exception - if nproc did not return a number.
     */
    public static function getNumProcessors() : int
    {
        $output = trim(self::runCommand("nproc --all"));

        if (!ctype_digit($output))
        {
            throw new \Exception("Expected nproc to return a number of processors, but got: {$output}");
        }

        $numProcessors = (int) $output;
        return $numProcessors;
    }


    /**
     * Run a shell command and get its output. Unlike shell_exec(), this throws an exception if the command fails
     * (exits with a non-zero exit code), rather than returning null, and returns an empty string rather than null if
     * the command succeeded but didn't output anything.
     * WARNING - the command is run through the shell, so make sure to escape any values you put into it with
     * escapeshellarg().
     * Note that if the command is a pipeline (e.g. "foo | bar"), then the exit code is only that of the last command
     * in the pipeline, so a failure earlier in the pipeline may not be detected.
     * WARNING - if the command starts a background process that keeps stdout open (e.g. xclip and wl-copy do this to
     * keep serving the clipboard), this will wait until that background process exits, which may be never. Redirect
     * the command's stdout to /dev/null to avoid this.
     * @param string $command - the command to run.
     * @param string|null $input - optionally provide content to send to the command's stdin. If null (the default),
     *                             the command inherits this script's stdin.
     * @return string - everything the command wrote to stdout, exactly as it was written (including any trailing
     *                  newline).
     * @throws ExceptionCommandFailed - if the command could not be run, or exited with a non-zero exit code. The
     *                                  exception holds the exit code and anything the command wrote to stderr.
     */
    public static function runCommand(string $command, ?string $input = null) : string
    {
        # Send stderr to a temporary file rather than a pipe. If we used a pipe for both stdout and stderr, and the
        # command wrote a lot to stderr while we were waiting on stdout, both sides could block forever.
        $errorFile = tmpfile();

        if ($errorFile === false)
        {
            throw new ExceptionCommandFailed($command, -1, "Failed to create a temporary file to capture stderr.");
        }

        $descriptors = [
            1 => ['pipe', 'w'],
            2 => $errorFile,
        ];

        if ($input !== null)
        {
            # Likewise, provide stdin from a temporary file rather than a pipe, so that we can't block forever writing
            # to stdin while the command is blocked writing to stdout.
            $inputFile = tmpfile();

            if ($inputFile === false || fwrite($inputFile, $input) !== strlen($input))
            {
                fclose($errorFile);
                throw new ExceptionCommandFailed($command, -1, "Failed to create a temporary file for stdin.");
            }

            rewind($inputFile);
            $descriptors[0] = $inputFile;
        }

        $process = proc_open($command, $descriptors, $pipes);

        if ($process === false)
        {
            fclose($errorFile);

            if (isset($inputFile))
            {
                fclose($inputFile);
            }

            throw new ExceptionCommandFailed($command, -1, "");
        }

        $output = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $exitCode = proc_close($process);

        if (isset($inputFile))
        {
            fclose($inputFile);
        }

        rewind($errorFile);
        $errorOutput = stream_get_contents($errorFile);
        fclose($errorFile);

        if ($output === false)
        {
            throw new ExceptionCommandFailed($command, -1, "Failed to read the command's output.");
        }

        if ($exitCode !== 0)
        {
            throw new ExceptionCommandFailed($command, $exitCode, ($errorOutput === false) ? "" : $errorOutput);
        }

        return $output;
    }


    /**
     * Linux specific function that fetches information on how much RAM is in
     * the system in MiB (base 2, not base 10)
     * @param bool $availableOnly - optionally specify that you want to know
     *                              only how much ram is free and not the total
     *                              amount of RAM in the system.
     * @return int - the amount of RAM in MiB, rounded down to the nearest whole MiB.
     * @throws FilesystemException - if /proc/meminfo could not be read.
     * @throws \Exception - if /proc/meminfo does not contain a field needed for the requested measurement.
     */
    #[\Deprecated("Please use Core::getMemory() instead. Note that getRam(true) returns MemoryTypeEnum::FREE, but you probably want MemoryTypeEnum::AVAILABLE.")]
    public static function getRam(bool $availableOnly=false) : int
    {
        $type = ($availableOnly) ? MemoryTypeEnum::FREE : MemoryTypeEnum::TOTAL;
        return self::getMemory($type);
    }


    /**
     * Linux specific function that fetches information about the system's memory (RAM and swap) from /proc/meminfo.
     * Based on:
     * http://stackoverflow.com/questions/1455379/get-server-ram-with-php
     * @param MemoryTypeEnum $type - optionally specify which measurement of memory you want, such as how much is
     *                               available, rather than the total amount of RAM in the system. Please refer to the
     *                               MemoryTypeEnum for what each one means.
     * @param DataSizeUnitEnum $unit - optionally specify the unit to return the amount of memory in. Defaults to MiB.
     * @return int - the amount of memory in the specified unit, rounded down to the nearest whole unit.
     * @throws FilesystemException - if /proc/meminfo could not be read.
     * @throws \Exception - if /proc/meminfo does not contain a field needed for the requested measurement. Not every
     *                      field exists on every system.
     */
    public static function getMemory(
        MemoryTypeEnum $type = MemoryTypeEnum::TOTAL,
        DataSizeUnitEnum $unit = DataSizeUnitEnum::MIB,
    ) : int
    {
        $meminfoContents = file_get_contents("/proc/meminfo");

        if ($meminfoContents === false)
        {
            throw new FilesystemException("Failed to read /proc/meminfo.");
        }

        $data = explode("\n", $meminfoContents);
        $meminfo = array();

        foreach ($data as $line)
        {
            # remove the kB identifier at the end.
            $line = str_replace(' kB', '', $line);

            $parts = explode(":", $line);

            if (count($parts) == 2)
            {
                $meminfo[$parts[0]] = trim($parts[1]);
            }
        }

        $getKib = function (string $field) use ($meminfo) : int {
            if (!array_key_exists($field, $meminfo))
            {
                throw new \Exception("/proc/meminfo does not contain the {$field} field.");
            }

            return (int) $meminfo[$field];
        };

        $memoryKib = match ($type) {
            # Calculated from multiple fields, in the same way as the "free" command.
            MemoryTypeEnum::USED => $getKib('MemTotal') - $getKib('MemAvailable'),
            MemoryTypeEnum::BUFF_CACHE => $getKib('Buffers') + $getKib('Cached') + $getKib('SReclaimable'),
            MemoryTypeEnum::SWAP_USED => $getKib('SwapTotal') - $getKib('SwapFree'),

            # These are a number of pages rather than an amount of memory, so convert them.
            MemoryTypeEnum::HUGE_PAGES_TOTAL,
            MemoryTypeEnum::HUGE_PAGES_FREE,
            MemoryTypeEnum::HUGE_PAGES_RESERVED,
            MemoryTypeEnum::HUGE_PAGES_SURPLUS => $getKib($type->value) * $getKib('Hugepagesize'),

            # Everything else is a single field in KiB, and the enum's value is that field's name.
            default => $getKib($type->value),
        };

        # /proc/meminfo reports in KiB (despite labelling it "kB"). Convert to the requested unit, rounding down so we
        # never over-report.
        $memory = intdiv($memoryKib * 1024, $unit->getNumBytes());

        return $memory;
    }


    /**
     * Generate a PHP config file to have the setting provided. This is useful if we want to be able to update our
     * config file through code, such as a web UI to update settings. Platforms like WordPress allow updating the
     * settings, but do this through a database.
     * @param mixed $settings - array or variable that we want to save to the file
     * @param string $variableName - name of the settings variable so that it is reloaded correctly
     * @param string $filePath - path to the file where we want to save the settings. (overwritten)
     * @return void - creates a config file, or throws an exception if failed.
     * @throws Exception if failed to write to the specified filePath, e.g don't have permissions.
     */
    public static function generateConfig($settings, $variableName, string $filePath)
    {
        $varStr = var_export($settings, true);

        $output =
            '<?php' . PHP_EOL .
            '$' . $variableName . ' = ' . $varStr . ';';

        # file_put_contents returns num bytes written or boolean false if fail
        $wroteFile = file_put_contents($filePath, $output);

        if ($wroteFile === FALSE)
        {
            $msg = "Failed to generate config file. Check permissions!";
            throw new \Exception($msg);
        }
    }


    /**
     * Sign some data. If we get this data back again, we will know that it came from us
     * untampered (as long as the signature still matches). This is useful for things like
     * tokens sent in emails allowing them to perform actions without having to sign in
     * @param array $data - the data we wish to sign.
     * @return string - the generated signature for the provided data.
     */
    public static function generateSignature(array $data, string $secret) : string
    {
        ksort($data);
        $stringForm = json_encode($data, JSON_THROW_ON_ERROR);
        return hash_hmac("sha256", $stringForm, $secret);
    }


    /**
     * Check if the provided data has the correct signature.
     * @param array $data - the data we received that was signed. The signature MUST NOT be in this
     *                      array.
     * @param string $signature - the signature that came with the data. This is what we will check
     *                            is valid for the data received.
     * @param string $serverSecret - the server's secret that it uses for generating signatures.
     * @return bool - true if the signature is correct for the data, or false if not (in which case
     *                a user probably tried to manipulate the data).
     */
    public static function isValidSignedRequest(array $data, string $signature, string $serverSecret) : bool
    {
        $generatedSignature = Core::generateSignature($data, $serverSecret);
        return ($generatedSignature == $signature);
    }


    /**
     * A wrapper around var_dump that will return the result as a string rather than dumping
     * out immediately.
     * @param mixed $variable - the variable you want to get the var_dump of
     * @return string - the result of the var_dump
     */
    public static function var_dump($variable) : string
    {
        ob_start();
        var_dump($variable);
        return ob_get_clean();
    }


    /**
     * Set the content of the user's clipboard (the one used by Ctrl+C / Ctrl+V).
     * On Linux, this supports both Wayland (using wl-copy from the wl-clipboard package) and X11 (using xclip). On
     * macOS this uses pbcopy, and on Windows this uses PowerShell.
     * @param string $content - the content you wish to set in the clipboard.
     * @return void
     * @throws ExceptionCommandFailed - if the clipboard could not be set, such as if the required tool isn't installed.
     * @throws \Exception - if running on an unsupported OS, or on Linux without a graphical session.
     */
    public static function setClipboard(string $content) : void
    {
        $command = match (PHP_OS_FAMILY) {
            # Untested. This uses PowerShell rather than clip.exe, as clip.exe mangles UTF-8 text. Set-Clipboard
            # won't accept an empty string, so clear the clipboard instead in that case.
            "Windows" => 'powershell -NoProfile -NonInteractive -Command "'
                . '[Console]::InputEncoding = [System.Text.Encoding]::UTF8; '
                . '$text = [Console]::In.ReadToEnd(); '
                . 'if ($text -eq \'\') { Set-Clipboard -Value $null } else { Set-Clipboard -Value $text }"',

            # Untested. pbcopy relies on the locale to know that the text is UTF-8.
            "Darwin" => "LC_CTYPE=UTF-8 pbcopy",

            # Both wl-copy and xclip leave a process running in the background to keep serving the clipboard, which
            # keeps stdout open. Send stdout to /dev/null, otherwise runCommand() would wait for it forever.
            "Linux" => (self::useWaylandClipboard("wl-copy"))
                ? "wl-copy > /dev/null"
                : "xclip -selection clipboard -in > /dev/null",

            default => throw new \Exception(
                "Running on unsupported OS: " . PHP_OS_FAMILY . " - only Windows, Linux, and macOS are supported."
            ),
        };

        self::runCommand($command, $content);
    }


    /**
     * Get the contents of the user's clipboard (the one used by Ctrl+C / Ctrl+V).
     * On Linux, this supports both Wayland (using wl-paste from the wl-clipboard package) and X11 (using xclip). On
     * macOS this uses pbpaste, and on Windows this uses PowerShell.
     * @return string - the content of the clipboard, exactly as it was copied. This will be an empty string if the
     *                  clipboard is empty, or holds something other than text (e.g. an image).
     * @throws ExceptionCommandFailed - if the clipboard could not be read, such as if the required tool isn't
     *                                  installed.
     * @throws \Exception - if running on an unsupported OS, or on Linux without a graphical session.
     */
    public static function getClipboard() : string
    {
        if (PHP_OS_FAMILY === "Windows")
        {
            # Untested. Get-Clipboard outputs nothing if the clipboard has no text in it. PowerShell puts a \r\n
            # after the output, which isn't part of the clipboard's content, so remove it.
            $output = self::runCommand(
                'powershell -NoProfile -NonInteractive -Command "'
                . '[Console]::OutputEncoding = [System.Text.Encoding]::UTF8; '
                . 'Get-Clipboard -Raw"'
            );

            $content = (str_ends_with($output, "\r\n")) ? substr($output, 0, -2) : $output;
        }
        elseif (PHP_OS_FAMILY === "Darwin")
        {
            # Untested. pbpaste outputs nothing (and succeeds) if the clipboard has no text in it.
            $content = self::runCommand("LC_CTYPE=UTF-8 pbpaste");
        }
        elseif (PHP_OS_FAMILY === "Linux")
        {
            if (self::useWaylandClipboard("wl-paste"))
            {
                # --no-newline stops wl-paste adding a newline that wasn't in the clipboard.
                $command = "wl-paste --no-newline --type text";

                # wl-paste fails with one of these if the clipboard has no text in it. "No selection" is from older
                # versions of wl-clipboard.
                $noTextErrorPattern = '/Nothing is copied|No selection|No suitable type of content copied/';
            }
            else
            {
                # Ask for UTF8_STRING, rather than xclip's default of STRING, so that non-ASCII text isn't mangled.
                $command = "xclip -selection clipboard -out -target UTF8_STRING";

                # xclip fails with "Error: target ... not available" if the clipboard has no text in it. Note that
                # xclip 0.13 says "target STRING not available" even though we asked for UTF8_STRING, so don't rely on
                # the target's name.
                $noTextErrorPattern = '/target \S+ not available/';
            }

            try
            {
                $content = self::runCommand($command);
            }
            catch (ExceptionCommandFailed $e)
            {
                $hasNoText = (preg_match($noTextErrorPattern, $e->getErrorOutput()) === 1);

                if (!$hasNoText)
                {
                    # A real failure, such as the display not being reachable.
                    throw $e;
                }

                $content = "";
            }
        }
        else
        {
            throw new \Exception(
                "Running on unsupported OS: " . PHP_OS_FAMILY . " - only Windows, Linux, and macOS are supported."
            );
        }

        return $content;
    }


    /**
     * Work out whether to use the Wayland clipboard tools (wl-clipboard) or the X11 one (xclip) on Linux.
     * The native Wayland tools are preferred when running under Wayland, but if they aren't installed and XWayland
     * is available, we fall back to xclip.
     * @param string $waylandTool - the Wayland tool we want to use (e.g. "wl-copy" or "wl-paste"), so we can check if
     *                              it is installed.
     * @return bool - true to use the Wayland tools, false to use xclip.
     * @throws \Exception - if there is no graphical session, and thus no clipboard.
     */
    private static function useWaylandClipboard(string $waylandTool) : bool
    {
        $hasWayland = ((string) getenv("WAYLAND_DISPLAY")) !== "";
        $hasX11 = ((string) getenv("DISPLAY")) !== "";

        if (!$hasWayland && !$hasX11)
        {
            throw new \Exception(
                "Cannot access the clipboard, as there is no graphical session (neither WAYLAND_DISPLAY nor DISPLAY " .
                "is set)."
            );
        }

        # If we're only on Wayland, use the Wayland tool even if it isn't installed, so that the user gets an error
        # telling them it's missing.
        $useWayland = $hasWayland && (!$hasX11 || self::isCommandAvailable($waylandTool));
        return $useWayland;
    }


    /**
     * Check whether a command is installed (available on the PATH).
     * @param string $command - the name of the command, e.g. "xclip".
     * @return bool - true if the command is available, false if not.
     */
    private static function isCommandAvailable(string $command) : bool
    {
        $isAvailable = true;

        try
        {
            self::runCommand("command -v " . escapeshellarg($command) . " > /dev/null");
        }
        catch (ExceptionCommandFailed $e)
        {
            $isAvailable = false;
        }

        return $isAvailable;
    }
}
