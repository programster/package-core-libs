<?php

/*
 * A library for network operations, such as sockets, IP addresses, ports and the public IP.
 */

namespace Programster\CoreLibs;


use Programster\CoreLibs\Exceptions\ExceptionCommandFailed;
use Programster\CoreLibs\Exceptions\ExceptionMissingExtension;
use Programster\CoreLibs\Exceptions\ExceptionNetworkInterfaceDoesNotExist;
use Programster\CoreLibs\Exceptions\ExceptionFilesystem;

final class Network
{
    /**
     * Send a message to a server over a direct TCP socket connection, and read back its response. Both the message
     * and the response are a single line, ending with a newline.
     * This supports IPv4 and IPv6. If $host is a hostname, every address it resolves to is tried in turn, so e.g.
     * "localhost" works whether the server is listening on ::1 or 127.0.0.1.
     *
     * @param string $host - who to send the request to. This can be a hostname, an IPv4 address, or an IPv6 address
     *                       (with or without square brackets, e.g. "::1" or "[::1]").
     * @param int $port - the port number to make the connection on.
     * @param string|\Stringable $request - the message to send. This is sent as-is, followed by a newline so that the
     *                                      receiver knows it has reached the end of the message. If the server expects
     *                                      a format such as JSON, it is up to you to encode it. This must not contain
     *                                      any newlines (\n or \r).
     * @param int $bufferSize - optionally define the maximum size (in bytes) of the response, including its newline.
     *                          If the response is larger than this, an exception is thrown. 10485760 = 10 MiB
     * @param int $timeout - (optional, default 2) the number of seconds to wait for each of: connecting, sending the
     *                       request, and receiving the complete response, before giving up. Must be at least 1.
     * @param int $attemptsLimit - (optional, default 5) the number of times to try to connect before giving up, with a
     *                             one second pause between attempts. Must be at least 1.
     * @param bool $stripEndline - (optional, default true) whether to remove the newline that marks the end of the
     *                             server's response. Set to false to get the response exactly as it was received.
     * @return string - the raw response from the server, read up until the first newline. If you are expecting a
     *                  format such as JSON, it is up to you to decode it.
     * @throws ExceptionMissingExtension - if the sockets extension isn't installed.
     * @throws \InvalidArgumentException - if the request contains a newline, or $timeout or $attemptsLimit is below 1.
     * @throws \Exception - if we failed to connect, send the request, or read a complete response (e.g. if the server
     *                      closed the connection without responding, or the response was larger than $bufferSize).
     */
    public static function sendTcpRequest(
        string $host,
        int $port,
        string|\Stringable $request,
        int $bufferSize=10485760,
        int $timeout=2,
        int $attemptsLimit=5,
        bool $stripEndline=true,
    ): string
    {
        if (!extension_loaded("sockets"))
        {
            throw new ExceptionMissingExtension("Your PHP environment does not have the sockets extension.");
        }

        if ($timeout < 1 || $attemptsLimit < 1)
        {
            throw new \InvalidArgumentException("sendTcpRequest: \$timeout and \$attemptsLimit must both be at least 1.");
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

        $socket = self::connectTcpSocket($host, $port, $timeout, $attemptsLimit);

        try
        {
            # Always end with "\n", rather than PHP_EOL, which is "\r\n" on Windows. A receiver that stops reading at
            # either character would otherwise see the leftover "\n" as an extra, empty message.
            self::writeToSocket($socket, $requestString . "\n");

            $response = self::readLineFromSocket($socket, $bufferSize, $timeout);
        }
        finally
        {
            # Always close the socket, even if something went wrong.
            @socket_shutdown($socket, 2); # 0=shut read, 1=shut write, 2=both
            socket_close($socket);
        }

        if ($stripEndline)
        {
            # PHP_NORMAL_READ stops at either \n or \r, so the response could end with either.
            $response = rtrim($response, "\r\n");
        }

        return $response;
    }


    /**
     * Connect a TCP socket to a host, trying every address the host resolves to (IPv6 and IPv4), and retrying up to
     * $attemptsLimit times with a one second pause between attempts.
     * @param string $host - the hostname, IPv4 address, or IPv6 address (with or without square brackets).
     * @param int $port - the port to connect to.
     * @param int $timeout - the number of seconds to wait for each connection attempt, and for later reads/writes.
     * @param int $attemptsLimit - the number of times to try to connect before giving up.
     * @return \Socket - the connected socket.
     * @throws \Exception - if we could not connect after $attemptsLimit attempts. The message lists every error.
     */
    private static function connectTcpSocket(string $host, int $port, int $timeout, int $attemptsLimit): \Socket
    {
        # Remove the square brackets from an IPv6 address such as "[::1]", as the lookup doesn't accept them.
        $lookupHost = (str_starts_with($host, "[") && str_ends_with($host, "]")) ? substr($host, 1, -1) : $host;

        # stream_set_timeout() does NOT work for sockets created with socket_create(), so set the timeouts on the socket
        # itself. On Linux, the send timeout also limits how long connecting can take.
        $timeoutSpec = ['sec' => $timeout, 'usec' => 0];

        $socket = null;
        $errors = [];
        $attempt = 0;
        $timeStart = microtime(true);

        while ($socket === null && $attempt < $attemptsLimit)
        {
            $attempt++;

            # Resolve the host on every attempt, in case a DNS failure was temporary.
            $addresses = @socket_addrinfo_lookup($lookupHost, (string) $port, ['ai_socktype' => SOCK_STREAM]);

            if ($addresses === false || count($addresses) === 0)
            {
                $errors[] = "attempt {$attempt}: could not resolve the host [{$lookupHost}]";
            }

            foreach (($addresses === false) ? [] : $addresses as $addressInfo)
            {
                # Stop trying addresses once one has connected.
                if ($socket === null)
                {
                    $details = socket_addrinfo_explain($addressInfo);
                    $family = $details['ai_family'];
                    $address = ($family === AF_INET6) ? $details['ai_addr']['sin6_addr'] : $details['ai_addr']['sin_addr'];

                    # Use a new socket for every attempt, as reusing a socket after a failed connection only works on
                    # some operating systems (e.g. Linux, but not macOS).
                    $candidate = @socket_create($family, SOCK_STREAM, SOL_TCP);

                    if ($candidate === false)
                    {
                        $errors[] = "attempt {$attempt} [{$address}]: failed to create a socket: " .
                            socket_strerror(socket_last_error());
                    }
                    else
                    {
                        socket_set_option($candidate, SOL_SOCKET, SO_RCVTIMEO, $timeoutSpec);
                        socket_set_option($candidate, SOL_SOCKET, SO_SNDTIMEO, $timeoutSpec);

                        # Suppress the warning, as the error is reported in the exception if we can't connect at all.
                        if (@socket_connect($candidate, $address, $port))
                        {
                            $socket = $candidate;
                        }
                        else
                        {
                            $errors[] = "attempt {$attempt} [{$address}]: " .
                                socket_strerror(socket_last_error($candidate));

                            socket_close($candidate);
                        }
                    }
                }
            }

            if ($socket === null && $attempt < $attemptsLimit)
            {
                # The server may just be busy, so give it a moment before retrying.
                sleep(1);
            }
        }

        if ($socket === null)
        {
            $secondsTaken = round(microtime(true) - $timeStart, 1);

            throw new \Exception(
                "sendTcpRequest: Failed to connect to [{$host}] on port {$port} after {$attempt} attempt(s) " .
                "({$secondsTaken} seconds). Errors:" . PHP_EOL . implode(PHP_EOL, $errors)
            );
        }

        return $socket;
    }


    /**
     * Read a single line from a socket, up to and including the first newline (\n or \r).
     * We don't use socket_read() with PHP_NORMAL_READ for this, as it silently ignores the socket's receive timeout,
     * so it would wait forever for a server that never responds. Instead, we wait for data with socket_select(), which
     * does respect a deadline, and read whatever is available until we have a newline.
     * @param \Socket $socket - the connected socket to read from.
     * @param int $maxBytes - the maximum size of the line (including its newline).
     * @param int $timeout - the maximum number of seconds to wait for the whole line.
     * @return string - the line, including the newline at the end.
     * @throws \Exception - if reading fails, times out, the server closes the connection before sending a newline,
     *                      or the line is larger than $maxBytes.
     */
    private static function readLineFromSocket(\Socket $socket, int $maxBytes, int $timeout): string
    {
        $line = "";
        $isComplete = false;
        $deadline = microtime(true) + $timeout;

        while (!$isComplete)
        {
            $secondsLeft = $deadline - microtime(true);

            if ($secondsLeft <= 0)
            {
                throw new \Exception(
                    "sendTcpRequest: Did not receive a complete response within {$timeout} second(s) (received " .
                    strlen($line) . " bytes, with no newline)."
                );
            }

            $read = [$socket];
            $write = null;
            $except = null;
            $wholeSeconds = (int) floor($secondsLeft);
            $microseconds = (int) (($secondsLeft - $wholeSeconds) * 1000000);
            $numReady = @socket_select($read, $write, $except, $wholeSeconds, $microseconds);

            if ($numReady === false)
            {
                throw new \Exception(
                    "sendTcpRequest: Failed while waiting for the response: " . socket_strerror(socket_last_error($socket))
                );
            }

            if ($numReady > 0)
            {
                $chunk = @socket_read($socket, 65536, PHP_BINARY_READ);

                if ($chunk === false)
                {
                    throw new \Exception(
                        "sendTcpRequest: Failed to read the response: " . socket_strerror(socket_last_error($socket))
                    );
                }

                if ($chunk === "")
                {
                    # An empty read with data supposedly ready means the server closed the connection.
                    throw new \Exception(
                        "sendTcpRequest: The server closed the connection before sending a complete response " .
                        "(received " . strlen($line) . " bytes, with no newline)."
                    );
                }

                $line .= $chunk;

                # Look for the end of the line. Anything the server sent after it is ignored.
                $newlinePosition = strcspn($line, "\r\n");

                if ($newlinePosition < strlen($line))
                {
                    $line = substr($line, 0, $newlinePosition + 1);
                    $isComplete = true;
                }

                if (strlen($line) > $maxBytes || (!$isComplete && strlen($line) >= $maxBytes))
                {
                    throw new \Exception(
                        "sendTcpRequest: The response is larger than the buffer size of {$maxBytes} bytes."
                    );
                }
            }
        }

        return $line;
    }


    /**
     * Write all of the data to a socket. socket_write() may only send part of the data, so keep writing until
     * everything has been sent.
     * @param \Socket $socket - the connected socket to write to.
     * @param string $data - the data to send.
     * @return void
     * @throws \Exception - if writing to the socket fails.
     */
    private static function writeToSocket(\Socket $socket, string $data): void
    {
        $remaining = $data;

        while ($remaining !== "")
        {
            $bytesWritten = @socket_write($socket, $remaining);

            if ($bytesWritten === false)
            {
                throw new \Exception(
                    "sendTcpRequest: Failed to send the request: " . socket_strerror(socket_last_error($socket))
                );
            }

            $remaining = substr($remaining, $bytesWritten);
        }
    }


    /**
     * Calculates the hostname including the starting http:// or https:// at
     * the beginning. This is useful for linking items by relative source and
     * getting around htaccess URL rewrites.
     * @return string  - The URL e.g. 'http://www.technostu.com'
     */
    public static function getHostname(): string
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
     * @throws ExceptionFilesystem - if the list of network interfaces could not be read from /sys/class/net.
     * @throws ExceptionCommandFailed - if the ip command failed.
     */
    public static function getIps(string $interface): array
    {
        # Check against the kernel's own list of interfaces, rather than relying on the ip command's error message.
        # Comparing against the list (rather than checking if /sys/class/net/{$interface} exists) also means names
        # like ".." can't match.
        $interfaces = scandir("/sys/class/net");

        if ($interfaces === false)
        {
            throw new ExceptionFilesystem("Failed to read the list of network interfaces from /sys/class/net.");
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
     * @return string $ip - the public ip address of this computer.
     * @throws \Exception - if we failed to get a response from icanhazip.com
     */
    public static function getPublicIp(): string
    {
        $response = file_get_contents('http://icanhazip.com/');

        if ($response === false)
        {
            throw new \Exception("Failed to fetch public IP from icanhazip.com");
        }

        return trim($response);
    }


    /**
     * Checks to see if the specified TCP port is open, by trying to connect to it.
     * @param string $host - the host to check against. If empty, this machine's public IP is used.
     * @param int $port - the port to check
     * @param string $protocol - the protocol of the port. Only "tcp" is supported, as there is no way to reliably check
     *                           whether a UDP port is open (UDP has no connection handshake, so "connecting" succeeds
     *                           whether anything is listening or not).
     * @param float $timeout - optionally specify how many seconds to wait for a connection before deciding the port
     *                         isn't open. Hosts behind firewalls often silently drop packets rather than refuse the
     *                         connection, in which case this is how long the check will take.
     * @return bool - true if the port is open, false if not.
     * @throws \InvalidArgumentException - if a protocol other than "tcp" is specified.
     * @throws \Exception - if $host is empty and we failed to fetch this machine's public IP.
     */
    public static function isPortOpen(string $host, int $port, string $protocol = 'tcp', float $timeout = 5.0): bool
    {
        $protocol = strtolower($protocol);

        if ($protocol === 'udp')
        {
            throw new \InvalidArgumentException(
                "isPortOpen: UDP ports cannot be reliably checked, as UDP has no connection handshake, so connecting " .
                "succeeds whether or not anything is listening on the port."
            );
        }

        if ($protocol !== 'tcp')
        {
            throw new \InvalidArgumentException("isPortOpen: unrecognized protocol [{$protocol}]. Only [tcp] is supported.");
        }

        if (empty($host))
        {
            $host = self::getPublicIp();
        }

        $connection = @fsockopen($host, $port, $errorCode, $errorMessage, $timeout);

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
    public static function isPrivateIp(string $ip): bool
    {
        $result = filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        );

        return $result === false;
    }
}
