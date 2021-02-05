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
     * Generates a unique id, which can be useful for javascript
     * @param prefix - optional - specify a prefix such as 'accordion' etc.
     * @return string id - the 'unique' id.
     */
    public static function generateUniqueId(string $prefix="") : string
    {
        static $counter = 0;
        $counter++;
        $id = $prefix . $counter;
        return $id;
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
     * Allows us to re-direct the user using javascript when headers have
     * already been submitted.
     *
     * @param string url that we want to re-direct the user to.
     * @param int numSeconds - optional integer specifying the number of
     *                         seconds to delay.
     *
     * @return htmlString - the html to print out in order to redirect the user.
     */
    public static function javascriptRedirectUser(string $url, int $numSeconds = 0) : string
    {
        $htmlString = '';

        $htmlString .=
            "<script type='text/javascript'>" .
                "var redirectTime=" . $numSeconds * 1000 . ";" . PHP_EOL .
                "var redirectURL='" . $url . "';" . PHP_EOL .
                'setTimeout("location.href = redirectURL;", redirectTime);' . PHP_EOL .
            "</script>";

        return $htmlString;
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
     * This is the socket "equivalent" to the sendApiRequest function. However
     * unlike that funciton it does not require the curl library to be
     * installed, and will try to send/recieve information over a direct socket
     * connection.
     *
     * @param string $host - who to send the request to
     * @param int $port - the port number to make the connection on.
     * @param Array $request - map of name/value pairs to send.
     * @param int $bufferSize - optionally define the size (num chars/bytes) of
     *                          the buffer. If this is too small your
     *                          information can get cut off, causing errors.
     *                          10485760 = 10 MiB
     * @param int $timeout - (optional, default 2) the number of seconds before
     *                       connection attempt times out.
     * @param int $attemptsLimit - (optional, default 5) the number of failed
     *                             connection attempts to make before giving up.
     * @return Array - the response from the api in name/value pairs.
     */
    public static function sendTcpRequest(
        string $host,
        int $port,
        $request,
        int $bufferSize=10485760,
        int $timeout=2,
        int $attemptsLimit=100
    )
    {
        # The PHP_EOL endline is so that the reciever knows that is the end of
        # the message with PHP_NORMAL_READ.
        $reqString = \Safe\json_encode($request) . PHP_EOL;

        $protocol = getprotobyname('tcp');
        $socket = socket_create(AF_INET, SOCK_STREAM, $protocol);

        # stream_set_timeout DOES NOT work for sockets created with
        # socket_create or socket_accept.
        # http://www.php.net/manual/en/function.stream-set-timeout.php
        $socketTimeoutSpec = array(
            'sec'  =>$timeout,
            'usec' => 0
        );

        socket_set_option($socket, SOL_SOCKET, SO_RCVTIMEO, $socketTimeoutSpec);
        socket_set_option($socket, SOL_SOCKET, SO_SNDTIMEO, $socketTimeoutSpec);

        $attemptsMade = 0;
        $socketErrors = array();
        $timeStart = time();

        do
        {
            $connected = socket_connect($socket, $host, $port);

            if (!$connected)
            {
                $socketErrorCode   = socket_last_error($socket);
                $socketErrorString = socket_strerror($socketErrorCode);
                $socketErrors[] = $socketErrorString;

                # socket_last_error does not clear the last error after having
                # fetched it, have to  do this manually
                socket_clear_error();

                if ($attemptsMade == $attemptsLimit)
                {
                    $totalWaitTime = time() - $timeStart;
                    $socketErrorsString = print_r($socketErrors, true);

                    $errorMsg =
                        "Failed to make socket connection " . PHP_EOL .
                        "host: [{$host}] " . PHP_EOL .
                        "total time waited: [{$totalWaitTime}]" . PHP_EOL .
                        "socket errors: " . PHP_EOL .
                        $socketErrorsString . PHP_EOL;

                    throw new \Exception($errorMsg);
                }

                $attemptsMade++;

                # The socket may just be "tied up", give it a bit of time
                # before retrying.
                print "Failed to connect so sleeping...." . PHP_EOL;
                sleep(1);
            }
        } while (!$connected); # 110 = timeout error code

        /* @var $socket Socket */
        $wroteBytes = socket_write($socket, $reqString, strlen($reqString));

        if ($wroteBytes === false)
        {
            throw new \Exception('Failed to write request to socket.');
        }

        # PHP_NORMAL_READ indicates end reading on newline
        $serverMessage = socket_read($socket, $bufferSize, PHP_NORMAL_READ);
        $response = \Safe\json_decode($serverMessage, $arrayForm=true);
        socket_shutdown($socket, 2); # 0=shut read, 1=shut write, 2=both
        socket_close($socket);

        return $response;
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
     * Calculates the hostname including the starting http:// or https:// at
     * the beginning. This is useful for linking items by relative source and
     * getting around htaccess url rewrites.
     * @return String  - The url e.g. 'http://www.technostu.com'
     */
    public static function getHostname() : string
    {
        $hostname = $_SERVER['HTTP_HOST'];

        if (isset($_SERVER['HTTPS']))
        {
            $hostname = 'https://' . $hostname;
        }
        else
        {
            $hostname = 'http://' . $hostname;
        }

        return $hostname;
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
     * Fetches what this computers IP address is. Please note that you may wish
     * to run getPublicIp instead which may return a different IP address depending on your network.
     * @param string $interface - the network interface that we want the IP of.
     * @return string - The ip of this machine on that interface. Will be empty if there is no IP.
     */
    public static function getIp(string $interface)
    {
        $command = "ip -o -4 addr list {$interface} | awk '{print $4}' | cut -d/ -f1";
        $result = shell_exec($command);
        return $result;
    }


    /**
     * Determines what this computers public IP address is (this is not
     * necessarily the IP address of the computer, and you may need to setup
     * port forwarding.
     * This is a very quick and dirty method that relies on icanhazip.com
     * remaining the same so use with  caution.
     * @param void
     * @return string $ip - the public ip address of this computer.
     */
    public static function getPublicIp() : string
    {
        return trim(\Safe\file_get_contents('http://icanhazip.com/'));
    }


    /**
     * Checks to see if the specified port is open.
     * @param string $host - the host to check against.
     * @param int $port - the port to check
     * @return $isOpen - true if port is open, false if not.
     */
    public static function isPortOpen(string $host, int $port, string $protocol) : bool
    {
        $protocol = strtolower($protocol);

        if ($protocol != 'tcp' && $protocol != 'udp')
        {
            $errMsg = 'Unrecognized protocol [' . $protocol . '] ' .
                      'please specify [tcp] or [udp]';
            throw new \Exception($errMsg);
        }

        if (empty($host))
        {
            $host = self::getPublicIp();
        }

        foreach ($ports as $port)
        {
            $connection = @fsockopen($host, $port);

            if (is_resource($connection))
            {
                $isOpen = true;
                fclose($connection);
            }

            else
            {
                $isOpen = false;
            }
        }

        return $isOpen;
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
     * Return whether the IP address provided is within the private or reserved.
     * E.g. 192.168. 0.0 – 192.168.255.255, 172.16. 0.0 – 172.31.255.255, or 10.0. 0.0 – 10.255.255.255.
     * This also applies to IPv6 though.
     * @param string $ip - the IP address to check
     * @return bool - true if a private/reserved IP, false if not.
     */
    public static function isPrivateIp(string $ip) : bool
    {
        $result = filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        );

        return $result === false;
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
