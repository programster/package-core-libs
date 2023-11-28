<?php

/*
 * This is a general library that can be used to "extend" the standard libary.
 * Only functions that are used frequently and are completely generic should
 * be placed here. Wherever possible, please use more specific libraries such
 * as the StringLib, Filesystem, and HtmlGenerator
 */

namespace Programster\CoreLibs;


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
     * Determines whether php is running as a CLI script or a website.
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
     * Make PHP slightly more like java and allow printing a line. If this is
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
     * location they want. (wont forget 'location:')
     *
     * @param location - the location/address/url you want to redirect to.
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
     */
    public static function getNumProcRunning(string $title) : int
    {
        $cmd = "ps -ef | tr -s ' ' | cut -d ' ' -f 8";
        $processes = explode(PHP_EOL, shell_exec($cmd));
        $numRunning = 0;

        # starts with our title and has one or more numbers afterwards.
        $regExp = "/^" . $title . "[0-9]+/";

        foreach ($processes as $processName)
        {
            if (preg_match($regExp, $processName))
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
     * Builds url of the current page, excluding any ?=&stuff,
     * @param void
     * @return pageURL - full page url of the current page
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
     * @param String $reqVersion - required version of php, e.g '5.4.0'
     * @throws an exception if we do not meet the required php
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
     */
    public static function getNumProcessors() : int
    {
        $cmd = "cat /proc/cpuinfo | grep processor | wc -l";
        $numProcessors = intval(shell_exec($cmd));
        return $numProcessors;
    }


    /**
     * Linux specific function that fetches information on how much RAM is in
     * the system in MiB (base 2, not base 10)
     * @param bool $availableOnly - optionally specify that you want to know
     *                              onlyhow much ram is free and not the total
     *                              amount of RAM in the system.
     * Based on:
     * http://stackoverflow.com/questions/1455379/get-server-ram-with-php
     */
    public static function getRam(bool $availableOnly=false)
    {
        $data = explode("\n", \Safe\file_get_contents("/proc/meminfo"));
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

        $memory = $meminfo['MemTotal'];

        if ($availableOnly)
        {
            $memory = $meminfo['MemFree'];
        }

        # Convert from KiB to MiB
        $memory = $memory / 1024.0;

        return $memory;
    }


    /**
     * Generate a php config file to have the setting provided. This is useful if we want to be able to update our
     * config file through code, such as a web ui to upadate settings. Platforms like wordpress allow updating the
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
        $stringForm = \Safe\json_encode($data);
        return hash_hmac("sha256", $stringForm, $secret);
    }


    /**
     * Check if the provided data has the correct signature.
     * @param array $data - the data we received that was signed. The signature MUST NOT be in this
     *                      array.
     * @param string $signature - the signature that came with the data. This is what we will check
     *                            if is valid for the data received.
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
     * Set the content of the users clipboard
     * @param string $content - the content you wish to set int the clipboard.
     * @return void
     */
    public static function setClipboard(string $content)
    {
        if (PHP_OS_FAMILY==="Windows")
        {
            // works on windows 7 +
            $clip = popen("clip","wb");
        }
        elseif (PHP_OS_FAMILY==="Linux")
        {
            // tested, works on ArchLinux
            $clip = popen('xclip -selection clipboard','wb');
        }
        elseif (PHP_OS_FAMILY==="Darwin")
        {
            // untested!
            $clip = popen('pbcopy','wb');
        }
        else
        {
            throw new \Exception("running on unsupported OS: " . PHP_OS_FAMILY . " - only Windows, Linux, and MacOS supported.");
        }

        $written=fwrite($clip, $content);
        return (pclose($clip)===0 && strlen($content)===$written);
    }


    /**
     * Get the contents of the user's clipboard
     * @return string - the content of the clipboard.
     */
    public static function getClipboard() : string
    {
        if (PHP_OS_FAMILY === "Windows")
        {
            // works on windows 7 + (PowerShell v2 + )
            return substr(shell_exec('powershell -sta "add-type -as System.Windows.Forms; [windows.forms.clipboard]::GetText()"'),0,-2);
        }
        elseif (PHP_OS_FAMILY === "Linux")
        {
            // untested! but should work on X.org-based linux GUI's
            return substr(shell_exec('xclip -out -selection primary'), 0, -1);
        }
        elseif (PHP_OS_FAMILY === "Darwin")
        {
            // untested!
            return substr(shell_exec('pbpaste'), 0, -1);
        }
        else
        {
            throw new \Exception("running on unsupported OS: ".PHP_OS_FAMILY." - only Windows, Linux, and MacOS supported.");
        }
    }
}
