<?php

/*
 * This is a general library that can be used to "extend" the standard libary.
 * Only functions that are used frequently and are completely generic should
 * be placed here. Wherever possible, please use more specific libraries such
 * as the StringLib, Filesystem, and HtmlGenerator
 */

namespace Programster\CoreLibs;


use Programster\CoreLibs\Exceptions\ExceptionMissingExtension;

class Network
{
    /**
     * This is the socket "equivalent" to the sendApiRequest function. However
     * unlike that funciton it does not require the curl library to be
     * installed, and will try to send/recieve information over a direct socket
     * connection.
     *
     * @param string $host - who to send the request to
     * @param int $port - the port number to make the connection on.
     * @param array $request - map of name/value pairs to send.
     * @param int $bufferSize - optionally define the size (num chars/bytes) of
     *                          the buffer. If this is too small your
     *                          information can get cut off, causing errors.
     *                          10485760 = 10 MiB
     * @param int $timeout - (optional, default 2) the number of seconds before
     *                       connection attempt times out.
     * @param int $attemptsLimit - (optional, default 5) the number of failed
     *                             connection attempts to make before giving up.
     * @return array - the response from the api in name/value pairs.
     * @throws ExceptionMissingExtension
     * @throws \Safe\Exceptions\JsonException
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
        if (!extension_loaded("sockets"))
        {
            throw new ExceptionMissingExtension("Your PHP environment does not have the sockets extension.");
        }

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

        $wroteBytes = socket_write($socket, $reqString, strlen($reqString));

        if ($wroteBytes === false)
        {
            throw new \Exception('Failed to write request to socket.');
        }

        # PHP_NORMAL_READ indicates end reading on newline
        $serverMessage = socket_read($socket, $bufferSize, PHP_NORMAL_READ);
        $response = \Safe\json_decode($serverMessage, true);
        socket_shutdown($socket, 2); # 0=shut read, 1=shut write, 2=both
        socket_close($socket);

        return $response;
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
     * Determines what this computers public IP address is. This is not
     * necessarily the IP address of the computer, and you may need to set up
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

        return $isOpen;
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
}
