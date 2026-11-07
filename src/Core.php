<?php

/*
 * This is a general library that can be used to "extend" the standard library. Only functions that
 * are used frequently and are completely generic should be placed here. Wherever possible, please
 * use more specific libraries such as the StringLib and Filesystem.
 */

namespace Programster\CoreLibs;

use Programster\CoreLibs\Enums\DataSizeUnitEnum;
use Programster\CoreLibs\Enums\MemoryTypeEnum;
use Programster\CoreLibs\Exceptions\CliTitleNotSetException;
use Programster\CoreLibs\Exceptions\CommandFailedException;
use Programster\CoreLibs\Exceptions\PhpVersionTooLowException;
use Programster\CoreLibs\Exceptions\FilesystemException;
use Programster\CoreLibs\Exceptions\UnexpectedOutputException;
use Programster\CoreLibs\Exceptions\UnsupportedPlatformException;
use Programster\CoreLibs\Exceptions\BadArgumentException;
use Programster\CoreLibs\Exceptions\NoWebRequestException;

final class Core
{
    # The values that generateYesNoString() and generateTrueFalseString() consider to be 'false'. These are compared
    # strictly, so e.g. the string "0.0" or "false" are not in this list.
    private const FALSE_VALUES = [false, null, "", "0", 0, 0.0, []];


    /**
     * Determines whether PHP is running as a CLI script or a website.
     * @return bool - true if CLI false if website.
     */
    public static function isCli(): bool
    {
        $result = false;

        if (defined('STDIN') )
        {
            $result = true;
        }

        return $result;
    }


    /**
     * Make PHP slightly more like Java and allow printing a line. If this is in a web app then it
     * will use <br /> as well.
     * @param string $message - the string to print out.
     * @return void - prints out immediately.
     */
    public static function println(string $message): void
    {
        if (!self::isCli())
        {
            $message .= '<br />';
        }

        print $message . PHP_EOL;
    }


    /**
     * Redirect the user to another page, and exit, so nothing after it runs by mistake.
     * By default, only a path on this site is allowed (e.g. "/account?tab=2"), so a location taken
     * from the request (e.g. "?next=...") can't send the user to another site, which attackers use
     * to make phishing links look trustworthy (an "open redirect").
     * @param string $location - where to redirect to. Without $allowExternal, this must be a path
     * starting with a single "/".
     * @param bool $allowExternal - true to allow any location, including other sites. Only set
     * this if $location can't come from the user, or has been checked against a list of allowed
     * sites.
     * @return never - redirects the user and exits, so this never returns.
     * @throws BadArgumentException - without $allowExternal, if $location isn't a path on this site
     * (e.g. it is "https://other.site/", or starts with "//", which browsers treat as another site).
     */
    public static function redirectUser(string $location, bool $allowExternal = false): never
    {
        # Browsers treat "//other.site" and "/\other.site" as another site, so only a single "/" is a path here.
        $isSameSitePath = str_starts_with($location, "/")
            && !str_starts_with($location, "//")
            && !str_starts_with($location, "/\\");

        if (!$allowExternal && !$isSameSitePath)
        {
            throw new BadArgumentException(
                "redirectUser: {$location} isn't a path on this site, so won't be redirected to (see \$allowExternal)."
            );
        }

        header("Location: {$location}");
        exit();
    }


    /**
     * Sets the title of the process and will append the appropriate number of already existing
     * processes with the same title.
     * @param string $namingPrefix - the name to give the process.
     * @return void
     * @throws CliTitleNotSetException - if the title could not be set, such as when not running
     * from the CLI, where cli_set_process_title() doesn't exist.
     * @throws CommandFailedException - if we failed to get the list of processes.
     */
    public static function setCliTitle(string $namingPrefix): void
    {
        if (!function_exists('cli_set_process_title'))
        {
            throw new CliTitleNotSetException(
                "Cannot set the process title, as cli_set_process_title() is only available when running from the CLI."
            );
        }

        $numRunning = self::getNumProcRunning($namingPrefix);

        # This raises a warning as well as returning false on failure, so capture the warning to put its reason in
        # the exception, rather than letting it through.
        $setProcessTitle = function () use ($namingPrefix, $numRunning): bool {
            return cli_set_process_title($namingPrefix . $numRunning);
        };

        $titleSet = self::runCapturingWarning($setProcessTitle, $error);
        $error ??= 'unknown error';

        if ($titleSet === false)
        {
            throw new CliTitleNotSetException("Failed to set the process title: {$error}");
        }
    }


