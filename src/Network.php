<?php

/*
 * A library for network operations, such as sockets, IP addresses, ports and the public IP.
 */

namespace Programster\CoreLibs;


use Programster\CoreLibs\Exceptions\CommandFailedException;
use Programster\CoreLibs\Exceptions\CoreLibsException;
use Programster\CoreLibs\Exceptions\MissingExtensionException;
use Programster\CoreLibs\Exceptions\NetworkInterfaceDoesNotExistException;
use Programster\CoreLibs\Exceptions\FilesystemException;
use Programster\CoreLibs\Exceptions\NetworkException;
use Programster\CoreLibs\Exceptions\UnsupportedPlatformException;
use Programster\CoreLibs\Exceptions\BadArgumentException;
use Programster\CoreLibs\Exceptions\NoWebRequestException;

final class Network
{
    # Lists every network interface ("IF") and IPv4 address ("IP") on Windows, one per line, separated by tabs, for
    # getIps(). It lists them all, so that no interface name has to be put into the command.
    private const string WINDOWS_IPS_COMMAND = 'powershell -NoProfile -NonInteractive -Command "'
        . 'Get-NetIPInterface -AddressFamily IPv4 | ForEach-Object { \'IF\' + [char]9 + $_.InterfaceAlias }; '
        . 'Get-NetIPAddress -AddressFamily IPv4 | ForEach-Object { '
        . '\'IP\' + [char]9 + $_.InterfaceAlias + [char]9 + $_.IPAddress }"';


    /**
     * Send a message to a server over a direct TCP socket connection, and read back its response.
     * Both the message and the response are a single line, ending with a newline. This supports
     * IPv4 and IPv6. If $host is a hostname, every address it resolves to is tried in turn, so e.g.
     * "localhost" works whether the server is listening on ::1 or 127.0.0.1.
     *
     * @param string $host - who to send the request to. This can be a hostname, an IPv4 address, or
     * an IPv6 address (with or without square brackets, e.g. "::1" or "[::1]").
     *
     * @param int $port - the port number to make the connection on.
     *
     * @param string|\Stringable $request - the message to send. This is sent as-is, followed by a
     * newline so that the receiver knows it has reached the end of the message. If the server
     * expects a format such as JSON, it is up to you to encode it. This must not contain any
     * newlines (\n or \r).
     *
     * @param int $bufferSize - optionally define the maximum size (in bytes) of the response,
     * including its newline. If the response is larger than this, an exception is thrown. 10485760
     * = 10 MiB
     *
     * @param int $timeout - (optional, default 2) the number of seconds to wait for each of:
     * connecting, sending the request, and receiving the complete response, before giving up. Must
     * be at least 1.
     *
     * @param int $attemptsLimit - (optional, default 5) the number of times to try to connect
     * before giving up, with a one second pause between attempts. Must be at least 1.
     *
     * @param bool $stripEndline - (optional, default true) whether to remove the newline that marks
     * the end of the server's response. Set to false to get the response exactly as it was
     * received.
     *
     * @return string - the raw response from the server, read up until the first newline. If you
     * are expecting a format such as JSON, it is up to you to decode it.
     *
     * @throws MissingExtensionException - if the sockets extension isn't installed.
     *
     * @throws BadArgumentException - if the request contains a newline, or $timeout or
     * $attemptsLimit is below 1.
     *
     * @throws NetworkException - if we failed to connect, send the request, or read a complete
     * response (e.g. if the server closed the connection without responding, or the response was
     * larger than $bufferSize).
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
            throw new MissingExtensionException("Your PHP environment does not have the sockets extension.");
        }

        if ($timeout < 1 || $attemptsLimit < 1)
        {
            throw new BadArgumentException("sendTcpRequest: \$timeout and \$attemptsLimit must both be at least 1.");
        }

        $requestString = (string) $request;

        # The receiver knows it has reached the end of the message when it reads a newline (e.g. with PHP_NORMAL_READ,
        # which stops at either \n or \r), so the message itself must not contain one, or it would be cut short.
        if (str_contains($requestString, "\n") || str_contains($requestString, "\r"))
        {
            throw new BadArgumentException(
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
     * Check whether the current web request was made over HTTPS, from the web server's HTTPS
     * setting. Web servers set it to a non-empty value (usually "on") for HTTPS, and leave it unset
     * for plain HTTP, apart from IIS, which sets it to "off".
     * Behind a proxy or load balancer that handles HTTPS, the web server only sees plain HTTP, so
     * this is false unless the web server is set up to report it. (The X-Forwarded-Proto header
     * isn't used, as the client can set it.)
     *
     * @return bool - true if it was made over HTTPS, and false if not, or there is no web request.
     */
    public static function isHttps(): bool
    {
        $serverHttps = strtolower((string)($_SERVER["HTTPS"] ?? ""));
        return $serverHttps !== "" && $serverHttps !== "off";
    }


