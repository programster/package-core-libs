<?php

/*
 * Smoke tests for Filesystem. These are run by testing/main.php, which provides $smoke and $dir.
 */

use Programster\CoreLibs\Enums\FileDeletionProblemEnum;
use Programster\CoreLibs\Exceptions\BadArgumentException;
use Programster\CoreLibs\Exceptions\BadFilePermissionsException;
use Programster\CoreLibs\Exceptions\DirectoryNotDeletableException;
use Programster\CoreLibs\Exceptions\DirectoryDoesNotExistException;
use Programster\CoreLibs\Exceptions\FileDoesNotExistException;
use Programster\CoreLibs\Exceptions\FilesystemException;
use Programster\CoreLibs\Exceptions\NoFileExtensionException;
use Programster\CoreLibs\Filesystem;
use Programster\CoreLibs\Models\FileDeletionProblem;
use Programster\CoreLibs\Network;
use Programster\CoreLibs\Testing\SmokeTest;

/** @var \Programster\CoreLibs\Testing\SmokeTest $smoke */
/** @var string $dir - a temporary directory for the tests to use, which is deleted afterwards. */

$smoke->run("directories and files", function () use ($dir) {
    Filesystem::mkdir("{$dir}/sub/deep", recursive: true);
    Filesystem::mkdir("{$dir}/sub/deep"); # already existing is fine

    # Without $recursive (the default), a missing parent directory is an error rather than being created.
    SmokeTest::expectException(
        FilesystemException::class,
        fn() => Filesystem::mkdir("{$dir}/no-parent/child"),
        "mkdir(): No such file or directory"
    );
    assert(!file_exists("{$dir}/no-parent"));
    $isFile = "{$dir}/sub/deep/f.txt";
    file_put_contents($isFile, "");
    # The reason is in the message, even though SmokeTest (like many applications) has its own error handler, which
    # leaves error_get_last() empty.
    SmokeTest::expectException(
        FilesystemException::class,
        fn() => Filesystem::mkdir("{$isFile}/child"),
        "Failed to create the directory {$isFile}/child: mkdir(): Not a directory"
    );
    file_put_contents("{$dir}/sub/deep/f.txt", "abc\nxyz\n");
    Filesystem::getDirectories($dir, true);
    Filesystem::getDirContents($dir);
    SmokeTest::expectException(
        DirectoryDoesNotExistException::class,
        fn() => Filesystem::getDirectories("{$dir}/nope")
    );
    SmokeTest::expectException(
        DirectoryDoesNotExistException::class,
        fn() => Filesystem::getDirContents("{$dir}/nope")
    );
    # A file isn't a directory either.
    SmokeTest::expectException(
        DirectoryDoesNotExistException::class,
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

    # mkdir()'s $overrideUmask gives every directory it creates (including parents) exactly the given permissions,
    # rather than reduced by the umask, and never changes a directory that already existed.
    $oldUmask = umask(0022);
    mkdir("{$dir}/umask-existing", 0700);
    Filesystem::mkdir("{$dir}/umask-default/child", "0775", recursive: true);
    Filesystem::mkdir("{$dir}/umask-existing/a/b", "0775", recursive: true, overrideUmask: true);
    Filesystem::mkdir("{$dir}/umask-sticky", "1777", overrideUmask: true);
    Filesystem::mkdir("{$dir}/umask-flat", "0770", false, true);
    umask($oldUmask);
    clearstatcache();
    $permsOf = fn(string $path) => fileperms($path) & 07777;
    assert($permsOf("{$dir}/umask-default") === 0755 && $permsOf("{$dir}/umask-default/child") === 0755);
    assert($permsOf("{$dir}/umask-existing/a") === 0775 && $permsOf("{$dir}/umask-existing/a/b") === 0775);
    assert($permsOf("{$dir}/umask-existing") === 0700, "mkdir() changed a directory that already existed");
    assert($permsOf("{$dir}/umask-sticky") === 01777 && $permsOf("{$dir}/umask-flat") === 0770);

    # The default matches PHP's mkdir() (0777), reduced by the umask, so with the usual umask of 022 it gives 0755.
    $oldUmask = umask(0022);
    Filesystem::mkdir("{$dir}/umask-php-default");
    umask(0002);
    Filesystem::mkdir("{$dir}/umask-group-default");
    umask($oldUmask);
    clearstatcache();
    assert($permsOf("{$dir}/umask-php-default") === 0755 && $permsOf("{$dir}/umask-group-default") === 0775);
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
    # Only web URLs are allowed, so it can't be used to read local files.
    $localUrls = ["file:///etc/hostname", "/etc/hostname", "ftp://127.0.0.1/x", "php://filter/resource=/etc/hostname"];

    foreach ($localUrls as $url)
    {
        SmokeTest::expectException(
            BadArgumentException::class,
            fn() => Filesystem::downloadFile($url),
            "isn't an http"
        );
    }

    # Serve a directory with PHP's built-in web server, so that HTTP can be tested without the internet.
    mkdir("{$dir}/www");
    file_put_contents("{$dir}/www/ok.txt", "downloaded content");
    file_put_contents("{$dir}/www/to-local-file.php", '<?php header("Location: file:///etc/hostname");');
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

        # Nor can a redirect point it at a local file.
        SmokeTest::expectException(
            FilesystemException::class,
            fn() => Filesystem::downloadFile("http://127.0.0.1:{$port}/to-local-file.php")
        );

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

$smoke->run("downloadFile without curl", function () {
    # The fallback for when curl isn't available can only be reached by running a separate PHP with curl turned off.
    # The test runner has its own error handler (as many applications do), so this also checks that the reason is in
    # the message, rather than "unknown error".
    $code = 'require ' . var_export(__DIR__ . '/../../vendor/autoload.php', true) . '; ' .
        'set_error_handler(fn(int $number, string $message) => true); ' .
        'try { Programster\\CoreLibs\\Filesystem::downloadFile("http://127.0.0.1:1/x"); echo "downloaded"; } ' .
        'catch (Exception $e) { echo get_class($e), ": ", $e->getMessage(); }';
    $output = (string)shell_exec(
        escapeshellarg(PHP_BINARY) . " -d disable_functions=curl_version -r " . escapeshellarg($code) . " 2>&1"
    );
    $expected = FilesystemException::class . ": Failed to download http://127.0.0.1:1/x: fopen(";
    assert(str_starts_with($output, $expected), "downloadFile() without curl gave: {$output}");
    assert(str_contains($output, "Connection refused"), "downloadFile() without curl gave: {$output}");
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

    # The destination is only created once the zip has opened, so an invalid zip doesn't leave it behind.
    assert(!file_exists("{$dir}/bad"));

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

$smoke->run("deleteDir / emptyDir check everything first", function () use ($dir) {
    # A successful delete removes everything, including nested directories. A symlink is removed without touching what
    # it points to.
    $base = "{$dir}/delete-test";
    mkdir("{$base}/a/b", 0777, true);
    file_put_contents("{$base}/a/b/c.txt", "c");
    file_put_contents("{$base}/keep-target.txt", "kept");
    mkdir("{$dir}/delete-outside");
    file_put_contents("{$dir}/delete-outside/x.txt", "x");
    symlink("{$dir}/delete-outside", "{$base}/a/outside-link");
    Filesystem::emptyDir("{$base}/a");
    assert(is_dir("{$base}/a") && scandir("{$base}/a") === [".", ".."]);
    assert(file_get_contents("{$dir}/delete-outside/x.txt") === "x");
    Filesystem::deleteDir($base);
    assert(!file_exists($base));
    SmokeTest::expectException(DirectoryDoesNotExistException::class, fn() => Filesystem::deleteDir($base));

    # Symlinks inside are deleted without following them, whether they point to a file, a directory, a read-only
    # directory (which mustn't block the delete), or nothing.
    $base = "{$dir}/delete-links";
    mkdir("{$base}/sub", 0777, true);
    mkdir("{$dir}/delete-outside/locked");
    file_put_contents("{$dir}/delete-outside/file.txt", "kept");
    file_put_contents("{$dir}/delete-outside/locked/inner.txt", "kept");
    chmod("{$dir}/delete-outside/locked", 0555);
    symlink("{$dir}/delete-outside/file.txt", "{$base}/file-link");
    symlink("{$dir}/delete-outside", "{$base}/sub/dir-link");
    symlink("{$dir}/delete-outside/locked", "{$base}/locked-link");
    symlink("{$dir}/nowhere", "{$base}/broken-link");
    Filesystem::deleteDir($base);
    assert(!file_exists($base) && !is_link("{$base}/broken-link"));
    assert(file_get_contents("{$dir}/delete-outside/file.txt") === "kept");
    assert(file_get_contents("{$dir}/delete-outside/x.txt") === "x");
    assert(file_get_contents("{$dir}/delete-outside/locked/inner.txt") === "kept");
    chmod("{$dir}/delete-outside/locked", 0755);

    # Given a symlink as the directory itself, deleteDir() refuses (unless following symlinks), with or without a
    # trailing slash.
    symlink("{$dir}/delete-outside", "{$dir}/delete-top-link");

    foreach (["{$dir}/delete-top-link", "{$dir}/delete-top-link/"] as $linkPath)
    {
        SmokeTest::expectException(
            BadArgumentException::class,
            fn() => Filesystem::deleteDir($linkPath),
            "as it is a symlink"
        );
    }

    assert(file_get_contents("{$dir}/delete-outside/file.txt") === "kept" && is_link("{$dir}/delete-top-link"));

    # emptyDir() given a symlink empties the directory it points to, leaving the link and the directory in place. A
    # symlink inside it is removed without following it, unless followSymlinks is set.
    mkdir("{$dir}/empty-target");
    mkdir("{$dir}/empty-elsewhere");
    file_put_contents("{$dir}/empty-target/a.txt", "a");
    file_put_contents("{$dir}/empty-elsewhere/e.txt", "e");
    symlink("{$dir}/empty-elsewhere", "{$dir}/empty-target/inner-link");
    symlink("{$dir}/empty-target", "{$dir}/empty-link");
    Filesystem::emptyDir("{$dir}/empty-link");
    assert(is_link("{$dir}/empty-link") && scandir("{$dir}/empty-target") === [".", ".."]);
    assert(file_get_contents("{$dir}/empty-elsewhere/e.txt") === "e");

    symlink("{$dir}/empty-elsewhere", "{$dir}/empty-target/inner-link");
    Filesystem::emptyDir("{$dir}/empty-link/", followSymlinks: true);
    assert(is_link("{$dir}/empty-link") && scandir("{$dir}/empty-target") === [".", ".."]);
    assert(!file_exists("{$dir}/empty-elsewhere"));
    SmokeTest::expectException(DirectoryDoesNotExistException::class, fn() => Filesystem::emptyDir($base));

    if (SmokeTest::canTestPermissions("deleteDir() / emptyDir() refusing to partly delete"))
    {
        # Something that can't be deleted makes the whole delete fail up front, so nothing at all is deleted.
        $base = "{$dir}/delete-locked";
        mkdir("{$base}/sub/locked", 0777, true);
        mkdir("{$base}/sub/unreadable");
        file_put_contents("{$base}/first.txt", "1");
        file_put_contents("{$base}/sub/second.txt", "2");
        file_put_contents("{$base}/sub/locked/inner.txt", "3");
        file_put_contents("{$base}/sub/unreadable/hidden.txt", "4");
        chmod("{$base}/sub/locked", 0555);
        chmod("{$base}/sub/unreadable", 0000);
        # getProblems() says what can't be deleted, which directory causes it, and why. The message is kept short.
        $expectedProblems = [
            ["{$base}/sub/locked/inner.txt", "{$base}/sub/locked", FileDeletionProblemEnum::DIRECTORY_NOT_WRITABLE],
            ["{$base}/sub/unreadable", "{$base}/sub/unreadable", FileDeletionProblemEnum::DIRECTORY_NOT_LISTABLE],
        ];

        foreach (["deleteDir", "emptyDir"] as $method)
        {
            $problems = null;

            try
            {
                Filesystem::$method($base);
            }
            catch (DirectoryNotDeletableException $e)
            {
                $problems = array_map(
                    fn(FileDeletionProblem $problem) => [$problem->path, $problem->directory, $problem->reason],
                    $e->getProblems()
                );
                assert(str_contains($e->getMessage(), "as 2 thing(s) in it can't be deleted (e.g. {$base}/sub/"));
                assert(str_contains($e->getMessage(), "Call getProblems() on this exception for the full list."));
            }

            # scandir() order isn't guaranteed, so compare them sorted.
            sort($problems);
            assert($problems === $expectedProblems, "{$method}() reported the wrong problems");
        }

        # It still extends BadFilePermissionsException, so existing catch blocks still work.
        SmokeTest::expectException(BadFilePermissionsException::class, fn() => Filesystem::deleteDir($base));

        chmod("{$base}/sub/unreadable", 0755);
        assert(file_get_contents("{$base}/first.txt") === "1" && file_get_contents("{$base}/sub/second.txt") === "2");
        assert(file_get_contents("{$base}/sub/locked/inner.txt") === "3");
        assert(file_get_contents("{$base}/sub/unreadable/hidden.txt") === "4");

        # Large numbers of problems stop being counted at 100.
        mkdir("{$base}/many");

        for ($i = 0; $i < 120; $i++)
        {
            touch("{$base}/many/{$i}.txt");
        }

        chmod("{$base}/many", 0555);
        $manyProblems = [];

        try
        {
            Filesystem::emptyDir("{$base}/many");
        }
        catch (DirectoryNotDeletableException $e)
        {
            $manyProblems = $e->getProblems();
            assert(str_contains($e->getMessage(), "as 100 or more (stopped counting at 100) things in it"));
        }

        assert(count($manyProblems) === 100);
        chmod("{$base}/many", 0755);

        # The 100 limit also holds when the 100th problem is a subdirectory that can't be listed either, which would
        # otherwise add one more for it.
        mkdir("{$base}/edge/zz", 0777, true);
        touch("{$base}/edge/zz/x");

        for ($i = 0; $i < 99; $i++)
        {
            touch(sprintf("%s/edge/%02d.txt", $base, $i));
        }

        chmod("{$base}/edge/zz", 0000);
        chmod("{$base}/edge", 0555);
        $edgeProblems = [];

        try
        {
            Filesystem::emptyDir("{$base}/edge");
        }
        catch (DirectoryNotDeletableException $e)
        {
            $edgeProblems = $e->getProblems();
        }

        assert(count($edgeProblems) === 100);
        chmod("{$base}/edge", 0755);
        chmod("{$base}/edge/zz", 0755);

        # Once it can all be deleted, it is.
        chmod("{$base}/sub/locked", 0755);
        Filesystem::deleteDir($base);
        assert(!file_exists($base));

        # deleteDir() also needs to be able to remove the directory itself from its parent. emptyDir() doesn't.
        mkdir("{$dir}/delete-parent/child", 0777, true);
        file_put_contents("{$dir}/delete-parent/child/file.txt", "f");
        chmod("{$dir}/delete-parent", 0555);
        SmokeTest::expectException(
            DirectoryNotDeletableException::class,
            fn() => Filesystem::deleteDir("{$dir}/delete-parent/child"),
            "(e.g. {$dir}/delete-parent/child, as {$dir}/delete-parent can't be written to)"
        );
        assert(file_get_contents("{$dir}/delete-parent/child/file.txt") === "f");
        Filesystem::emptyDir("{$dir}/delete-parent/child");
        assert(scandir("{$dir}/delete-parent/child") === [".", ".."]);
        chmod("{$dir}/delete-parent", 0755);
    }
});

$smoke->run("deleteDir with followSymlinks", function () use ($dir) {
    # The "up" links below point at $dir (the tests' own temporary directory), never at "/", so that if following
    # them were ever broken, the worst it could delete is the test directory.
    $base = "{$dir}/follow";
    mkdir("{$base}/start/sub", 0777, true);
    mkdir("{$base}/outside-dir/nested", 0777, true);
    mkdir("{$base}/loop-a");
    mkdir("{$base}/loop-b");
    file_put_contents("{$base}/outside-dir/nested/deep.txt", "deep");
    file_put_contents("{$base}/outside-file.txt", "file");
    file_put_contents("{$base}/linked-from-outside.txt", "linked");
    file_put_contents("{$base}/start/sub/inside.txt", "inside");

    # Links inside: to a directory, to a file, a second link to the same directory, a broken one, one to something
    # inside the tree, and ones pointing "up" to directories containing them.
    symlink("{$base}/outside-dir", "{$base}/start/dir-link");
    symlink("{$base}/outside-dir", "{$base}/start/sub/same-dir-link");
    symlink("{$base}/outside-file.txt", "{$base}/start/file-link");
    symlink("{$base}/nowhere", "{$base}/start/broken-link");
    symlink("{$base}/start/sub/inside.txt", "{$base}/start/inside-link");
    symlink("..", "{$base}/start/sub/up-link");
    symlink($dir, "{$base}/start/far-up-link");

    # A link inside a followed directory, to a file elsewhere, which is followed too.
    symlink("{$base}/linked-from-outside.txt", "{$base}/outside-dir/nested/onward-link");

    # Directories whose links point at each other, which mustn't loop.
    symlink("{$base}/loop-b", "{$base}/loop-a/to-b");
    symlink("{$base}/loop-a", "{$base}/loop-b/to-a");
    symlink("{$base}/loop-a", "{$base}/start/loop-link");

    Filesystem::deleteDir("{$base}/start", followSymlinks: true);
    $leftInBase = array_values(array_diff(scandir($base), [".", ".."]));

    # Everything reached by following links is gone. Only $base and the test directory, which the "up" links point
    # to, are left (with nothing else in $base).
    assert($leftInBase === [], "followSymlinks left behind: " . implode(", ", $leftInBase));
    assert(is_dir($base) && is_dir($dir));

    # Given a symlink to a directory, the directory and the link are both deleted.
    mkdir("{$base}/real/sub", 0777, true);
    file_put_contents("{$base}/real/sub/a.txt", "a");
    symlink("{$base}/real", "{$base}/link-to-real");
    Filesystem::deleteDir("{$base}/link-to-real/", followSymlinks: true);
    assert(!file_exists("{$base}/real") && !is_link("{$base}/link-to-real"));

    # A symlink that points "up" to a directory containing it can't be deleted this way, and nothing is touched.
    mkdir("{$base}/container/inner", 0777, true);
    symlink("{$base}/container", "{$base}/container/inner/up");
    SmokeTest::expectException(
        BadArgumentException::class,
        fn() => Filesystem::deleteDir("{$base}/container/inner/up", followSymlinks: true),
        "which contains the link itself"
    );
    assert(is_link("{$base}/container/inner/up") && is_dir("{$base}/container/inner"));

    # Without followSymlinks, a symlink is still refused, and the message mentions the option.
    SmokeTest::expectException(
        BadArgumentException::class,
        fn() => Filesystem::deleteDir("{$base}/container/inner/up"),
        'set $followSymlinks to true to delete both'
    );

    if (SmokeTest::canTestPermissions("deleteDir() with followSymlinks checking what it follows"))
    {
        # Something reached by following a link that can't be deleted stops the whole delete, before anything goes.
        mkdir("{$base}/locked-parent/target", 0777, true);
        mkdir("{$base}/start2");
        file_put_contents("{$base}/start2/keep.txt", "keep");
        file_put_contents("{$base}/locked-parent/target/t.txt", "t");
        symlink("{$base}/locked-parent/target", "{$base}/start2/link");
        chmod("{$base}/locked-parent", 0555);
        SmokeTest::expectException(
            DirectoryNotDeletableException::class,
            fn() => Filesystem::deleteDir("{$base}/start2", followSymlinks: true),
            "(e.g. {$base}/locked-parent/target, as {$base}/locked-parent can't be written to)"
        );
        assert(file_get_contents("{$base}/start2/keep.txt") === "keep");
        assert(file_get_contents("{$base}/locked-parent/target/t.txt") === "t");
        chmod("{$base}/locked-parent", 0755);
    }
});

$smoke->run("fileFilter", function () use ($dir) {
    $base = "{$dir}/filter";
    mkdir($base);
    $keepNonX = fn(string $line) => !str_contains($line, "x");
    $tempFilesBefore = glob(sys_get_temp_dir() . "/corelibs-tmp-*");

    # In place: the file is changed in place, keeping its permissions, owner and links.
    file_put_contents("{$base}/in-place.txt", "abc\nxyz\ndef\n");
    chmod("{$base}/in-place.txt", 0640);
    link("{$base}/in-place.txt", "{$base}/in-place-link.txt");
    $ownedByOtherUser = SmokeTest::giveToOtherUser("{$base}/in-place.txt", "fileFilter()");
    Filesystem::fileFilter("{$base}/in-place.txt", $keepNonX);
    clearstatcache();
    assert(file_get_contents("{$base}/in-place.txt") === "abc\ndef\n");
    assert(file_get_contents("{$base}/in-place-link.txt") === "abc\ndef\n");
    assert((fileperms("{$base}/in-place.txt") & 0777) === 0640);

    if ($ownedByOtherUser)
    {
        SmokeTest::assertOwnedByOtherUser("{$base}/in-place.txt", "fileFilter()");
    }

    # The same file under another path (here a symlink) counts as in place.
    symlink("{$base}/in-place.txt", "{$base}/in-place-symlink.txt");
    Filesystem::fileFilter(
        "{$base}/in-place.txt",
        fn(string $line) => $line !== "def\n",
        "{$base}/in-place-symlink.txt"
    );
    assert(file_get_contents("{$base}/in-place.txt") === "abc\n" && is_link("{$base}/in-place-symlink.txt"));

    # The callback throwing part way through leaves the file untouched.
    file_put_contents("{$base}/throws.txt", "one\ntwo\nthree\n");
    SmokeTest::expectException(\RuntimeException::class, fn() => Filesystem::fileFilter(
        "{$base}/throws.txt",
        fn(string $line) => ($line === "three\n") ? throw new \RuntimeException("stop") : true
    ));
    assert(file_get_contents("{$base}/throws.txt") === "one\ntwo\nthree\n");

    # To a new output file: it is created with the default permissions for a new file (not the temporary file's
    # 0600), and the input is left alone.
    file_put_contents("{$base}/source.txt", "abc\nxyz\n");
    Filesystem::fileFilter("{$base}/source.txt", $keepNonX, "{$base}/new-output.txt");
    clearstatcache();
    assert(file_get_contents("{$base}/new-output.txt") === "abc\n");
    assert((fileperms("{$base}/new-output.txt") & 0777) === (0666 & ~umask()));
    assert(file_get_contents("{$base}/source.txt") === "abc\nxyz\n");

    # To an existing output file: it is replaced, keeping its permissions.
    file_put_contents("{$base}/existing-output.txt", "old contents that are longer\n");
    chmod("{$base}/existing-output.txt", 0604);
    Filesystem::fileFilter("{$base}/source.txt", $keepNonX, "{$base}/existing-output.txt");
    clearstatcache();
    assert(file_get_contents("{$base}/existing-output.txt") === "abc\n");
    assert((fileperms("{$base}/existing-output.txt") & 0777) === 0604);

    # Nothing is left in the temporary directory, whether it worked or not.
    assert(glob(sys_get_temp_dir() . "/corelibs-tmp-*") === $tempFilesBefore);
    SmokeTest::expectException(
        FileDoesNotExistException::class,
        fn() => Filesystem::fileFilter("{$base}/nope.txt", $keepNonX)
    );

    if (SmokeTest::canTestPermissions("fileFilter() checking permissions before filtering"))
    {
        # A read-only file can't be filtered in place, and an output file can't be created in a read-only directory.
        # Both are found before the callback is run at all.
        $callbackRan = false;
        $recordingCallback = function (string $line) use (&$callbackRan) { $callbackRan = true; return true; };
        file_put_contents("{$base}/read-only.txt", "abc\n");
        chmod("{$base}/read-only.txt", 0444);
        SmokeTest::expectException(
            BadFilePermissionsException::class,
            fn() => Filesystem::fileFilter("{$base}/read-only.txt", $recordingCallback)
        );
        mkdir("{$base}/read-only-dir");
        chmod("{$base}/read-only-dir", 0555);
        SmokeTest::expectException(
            BadFilePermissionsException::class,
            fn() => Filesystem::fileFilter("{$base}/source.txt", $recordingCallback, "{$base}/read-only-dir/out.txt"),
            "can't be created"
        );
        assert($callbackRan === false && !file_exists("{$base}/read-only-dir/out.txt"));
        chmod("{$base}/read-only-dir", 0755);
    }
});

$smoke->run("tmpDir", function () use ($dir) {
    # It makes a new, empty directory in the system's temporary directory, with a different name each time.
    $tmpDir1 = Filesystem::tmpDir();
    $tmpDir2 = Filesystem::tmpDir("0700");
    clearstatcache();
    assert(dirname($tmpDir1) === sys_get_temp_dir() && str_starts_with(basename($tmpDir1), "corelibs-dir-"));
    assert(is_dir($tmpDir1) && scandir($tmpDir1) === [".", ".."] && $tmpDir1 !== $tmpDir2);

    # The mode is applied, reduced by the umask, as with mkdir().
    assert((fileperms($tmpDir1) & 0777) === (0777 & ~umask()));
    assert((fileperms($tmpDir2) & 0777) === (0700 & ~umask()));
    rmdir($tmpDir1);
    rmdir($tmpDir2);

    # With $overrideUmask, the mode is applied exactly. Set a known umask, so the difference can be seen.
    $oldUmask = umask(0022);
    $exactTmpDir = Filesystem::tmpDir("0777", true);
    clearstatcache();
    assert((fileperms($exactTmpDir) & 0777) === 0777);
    rmdir($exactTmpDir);
    umask($oldUmask);

    if (SmokeTest::canTestPermissions("tmpDir() in a temporary directory that can't be written to"))
    {
        # sys_get_temp_dir() can't be changed once PHP is running, so run a separate PHP with it pointing at a
        # directory that can't be written to.
        mkdir("{$dir}/read-only-tmp");
        chmod("{$dir}/read-only-tmp", 0555);
        $code = 'require ' . var_export(__DIR__ . '/../../vendor/autoload.php', true) . '; ' .
            'try { Programster\\CoreLibs\\Filesystem::tmpDir(); echo "created"; } ' .
            'catch (Exception $e) { echo get_class($e), ": ", $e->getMessage(); }';
        $output = shell_exec(
            escapeshellarg(PHP_BINARY) . " -d sys_temp_dir=" . escapeshellarg("{$dir}/read-only-tmp") . " -r " .
            escapeshellarg($code) . " 2>&1"
        );
        $expectedStart = FilesystemException::class . ": Failed to create a temporary directory";
        assert(
            str_starts_with((string)$output, $expectedStart),
            "tmpDir() in a read-only directory gave: {$output}"
        );
        assert(scandir("{$dir}/read-only-tmp") === [".", ".."]);
        chmod("{$dir}/read-only-tmp", 0755);
    }
});

$smoke->run("zipDir error messages", function () use ($dir) {
    if (SmokeTest::canTestPermissions("zipDir() giving the reason when a file can't be read"))
    {
        # A file that can't be read makes close() fail. The reason is PHP's own warning, even though the test runner
        # has its own error handler, which leaves error_get_last() empty.
        mkdir("{$dir}/zip-unreadable");
        file_put_contents("{$dir}/zip-unreadable/secret.txt", "secret");
        chmod("{$dir}/zip-unreadable/secret.txt", 0000);
        SmokeTest::expectException(
            FilesystemException::class,
            fn() => Filesystem::zipDir("{$dir}/zip-unreadable", "{$dir}/unreadable.zip"),
            "Failed to write the zip file {$dir}/unreadable.zip: ZipArchive::close(): Can't open file"
        );
        chmod("{$dir}/zip-unreadable/secret.txt", 0644);
    }
});

$smoke->run("getDirectories / getDirContents and symlinks", function () use ($dir) {
    $base = "{$dir}/listing";
    mkdir("{$base}/inside/a", 0777, true);
    mkdir("{$base}/inside/b");
    mkdir("{$base}/elsewhere");
    file_put_contents("{$base}/inside/a/a.txt", "a");
    file_put_contents("{$base}/elsewhere/e.txt", "e");
    $root = "{$base}/inside";

    # A link back up to a directory being listed, a link to a directory elsewhere, and two links between sibling
    # directories, pointing at each other.
    symlink("..", "{$root}/a/up");
    symlink("{$base}/elsewhere", "{$root}/elsewhere-link");
    symlink("../b", "{$root}/a/to-b");
    symlink("../a", "{$root}/b/to-a");

    # By default, symlinks to directories are listed, but not gone into.
    $directories = Filesystem::getDirectories($root, true);
    sort($directories);
    $expected = [
        "{$root}/a",
        "{$root}/a/to-b",
        "{$root}/a/up",
        "{$root}/b",
        "{$root}/b/to-a",
        "{$root}/elsewhere-link",
    ];
    assert($directories === $expected, "getDirectories() gave: " . implode(", ", $directories));
    assert(Filesystem::getDirContents($root) === ["{$root}/a/a.txt"]);

    # With $followSymlinks, they are gone into (with the contents given under the link's path), but a link back up
    # to a directory already being listed isn't, so it can't go round in circles.
    $files = Filesystem::getDirContents($root, followSymlinks: true);
    sort($files);
    $expectedFiles = [
        "{$root}/a/a.txt",
        "{$root}/b/to-a/a.txt",
        "{$root}/elsewhere-link/e.txt",
    ];
    assert($files === $expectedFiles, "getDirContents() following symlinks gave: " . implode(", ", $files));
    # listDirectories() and listDirContents() are aliases for getDirectories() and getDirContents().
    $listed = Filesystem::listDirectories($root, true);
    sort($listed);
    assert($listed === $directories);
    $listedFiles = Filesystem::listDirContents($root, followSymlinks: true);
    sort($listedFiles);
    assert($listedFiles === $files);

    # A link back up is still listed, as it is a directory, but nothing inside it is.
    $followedDirectories = Filesystem::getDirectories($root, true, true, true);
    assert(in_array("{$root}/a/to-b/to-a", $followedDirectories, true));
    assert(!in_array("{$root}/a/to-b/to-a/to-b", $followedDirectories, true));
    assert(!in_array("{$root}/a/up/inside", $followedDirectories, true));

    # Names only, and hidden entries left out, still work.
    file_put_contents("{$root}/.hidden", "h");
    assert(Filesystem::getDirContents($root, false, false, true, false) === []);
    assert(Filesystem::getDirContents($root, false, false) === [".hidden"]);

    # The links point back into the test directory, so remove them, as other tests list $dir.
    Filesystem::deleteDir($base);
});

$smoke->run("unzip", function () use ($dir) {
    $base = "{$dir}/unzip-test";
    mkdir("{$base}/source/inner", 0777, true);
    file_put_contents("{$base}/source/inner/a.txt", "a");
    Filesystem::zipDir("{$base}/source", "{$base}/keep.zip", false);

    # A destination that doesn't exist is created, along with any parents, and the zip is deleted by default.
    copy("{$base}/keep.zip", "{$base}/delete-me.zip");
    Filesystem::unzip("{$base}/delete-me.zip", "{$base}/new/nested/destination");
    assert(file_get_contents("{$base}/new/nested/destination/source/inner/a.txt") === "a");
    assert(!file_exists("{$base}/delete-me.zip"));

    # A missing zip, and a destination that is a file, are reported clearly.
    SmokeTest::expectException(
        FileDoesNotExistException::class,
        fn() => Filesystem::unzip("{$base}/nope.zip", "{$base}/out")
    );
    file_put_contents("{$base}/a-file", "not a directory");
    SmokeTest::expectException(
        BadArgumentException::class,
        fn() => Filesystem::unzip("{$base}/keep.zip", "{$base}/a-file", false),
        "as it isn't a directory"
    );
    assert(file_get_contents("{$base}/a-file") === "not a directory" && is_file("{$base}/keep.zip"));

    # Nothing is left of the temporary directory it extracts into, wherever that was.
    assert(glob("{$base}/new/nested/destination/.corelibs-unzip-*") === []);
    assert(glob(sys_get_temp_dir() . "/corelibs-unzip-*") === []);

    # A destination on a different filesystem from the system's temporary directory can't have the contents moved
    # there instantly, so it extracts into a hidden directory inside the destination instead. /dev/shm is usually a
    # separate filesystem, so check there if so.
    $isSeparateFilesystem = is_dir("/dev/shm")
        && is_writable("/dev/shm")
        && stat("/dev/shm")['dev'] !== stat(sys_get_temp_dir())['dev'];

    if ($isSeparateFilesystem)
    {
        $otherFilesystem = "/dev/shm/corelibs-unzip-test-" . getmypid();

        try
        {
            Filesystem::unzip("{$base}/keep.zip", "{$otherFilesystem}/out", false);
            assert(file_get_contents("{$otherFilesystem}/out/source/inner/a.txt") === "a");
            assert(glob("{$otherFilesystem}/out/.corelibs-unzip-*") === []);
        }
        finally
        {
            if (is_dir($otherFilesystem))
            {
                Filesystem::deleteDir($otherFilesystem);
            }
        }
    }

    # Extracting into an existing destination merges into its directories, and replaces files with the same names.
    mkdir("{$base}/existing/source/inner", 0777, true);
    file_put_contents("{$base}/existing/source/inner/a.txt", "old a");
    file_put_contents("{$base}/existing/source/inner/kept.txt", "kept");
    Filesystem::unzip("{$base}/keep.zip", "{$base}/existing", false);
    assert(file_get_contents("{$base}/existing/source/inner/a.txt") === "a");
    assert(file_get_contents("{$base}/existing/source/inner/kept.txt") === "kept");

    # Something in the way that can't be merged (here a file where the zip has a directory) stops it before anything
    # is moved, leaving the destination exactly as it was.
    mkdir("{$base}/blocked");
    file_put_contents("{$base}/blocked/source", "a file in the way");
    SmokeTest::expectException(
        FilesystemException::class,
        fn() => Filesystem::unzip("{$base}/keep.zip", "{$base}/blocked", false),
        "the zip has a directory at {$base}/blocked/source, where there is already a file."
    );
    assert(file_get_contents("{$base}/blocked/source") === "a file in the way");
    assert(array_values(array_diff(scandir("{$base}/blocked"), [".", ".."])) === ["source"]);

    # Extraction failing part way through leaves nothing behind. This zip has a file "clash" and a directory "clash/",
    # so once the file is extracted, the directory can't be. A destination it created is removed again too.
    $clashZip = new ZipArchive();
    $clashZip->open("{$base}/clash.zip", ZipArchive::CREATE);
    $clashZip->addFromString("first.txt", "first");
    $clashZip->addFromString("clash", "a file");
    $clashZip->addFromString("clash/inside.txt", "inside a directory");
    $clashZip->close();
    SmokeTest::expectException(
        FilesystemException::class,
        fn() => Filesystem::unzip("{$base}/clash.zip", "{$base}/new-for-clash/deeper", false),
        "Unable to unzip {$base}/clash.zip"
    );
    assert(!file_exists("{$base}/new-for-clash"));

    mkdir("{$base}/existing-for-clash");
    SmokeTest::expectException(
        FilesystemException::class,
        fn() => Filesystem::unzip("{$base}/clash.zip", "{$base}/existing-for-clash", false)
    );
    assert(scandir("{$base}/existing-for-clash") === [".", ".."]);
    assert(glob(sys_get_temp_dir() . "/corelibs-unzip-*") === []);

    if (SmokeTest::canTestPermissions("unzip() reporting why it can't create, extract or delete"))
    {
        # A destination that can't be created gives the reason, even though the test runner has its own error handler.
        mkdir("{$base}/read-only");
        chmod("{$base}/read-only", 0555);
        SmokeTest::expectException(
            FilesystemException::class,
            fn() => Filesystem::unzip("{$base}/keep.zip", "{$base}/read-only/out", false),
            "Permission denied"
        );

        # A zip that can't be deleted afterwards is reported, but its contents have already been extracted.
        mkdir("{$base}/locked-zip");
        copy("{$base}/keep.zip", "{$base}/locked-zip/locked.zip");
        chmod("{$base}/locked-zip", 0555);
        SmokeTest::expectException(
            FilesystemException::class,
            fn() => Filesystem::unzip("{$base}/locked-zip/locked.zip", "{$base}/from-locked"),
            "but failed to delete the zip: unlink("
        );
        assert(file_get_contents("{$base}/from-locked/source/inner/a.txt") === "a");
        assert(is_file("{$base}/locked-zip/locked.zip"));
        chmod("{$base}/read-only", 0755);
        chmod("{$base}/locked-zip", 0755);
    }
});

$smoke->run("isOnSameFilesystem", function () use ($dir) {
    # Paths in the same directory are on the same filesystem, including ones that don't exist yet (checked using
    # the nearest directory above them that does).
    file_put_contents("{$dir}/same-fs.txt", "x");
    assert(Filesystem::isOnSameFilesystem("{$dir}/same-fs.txt", $dir));
    assert(Filesystem::isOnSameFilesystem("{$dir}/not/created/yet.txt", "{$dir}/same-fs.txt"));

    # /dev/shm is usually a separate filesystem, so check there if so.
    if (is_dir("/dev/shm") && stat("/dev/shm")['dev'] !== stat($dir)['dev'])
    {
        assert(!Filesystem::isOnSameFilesystem("/dev/shm", $dir));
        assert(!Filesystem::isOnSameFilesystem("/dev/shm/not/created/yet", "{$dir}/not/created/yet"));
    }

    SmokeTest::expectException(
        BadArgumentException::class,
        fn() => Filesystem::isOnSameFilesystem("relative/path", $dir),
        "must be an absolute path"
    );
});

$smoke->run("fileperms", function () use ($dir) {
    $base = "{$dir}/fileperms";
    mkdir($base);
    file_put_contents("{$base}/file.txt", "x");
    chmod("{$base}/file.txt", 0640);
    assert(Filesystem::fileperms("{$base}/file.txt") === "-rw-r-----");
    mkdir("{$base}/dir");
    chmod("{$base}/dir", 0750);
    assert(Filesystem::fileperms("{$base}/dir") === "drwxr-x---");
    assert(Filesystem::fileperms("/dev/null") === "crw-rw-rw-");

    # The setuid, setgid and sticky bits replace the matching "x", in capitals when the "x" isn't set.
    chmod("{$base}/file.txt", 06755);
    assert(Filesystem::fileperms("{$base}/file.txt") === "-rwsr-sr-x");
    chmod("{$base}/file.txt", 06644);
    assert(Filesystem::fileperms("{$base}/file.txt") === "-rwSr-Sr--");
    chmod("{$base}/dir", 01777);
    assert(Filesystem::fileperms("{$base}/dir") === "drwxrwxrwt");
    chmod("{$base}/dir", 01776);
    assert(Filesystem::fileperms("{$base}/dir") === "drwxrwxrwT");

    # A symlink is reported as the link itself, even when it is broken.
    symlink("{$base}/file.txt", "{$base}/link");
    assert(Filesystem::fileperms("{$base}/link") === "lrwxrwxrwx");
    symlink("{$base}/missing", "{$base}/broken-link");
    assert(Filesystem::fileperms("{$base}/broken-link") === "lrwxrwxrwx");

    if (function_exists("posix_mkfifo"))
    {
        posix_mkfifo("{$base}/pipe", 0600);
        assert(Filesystem::fileperms("{$base}/pipe") === "prw-------");
    }

    SmokeTest::expectException(
        FileDoesNotExistException::class,
        fn() => Filesystem::fileperms("{$base}/missing"),
        "There is nothing at {$base}/missing"
    );
});

$smoke->run("zipDir / unzip with symlinks", function () use ($dir) {
    $base = "{$dir}/zip-links";
    mkdir("{$base}/outside/deeper", 0777, true);
    file_put_contents("{$base}/outside/o.txt", "outside");
    file_put_contents("{$base}/outside/deeper/d.txt", "deeper");
    mkdir("{$base}/project/real", 0777, true);
    file_put_contents("{$base}/project/real/f.txt", "inside");
    symlink("real", "{$base}/project/dir-link");
    symlink("real/f.txt", "{$base}/project/file-link");
    symlink("{$base}/project/real/f.txt", "{$base}/project/absolute-inside");
    symlink("{$base}/project/missing", "{$base}/project/broken");

    # The targets stored for each symlink entry in a zip.
    $storedLinks = function (string $zipPath): array {
        $zip = new ZipArchive();
        $zip->open($zipPath);
        $links = [];

        for ($i = 0; $i < $zip->numFiles; $i++)
        {
            $zip->getExternalAttributesIndex($i, $system, $attributes);

            if ((($attributes >> 16) & 0170000) === 0120000)
            {
                $links[$zip->getNameIndex($i)] = $zip->getFromIndex($i);
            }
        }

        $zip->close();
        ksort($links);
        return $links;
    };

    # Symlinks inside the folder are stored as symlinks relative to themselves, rather than followed.
    Filesystem::zipDir("{$base}/project", "{$base}/inside.zip", false);

    $expected = [
        "project/absolute-inside" => "real/f.txt",
        "project/broken" => "missing",
        "project/dir-link" => "real",
        "project/file-link" => "real/f.txt",
    ];

    assert($storedLinks("{$base}/inside.zip") === $expected);

    # unzip() recreates them as symlinks, which work where it is extracted.
    Filesystem::unzip("{$base}/inside.zip", "{$base}/out-inside", false);
    assert(readlink("{$base}/out-inside/project/dir-link") === "real");
    assert(file_get_contents("{$base}/out-inside/project/dir-link/f.txt") === "inside");
    assert(file_get_contents("{$base}/out-inside/project/absolute-inside") === "inside");
    assert(is_link("{$base}/out-inside/project/broken"));

    # A symlink pointing outside is stored as it is, so an untrusted unzip refuses it, leaving nothing behind.
    symlink("{$base}/outside", "{$base}/project/outside-dir");
    symlink("../outside/o.txt", "{$base}/project/outside-file");
    Filesystem::zipDir("{$base}/project", "{$base}/outside.zip", false);
    $links = $storedLinks("{$base}/outside.zip");
    assert($links["project/outside-dir"] === "{$base}/outside");
    assert($links["project/outside-file"] === "../outside/o.txt");

    SmokeTest::expectException(
        FilesystemException::class,
        fn() => Filesystem::unzip("{$base}/outside.zip", "{$base}/out-untrusted", false),
        "pointing to {$base}/outside, which is outside {$base}/out-untrusted"
    );

    assert(!file_exists("{$base}/out-untrusted"));

    # As is one that climbs out with "..", which only a hand-made zip would have.
    $escapingZip = new ZipArchive();
    $escapingZip->open("{$base}/escaping.zip", ZipArchive::CREATE);
    $escapingZip->addFromString("sub/escape", "../../../etc/passwd");
    $escapingZip->setExternalAttributesName("sub/escape", ZipArchive::OPSYS_UNIX, 0120777 << 16);
    $escapingZip->close();
    mkdir("{$base}/out-escaping");

    SmokeTest::expectException(
        FilesystemException::class,
        fn() => Filesystem::unzip("{$base}/escaping.zip", "{$base}/out-escaping", false),
        "pointing to ../../../etc/passwd, which is outside"
    );

    assert(scandir("{$base}/out-escaping") === [".", ".."]);

    # A trusted unzip allows them.
    Filesystem::unzip("{$base}/outside.zip", "{$base}/out-trusted", false, true);
    assert(readlink("{$base}/out-trusted/project/outside-dir") === "{$base}/outside");

    # With $followSymlinks, what symlinks pointing outside lead to is zipped instead, but ones inside stay links.
    Filesystem::zipDir("{$base}/project", "{$base}/followed.zip", false, followSymlinks: true);
    $links = $storedLinks("{$base}/followed.zip");
    assert(!isset($links["project/outside-dir"]) && !isset($links["project/outside-file"]));
    assert($links["project/dir-link"] === "real");
    Filesystem::unzip("{$base}/followed.zip", "{$base}/out-followed", false);
    assert(file_get_contents("{$base}/out-followed/project/outside-file") === "outside");
    assert(file_get_contents("{$base}/out-followed/project/outside-dir/deeper/d.txt") === "deeper");
    assert(!is_link("{$base}/out-followed/project/outside-dir"));

    # A symlink inside a followed folder that points back into the zipped folder becomes relative to where it ends up.
    symlink("{$base}/project/real", "{$base}/outside/back-inside");
    Filesystem::zipDir("{$base}/project", "{$base}/back.zip", false, followSymlinks: true);
    assert($storedLinks("{$base}/back.zip")["project/outside-dir/back-inside"] === "../real");

    # Following a symlink that leads back to a folder above it would go round in circles, so it is refused.
    symlink("{$base}/outside", "{$base}/outside/deeper/loop");
    SmokeTest::expectException(
        FilesystemException::class,
        fn() => Filesystem::zipDir("{$base}/project", "{$base}/loop.zip", false, followSymlinks: true),
        "would go round in circles"
    );

    # Without $includeFolder, only what is in the folder is zipped, so it is extracted straight into the destination,
    # and symlinks inside it still point at the right place.
    unlink("{$base}/outside/deeper/loop");
    unlink("{$base}/outside/back-inside");
    Filesystem::zipDir("{$base}/project", "{$base}/contents.zip", false, false);
    assert($storedLinks("{$base}/contents.zip")["dir-link"] === "real");
    Filesystem::unzip("{$base}/contents.zip", "{$base}/out-contents", false, true);
    assert(file_get_contents("{$base}/out-contents/real/f.txt") === "inside");
    assert(file_get_contents("{$base}/out-contents/dir-link/f.txt") === "inside");
    assert(!file_exists("{$base}/out-contents/project"));

    # Zipping a symlink to a folder zips the folder, named after the symlink.
    symlink("{$base}/outside/deeper", "{$base}/named-link");
    Filesystem::zipDir("{$base}/named-link", "{$base}/named.zip", false);
    Filesystem::unzip("{$base}/named.zip", "{$base}/out-named", false, true);
    assert(file_get_contents("{$base}/out-named/named-link/d.txt") === "deeper");
});

$smoke->run("zipDir including hidden files", function () use ($dir) {
    $base = "{$dir}/zip-hidden";
    mkdir("{$base}/project/.hidden-dir/.nested", 0777, true);
    mkdir("{$base}/project/.empty-hidden");
    file_put_contents("{$base}/project/.env", "x");
    file_put_contents("{$base}/project/.hidden-dir/.nested/.deep", "x");
    $expected = [".empty-hidden/", ".env", ".hidden-dir/", ".hidden-dir/.nested/", ".hidden-dir/.nested/.deep"];

    foreach (["with" => true, "without" => false] as $name => $includeFolder)
    {
        Filesystem::zipDir("{$base}/project", "{$base}/{$name}.zip", false, $includeFolder);
        $zip = new ZipArchive();
        $zip->open("{$base}/{$name}.zip");
        $names = [];

        for ($i = 0; $i < $zip->numFiles; $i++)
        {
            $names[] = $zip->getNameIndex($i);
        }

        $zip->close();
        sort($names);
        $prefix = $includeFolder ? "project/" : "";
        $expectedNames = array_map(fn(string $entry) => $prefix . $entry, $expected);
        $expectedNames = $includeFolder ? array_merge(["project/"], $expectedNames) : $expectedNames;
        sort($expectedNames);
        assert($names === $expectedNames, "{$name} the folder, the zip had: " . implode(", ", $names));
    }
});