    /**
     * Fetches the number of processes running with the given search name (have it in their name)
     * @param string $title - the string to search for a process by (e.g. its name/title)
     * @return int - the number of processes running with that title.
     * @throws CommandFailedException - if we failed to get the list of processes.
     */
    public static function getNumProcRunning(string $title): int
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
     * Fetches the specified list of arguments from the provided array (e.g. $_REQUEST). This will
     * throw an exception if any of them could not be found. An argument that is in $inputArray with
     * a null value counts as provided, and its null is returned.
     *
     * @param array $args - array of all the argument names. Each must be a string or an int (the
     * types that array keys can be), so null is not allowed.
     * @param array $inputArray - array from which we are pulling the required args.
     *
     * @return array - the name/value pairs of the arguments.
     * @throws BadArgumentException - if any of the arguments could not be found, or one of the
     * names in $args is not a string or an int.
     */
    public static function fetchReqArgsFromArray(array $args, array $inputArray): array
    {
        $values = [];

        foreach ($args as $arg)
        {
            self::validateArgName($arg);

            # Use array_key_exists() rather than isset(), as isset() is false for a key that exists with a null value.
            if (array_key_exists($arg, $inputArray))
            {
                $values[$arg] = $inputArray[$arg];
            }
            else
            {
                $errMsg = "Required parameter: " . $arg . " not specified";
                throw new BadArgumentException($errMsg);
            }
        }

        return $values;
    }


    /**
     * Fetches as many of the specified list of arguments from $_REQUEST that it can retrieve. This
     * will NOT throw an exception or return false if it fails to find one. An argument that is in
     * $inputArray with a null value counts as provided, and its null is returned.
     *
     * @param array $args - array of all the argument names. Each must be a string or an int (the
     * types that array keys can be), so null is not allowed.
     * @param array $inputArray - array from which we are pulling the optional args.
     *
     * @return array - array of retrieved values
     * @throws BadArgumentException - if one of the names in $args is not a string or an int.
     */
    public static function fetchOptionalArgsFromArray(array $args, array $inputArray): array
    {
        $values = [];

        foreach ($args as $arg)
        {
            self::validateArgName($arg);

            # Use array_key_exists() rather than isset(), as isset() is false for a key that exists with a null value.
            if (array_key_exists($arg, $inputArray))
            {
                $values[$arg] = $inputArray[$arg];
            }
        }

        return $values;
    }


    /**
     * Builds the URL of the current page, including any query string, e.g.
     * "https://blog.programster.org/some-page?id=1". The host name has to be given, as there is no
     * safe way to get it from the request: HTTP_HOST comes from the client's Host header, and so can
     * SERVER_NAME, depending on the web server's set up, so either could be spoofed. Only the path
     * and query string come from the request.
     * WARNING: as the path and query string come from the client, they can contain characters such
     * as " and <, so escape the URL (e.g. with htmlspecialchars()) before putting it in HTML.
     * @param string $fqdn - the fully qualified domain name of the site, e.g. "blog.programster.org".
     * It can include a port if the site isn't on the standard one, e.g. "localhost:8080". Surrounding
     * whitespace and any trailing slashes are removed.
     * @param bool $forceHttps - true (the default) to always give an https:// URL. Many sites are
     * behind a proxy or load balancer that handles HTTPS, so the web server only sees plain HTTP,
     * and can't tell the URL should be HTTPS. Set false to use whatever the web server reports
     * instead (see Network::isHttps()).
     * @return string - the full URL of the current page.
     * @throws BadArgumentException - if $fqdn isn't a valid host name (with an optional port), e.g.
     * it includes a scheme such as "https://", or a path.
     * @throws NoWebRequestException - if there is no web request (e.g. this is running from the CLI),
     * so there is no current page.
     */
    public static function getCurrentUrl(string $fqdn, bool $forceHttps = true): string
    {
        $host = rtrim(trim($fqdn), "/");
        $hostParts = explode(":", $host, 2);
        $isValidName = filter_var($hostParts[0], FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) !== false;
        $isValidPort = !isset($hostParts[1]) || preg_match('/^[0-9]{1,5}$/', $hostParts[1]) === 1;

        if ($hostParts[0] === "" || !$isValidName || !$isValidPort)
        {
            throw new BadArgumentException(
                "getCurrentUrl: {$fqdn} isn't a valid host name, e.g. blog.programster.org (with no scheme or path)."
            );
        }

        if (!isset($_SERVER["REQUEST_URI"]))
        {
            throw new NoWebRequestException("getCurrentUrl: there is no current page, as this isn't a web request.");
        }

        # The request line can give the URL in full (e.g. "http://other.host/page"), so only keep its path and query.
        $requestUri = (string)$_SERVER["REQUEST_URI"];

        if (!str_starts_with($requestUri, "/"))
        {
            $path = parse_url($requestUri, PHP_URL_PATH) ?: "/";
            $query = parse_url($requestUri, PHP_URL_QUERY);
            $requestUri = ($query === null || $query === false) ? $path : "{$path}?{$query}";
        }

        $scheme = ($forceHttps || Network::isHttps()) ? "https" : "http";
        return "{$scheme}://{$host}{$requestUri}";
    }


