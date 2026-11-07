<?php

/*
 * Smoke tests for Network. These are run by testing/main.php, which provides $smoke and $dir.
 */

use Programster\CoreLibs\Exceptions\NetworkInterfaceDoesNotExistException;
use Programster\CoreLibs\Exceptions\UnsupportedPlatformException;
use Programster\CoreLibs\Exceptions\BadArgumentException;
use Programster\CoreLibs\Exceptions\NetworkException;
use Programster\CoreLibs\Core;
use Programster\CoreLibs\Exceptions\NoWebRequestException;
use Programster\CoreLibs\Network;
use Programster\CoreLibs\Testing\FakeHttpsStream;
use Programster\CoreLibs\Testing\SmokeTest;

/** @var \Programster\CoreLibs\Testing\SmokeTest $smoke */
/** @var string $dir - a temporary directory for the tests to use, which is deleted afterwards. */

$smoke->run("getPublicIp", function () {
    # Fake icanhazip.com's responses, so this doesn't need the internet.
    try
    {
        FakeHttpsStream::install("203.0.113.7\n");
        assert(Network::getPublicIp() === "203.0.113.7");

        # A response that isn't an IP address, such as a proxy's error page, must be rejected.
        FakeHttpsStream::install("<html>Please log in to the Wi-Fi</html>");
        SmokeTest::expectException(NetworkException::class, fn() => Network::getPublicIp());

        # As must the server not being reachable, with PHP's reason in the message rather than "unknown error".
        FakeHttpsStream::install(null);
        SmokeTest::expectException(NetworkException::class, fn() => Network::getPublicIp());

        try
        {
            Network::getPublicIp();
        }
        catch (NetworkException $e)
        {
            assert(str_contains($e->getMessage(), "Failed to open stream"));
            assert(!str_contains($e->getMessage(), "unknown error"));
        }
    }
    finally
    {
        FakeHttpsStream::uninstall();
    }
});

$smoke->run("isPrivateIp / isPortOpen / getIps", function () {
    assert(Network::isPrivateIp("10.0.0.1") && !Network::isPrivateIp("8.8.8.8"));
    assert(Network::isPrivateIp("127.0.0.1") && Network::isPrivateIp("fd00::1"));
    assert(!Network::isPrivateIp("2001:4860::8888"));
    SmokeTest::expectException(BadArgumentException::class, fn() => Network::isPrivateIp("not-an-ip"));
    SmokeTest::expectException(BadArgumentException::class, fn() => Network::isPrivateIp("[::1]"));
    assert(!Network::isPortOpen("127.0.0.1", 1, timeout: 1));

    # The loopback interface has 127.0.0.1, but is named differently on each system.
    $loopback = match (PHP_OS_FAMILY) {
        "Windows" => "Loopback Pseudo-Interface 1",
        "Darwin", "BSD" => "lo0",
        default => "lo",
    };

    assert(in_array("127.0.0.1", Network::getIps($loopback), true), "{$loopback} doesn't have 127.0.0.1");
    SmokeTest::expectException(NetworkInterfaceDoesNotExistException::class, fn() => Network::getIps("no-such-if"));
});

$smoke->run("sendTcpRequest", function () {
    if (function_exists("pcntl_fork"))
    {
        $server = stream_socket_server("tcp://127.0.0.1:0");
        $port = (int) substr(strrchr(stream_socket_get_name($server, false), ":"), 1);
        $childPid = pcntl_fork();

        if ($childPid === 0)
        {
            # The child process acts as the server, replying to a single request.
            $connection = stream_socket_accept($server, 5);
            fwrite($connection, "got:" . fgets($connection));
            fclose($connection);
            exit(0);
        }

        assert(Network::sendTcpRequest("127.0.0.1", $port, "hello") === "got:hello");
        pcntl_waitpid($childPid, $status);
    }
    else
    {
        echo "(skipping sendTcpRequest, as the pcntl extension isn't installed)\n";
    }
});

