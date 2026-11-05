<?php

/*
 * This is a general library that can be used to "extend" the standard library.
 * Only functions that are used frequently and are completely generic should
 * be placed here. Wherever possible, please use more specific libraries such
 * as the StringLib, Filesystem, and HtmlGenerator
 */

namespace Programster\CoreLibs;


use Programster\CoreLibs\Exceptions\ExceptionCommandFailed;
use Programster\CoreLibs\Exceptions\ExceptionMissingExtension;
use Programster\CoreLibs\Exceptions\ExceptionNetworkInterfaceDoesNotExist;
use Programster\CoreLibs\Exceptions\FilesystemException;

class Network
{
    /**
     * This is the socket "equivalent" to the sendApiRequest function. However
     * unlike that function it does not require the curl library to be
     * installed, and will try to send/receive information over a direct socket
     * connection.
     *
     * @param string $host - who to send the request to
     * @param int $port - the port number to make the connection on.
     * @param string|\Stringable $request - the message to send. This is sent as-is, followed by a newline so that the
     *                                      receiver knows it has reached the end of the message. If the server expects
     *                                      a format such as JSON, it is up to you to encode it. This must not contain
     *                                      any newlines (\n or \r).
     * @param int $bufferSize - optionally define the size (num chars/bytes) of
     *                          the buffer. If this is too small your
     *                          information can get cut off, causing errors.
     *                          10485760 = 10 MiB
     * @param int $timeout - (optional, default 2) the number of seconds before
     *                       connection attempt times out.
     * @param int $attemptsLimit - (optional, default 5) the number of failed
     *                             connection attempts to make before giving up.
     * @param bool $stripEndline - (optional, default true) whether to remove the newline that marks the end of the
     *                             server's response. Set to false to get the response exactly as it was received.
     * @return string - the raw response from the server, read up until the first newline. If you are expecting a
     *                  format such as JSON, it is up to you to decode it.
     * @throws ExceptionMissingExtension
     * @throws \InvalidArgumentException - if the request contains a newline.
     * @throws \Exception - if we failed to connect, send the request, or read the response.
     */
    public static function sendTcpRequest(
        string $host,
        int $port,
        string|\Stringable $request,
        int $bufferSize=10485760,
        int $timeout=2,
        int $attemptsLimit=100,
        bool $stripEndline=true,
    ) : string
    {
        if (!extension_loaded("sockets"))
        {
            throw new ExceptionMissingExtension("Your PHP environment does not have the sockets extension.");
        }

        $requestString = (string) $request;

        # The receiver knows it has reached the end of the message when it reads a newline (e.g. with PHP_NORMAL_READ,
        # which stops at either \n or \r), so the message itself must not contain one, or it would be cut short.
        if (str_contains($requestString, "\n") || str_contains($requestString, "\r"))
        {
            throw new \InvalidArgumentException(
                "sendTcpRequest: the request cannot contain a newline (\\n or \\r), as a newline is used to mark the " .
                "end of the message."
            );
        }

        $reqString = $requestString . PHP_EOL;

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
                # fetched it, have to do this manually
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
        $response = socket_read($socket, $bufferSize, PHP_NORMAL_READ);

        if ($response === false)
        {
            throw new \Exception('Failed to read response from socket: ' . socket_strerror(socket_last_error($socket)));
        }

        socket_shutdown($socket, 2); # 0=shut read, 1=shut write, 2=both
        socket_close($socket);

        if ($stripEndline)
        {
            # PHP_NORMAL_READ stops at either \n or \r, so the response could end with either.
            $response = rtrim($response, "\r\n");
        }

        return $response;
    }


    /**
     * Calculates the hostname including the starting http:// or https:// at
     * the beginning. This is useful for linking items by relative source and
     * getting around htaccess URL rewrites.
     * @return String  - The URL e.g. 'http://www.technostu.com'
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
     * Fetches this computer's IPv4 addresses on the specified network interface. Please note that you may wish
     * to run getPublicIp instead which may return a different IP address depending on your network.
     * @param string $interface - the network interface that we want the IPs of.
     * @return array<string> - list of the IPv4 addresses of this machine on that interface, in the order the ip
     *                         command lists them. Will be empty if the interface exists but has no IPv4 addresses.
     * @throws ExceptionNetworkInterfaceDoesNotExist - if there is no network interface with that name.
     * @throws FilesystemException - if the list of network interfaces could not be read from /sys/class/net.
     * @throws ExceptionCommandFailed - if the ip command failed.
     */
    public static function getIps(string $interface) : array
    {
        # Check against the kernel's own list of interfaces, rather than relying on the ip command's error message.
        # Comparing against the list (rather than checking if /sys/class/net/{$interface} exists) also means names
        # like ".." can't match.
        $interfaces = scandir("/sys/class/net");

        if ($interfaces === false)
        {
            throw new FilesystemException("Failed to read the list of network interfaces from /sys/class/net.");
        }

        if (!in_array($interface, array_diff($interfaces, [".", ".."]), true))
        {
            throw new ExceptionNetworkInterfaceDoesNotExist($interface);
        }

        # We deliberately don't use a pipeline (e.g. into awk) here, as a pipeline's exit code is only that of its
        # last command, which would hide the ip command failing (e.g. because the interface doesn't exist).
        $output = Core::runCommand("ip -o -4 addr list " . escapeshellarg($interface));

        # Each line looks like: "2: eth0    inet 192.168.1.5/24 brd 192.168.1.255 scope global eth0 ..."
        preg_match_all('/\binet ([0-9.]+)\//', $output, $matches);
        $ips = $matches[1];
        return $ips;
    }


    /**
     * Determines what this computer's public IP address is. This is not
     * necessarily the IP address of the computer, and you may need to set up
     * port forwarding.
     * This is a very quick and dirty method that relies on icanhazip.com
     * remaining the same so use with caution.
     * @param void
     * @return string $ip - the public ip address of this computer.
     * @throws \Exception - if we failed to get a response from icanhazip.com
     */
    public static function getPublicIp() : string
    {
        $response = file_get_contents('http://icanhazip.com/');

        if ($response === false)
        {
            throw new \Exception("Failed to fetch public IP from icanhazip.com");
        }

        return trim($response);
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
     * Return whether the IP address provided is within the private or reserved ranges.
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