    /**
     * Builds the URL of the current page, including any query string, e.g.
     * "https://blog.programster.org/some-page?id=1". The host name has to be given, as there is no
     * safe way to get it from the request: HTTP_HOST comes from the client's Host header, and so can
     * SERVER_NAME, depending on the web server's set up, so either could be spoofed. Only the path
     * and query string come from the request.
     * WARNING: as the path and query string come from the client, they can contain characters such
     * as " and <, so escape the URL (e.g. with htmlspecialchars()) before putting it in HTML.
     *
     * @param string $host - the host name of the site, e.g. "blog.programster.org" or "localhost", or
     * its IP address, e.g. "127.0.0.1", or "[::1]" for IPv6 (in square brackets, as in a URL). It can
     * include a port (1 to 65535, with no leading zeros) if the site isn't on the standard one, e.g.
     * "localhost:8080" or "[::1]:8080". Surrounding whitespace and any trailing slashes are removed.
     *
     * @param bool $forceHttps - true (the default) to always give an https:// URL. Many sites are
     * behind a proxy or load balancer that handles HTTPS, so the web server only sees plain HTTP,
     * and can't tell the URL should be HTTPS. Set false to use whatever the web server reports
     * instead (see isHttps()).
     *
     * @return string - the full URL of the current page.
     *
     * @throws BadArgumentException - if $host isn't a valid host name or IP address (with an
     * optional port), e.g. it includes a scheme such as "https://" or a path, its port is out of
     * range, or it is an IPv6 address without square brackets.
     *
     * @throws NoWebRequestException - if there is no web request (e.g. this is running from the CLI),
     * so there is no current page.
     */
    public static function getCurrentUrl(string $host, bool $forceHttps = true): string
    {
        $trimmedHost = rtrim(trim($host), "/");

        if (!self::isValidHostWithOptionalPort($trimmedHost))
        {
            throw new BadArgumentException(
                "getCurrentUrl: {$host} isn't a valid host name, e.g. blog.programster.org, or [::1]:8080 for an " .
                "IPv6 address with a port (with no scheme or path, and a port from 1 to 65535)."
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

        $scheme = ($forceHttps || self::isHttps()) ? "https" : "http";
        return "{$scheme}://{$trimmedHost}{$requestUri}";
    }


    /**
     * Fetches this computer's IPv4 addresses on the specified network interface. Please note that
     * you may wish to run getPublicIp instead which may return a different IP address depending on
     * your network. This uses the usual tool on each system: the ip command on Linux, ifconfig on
     * macOS and BSD, and PowerShell's Get-NetIPAddress on Windows. Interface names differ between
     * them, e.g. the loopback interface is "lo" on Linux, "lo0" on macOS, and "Loopback
     * Pseudo-Interface 1" on Windows.
     *
     * @param string $interface - the network interface that we want the IPs of.
     *
     * @return array<string> - list of the IPv4 addresses of this machine on that interface, in the
     * order the system lists them. Will be empty if the interface exists but has no IPv4 addresses.
     *
     * @throws NetworkInterfaceDoesNotExistException - if there is no network interface with that
     * name.
     *
     * @throws UnsupportedPlatformException - on a system other than Linux, macOS, BSD or Windows, or
     * Linux without /sys/class/net.
     *
     * @throws FilesystemException - if the list of network interfaces could not be read from
     * /sys/class/net.
     *
     * @throws CommandFailedException - if the command listing them failed.
     */
    public static function getIps(string $interface): array
    {
        $ipsByInterface = match (PHP_OS_FAMILY) {
            "Linux" => self::getLinuxIps($interface),
            "Darwin", "BSD" => self::parseIfconfigIps(Core::runCommand("ifconfig")),
            "Windows" => self::parseWindowsIps(Core::runCommand(self::WINDOWS_IPS_COMMAND)),
            default => throw new UnsupportedPlatformException("getIps: unsupported OS " . PHP_OS_FAMILY),
        };

        if (!array_key_exists($interface, $ipsByInterface))
        {
            throw new NetworkInterfaceDoesNotExistException($interface);
        }

        return $ipsByInterface[$interface];
    }


    /**
     * Determines what this computer's public IP address is. This is not necessarily the IP address
     * of the computer, and you may need to set up port forwarding. This is a very quick and dirty
     * method that relies on icanhazip.com remaining the same so use with caution. This uses HTTPS,
     * so that the answer can't be tampered with on the way, which needs the openssl extension. It
     * may return an IPv6 address, if that is how this computer connects to icanhazip.com.
     *
     * @param float $timeout - optionally specify how many seconds to wait for icanhazip.com to
     * respond. Defaults to 5.
     *
     * @return string - the public ip address of this computer.
     *
     * @throws NetworkException - if we failed to get a response from icanhazip.com, or the response
     * wasn't an IP address.
     */
    public static function getPublicIp(float $timeout = 5.0): string
    {
        # Without a timeout, this would wait for PHP's default_socket_timeout, which is normally 60 seconds.
        $context = stream_context_create(['http' => ['timeout' => $timeout]]);

        # Capture the warning to put its reason in the exception, rather than letting it through.
        $fetchPublicIp = function () use ($context): string|false {
            return file_get_contents('https://icanhazip.com/', false, $context);
        };

        $response = Core::runCapturingWarning($fetchPublicIp, $error);
        $error ??= 'unknown error';

        if ($response === false)
        {
            throw new NetworkException("Failed to fetch public IP from icanhazip.com: {$error}");
        }

        $ip = trim($response);

        # Check we really got an IP address, rather than e.g. an error page from a proxy or captive portal.
        if (filter_var($ip, FILTER_VALIDATE_IP) === false)
        {
            # Only include the start of the response, in case it's a whole web page.
            throw new NetworkException(
                "Expected icanhazip.com to respond with an IP address, but got: " . substr($ip, 0, 100)
            );
        }

        return $ip;
    }


    /**
     * Checks to see if the specified TCP port is open, by trying to connect to it. UDP ports aren't
     * supported, as there is no way to reliably check whether one is open (UDP has no connection
     * handshake, so "connecting" succeeds whether anything is listening or not).
     *
     * @param string $host - the host to check against. If empty, this machine's public IP is used.
     *
     * @param int $port - the port to check
     *
     * @param float $timeout - optionally specify how many seconds to wait for a connection before
     * deciding the port isn't open. Hosts behind firewalls often silently drop packets rather than
     * refuse the connection, in which case this is how long the check will take.
     *
     * @return bool - true if the port is open, false if not.
     *
     * @throws NetworkException - if $host is empty and we failed to fetch this machine's public IP.
     */
    public static function isPortOpen(string $host, int $port, float $timeout = 5.0): bool
    {
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
     * Return whether the IP address provided is within the private or reserved ranges. E.g.
     * 192.168.0.0 – 192.168.255.255, 172.16.0.0 – 172.31.255.255, or 10.0.0.0 – 10.255.255.255.
     * Reserved ranges, such as 127.0.0.0/8 (loopback) and 169.254.0.0/16 (link-local), also count.
     * This also applies to IPv6 though.
     *
     * @param string $ip - the IP address to check, e.g. "192.168.1.5" or "fd00::1". IPv6 addresses
     * must not be in square brackets.
     *
     * @return bool - true if a private/reserved IP, false if not.
     *
     * @throws BadArgumentException - if $ip is not a valid IPv4 or IPv6 address.
     */
    public static function isPrivateIp(string $ip): bool
    {
        # Check this first, as the check below returns false both for a private IP and for something that isn't an IP
        # at all, so without this, anything that isn't an IP would be reported as private.
        if (filter_var($ip, FILTER_VALIDATE_IP) === false)
        {
            throw new BadArgumentException("isPrivateIp: \"{$ip}\" is not a valid IP address.");
        }

        $publicIp = filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        );

        $isPrivate = ($publicIp === false);
        return $isPrivate;
    }


    /**
     * Get the IPv4 addresses of a network interface on Linux, for getIps(), with the ip command.
     *
     * @param string $interface - the network interface.
     *
     * @return array<string, array<string>> - the interface mapped to its addresses, or nothing if
     * there is no such interface.
     *
     * @throws UnsupportedPlatformException - if there is no /sys/class/net.
     * @throws FilesystemException - if the list of interfaces in /sys/class/net couldn't be read.
     * @throws CommandFailedException - if the ip command failed.
     */
    private static function getLinuxIps(string $interface): array
    {
        # Check against the kernel's own list of interfaces, rather than relying on the ip command's error message.
        # Comparing against the list (rather than checking if /sys/class/net/{$interface} exists) also means names
        # like ".." can't match.
        if (!is_dir("/sys/class/net"))
        {
            throw new UnsupportedPlatformException("getIps: this needs /sys/class/net on Linux.");
        }

        try
        {
            $interfaces = Filesystem::getDirContents("/sys/class/net", false, false, false);
        }
        catch (CoreLibsException $e)
        {
            throw new FilesystemException("Failed to read the list of network interfaces from /sys/class/net.", 0, $e);
        }

        $ipsByInterface = [];

        if (in_array($interface, $interfaces, true))
        {
            # We deliberately don't use a pipeline (e.g. into awk) here, as a pipeline's exit code is only that of its
            # last command, which would hide the ip command failing.
            $output = Core::runCommand("ip -o -4 addr list " . escapeshellarg($interface));

            # Each line looks like: "2: eth0    inet 192.168.1.5/24 brd 192.168.1.255 scope global eth0 ..."
            preg_match_all('/\binet ([0-9.]+)\//', $output, $matches);
            $ipsByInterface[$interface] = $matches[1];
        }

        return $ipsByInterface;
    }


    /**
     * Read every network interface's IPv4 addresses from the output of ifconfig (macOS and BSD), for
     * getIps(). Each interface's block starts with its name and a colon at the start of a line (e.g.
     * "en0: flags=..."), and its IPv4 addresses are on indented "inet" lines.
     *
     * @param string $output - what ifconfig printed.
     *
     * @return array<string, array<string>> - each interface mapped to its IPv4 addresses.
     */
    private static function parseIfconfigIps(string $output): array
    {
        $ipsByInterface = [];
        $current = null;

        foreach (explode("\n", $output) as $line)
        {
            if (preg_match('/^([^\s:]+):\s/', $line, $nameMatch) === 1)
            {
                $current = $nameMatch[1];
                $ipsByInterface[$current] = [];
            }
            elseif ($current !== null && preg_match('/^\s+inet ([0-9.]+)\s/', $line, $ipMatch) === 1)
            {
                $ipsByInterface[$current][] = $ipMatch[1];
            }
        }

        return $ipsByInterface;
    }


    /**
     * Read every network interface's IPv4 addresses from the output of WINDOWS_IPS_COMMAND, for
     * getIps(). It prints a tab-separated "IF" line for each interface, and an "IP" line for each
     * address, e.g. "IF\tEthernet" and "IP\tEthernet\t192.168.1.5".
     *
     * @param string $output - what the command printed.
     *
     * @return array<string, array<string>> - each interface mapped to its IPv4 addresses.
     */
    private static function parseWindowsIps(string $output): array
    {
        $ipsByInterface = [];

        foreach (explode("\n", str_replace("\r", "", $output)) as $line)
        {
            $fields = explode("\t", $line);

            if ($fields[0] === "IF" && count($fields) === 2)
            {
                $ipsByInterface[$fields[1]] ??= [];
            }
            elseif ($fields[0] === "IP" && count($fields) === 3)
            {
                $ipsByInterface[$fields[1]] ??= [];
                $ipsByInterface[$fields[1]][] = $fields[2];
            }
        }

        return $ipsByInterface;
    }


    /**
     * Connect a TCP socket to a host, trying every address the host resolves to (IPv6 and IPv4),
     * and retrying up to $attemptsLimit times with a one second pause between attempts.
     *
     * @param string $host - the hostname, IPv4 address, or IPv6 address (with or without square
     * brackets).
     *
     * @param int $port - the port to connect to.
     *
     * @param int $timeout - the number of seconds to wait for each connection attempt, and for
     * later reads/writes.
     *
     * @param int $attemptsLimit - the number of times to try to connect before giving up.
     *
     * @return \Socket - the connected socket.
     *
     * @throws NetworkException - if we could not connect after $attemptsLimit attempts. The message
     * lists every error.
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

                    $address = ($family === AF_INET6)
                        ? $details['ai_addr']['sin6_addr']
                        : $details['ai_addr']['sin_addr'];

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

            throw new NetworkException(
                "sendTcpRequest: Failed to connect to [{$host}] on port {$port} after {$attempt} attempt(s) " .
                "({$secondsTaken} seconds). Errors:" . PHP_EOL . implode(PHP_EOL, $errors)
            );
        }

        return $socket;
    }


    /**
     * Read a single line from a socket, up to and including the first newline (\n or \r). We don't
     * use socket_read() with PHP_NORMAL_READ for this, as it silently ignores the socket's receive
     * timeout, so it would wait forever for a server that never responds. Instead, we wait for data
     * with socket_select(), which does respect a deadline, and read whatever is available until we
     * have a newline.
     *
     * @param \Socket $socket - the connected socket to read from.
     *
     * @param int $maxBytes - the maximum size of the line (including its newline).
     *
     * @param int $timeout - the maximum number of seconds to wait for the whole line.
     *
     * @return string - the line, including the newline at the end.
     *
     * @throws NetworkException - if reading fails, times out, the server closes the connection
     * before sending a newline, or the line is larger than $maxBytes.
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
                throw new NetworkException(
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
                throw new NetworkException(
                    "sendTcpRequest: Failed while waiting for the response: " .
                    socket_strerror(socket_last_error($socket))
                );
            }

            if ($numReady > 0)
            {
                $chunk = @socket_read($socket, 65536, PHP_BINARY_READ);

                if ($chunk === false)
                {
                    throw new NetworkException(
                        "sendTcpRequest: Failed to read the response: " . socket_strerror(socket_last_error($socket))
                    );
                }

                if ($chunk === "")
                {
                    # An empty read with data supposedly ready means the server closed the connection.
                    throw new NetworkException(
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
                    throw new NetworkException(
                        "sendTcpRequest: The response is larger than the buffer size of {$maxBytes} bytes."
                    );
                }
            }
        }

        return $line;
    }


    /**
     * Write all of the data to a socket. socket_write() may only send part of the data, so keep
     * writing until everything has been sent.
     *
     * @param \Socket $socket - the connected socket to write to.
     *
     * @param string $data - the data to send.
     *
     * @return void
     *
     * @throws NetworkException - if writing to the socket fails.
     */
    private static function writeToSocket(\Socket $socket, string $data): void
    {
        $remaining = $data;

        while ($remaining !== "")
        {
            $bytesWritten = @socket_write($socket, $remaining);

            if ($bytesWritten === false)
            {
                throw new NetworkException(
                    "sendTcpRequest: Failed to send the request: " . socket_strerror(socket_last_error($socket))
                );
            }

            $remaining = substr($remaining, $bytesWritten);
        }
    }


    /**
     * Check whether a host is valid for getCurrentUrl(): a host name (e.g. "localhost") or IPv4
     * address, or an IPv6 address in square brackets (e.g. "[::1]"), each with an optional port from
     * 1 to 65535, with no leading zeros (e.g. "localhost:8080" or "[::1]:8080").
     *
     * @param string $host - the host to check, already trimmed.
     *
     * @return bool - true if it is valid, false if not.
     */
    private static function isValidHostWithOptionalPort(string $host): bool
    {
        $isBracketed = preg_match('/^\[([^\]]+)\](?::([^:]*))?$/', $host, $bracketedParts) === 1;

        if ($isBracketed)
        {
            $isValidAddress = filter_var($bracketedParts[1], FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false;
            $port = $bracketedParts[2] ?? null;
        }
        else
        {
            $hostParts = explode(":", $host, 2);
            $isValidAddress = filter_var($hostParts[0], FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) !== false;
            $port = $hostParts[1] ?? null;
        }

        $isValidPort = (
               $port === null
            || (preg_match('/^[1-9][0-9]{0,4}$/', $port) === 1 && (int)$port <= 65535)
        );

        return $isValidAddress && $isValidPort;
    }
}
