<?php

namespace Irap\CoreLibs;


/*
 * This is a general library that can be used to "extend" the standard libary.
 * Only functions that are used fruquently and are completely generic should
 * be placed here. Wherever possible, please use more specific libraries such as the
 * string_lib, time_lib, array_lib, and html_generator.
 */

class Core
{    
    /**
     * Wrapping throw new Exception in a function so that can be used like 
     * 'or throwException(message)' in place of 'or die(message)'
     * 
     * @param message - optional message to be put in the exception.
     * 
     * @return void - throws an exception.
     */
    public static function throw_exception($message="") 
    { 
        global $globals;
        
        if (isset($globals['DEBUG']) && $globals['DEBUG'] == true)
        {
            ob_start();
            debug_print_backtrace();
            $stringBacktrace = '' . ob_get_clean();
            $message .= ' ' . $stringBacktrace;
        }
        
        if (!self::is_cli())
        {
            $message = nl2br($message);
        }
        
        throw new \Exception($message); 
    }
    
    
    /**
     * Determines whether php is running as a CLI script or a website.
     * @param void
     * @return result (boolean) - true if CLI false if website.
     */
    public static function is_cli()
    {
        $result = false;
        
        if (defined('STDIN') )
        {
            $result = true;
        }
        
        return $result;
    }
    
    
    /**
     * Make PHP slightly more like java and allow printing a line. If this is in a web app
     * then it will use <br /> as well.
     * @param String $message - the string to print out.
     * @return void - prints out immediately.
     */
    public static function println($message)
    {   
        if (!self::is_cli())
        {
            $message .= '<br />';
        }

        print $message . PHP_EOL;
    }
    
    
    /**
     * Function for outputting debug statments if debugging is switched on.
     * @param message - the message to be logged.
     * @return void - prints to the screen
     */
    public static function debug_println($message)
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
     * @return id - the 'unique' id.
     */
    public static function generate_unique_id($prefix="")
    {
        static $counter = 0;
        $counter++;
        $id = $prefix . $counter;
        return $id;
    }

    
    /**
     * Tiny helper function to help ensure that exit is always called after redirection and allows
     * the developer to only have to remember the location they want. (wont forget 'location:')
     * 
     * @param location - the location/address/url you want to redirect to.
     * 
     * @return void - redirects the user and quits.
     */
    public static function redirect_user($location)
    {
        header("location: " . $location);
        exit();
    }

    
    /**
     * Allows us to re-direct the user using javascript when headers have already been submitted.
     * 
     * @param url that we want to re-direct the user to.
     * @param numSeconds - optional integer specifying the number of seconds to delay.
     * @param message - optional message to display to the user whilst waiting to change page.
     * 
     * @return htmlString - the html to print out in order to redirect the user.
     */
    public static function javascript_redirect_user($url, $numSeconds = 0, $message = '')
    {
        $htmlString = '';

        if ($message != '')
        {
            $htmlString .= $message . "<br><br>";
        }

        $htmlString .=
            "You are being redirected. <a href='" . $url . "'>Click here</a> If you are not " .
            "automatically redirected within " . $numSeconds . " seconds.";
            
        $htmlString .= 
            "<script type='text/javascript'>" .
                "var redirectTime=" . $numSeconds * 1000 . ";" . PHP_EOL .
                "var redirectURL='" . $url . "';" . PHP_EOL .
                'setTimeout("location.href = redirectURL;", redirectTime);' . PHP_EOL .
            "</script>";
            
        return $htmlString;
    }
    
    
    /**
     * Generates the SET part of a mysql query with the provided name/value pairs provided
     * @param pairs - assoc array of name/value pairs to go in mysql
     * @param bool $wrap_with_quotes - optionally set to false to disable quote wrapping if you have already taken
     *                                 care of this.
     * @return query - the generated query string that can be appended.
     */
    public static function generate_mysql_pairs($pairs, $wrap_with_quotes=true)
    {
        $query = '';
        
        foreach ($pairs as $name => $value)
        {
            if ($wrap_with_quotes)
            {
                if ($value === null)
                {
                    $query .= "`" . $name . "`= NULL, ";
                }
                else
                {
                    $query .= "`" . $name . "`='" . $value . "', ";
                }
            }
            else
            {
                if ($value === null)
                {
                    $query .= $name . "= NULL, ";
                }
                else
                {
                    $query .= $name . "=" . $value . ", ";
                }
            }
        }
        
        $query = substr($query, 0, -2); # remove the last comma.
        return $query;
    }
    
    
    /**
     * Generates the Select as section for a mysql query (but does not include SELECT) directly.
     * example: $query = "SELECT " . generateSelectAs($my_columns) . ' WHERE 1';
     * @param type $columns - map of sql column names to the new names
     * @param bool $wrap_with_quotes - optionally set to false if you have taken care of quotation already. Useful
     *                                 if you are doing something like table1.`field1` instead of field1
     * @return string - the genereted query section
     */
    public static function generate_select_as_pairs($columns, $wrap_with_quotes=true)
    {
        $query = '';
        
        foreach ($columns as $column_name => $new_name)
        {
            if ($wrap_with_quotes)
            {
                $query .= '`' . $column_name . '` AS `' . $new_name . '`, ';
            }
            else
            {
                $query .= $column_name . ' AS ' . $new_name . ', ';
            }
            
        }
        
        $query = substr($query, 0, -2);
        
        return $query;
    }
    
    
    /**
     * Generates the source link for the latest jquery ui source so that you dont have to remember 
     * it, or store it locally on your server and keep updating it.
     * @param String $naming_prefix - the name to give the process. This will auto append the count
     *                               of this process already running in order to make it unique.
     * @return html - the html for including jquery ui in your website.
     */
    public static function setCliTitle($naming_prefix)
    {
        $num_running = self::get_num_proc_running($naming_prefix);
        cli_set_process_title($naming_prefix . $num_running);
    }
    
    
    /**
     * Fetches the number of processes running with the given search name (have it in their name)
     * @param String $title - the string to search for a process by (e.g. its name/title)
     * @return int
     */
    public static function get_num_proc_running($title)
    {
        $processes = explode(PHP_EOL, shell_exec("ps -ef | tr -s ' ' | cut -d ' ' -f 8"));
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
     * 
     * @param url - the url where the api is located. e.g. technostu.com/api
     * @param parameters - associative array of name value pairs for sending to the api server.
     * 
     * @return ret - array formed from decoding json message retrieved from xml api
     */
    public static function send_api_request($url, $parameters)
    {
        global $globals;
      
        $query_string = http_build_query($parameters, '', '&');
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $query_string);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
        
        $jsondata = curl_exec($ch);
        if (curl_error($ch))
        {
            self::throw_exception("Connection Error: " . curl_errno($ch) . ' - ' . curl_error($ch));
        }
        
        curl_close($ch);
        $ret = json_decode($jsondata); # Decode JSON String
        
        if ($ret == null)
        {
            self::throw_exception('Recieved a non json response from API: ' . $jsondata);
        }
        
        return $ret;
    }
    
    
    /**
     * This is the socket "equivalent" to the sendApiRequest function. However unlike
     * that funciton it does not require the curl library to be installed, and will try to
     * send/recieve information over a direct socket connection.
     *
     * @param Array $request - map of name/value pairs to send.
     * @param string $host - the host wish to send the request to.
     * @param int $port - the port number to make the connection on.
     * @param int $buffer_size - optionally define the size (num chars/bytes) of the buffer. If this
     *                     is too small your information can get cut off, causing errors.
     *                     10485760 = 10 MiB
     * @param int $timeout - (optional, default 2) the number of seconds before connection attempt 
     *                       times out.
     * @param int $attempts_limit - (optional, default 5) the number of failed connection attempts to 
     *                         make before giving up.
     * @return Array - the response from the api in name/value pairs.
     */
    public static function send_tcp_request($host, 
                                            $port, 
                                            $request,
                                            $buffer_size=10485760, 
                                            $timeout=2, 
                                            $attempts_limit=100)
    {
        # The PHP_EOL endline is so that the reciever knows that is the end of the message with
        # PHP_NORMAL_READ.
        $request_string = json_encode($request) . PHP_EOL;
        
        $protocol = getprotobyname('tcp');
        $socket = socket_create(AF_INET, SOCK_STREAM, $protocol);
        
        # stream_set_timeout DOES NOT work for sockets created with socket_create or socket_accept.
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
                
                # socket_last_error does not clear the last error after having fetched it, have to 
                # do this manually
                socket_clear_error();
                
                if ($attempts_made == $attempts_limit)
                {
                    $errorMsg = 
                        "Failed to make socket connection " . PHP_EOL .
                        "host: [" . $host . "] " . PHP_EOL .
                        "total time waited: [" . time() - $timeStart . "]" . PHP_EOL .
                        "socket errors: " . PHP_EOL . 
                        print_r($socketErrors, true) . PHP_EOL;
                    
                    self::throw_exception($errorMsg);
                }
                
                $attempts_made++;
                
                # The socket may just be "tied up", give it a bit of time before retrying.
                print "Failed to connect so sleeping...." . PHP_EOL;
                sleep(1);
            }
        } while (!$connected); # 110 = timeout error code
        
        /* @var $socket Socket */
        $wroteBytes = socket_write($socket, $request_string, strlen($request_string));
        
        if ($wroteBytes === false)
        {
            self::throw_exception('Failed to write request to socket.');
        }
        
        # PHP_NORMAL_READ indicates end reading on newline
        $serverMessage = socket_read($socket, $buffer_size, PHP_NORMAL_READ);
        $response = json_decode($serverMessage, $arrayForm=true);
        socket_shutdown($socket, 2); # 0=shut read, 1=shut write, 2=both
        socket_close($socket);
        
        return $response;
    }
    
    
    /**
     * Fetches the specified list of arguments from $_REQUEST. This will return false if any 
     * parameters could not be found.
     * 
     * @param args - array of all the argument names.
     * 
     * @return result - false if any parameters could not be found.
     */
    public static function fetch_required_args($args)
    {
        $values = self::fetch_required_args_from_array($args, $_REQUEST);
        return $values;
    }
    
    
    /**
     * Fetches the specified list of arguments from $_REQUEST. This will return false if any 
     * parameters could not be found.
     * 
     * @param array $args - array of all the argument names.
     * @param array $input_array - array from which we are pulling the required args.
     * 
     * @return result - false if any parameters could not be found.
     */
    public static function fetch_required_args_from_array($args, $input_array)
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
                self::throw_exception("Required parameter: " . $arg . " not specified");
                break;
            }
        }

        return $values;
    }
    
    
    /**
     * Fetches as many of the specified list of arguments from $_REQUEST that it can retrieve.
     * This will NOT throw an exception or return false if it fails to find one.
     * @param args - array of all the argument names.
     * @return values - array of retrieved values
     */
    public static function fetch_optional_args($args)
    {
        $values = self::fetch_optional_args_from_array($args, $_REQUEST);
        return $values;
    }
    
    
    /**
     * Fetches as many of the specified list of arguments from $_REQUEST that it can retrieve.
     * This will NOT throw an exception or return false if it fails to find one.
     * 
     * @param array $args - array of all the argument names.
     * @param array $input_array - array from which we are pulling the optional args.
     * 
     * @return values - array of retrieved values
     */
    public static function fetch_optional_args_from_array($args, $input_array)
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
     * Retrieves the specified arguments from REQUEST. This will throw an exception if a required
     * argument is not present, but not if an optional argument is not.
     * 
     * @param reqArgs - array list of required arguments that must exist
     * @param optionalArgs - array list of arguments that should be retrieved if present.
     * 
     * @return values - map of argument name/value pairs retrieved.
     */
    public static function fetch_args($req_args, $optional_args)
    {
        $values = self::fetch_required_args($req_args);
        $values = array_merge($values, self::fetch_optional_args($optional_args));
        return $values;
    }
    
    
    
    /**
     * Builds url of the current page, excluding any ?=&stuff,   
     * @param void
     * @return pageURL - full page url of the current page e.g. https://www.google.com/some-page
     */
    public static function get_current_url() 
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
     * Ensures that a given value is within the given range and if not, moves it to the boundary.
     * Note that this can work for objects if you install the following extension:
     * http://pecl.php.net/package/operator
     * 
     * @param mixed $value - the variable to make sure is within range.
     * @param mixed $max   - the max allowed value.
     * @param mixed $min   - the minimum allowed value
     * 
     * @return $value - the clamped input.
     */
    public static function clamp_value($value, $max, $min)
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
     * Safely retrieve variables from POST or GET. This needs to protect from injection attacks etc. 
     * 
     * @param mysqli $mysqli - the mysqli resource returned from connecting to the database.
     * @param varName - the name of the variable that was posted.
     * @param extraParams - extra parameters such as whether to 
     * 
     * @return variable - the safely retrieved value
     */
    public static function safely_get($mysqli, $var_name, $extra_params=array())
    {
        if (!isset($_REQUEST[$var_name]))
        {
            self::throw_exception("Could not get variable:" . $var_name);
        }

        $variable = $_REQUEST[$var_name];

        if (isset($extra_params['urldecode']) && $extra_params['urldecode'] == true)
        {
            $variable = urldecode($variable);
        }

        $variable = stripslashes($variable);
        $variable = strip_tags($variable);
        $variable = mysqli_real_escape_string($mysqli, $variable);
        
        return $variable;
    }
    
    
    /**
     * Script function (not for websits) Fetches the password from the shell without it being 
     * displayed whilst being typed. Only works on *nix systems and requires shell_exec and stty.
     * 
     * @param stars - (optional) set to false to stop outputting stars as user types password. This 
     *                prevents onlookers seeing the password length but does make more difficult.
     * 
     * @return string - the password that was typed in. (any text entered before hitting return)
     */
    public static function get_password_from_user_input($stars = true)
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
     * Calculates the hostname including the starting http:// or https:// at the beginning. This is 
     * useful for linking items by relative source and getting around htaccess url rewrites. I 
     * believe php 5.3 has gethostname() function but our server is centos php 5.2
     * 
     * @param void
     * 
     * @return hostname - sting of url e.g. 'http://www.technostu.com'
    */
    public static function get_hostname()
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
    public static function generate_yes_no_string($input)
    {
        $result = 'Yes';
        
        if ($input == "0" || $input == 0 || $input == false)
        {
            $result = 'No';
        }
        
        return $result;
    }
    
    
    /**
     * Generates a string 'True' or 'False' based on whether the value passed in.
     * Note that this will consider string 0 or a 0 integer as 'false' values.
     * @param input - the input variable to decide whether to output true or false on.
     * @return 
     */
    public static function generate_true_false_string($input)
    {
        $result = 'True';
        
        if ($input == "0" || $input == 0 || $input == false)
        {
            $result = 'False';
        }
        
        return $result;
    }
    
    
    /**
     * Sets a variable to the sepcified default if it is not set within the $_REQUEST superglobal.
     * You can think of this as overriding the default if it is set in the $_REQUEST superglobal. 
     * 
     * @param variableName - the name of the variable if it would appear within the $_REQUEST
     * @param defaultValue - the value to set if the var is not set within $_REQUEST
     * 
     * @return returnVar - the calculated resulting value. (default value if not set) 
     */
    public static function override_if_set($variable_name, $default_value)
    {
        $returnVar = $default_value;
        
        if (isset($_REQUEST[$variable_name]))
        {
            $returnVar = $_REQUEST[$variable_name];
        }
        
        return $returnVar;
    }
    
    
    /**
     * Implement a version guard. This will throw an exception if we do not have the required
     * version of PHP that is specified.
     * @param String $req_version - required version of php, e.g '5.4.0'
     * @return void - throws an exception if we do not meet the required php version.
     */
    public static function version_guard($req_version, $err_msg='')
    {
        if (version_compare(PHP_VERSION, $req_version) == -1) 
        {
            if ($err_msg == '')
            {
                $err_msg = 'Required PHP version: ' . $req_version . 
                                ', current Version: ' . PHP_VERSION;    
            }
            
            die($err_msg); 
        }
    }
    
    
    /**
     * Fetches what this computers IP address is. Please note that you may wish to run getPublicIp 
     * instead which may return a different IP address depending on your network.
     * @param string $interface - the network interface that we want the IP of, defaults to eth0
     * @return string - The ip of this machine on that interface. Will be empty if there is no IP.
     */
    public static function get_ip($interface = 'eth0')
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
     * Determines what this computers public IP address is (this is not necessarily the IP address 
     * of the computer, and you may need to setup port forwarding.
     * This is a very quick and dirty method that relies on icanhazip.com remaining the same so use 
     * with  caution.
     * @param void
     * @return string $ip - the public ip address of this computer.
     */
    public static function get_public_ip()
    {
        $ip = file_get_contents('http://icanhazip.com/');
        $ip = trim($ip);
        return $ip;
    }
    
    
    /**
     * Checks to see if the specified port is open.
     * @param int $por_number - the number of the port to check
     * @param $host - optional - the host to check against. Good for testing not just our outbound
     *                           but their inbound. If not specified just checking our own public IP
     * @return $isOpen - true if port is open, false if not.
     */
    public static function is_port_open($por_number, $protocol, $host='')
    {
        $protocol = strtolower($protocol);
        
        if ($protocol != 'tcp' && $protocol != 'udp')
        {
            $errMsg = 'Unrecognized protocol [' . $protocol . '] please specify [tcp] or [udp]';
            self::throw_exception($errMsg);
        }
        
        if (empty($host))
        {
            $host = self::get_public_ip();
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
     * Fetches the number of vCPU on a Linux machine. I state vCPU instead of CPU as this includes
     * all hyperthreads/cores. (e.g. an i7 will show up as 8 even though there is only 1 physical
     * processor)
     * @return int - the number of threads this machine can concurrently run.
     */
    public static function get_num_processsors()
    {
        $cmd = "cat /proc/cpuinfo | grep processor | wc -l";
        $numProcessors = intval(shell_exec($cmd));
        return $numProcessors;
    }
    
    
    /**
     * Linux specific function that fetches information on how much RAM is in the system in 
     * MiB (base 2, not base 10)
     * @param $availableOnly - optionally specify that you want to know only how much ram is free
     *                         and not the total amount of RAM in the system.
     * Based on: http://stackoverflow.com/questions/1455379/get-server-ram-with-php
     */
    public static function get_ram($availableOnly=false)
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
     * Generate a php config file to have the setting provided. This is useful if we want to be
     * able to update our config file through code, such as a web ui to upadate settings. Platforms
     * like wordpress allow updating the settings, but do this through a database.
     * @param mixed $settings - the array or variable that we want to save to the file
     * @param $variable_name - the name of the settings variable so that it is reloaded correctly
     * @param $filePath - the path to the file where we want to save the settings. (overwritten)
     * @return void - creates a config file, or throws an exception if failed.
     * @throws Exception if failed to write to the specified filePath, e.g dont have permissions.
     */
    public static function generate_config_file($settings, $variable_name, $filePath)
    {
        $var_str = var_export($settings, true);

        $output = 
            '<?php' . PHP_EOL .
            '$' . $variable_name . ' = ' . $var_str . ';';

        # file_put_contents returns num bytes written or boolean false if failed.
        $wroteFile = file_put_contents($filePath, $output);

        if ($wroteFile === FALSE)
        {
            throw new \Exception("Failed to generate config file. Check permissions!");
        }
    }
}