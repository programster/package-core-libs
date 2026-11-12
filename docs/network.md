# Networking (`Network`)

`Programster\CoreLibs\Network` has static methods for IP addresses, ports, simple TCP requests, and the current web
request.

- [IP addresses](#ip-addresses)
- [Checking a port](#checking-a-port)
- [Sending a TCP request](#sending-a-tcp-request)
- [Web requests](#web-requests)

## IP addresses

```php
use Programster\CoreLibs\Network;

// This machine's IPv4 addresses on a network interface.
$ips = Network::getIps("eth0");    // e.g. ["192.168.1.20"], or [] if it has none

// The address this machine reaches the internet from, as icanhazip.com sees it.
$publicIp = Network::getPublicIp();

// Whether an address is in a private or reserved range (e.g. 192.168.0.0/16, 10.0.0.0/8, 127.0.0.0/8, fd00::/8).
Network::isPrivateIp("192.168.1.5");  // true
Network::isPrivateIp("8.8.8.8");      // false
Network::isPrivateIp("fd00::1");      // true
```

`getIps()` throws a `NetworkInterfaceDoesNotExistException` (with `getInterface()`) for an interface that doesn't exist.
Interface names differ between systems: the loopback interface is `lo` on Linux, `lo0` on macOS, and
`Loopback Pseudo-Interface 1` on Windows. It uses the `ip` command on Linux, `ifconfig` on macOS and BSD, and PowerShell
on Windows.

`getPublicIp()` asks icanhazip.com over HTTPS (so needs the `openssl` extension), and waits up to 5 seconds, or the
`$timeout` you pass. It can give an IPv6 address, if that is how this machine connects. It throws a `NetworkException`
if it gets no answer, or the answer isn't an IP address. Behind NAT, the public address isn't this machine's own, so
connecting to it may need port forwarding.

`isPrivateIp()` throws a `BadArgumentException` for something that isn't an IP address. Give IPv6 addresses without
square brackets.

## Checking a port
`isPortOpen()` checks whether something is listening on a TCP port, by trying to connect to it:

```php
if (!Network::isPortOpen("db.internal", 5432, 2.0))
{
    throw new RuntimeException("The database isn't reachable.");
}
```

It waits up to `$timeout` seconds (5 by default). Firewalls often silently drop the connection rather than refusing it,
in which case the check takes that long. An empty host checks this machine's public IP address. UDP isn't supported, as
there is no reliable way to tell whether a UDP port is open.

## Sending a TCP request
`sendTcpRequest()` sends a single line to a server over a TCP connection, and returns the single line it sends back. It
needs the `sockets` extension.

```php
$response = Network::sendTcpRequest("127.0.0.1", 9000, json_encode(["action" => "status"]));
$status = json_decode($response, true, flags: JSON_THROW_ON_ERROR);
```

- The request is sent as it is, followed by a newline, which marks the end of it, so it mustn't contain a newline
  itself. Encode it (e.g. as JSON) yourself if the server expects that.
- The response is read up to its first newline, which is removed unless you pass `stripEndline: false`.
- It supports IPv4 and IPv6. A host name is tried at each address it resolves to, so `localhost` works whether the
  server listens on `::1` or `127.0.0.1`.
- `$timeout` (2 seconds by default) applies to each of connecting, sending and receiving, and `$attemptsLimit` (5) is how
  many times to try connecting, a second apart.
- A response bigger than `$bufferSize` (10 MiB) throws a `NetworkException`, as does failing to connect, send, or read a
  complete response.

## Web requests
`isHttps()` says whether the current web request was made over HTTPS, from the web server's `HTTPS` setting. It is false
from the command line.

```php
// Only send the session cookie over HTTPS, when the site is being used over HTTPS.
session_set_cookie_params(["secure" => Network::isHttps(), "httponly" => true, "samesite" => "Lax"]);
```

Behind a proxy or load balancer that handles HTTPS, the web server only sees plain HTTP, so this is false unless the web
server is set up to report it. The `X-Forwarded-Proto` header isn't used, as the client can set it.

`getCurrentUrl()` builds the URL of the current page. The host name has to be given, as the one in the request comes
from the client, so can't be trusted:

```php
$url = Network::getCurrentUrl("blog.programster.org");   // "https://blog.programster.org/some-page?id=1"
$url = Network::getCurrentUrl("localhost:8080", false);  // http or https, as the web server reports it
$url = Network::getCurrentUrl("[::1]:8080");             // an IPv6 address goes in square brackets, as in a URL
```

It gives an `https://` URL by default, as a site behind a proxy or load balancer that handles HTTPS can't tell from the
request (see `isHttps()` above). The path and query string come from the client, so escape the URL (e.g. with
`htmlspecialchars()`) before putting it in HTML. It throws a `NoWebRequestException` from the command line.

For redirects, see [Core](core.md#redirects).
