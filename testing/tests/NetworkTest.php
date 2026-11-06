<?php

/*
 * Smoke tests for Network. These are run by testing/main.php, which provides $smoke and $dir.
 */

use Programster\CoreLibs\Exceptions\BadArgumentException;
use Programster\CoreLibs\Exceptions\NetworkException;
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
    assert(in_array("127.0.0.1", Network::getIps("lo"), true));
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