    /**
     * Ensures that a given value is within the given range and if not, moves it to the boundary.
     * This works with numbers, and with dates (e.g. to clamp a DateTime between two others). The
     * value and the boundaries should be the same kind: comparing a number with a date isn't
     * meaningful.
     *
     * @param int|float|\DateTimeInterface $value - the variable to make sure is within range.
     * @param int|float|\DateTimeInterface $max   - the max allowed value.
     * @param int|float|\DateTimeInterface $min   - the minimum allowed value
     *
     * @return int|float|\DateTimeInterface - the clamped input. This is $value if it is within
     * range, otherwise the boundary it was beyond ($max or $min).
     */
    public static function clampValue(
        int|float|\DateTimeInterface $value,
        int|float|\DateTimeInterface $max,
        int|float|\DateTimeInterface $min
    ): int|float|\DateTimeInterface
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
     * Generates a string 'Yes' or 'No' based on the value passed in. This will consider false,
     * null, an empty string, the string "0", the integer 0, the float 0.0 and an empty array as
     * 'false' values, which give 'No'. Everything else gives 'Yes' (note that this includes the
     * string "false").
     * @param mixed $input - the input variable to decide whether to output yes/no on.
     * @return string - "Yes" or "No"
     */
    public static function generateYesNoString(mixed $input): string
    {
        return (in_array($input, self::FALSE_VALUES, true)) ? "No" : "Yes";
    }


    /**
     * Generates a string 'True' or 'False' based on the value passed in. This will consider false,
     * null, an empty string, the string "0", the integer 0, the float 0.0 and an empty array as
     * 'false' values. Everything else is 'true' (note that this includes the string "false").
     * @param mixed $input - the input variable to decide whether to output true or false on.
     * @return string - "True" or "False"
     */
    public static function generateTrueFalseString(mixed $input): string
    {
        return (in_array($input, self::FALSE_VALUES, true)) ? "False" : "True";
    }


    /**
     * Implement a version guard. This will throw an exception if we do not have the required
     * version of PHP that is specified.
     * @param string $reqVersion - required version of PHP, e.g. '8.5.0'
     * @param string $errMsg - optionally specify the message for the exception. If left empty, a
     * message stating the required and current versions is used.
     * @return void
     * @throws PhpVersionTooLowException - if we do not meet the required PHP version. The exception
     * holds both the required and current versions.
     */
    public static function versionGuard(string $reqVersion, string $errMsg = ''): void
    {
        if (version_compare(PHP_VERSION, $reqVersion, '<'))
        {
            if ($errMsg === '')
            {
                $errMsg = 'Required PHP version: ' . $reqVersion .
                                ', current version: ' . PHP_VERSION;
            }

            throw new PhpVersionTooLowException($errMsg, $reqVersion, PHP_VERSION);
        }
    }


    /**
     * Fetches the number of vCPU on a Linux machine. I state vCPU instead of CPU as this includes
     * all hyperthreads/cores. (e.g. an i7 will show up as 8 even though there is only 1 physical
     * processor)
     * @return int - the number of threads this machine can concurrently run.
     * @throws CommandFailedException - if the nproc command failed.
     * @throws UnexpectedOutputException - if nproc did not return a number.
     */
    public static function getNumProcessors(): int
    {
        $output = trim(self::runCommand("nproc --all"));

        if (!ctype_digit($output))
        {
            throw new UnexpectedOutputException("Expected nproc to return a number of processors, but got: {$output}");
        }

        $numProcessors = (int) $output;
        return $numProcessors;
    }


