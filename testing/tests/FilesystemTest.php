<?php

/*
 * Smoke tests for Filesystem. These are run by testing/main.php, which provides $smoke and $dir.
 */

use Programster\CoreLibs\Exceptions\BadArgumentException;
use Programster\CoreLibs\Exceptions\BadFilePermissionsException;
use Programster\CoreLibs\Exceptions\FileDoesNotExistException;
use Programster\CoreLibs\Exceptions\FilesystemException;
use Programster\CoreLibs\Exceptions\NoFileExtensionException;
use Programster\CoreLibs\Filesystem;
use Programster\CoreLibs\Network;
use Programster\CoreLibs\Testing\SmokeTest;

/** @var \Programster\CoreLibs\Testing\SmokeTest $smoke */
/** @var string $dir - a temporary directory for the tests to use, which is deleted afterwards. */

$smoke->run("directories and files", function () use ($dir) {
    Filesystem::mkdir("{$dir}/sub/deep");
    Filesystem::mkdir("{$dir}/sub/deep"); # already existing is fine
    $isFile = "{$dir}/sub/deep/f.txt";
    file_put_contents($isFile, "");
    SmokeTest::expectException(FilesystemException::class, fn() => Filesystem::mkdir("{$isFile}/child"));
    file_put_contents("{$dir}/sub/deep/f.txt", "abc\nxyz\n");
    Filesystem::getDirectories($dir, true);
    Filesystem::getDirContents($dir);
    SmokeTest::expectException(FileDoesNotExistException::class, fn() => Filesystem::getDirectories("{$dir}/nope"));
    SmokeTest::expectException(FileDoesNotExistException::class, fn() => Filesystem::getDirContents("{$dir}/nope"));
    # A file isn't a directory either.
    SmokeTest::expectException(
        FileDoesNotExistException::class,
        fn() => Filesystem::getDirContents("{$dir}/sub/deep/f.txt")
    );
    assert(Filesystem::getDirSize("{$dir}/sub") > 0);
    assert(Filesystem::getFileSize("{$dir}/sub/deep/f.txt") === 8.0);
    Filesystem::fileWalk("{$dir}/sub/deep/f.txt", fn(string $line) => null);
    Filesystem::fileFilter("{$dir}/sub/deep/f.txt", fn(string $line) => !str_contains($line, "x"));
    assert(file_get_contents("{$dir}/sub/deep/f.txt") === "abc\n");
    Filesystem::fileperms("{$dir}/sub/deep/f.txt");
    assert(Filesystem::getFileExtension("a.tar.gz") === "gz");
    SmokeTest::expectException(NoFileExtensionException::class, fn() => Filesystem::getFileExtension("README"));
    SmokeTest::expectException(
        NoFileExtensionException::class,
        fn() => Filesystem::getFileExtension("/var/v1.2/README")
    );
    SmokeTest::expectException(NoFileExtensionException::class, fn() => Filesystem::getFileExtension("file."));
    Filesystem::deleteDir(Filesystem::tmpDir());
    Filesystem::mkdir("{$dir}/perms", "0o750");
    assert((fileperms("{$dir}/perms") & 0777) === (0750 & ~umask()));
    SmokeTest::expectException(BadArgumentException::class, fn() => Filesystem::mkdir("{$dir}/bad", "493"));
    SmokeTest::expectException(BadArgumentException::class, fn() => Filesystem::mkdir("{$dir}/bad", "0758"));
    assert(!file_exists("{$dir}/bad"));
    SmokeTest::expectException(BadArgumentException::class, fn() => Filesystem::tmpDir("rwx"));
    assert(Filesystem::permissionsStringToInt("755") === 0755);
    assert(Filesystem::permissionsStringToInt("0o1777") === 01777);
    SmokeTest::expectException(BadArgumentException::class, fn() => Filesystem::permissionsStringToInt("0x755"));
    assert(Filesystem::lockFile("{$dir}/lock", true, false));
    Filesystem::unlockFile("{$dir}/lock");
});

