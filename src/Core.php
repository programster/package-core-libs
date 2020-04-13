<?php

/*
 * This is a general library that can be used to "extend" the standard libary.
 * Only functions that are used frequently and are completely generic should
 * be placed here. Wherever possible, please use more specific libraries such
 * as the StringLib, Filesystem, and HtmlGenerator
 */

namespace Programster\CoreLibs;

use function Safe\file_get_contents;
use function Safe\json_decode;
use function Safe\json_encode;


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
     * Output messages only if debugging is enabled (DEBUG defined and true)
     * @param string message - the message to be logged.
     */
    public static function debugPrintln(string $message)
    {
        global $globals;

        if (isset($globals['DEBUG']) && $globals['DEBUG'] == true)
        {
            self::println($message);
        }
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
     * Sends an api request through the use of CURL
     * @param string url - the url where the api is located.
     * @param array parameters - name value pairs for sending to the api server
     * @param string $requestType - the request type. One of GET, POST, PUT, PATCH or DELETE
     * @param array $headers - name/value pairs for headers. Useful for authentication etc.
     * @return stdObject - json response object from the api server
     * @throws \Exception
     */
    public static function sendApiRequest(
        string $url,
        array $parameters,
        string $requestType="POST",
        array $headers=array()
    )
    {
        $allowedRequestTypes = array("GET", "POST", "PUT", "PATCH", "DELETE");
        $requestTypeUpper = strtoupper($requestType);

        if (!in_array($requestTypeUpper, $allowedRequestTypes))
        {
            throw new \Exception("API request needs to be one of GET, POST, PUT, PATCH, or DELETE.");
        }

        if ($requestType === "GET")
        {
            $ret = self::sendGetRequest($url, $parameters);
        }
        else
        {
            $query_string = http_build_query($parameters, '', '&');

            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);

            switch ($requestTypeUpper)
            {
                case "POST":
                {
                    curl_setopt($ch, CURLOPT_POST, 1);
                }
                break;

                case "PUT":
                case "PATCH":
                case "DELETE":
                {
                    curl_setopt($this->m_ch, CURLOPT_CUSTOMREQUEST, $requestTypeUpper);
                }
                break;

                default:
                {
                    throw new \Exception("Unrecognized request type.");
                }
            }

            curl_setopt($ch, CURLOPT_TIMEOUT, 30);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $query_string);

            // @TODO - S.P. to review...
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);

            // Manage if user provided headers.
            if (count($headers) > 0)
            {
                $headersStrings = array();

                foreach ($headers as $key=>$value)
                {
                    $headersStrings[] = "{$key}: {$value}";
                }

                curl_setopt($this->m_ch, CURLOPT_HTTPHEADER, $this->m_headers);
            }

            $jsondata = curl_exec($ch);

            if (curl_error($ch))
            {
                $errMsg = "Connection Error: " . curl_errno($ch) .
                          ' - ' . curl_error($ch);

                throw new \Exception($errMsg);
            }

            curl_close($ch);
            $ret = json_decode($jsondata); # Decode JSON String

            if ($ret == null)
            {
                $errMsg = 'Recieved a non json response from API: ' . $jsondata;
                throw new \Exception($errMsg);
            }
        }

        return $ret;
    }


    /**
     * Sends a GET request to a RESTful API through cURL.
     *
     * @param string url - the url where the api is located.
     * @param array parameters - optional array of name value pairs for sending to
     *                           the RESTful API.
     * @param bool arrayForm - optional - set to true to return an array instead of
     *                                    a stdClass object.
     *
     * @return stdObject - json response object from the api server
     */
    public static function sendGetRequest(string $url, array $parameters=array(), bool $arrayForm=false)
    {
        if (count($parameters) > 0)
        {
            $query_string = http_build_query($parameters, '', '&');
            $url .= $query_string;
        }

        # Get cURL resource
        $curl = curl_init();

        # Set some options - we are passing in a useragent too here
        $curlOptions = array(
            CURLOPT_RETURNTRANSFER => 1,
            CURLOPT_URL => $url,
        );

        curl_setopt_array($curl, $curlOptions);

        # Send the request
        $rawResponse = curl_exec($curl);

        # Close request to clear up some resources
        curl_close($curl);

        # Convert to json object.
        $responseObj = json_decode($rawResponse, $arrayForm); # Decode JSON String

        if ($responseObj == null)
        {
            $errMsg = 'Recieved a non json response from API: ' . $rawResponse;
            throw new \Exception($errMsg);
        }

        return $responseObj;
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
        $reqString = json_encode($request) . PHP_EOL;

        $protocol = getprotobyname('tcp');
        $socket = socket_create(AF_INET, SOCK_STREAM, $protocol);

        # stream_set_timeout DOES NOT work for sockets created with
        # socket_create or socket_accept.
        # http://www.php.net/manual/en/function.stream-set-timeout.php
        $socket_timout_spec = array(
            'sec'  =>$timeout,
            'usec' => 0
        );

        socket_set_option($socket, SOL_SOCKET, SO_RCVTIMEO, $socket_timout_spec);
        socket_set_option($socket, SOL_SOCKET, SO_SNDTIMEO, $socket_timout_spec);

        $attempts_made = 0;
        $socketErrors = array();
        $timeStart = time();

        do
        {
            $connected = socket_connect($socket, $host, $port);

            if (!$connected)
            {
                $socket_error_code   = socket_last_error($socket);
                $socket_error_string = socket_strerror($socket_error_code);
                $socketErrors[] = $socket_error_string;

                # socket_last_error does not clear the last error after having
                # fetched it, have to  do this manually
                socket_clear_error();

                if ($attempts_made == $attemptsLimit)
                {
                    $errorMsg =
                        "Failed to make socket connection " . PHP_EOL .
                        "host: [" . $host . "] " . PHP_EOL .
                        "total time waited: [" . time() - $timeStart . "]" . PHP_EOL .
                        "socket errors: " . PHP_EOL .
                        print_r($socketErrors, true) . PHP_EOL;

                    throw new \Exception($errorMsg);
                }

                $attempts_made++;

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
        $response = json_decode($serverMessage, $arrayForm=true);
        socket_shutdown($socket, 2); # 0=shut read, 1=shut write, 2=both
        socket_close($socket);

        return $response;
    }


    /**
     * Fetches the specified list of arguments from $_REQUEST. This will return
     * false if any parameters could not be found.
     *
     * @param args - array of all the argument names.
     *
     * @return result - false if any parameters could not be found.
     */
    public static function fetchReqArgs(array $args)
    {
        $values = self::fetchReqArgsFromArray($args, $_REQUEST);
        return $values;
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
     * Fetches as many of the specified list of arguments from $_REQUEST that it
     * can retrieve.
     * This will NOT throw an exception or return false if it fails to find one.
     * @param args - array of all the argument names.
     * @return values - array of retrieved values
     */
    public static function fetchOptionalArgs(array $args)
    {
        $values = self::fetchOptionalArgsFromArray($args, $_REQUEST);
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
     * Retrieves the specified arguments from REQUEST. This will throw an
     * exception if a required argument is not present, but not if an optional
     * argument is not.
     *
     * @param array reqArgs - required arguments that must exist
     * @param array optionalArgs - arguments that should be retrieved if exist
     *
     * @return values - map of argument name/value pairs retrieved.
     */
    public static function fetchArgs(array $reqArgs, array $optionalArgs)
    {
        $values = self::fetchReqArgs($reqArgs);
        $values = array_merge($values, self::fetchOptionalArgs($optionalArgs));
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
        $result = 'Yes';

        if ($input == "0" || $input == 0 || $input == false)
        {
            $result = 'No';
        }

        return $result;
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
        $result = 'True';

        if ($input == "0" || $input == 0 || $input == false)
        {
            $result = 'False';
        }

        return $result;
    }


    /**
     * Sets a variable to the sepcified default if it is not set within the
     * $_REQUEST superglobal. You can think of this as overriding the default
     * if it is set in the $_REQUEST superglobal.
     *
     * @param string $varName - the name of the variable if it would appear
     *                          within the $_REQUEST
     * @param mixed $defaultValue - the value to set if the var is not set
     *                              within $_REQUEST
     *
     * @return mixed - the resulting value. (default value if not set)
     */
    public static function overrideIfSet($varName, $defaultValue)
    {
        $returnVar = $defaultValue;

        if (isset($_REQUEST[$varName]))
        {
            $returnVar = $_REQUEST[$varName];
        }

        return $returnVar;
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
     * to run getPublicIp instead which may return a different IP address
     * depending on your network.
     * @param string $interface - the network interface that we want the IP of,
     *                            defaults to eth0
     * @return string - The ip of this machine on that interface. Will be empty
     *                  if there is no IP.
     */
    public static function getIp(string $interface = 'eth0')
    {
        $command =
            'ifconfig ' . $interface . ' | ' .
            'grep "inet addr" |  ' .
            'awk \'{print $2;}\' | ' .
            'cut -d : -f 2';

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
        $ip = file_get_contents('http://icanhazip.com/');
        $ip = trim($ip);
        return $ip;
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
        $data = explode("\n", file_get_contents("/proc/meminfo"));
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
     * Generate a php config file to have the setting provided. This is useful
     * if we want to be able to update our config file through code, such as a
     * web ui to upadate settings. Platforms like wordpress allow updating the
     * settings, but do this through a database.
     * @param mixed $settings       - array or variable that we want to save to
     *                                the file
     * @param string $variableName  - name of the settings variable so that it
     *                                is reloaded correctly
     * @param string $filePath      - path to the file where we want to save the
     *                                settings. (overwritten)
     * @return void - creates a config file, or throws an exception if failed.
     * @throws Exception if failed to write to the specified filePath, e.g dont
     *                   have permissions.
     */
    public static function generateConfig($settings, $variableName, $filePath)
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
     * Converts a raw password into a hash using PHP 5.5's new hashing method
     * @param String $rawPassword - the password we wish to hash
     * @param int $cost - The two digit cost parameter is the base-2 logarithm
     *                    of the iteration count for the underlying
     *                    Blowfish-based hashing algorithmeter and must be in
     *                    range 04-31
     * @return string - the generated hash
     */
    public static function generatePasswordHash(string $rawPassword, int $cost=11) : string
    {
        $cost = intval($cost);
        $cost = self::clampValue($cost, $max=31, $min=4);

        # has to be 2 digits, eg. 04
        if ($cost < 10)
        {
            $cost = "0" . $cost;
        }

        $options = array('cost' => $cost);

        $hash = password_hash($rawPassword, PASSWORD_BCRYPT, $options);
        return $hash;
    }


    /**
     * Counterpart to generate_password_hash. This function should be used to
     * verify that the provided password is correct. This just wraps around
     * password_verify (req 5.5) which automatically knows which algo and cost
     * to use as they are burried in the hash.
     * @param string $rawPassword - the raw password that the user entered.
     * @param string $expectedHash - the hash that we are expecting
     *                              (generated from generate_password_hash)
     * @return boolean - true if valid, false if not.
     */
    public static function verifyPassword(string $rawPassword, string $expectedHash) : bool
    {
        $verified = false;

        if (password_verify($rawPassword, $expectedHash))
        {
            $verified = true;
        }

        return $verified;
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
        $stringForm = json_encode($data);
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
}