    /**
     * Run a shell command and get its output. Unlike shell_exec(), this throws an exception if the
     * command fails (exits with a non-zero exit code), rather than returning null, and returns an
     * empty string rather than null if the command succeeded but didn't output anything. WARNING -
     * the command is run through the shell, so make sure to escape any values you put into it with
     * escapeshellarg(). Note that if the command is a pipeline (e.g. "foo | bar"), then the exit
     * code is only that of the last command in the pipeline, so a failure earlier in the pipeline
     * may not be detected. WARNING - if the command starts a background process that keeps stdout
     * open (e.g. xclip and wl-copy do this to keep serving the clipboard), this will wait until
     * that background process exits, which may be never. Redirect the command's stdout to /dev/null
     * to avoid this.
     * @param string $command - the command to run.
     * @param string|null $input - optionally provide content to send to the command's stdin. If
     * null (the default), the command inherits this script's stdin.
     * @return string - everything the command wrote to stdout, exactly as it was written (including
     * any trailing newline).
     * @throws CommandFailedException - if the command could not be run, or exited with a non-zero
     * exit code. The exception holds the exit code and anything the command wrote to stderr.
     */
    public static function runCommand(string $command, ?string $input = null): string
    {
        # Send stderr to a temporary file rather than a pipe. If we used a pipe for both stdout and stderr, and the
        # command wrote a lot to stderr while we were waiting on stdout, both sides could block forever.
        $errorFile = tmpfile();

        if ($errorFile === false)
        {
            throw new CommandFailedException($command, -1, "Failed to create a temporary file to capture stderr.");
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
                throw new CommandFailedException($command, -1, "Failed to create a temporary file for stdin.");
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

            throw new CommandFailedException($command, -1, "");
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
            throw new CommandFailedException($command, -1, "Failed to read the command's output.");
        }

        if ($exitCode !== 0)
        {
            throw new CommandFailedException($command, $exitCode, ($errorOutput === false) ? "" : $errorOutput);
        }

        return $output;
    }


    /**
     * Linux specific function that fetches information about the system's memory (RAM and swap)
     * from /proc/meminfo. Based on:
     * http://stackoverflow.com/questions/1455379/get-server-ram-with-php
     * @param MemoryTypeEnum $type - optionally specify which measurement of memory you want, such
     * as how much is available, rather than the total amount of RAM in the system. Please refer to
     * the MemoryTypeEnum for what each one means.
     * @param DataSizeUnitEnum $unit - optionally specify the unit to return the amount of memory
     * in. Defaults to MiB.
     * @return float - the amount of memory in the specified unit. E.g. 1.5 for 1536 KiB in MiB.
     * @throws UnsupportedPlatformException - if there is no /proc/meminfo, as this isn't Linux.
     * @throws FilesystemException - if /proc/meminfo could not be read.
     * @throws UnexpectedOutputException - if /proc/meminfo does not contain a field needed for the
     * requested measurement. Not every field exists on every system.
     */
    public static function getMemory(
        MemoryTypeEnum $type = MemoryTypeEnum::TOTAL,
        DataSizeUnitEnum $unit = DataSizeUnitEnum::MIB,
    ): float
    {
        if (!file_exists("/proc/meminfo"))
        {
            throw new UnsupportedPlatformException("getMemory: this needs /proc/meminfo, so only works on Linux.");
        }

        $meminfoContents = file_get_contents("/proc/meminfo");

        if ($meminfoContents === false)
        {
            throw new FilesystemException("Failed to read /proc/meminfo.");
        }

        $data = explode("\n", $meminfoContents);
        $meminfo = [];

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

        $getKib = function (string $field) use ($meminfo): int {
            if (!array_key_exists($field, $meminfo))
            {
                throw new UnexpectedOutputException("/proc/meminfo does not contain the {$field} field.");
            }

            return (int) $meminfo[$field];
        };

        # The huge page fields are a number of pages rather than an amount of memory, so convert them.
        $hugePagesKib = function () use ($getKib, $type): float {
            return $getKib($type->value) * $getKib('Hugepagesize');
        };

        $memoryKib = match ($type) {
            # Calculated from multiple fields, in the same way as the "free" command.
            MemoryTypeEnum::USED => $getKib('MemTotal') - $getKib('MemAvailable'),
            MemoryTypeEnum::BUFF_CACHE => $getKib('Buffers') + $getKib('Cached') + $getKib('SReclaimable'),
            MemoryTypeEnum::SWAP_USED => $getKib('SwapTotal') - $getKib('SwapFree'),
            MemoryTypeEnum::HUGE_PAGES_TOTAL => $hugePagesKib(),
            MemoryTypeEnum::HUGE_PAGES_FREE => $hugePagesKib(),
            MemoryTypeEnum::HUGE_PAGES_RESERVED => $hugePagesKib(),
            MemoryTypeEnum::HUGE_PAGES_SURPLUS => $hugePagesKib(),

            # Everything else is a single field in KiB, and the enum's value is that field's name.
            default => $getKib($type->value),
        };

        # /proc/meminfo reports in KiB (despite labelling it "kB"), so convert to bytes and then to the requested unit.
        $memory = $unit->convertFromBytes($memoryKib * 1024);

        return $memory;
    }


