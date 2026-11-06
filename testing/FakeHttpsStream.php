<?php

namespace Programster\CoreLibs\Testing;


/**
 * A stand-in for the https:// stream wrapper, so that methods that fetch a URL (such as
 * Network::getPublicIp()) can be tested without the internet. Register it with
 * FakeHttpsStream::install(), and put things back with uninstall().
 */
final class FakeHttpsStream
{
    # PHP sets this on every stream wrapper, and requires it to be a public property.
    public $context;

    # The body to respond with, or null to fail as if the server couldn't be reached.
    private static ?string $s_response = null;
    private int $m_position = 0;


    /**
     * Replace the https:// stream wrapper with this one.
     * @param ?string $response - the body every request gets, or null for every request to fail.
     * @return void
     */
    public static function install(?string $response): void
    {
        self::$s_response = $response;
        stream_wrapper_unregister("https");
        stream_wrapper_register("https", self::class);
    }


    /**
     * Put the real https:// stream wrapper back.
     * @return void
     */
    public static function uninstall(): void
    {
        stream_wrapper_restore("https");
    }


    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        # Returning false makes PHP raise a "Failed to open stream" warning, as it would for a real failure.
        return self::$s_response !== null;
    }


    public function stream_read(int $count): string
    {
        $chunk = substr(self::$s_response, $this->m_position, $count);
        $this->m_position += strlen($chunk);
        return $chunk;
    }


    public function stream_eof(): bool { return $this->m_position >= strlen(self::$s_response); }
    public function stream_stat(): array { return []; }
}