$smoke->run("UTF-8 methods", function () use ($dir) {
    file_put_contents("{$dir}/u.txt", iconv("UTF-8", "WINDOWS-1251", "Привет"));
    assert(!Filesystem::isValidUtf8File("{$dir}/u.txt"));
    Filesystem::convertFileToUtf8("{$dir}/u.txt", "Windows-1251");
    assert(file_get_contents("{$dir}/u.txt") === "Привет");
    assert(Filesystem::isValidUtf8File("{$dir}/u.txt", 3));

    # convertFileToUtf8() changes the file in place: it keeps its permissions and links, works in a directory that
    # can't be written to (for anyone but root), and leaves nothing in the temporary directory.
    $tempFilesBefore = glob(sys_get_temp_dir() . "/corelibs-tmp-*");
    mkdir("{$dir}/utf8-dir");
    file_put_contents("{$dir}/utf8-dir/w.txt", iconv("UTF-8", "WINDOWS-1251", "Привет"));
    chmod("{$dir}/utf8-dir/w.txt", 0640);
    $ownedByOtherUser = SmokeTest::giveToOtherUser("{$dir}/utf8-dir/w.txt", "convertFileToUtf8()");
    link("{$dir}/utf8-dir/w.txt", "{$dir}/utf8-dir/w-link.txt");
    chmod("{$dir}/utf8-dir", 0555);
    Filesystem::convertFileToUtf8("{$dir}/utf8-dir/w.txt", "Windows-1251");
    chmod("{$dir}/utf8-dir", 0755);
    clearstatcache();
    assert(file_get_contents("{$dir}/utf8-dir/w.txt") === "Привет");
    assert(file_get_contents("{$dir}/utf8-dir/w-link.txt") === "Привет");
    assert((fileperms("{$dir}/utf8-dir/w.txt") & 0777) === 0640);

    if ($ownedByOtherUser)
    {
        SmokeTest::assertOwnedByOtherUser("{$dir}/utf8-dir/w.txt", "convertFileToUtf8()");
    }
    assert(glob(sys_get_temp_dir() . "/corelibs-tmp-*") === $tempFilesBefore);
    assert(array_values(array_diff(scandir("{$dir}/utf8-dir"), [".", ".."])) === ["w-link.txt", "w.txt"]);

    # A file that needs converting but can't be written is rejected, and left as it was. Root can write to any file,
    # so this can only be checked when not running as root.
    file_put_contents("{$dir}/utf8-dir/read-only.txt", "\xE6");
    chmod("{$dir}/utf8-dir/read-only.txt", 0444);

    if (!is_writable("{$dir}/utf8-dir/read-only.txt"))
    {
        SmokeTest::expectException(
            BadFilePermissionsException::class,
            fn() => Filesystem::convertFileToUtf8("{$dir}/utf8-dir/read-only.txt")
        );
        assert(file_get_contents("{$dir}/utf8-dir/read-only.txt") === "\xE6");
    }
});

$smoke->run("replacing file contents in place", function () use ($dir) {
    # createReplacementFile() makes an empty, private file in the system's temporary directory.
    $tmpFilepath = Filesystem::createReplacementFile();
    clearstatcache();
    assert(dirname($tmpFilepath) === sys_get_temp_dir() && str_starts_with(basename($tmpFilepath), "corelibs-tmp-"));
    assert(filesize($tmpFilepath) === 0 && (fileperms($tmpFilepath) & 0777) === 0600);

    # replaceWithFile() copies it into the original, shortening it if needed, and removes the temporary file.
    file_put_contents("{$dir}/replace.txt", "a long original line\n");
    chmod("{$dir}/replace.txt", 0644);
    $ownedByOtherUser = SmokeTest::giveToOtherUser("{$dir}/replace.txt", "replaceWithFile()");
    file_put_contents($tmpFilepath, "short\n");
    Filesystem::replaceWithFile("{$dir}/replace.txt", $tmpFilepath);
    clearstatcache();
    assert(file_get_contents("{$dir}/replace.txt") === "short\n" && (fileperms("{$dir}/replace.txt") & 0777) === 0644);
    assert(!file_exists($tmpFilepath));

    if ($ownedByOtherUser)
    {
        SmokeTest::assertOwnedByOtherUser("{$dir}/replace.txt", "replaceWithFile()");
    }

    # checkCanChangeFile() rejects a missing file, and (when not running as root) one that can't be written.
    SmokeTest::expectException(
        FileDoesNotExistException::class,
        fn() => Filesystem::checkCanChangeFile("{$dir}/nope.txt")
    );
    Filesystem::checkCanChangeFile("{$dir}/replace.txt");
    chmod("{$dir}/replace.txt", 0444);

    if (!is_writable("{$dir}/replace.txt"))
    {
        SmokeTest::expectException(
            BadFilePermissionsException::class,
            fn() => Filesystem::checkCanChangeFile("{$dir}/replace.txt")
        );
    }
});