    /**
     * Sign some data. If we get this data back again, we will know that it came from us untampered
     * (as long as the signature still matches). This is useful for things like tokens sent in
     * emails allowing them to perform actions without having to sign in
     * @param array $data - the data we wish to sign.
     * @param string $secret - the secret key to sign the data with. Keep this private, as anyone
     * who has it can create valid signatures.
     * @return string - the generated signature for the provided data.
     */
    public static function generateSignature(array $data, string $secret): string
    {
        ksort($data);
        $stringForm = json_encode($data, JSON_THROW_ON_ERROR);
        return hash_hmac("sha256", $stringForm, $secret);
    }


    /**
     * Check if the provided data has the correct signature.
     * @param array $data - the data we received that was signed. The signature MUST NOT be in this
     * array.
     * @param string $signature - the signature that came with the data. This is what we will check
     * is valid for the data received.
     * @param string $serverSecret - the server's secret that it uses for generating signatures.
     * @return bool - true if the signature is correct for the data, or false if not (in which case
     * a user probably tried to manipulate the data).
     */
    public static function isValidSignedRequest(array $data, string $signature, string $serverSecret): bool
    {
        $generatedSignature = Core::generateSignature($data, $serverSecret);

        # Use hash_equals() rather than ==, as it takes the same amount of time regardless of where the strings differ,
        # which prevents timing attacks. The known string must be the first parameter.
        return hash_equals($generatedSignature, $signature);
    }


    /**
     * A wrapper around var_dump that will return the result as a string rather than dumping out
     * immediately.
     * @param mixed $variable - the variable you want to get the var_dump of
     * @return string - the result of the var_dump
     */
    public static function var_dump(mixed $variable): string
    {
        ob_start();
        var_dump($variable);
        return ob_get_clean();
    }


    /**
     * Set the content of the user's clipboard (the one used by Ctrl+C / Ctrl+V). On Linux, this
     * supports both Wayland (using wl-copy from the wl-clipboard package) and X11 (using xclip). On
     * macOS this uses pbcopy, and on Windows this uses PowerShell.
     * @param string $content - the content you wish to set in the clipboard.
     * @return void
     * @throws CommandFailedException - if the clipboard could not be set, such as if the required
     * tool isn't installed.
     * @throws UnsupportedPlatformException - if running on an unsupported OS, or on Linux without a
     * graphical session.
     */
    public static function setClipboard(string $content): void
    {
        # Untested. This uses PowerShell rather than clip.exe, as clip.exe mangles UTF-8 text. Set-Clipboard won't
        # accept an empty string, so clear the clipboard instead in that case.
        $windowsCommand = 'powershell -NoProfile -NonInteractive -Command "'
            . '[Console]::InputEncoding = [System.Text.Encoding]::UTF8; '
            . '$text = [Console]::In.ReadToEnd(); '
            . 'if ($text -eq \'\') { Set-Clipboard -Value $null } else { Set-Clipboard -Value $text }"';

        # Both wl-copy and xclip leave a process running in the background to keep serving the clipboard, which keeps
        # stdout open. Send stdout to /dev/null, otherwise runCommand() would wait for it forever. This is a closure,
        # so the clipboard tools are only looked for on Linux.
        $linuxCommand = function (): string {
            return self::useWaylandClipboard("wl-copy")
                ? "wl-copy > /dev/null"
                : "xclip -selection clipboard -in > /dev/null";
        };

        $unsupported = "Running on unsupported OS: " . PHP_OS_FAMILY . " - only Windows, Linux, and macOS are " .
            "supported.";

        $command = match (PHP_OS_FAMILY) {
            "Windows" => $windowsCommand,
            # Untested. pbcopy relies on the locale to know that the text is UTF-8.
            "Darwin" => "LC_CTYPE=UTF-8 pbcopy",
            "Linux" => $linuxCommand(),
            default => throw new UnsupportedPlatformException($unsupported),
        };

        self::runCommand($command, $content);
    }