$smoke->run("getCurrentUrl() / isHttps()", function () {
    $originalServer = $_SERVER;

    try
    {
        # Outside a web request (as here, from the CLI), there is no current page, so it throws rather than returning
        # half a URL with warnings.
        unset($_SERVER['REQUEST_URI'], $_SERVER['HTTPS']);
        SmokeTest::expectException(NoWebRequestException::class, fn() => Core::getCurrentUrl("blog.programster.org"));
        assert(Network::isHttps() === false);

        # The host is what was given (tidied up), never the request's own, so a spoofed Host header has no effect.
        $_SERVER['HTTP_HOST'] = "evil.example.com";
        $_SERVER['SERVER_NAME'] = "evil.example.com";
        $_SERVER['REQUEST_URI'] = "/some-page?id=1";
        assert(Core::getCurrentUrl("blog.programster.org") === "https://blog.programster.org/some-page?id=1");
        assert(Core::getCurrentUrl("  blog.programster.org//  ") === "https://blog.programster.org/some-page?id=1");
        assert(Core::getCurrentUrl("localhost:8080") === "https://localhost:8080/some-page?id=1");

        # A request line with the URL in full only has its path and query used.
        $_SERVER['REQUEST_URI'] = "http://evil.example.com/other?x=2";
        assert(Core::getCurrentUrl("blog.programster.org") === "https://blog.programster.org/other?x=2");
        $_SERVER['REQUEST_URI'] = "/some-page?id=1";

        # HTTPS is forced by default, as behind a proxy the web server only sees HTTP. Without forcing it, the web
        # server's HTTPS setting is used, which IIS sets to "off" for plain HTTP.
        assert(Core::getCurrentUrl("blog.programster.org", false) === "http://blog.programster.org/some-page?id=1");
        $_SERVER['HTTPS'] = "off";
        assert(Network::isHttps() === false);
        assert(Core::getCurrentUrl("blog.programster.org", false) === "http://blog.programster.org/some-page?id=1");
        $_SERVER['HTTPS'] = "on";
        assert(Network::isHttps() === true);
        assert(Core::getCurrentUrl("blog.programster.org", false) === "https://blog.programster.org/some-page?id=1");

        foreach (["", "https://blog.programster.org", "blog.programster.org/page", "bad host", "host:abc"] as $bad)
        {
            SmokeTest::expectException(BadArgumentException::class, fn() => Core::getCurrentUrl($bad), "isn't a valid");
        }
    }
    finally
    {
        $_SERVER = $originalServer;
    }
});

$smoke->run("getIps() reading macOS and Windows output", function () {
    # These only run on macOS and Windows, so check them here with sample output.
    $ifconfig = new ReflectionMethod(Network::class, "parseIfconfigIps");
    $windows = new ReflectionMethod(Network::class, "parseWindowsIps");

    $ifconfigOutput = "lo0: flags=8049<UP,LOOPBACK,RUNNING,MULTICAST> mtu 16384\n" .
        "\tinet 127.0.0.1 netmask 0xff000000\n" .
        "\tinet6 ::1 prefixlen 128\n" .
        "en0: flags=8863<UP,BROADCAST,SMART,RUNNING,SIMPLEX,MULTICAST> mtu 1500\n" .
        "\tether 00:11:22:33:44:55\n" .
        "\tinet 192.168.1.5 netmask 0xffffff00 broadcast 192.168.1.255\n" .
        "\tinet 10.0.0.2 netmask 0xff000000\n" .
        "utun0: flags=8051<UP,POINTOPOINT,RUNNING,MULTICAST> mtu 1380\n";

    $expected = ["lo0" => ["127.0.0.1"], "en0" => ["192.168.1.5", "10.0.0.2"], "utun0" => []];
    assert($ifconfig->invoke(null, $ifconfigOutput) === $expected);

    $windowsOutput = "IF\tEthernet\r\nIF\tLoopback Pseudo-Interface 1\r\nIF\tWi-Fi\r\n" .
        "IP\tEthernet\t192.168.1.5\r\nIP\tLoopback Pseudo-Interface 1\t127.0.0.1\r\n";

    $expected = ["Ethernet" => ["192.168.1.5"], "Loopback Pseudo-Interface 1" => ["127.0.0.1"], "Wi-Fi" => []];
    assert($windows->invoke(null, $windowsOutput) === $expected);
});