$smoke->run("downloadFile", function () use ($dir) {
    assert(filesize(Filesystem::downloadFile("file:///etc/hostname")) > 0);

    # Serve a directory with PHP's built-in web server, so that HTTP can be tested without the internet.
    mkdir("{$dir}/www");
    file_put_contents("{$dir}/www/ok.txt", "downloaded content");
    $portFinder = stream_socket_server("tcp://127.0.0.1:0");
    $port = (int) substr(strrchr(stream_socket_get_name($portFinder, false), ":"), 1);
    fclose($portFinder);
    $nullDevice = ['file', '/dev/null', 'w'];
    $server = proc_open(
        [PHP_BINARY, '-S', "127.0.0.1:{$port}", '-t', "{$dir}/www"],
        [1 => $nullDevice, 2 => $nullDevice],
        $pipes
    );

    try
    {
        # Wait for the server to start listening.
        for ($i = 0; $i < 50 && !Network::isPortOpen("127.0.0.1", $port, 0.1); $i++)
        {
            usleep(100000);
        }

        $tempFilesBefore = glob(sys_get_temp_dir() . "/*");
        assert(file_get_contents(Filesystem::downloadFile("http://127.0.0.1:{$port}/ok.txt")) === "downloaded content");

        # An HTTP error must fail, rather than "downloading" the error page.
        SmokeTest::expectException(
            FilesystemException::class,
            fn() => Filesystem::downloadFile("http://127.0.0.1:{$port}/does-not-exist.txt")
        );

        # As must a server that can't be reached, which also checks that the temporary files are cleaned up.
        SmokeTest::expectException(
            FilesystemException::class,
            fn() => Filesystem::downloadFile("http://127.0.0.1:1/x")
        );
        $newTempFiles = array_diff(glob(sys_get_temp_dir() . "/*"), $tempFilesBefore);
        assert(count($newTempFiles) === 1, "expected only the successful download's file to be left behind");
    }
    finally
    {
        proc_terminate($server);
        proc_close($server);
    }
});

$smoke->run("zipDir / unzip", function () use ($dir) {
    mkdir("{$dir}/ziptest/sub", 0777, true);
    mkdir("{$dir}/ziptest/empty/nested-empty", 0777, true);
    file_put_contents("{$dir}/ziptest/sub/a.txt", "zipped content");
    Filesystem::zipDir("{$dir}/ziptest", "{$dir}/test.zip", false);
    Filesystem::unzip("{$dir}/test.zip", "{$dir}/unzipped", false);
    assert(file_get_contents("{$dir}/unzipped/ziptest/sub/a.txt") === "zipped content");

    # Empty directories must be kept, including ones nested inside other empty directories.
    assert(is_dir("{$dir}/unzipped/ziptest/empty/nested-empty"));

    # A relative path with a trailing slash used to put the files' full paths into the zip.
    $originalDir = getcwd();
    chdir($dir);
    Filesystem::zipDir("ziptest/", "{$dir}/relative.zip", false);
    chdir($originalDir);
    $zip = new ZipArchive();
    $zip->open("{$dir}/relative.zip");
    assert($zip->locateName("ziptest/sub/a.txt") !== false);
    assert($zip->locateName("ziptest/empty/nested-empty/") !== false);
    $zip->close();

    # ZipArchive::open() returns an error code (which is truthy) rather than false on failure, so check it's caught.
    file_put_contents("{$dir}/bad.zip", "this is not a zip file");
    SmokeTest::expectException(
        FilesystemException::class,
        fn() => Filesystem::unzip("{$dir}/bad.zip", "{$dir}/bad", false)
    );

    # deleteDir() through a symlink used to empty the folder it points to, so it must now refuse, and touch nothing.
    symlink("{$dir}/ziptest", "{$dir}/ziplink");
    SmokeTest::expectException(BadArgumentException::class, fn() => Filesystem::deleteDir("{$dir}/ziplink"));
    SmokeTest::expectException(BadArgumentException::class, fn() => Filesystem::deleteDir("{$dir}/ziplink/"));
    assert(file_exists("{$dir}/ziptest/sub/a.txt") && is_link("{$dir}/ziplink"));

    # zipDir() with $deleteOnComplete on a symlink zips the folder, then removes only the link, keeping the folder.
    Filesystem::zipDir("{$dir}/ziplink/", "{$dir}/link.zip", true);
    assert(!is_link("{$dir}/ziplink") && file_exists("{$dir}/ziptest/sub/a.txt"));
    $linkZip = new ZipArchive();
    $linkZip->open("{$dir}/link.zip");
    assert($linkZip->locateName("ziplink/sub/a.txt") !== false);
    $linkZip->close();

    # The zip is only written when it is closed, so this checks that a failed close() is caught.
    SmokeTest::expectException(
        FilesystemException::class,
        fn() => Filesystem::zipDir("{$dir}/ziptest", "/nonexistent-dir/test.zip", false)
    );
});