    /**
     * Get the contents of the user's clipboard (the one used by Ctrl+C / Ctrl+V). On Linux, this
     * supports both Wayland (using wl-paste from the wl-clipboard package) and X11 (using xclip).
     * On macOS this uses pbpaste, and on Windows this uses PowerShell.
     * @return string - the content of the clipboard, exactly as it was copied. This will be an
     * empty string if the clipboard is empty, or holds something other than text (e.g. an image).
     * @throws CommandFailedException - if the clipboard could not be read, such as if the required
     * tool isn't installed.
     * @throws UnsupportedPlatformException - if running on an unsupported OS, or on Linux without a
     * graphical session.
     */
    public static function getClipboard(): string
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
            catch (CommandFailedException $e)
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
            throw new UnsupportedPlatformException(
                "Running on unsupported OS: " . PHP_OS_FAMILY . " - only Windows, Linux, and macOS are supported."
            );
        }

        return $content;
    }


    /**
     * Run a callback, capturing any warning (or notice, or deprecation) it raises, rather than
     * letting it through. This is for wrapping PHP functions that report why they failed with a
     * warning as well as returning false (e.g. mkdir() or file_get_contents()), so that the reason
     * can go in an exception instead. This uses a temporary error handler, which is always removed
     * again afterwards. That works whether or not the application has its own error handler, unlike
     * @ and error_get_last(), as error_get_last() is empty when the application has one. The
     * application's own handler doesn't see the warning, just as with @.
     * @param callable $callback - the code to run, e.g. function () use ($path): bool { return
     * mkdir($path); }.
     * @param string|null $warning - set to the message of the last warning raised, or null if there
     * wasn't one. E.g. "mkdir(): Not a directory".
     * @return mixed - whatever $callback returns.
     */
    public static function runCapturingWarning(callable $callback, ?string &$warning = null): mixed
    {
        $warning = null;

        $captureWarning = function (int $errorNumber, string $errorMessage) use (&$warning): bool {
            $warning = $errorMessage;
            return true;
        };

        set_error_handler($captureWarning);

        try
        {
            $result = $callback();
        }
        finally
        {
            restore_error_handler();
        }

        return $result;
    }


    /**
     * Check that an argument name, for fetchReqArgsFromArray() and fetchOptionalArgsFromArray(), is
     * a string or an int, as those are the only types an array key can be. Anything else, such as
     * null, would be quietly converted to a different key (e.g. null becomes "").
     * @param mixed $arg - the argument name to check.
     * @return void
     * @throws BadArgumentException - if it isn't a string or an int.
     */
    private static function validateArgName(mixed $arg): void
    {
        if (!is_string($arg) && !is_int($arg))
        {
            throw new BadArgumentException(
                "Argument names must be strings or ints, but one is " . get_debug_type($arg) . "."
            );
        }
    }


    /**
     * Work out whether to use the Wayland clipboard tools (wl-clipboard) or the X11 one (xclip) on
     * Linux. The native Wayland tools are preferred when running under Wayland, but if they aren't
     * installed and XWayland is available, we fall back to xclip.
     * @param string $waylandTool - the Wayland tool we want to use (e.g. "wl-copy" or "wl-paste"),
     * so we can check if it is installed.
     * @return bool - true to use the Wayland tools, false to use xclip.
     * @throws UnsupportedPlatformException - if there is no graphical session, and thus no
     * clipboard.
     */
    private static function useWaylandClipboard(string $waylandTool): bool
    {
        $hasWayland = ((string) getenv("WAYLAND_DISPLAY")) !== "";
        $hasX11 = ((string) getenv("DISPLAY")) !== "";

        if (!$hasWayland && !$hasX11)
        {
            throw new UnsupportedPlatformException(
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
    private static function isCommandAvailable(string $command): bool
    {
        $isAvailable = true;

        try
        {
            self::runCommand("command -v " . escapeshellarg($command) . " > /dev/null");
        }
        catch (CommandFailedException $e)
        {
            $isAvailable = false;
        }

        return $isAvailable;
    }
}
