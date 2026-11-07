<?php

/*
 * Smoke tests for Filesystem. These are run by testing/main.php, which provides $smoke and $dir.
 */

use Programster\CoreLibs\Enums\DataSizeUnitEnum;
use Programster\CoreLibs\Enums\FileDeletionOperationEnum;
use Programster\CoreLibs\Enums\FileDeletionProblemEnum;
use Programster\CoreLibs\Enums\PreserveOwnershipEnum;
use Programster\CoreLibs\Enums\UseSystemTempDirEnum;
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

    $mkdirWithoutParent = function () use ($dir): void {
        Filesystem::mkdir("{$dir}/no-parent/child");
    };

    # Without $recursive (the default), a missing parent directory is an error rather than being created.
    SmokeTest::expectException(
        FilesystemException::class,
        $mkdirWithoutParent,
        "mkdir(): No such file or directory"
    );

    assert(!file_exists("{$dir}/no-parent"));
    $isFile = "{$dir}/sub/deep/f.txt";
    file_put_contents($isFile, "");

    $mkdirInsideFile = function () use ($isFile): void {
        Filesystem::mkdir("{$isFile}/child");
    };

    # The reason is in the message, even though SmokeTest (like many applications) has its own error handler, which
    # leaves error_get_last() empty.
    SmokeTest::expectException(
        FilesystemException::class,
        $mkdirInsideFile,
        # Windows gives a different reason for the same thing.
        "Failed to create the directory {$isFile}/child: mkdir(): " .
        ((PHP_OS_FAMILY === "Windows") ? "No such file or directory" : "Not a directory")
    );

    file_put_contents("{$dir}/sub/deep/f.txt", "abc\nxyz\n");
    Filesystem::getDirectories($dir, true);
    Filesystem::getDirContents($dir);

    $getDirectoriesOfMissing = function () use ($dir): void {
        Filesystem::getDirectories("{$dir}/nope");
    };

    SmokeTest::expectException(
        DirectoryDoesNotExistException::class,
        $getDirectoriesOfMissing
    );

    $getContentsOfMissing = function () use ($dir): void {
        Filesystem::getDirContents("{$dir}/nope");
    };

    SmokeTest::expectException(
        DirectoryDoesNotExistException::class,
        $getContentsOfMissing
    );

    $getContentsOfFile = function () use ($dir): void {
        Filesystem::getDirContents("{$dir}/sub/deep/f.txt");
    };

    # A file isn't a directory either.
    SmokeTest::expectException(
        DirectoryDoesNotExistException::class,
        $getContentsOfFile
    );

    assert(Filesystem::getDirSize("{$dir}/sub") > 0);
    assert(Filesystem::getFileSize("{$dir}/sub/deep/f.txt") === 8.0);

    $ignoreLine = function (string $line): void {
        # Nothing is done with each line, as only the reading is being tested.
    };

    Filesystem::fileWalk("{$dir}/sub/deep/f.txt", $ignoreLine);

    $keepNonX = function (string $line): bool {
        return !str_contains($line, "x");
    };

    Filesystem::fileFilter("{$dir}/sub/deep/f.txt", $keepNonX);
    assert(file_get_contents("{$dir}/sub/deep/f.txt") === "abc\n");
    Filesystem::fileperms("{$dir}/sub/deep/f.txt");
    assert(Filesystem::getFileExtension("a.tar.gz") === "gz");

    $getExtensionWithoutDot = function (): void {
        Filesystem::getFileExtension("README");
    };

    SmokeTest::expectException(NoFileExtensionException::class, $getExtensionWithoutDot);

    $getExtensionOfDottedDirectory = function (): void {
        Filesystem::getFileExtension("/var/v1.2/README");
    };

    SmokeTest::expectException(
        NoFileExtensionException::class,
        $getExtensionOfDottedDirectory
    );

    $getEmptyExtension = function (): void {
        Filesystem::getFileExtension("file.");
    };

    SmokeTest::expectException(NoFileExtensionException::class, $getEmptyExtension);
    Filesystem::deleteDir(Filesystem::tmpDir());
    Filesystem::mkdir("{$dir}/perms", "0o750");
    SmokeTest::assertPermissions("{$dir}/perms", (0750 & ~umask()));

    $mkdirWithDecimalPermissions = function () use ($dir): void {
        Filesystem::mkdir("{$dir}/bad", "493");
    };

    SmokeTest::expectException(BadArgumentException::class, $mkdirWithDecimalPermissions);

    $mkdirWithBadOctal = function () use ($dir): void {
        Filesystem::mkdir("{$dir}/bad", "0758");
    };

    SmokeTest::expectException(BadArgumentException::class, $mkdirWithBadOctal);
    assert(!file_exists("{$dir}/bad"));

    $tmpDirWithBadPermissions = function (): void {
        Filesystem::tmpDir("rwx");
    };

    SmokeTest::expectException(BadArgumentException::class, $tmpDirWithBadPermissions);

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
    SmokeTest::assertPermissions("{$dir}/umask-default", 0755);
    SmokeTest::assertPermissions("{$dir}/umask-default/child", 0755);
    SmokeTest::assertPermissions("{$dir}/umask-existing/a", 0775);
    SmokeTest::assertPermissions("{$dir}/umask-existing/a/b", 0775);
    SmokeTest::assertPermissions("{$dir}/umask-existing", 0700, "mkdir() changed a directory that already existed");
    SmokeTest::assertPermissions("{$dir}/umask-sticky", 01777);
    SmokeTest::assertPermissions("{$dir}/umask-flat", 0770);

    # The default matches PHP's mkdir() (0777), reduced by the umask, so with the usual umask of 022 it gives 0755.
    $oldUmask = umask(0022);
    Filesystem::mkdir("{$dir}/umask-php-default");
    umask(0002);
    Filesystem::mkdir("{$dir}/umask-group-default");
    umask($oldUmask);
    clearstatcache();
    SmokeTest::assertPermissions("{$dir}/umask-php-default", 0755);
    SmokeTest::assertPermissions("{$dir}/umask-group-default", 0775);
    assert(Filesystem::permissionsStringToInt("755") === 0755);
    assert(Filesystem::permissionsStringToInt("0o1777") === 01777);

    $convertHexPermissions = function (): void {
        Filesystem::permissionsStringToInt("0x755");
    };

    SmokeTest::expectException(BadArgumentException::class, $convertHexPermissions);
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
    SmokeTest::assertPermissions("{$dir}/utf8-dir/w.txt", 0640);

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
        $convertReadOnlyFile = function () use ($dir): void {
            Filesystem::convertFileToUtf8("{$dir}/utf8-dir/read-only.txt");
        };

        SmokeTest::expectException(
            BadFilePermissionsException::class,
            $convertReadOnlyFile
        );

        assert(file_get_contents("{$dir}/utf8-dir/read-only.txt") === "\xE6");
    }

    # Make it writable again, as Windows won't let a read-only file be deleted, so the clean-up couldn't remove it.
    chmod("{$dir}/utf8-dir/read-only.txt", 0644);
});

$smoke->run("replacing file contents in place", function () use ($dir) {
    # createReplacementFile() with ALWAYS makes an empty, private file in the system's temporary directory.
    $tmpFilepath = Filesystem::createReplacementFile("{$dir}/replace.txt", UseSystemTempDirEnum::ALWAYS);
    clearstatcache();
    SmokeTest::assertSamePath(dirname($tmpFilepath), sys_get_temp_dir());
    assert(str_starts_with(basename($tmpFilepath), SmokeTest::tempnamPrefix("corelibs-tmp-")));
    assert(filesize($tmpFilepath) === 0);
    SmokeTest::assertPermissions($tmpFilepath, 0600);

    # replaceWithFile() copies it into the original, shortening it if needed, and removes the temporary file.
    file_put_contents("{$dir}/replace.txt", "a long original line\n");
    chmod("{$dir}/replace.txt", 0644);
    $ownedByOtherUser = SmokeTest::giveToOtherUser("{$dir}/replace.txt", "replaceWithFile()");
    file_put_contents($tmpFilepath, "short\n");
    Filesystem::replaceWithFile("{$dir}/replace.txt", $tmpFilepath);
    clearstatcache();
    assert(file_get_contents("{$dir}/replace.txt") === "short\n");
    SmokeTest::assertPermissions("{$dir}/replace.txt", 0644);
    assert(!file_exists($tmpFilepath));

    if ($ownedByOtherUser)
    {
        SmokeTest::assertOwnedByOtherUser("{$dir}/replace.txt", "replaceWithFile()");
    }

    $checkMissingFile = function () use ($dir): void {
        Filesystem::checkCanChangeFile("{$dir}/nope.txt");
    };

    # checkCanChangeFile() rejects a missing file, and (when not running as root) one that can't be written.
    SmokeTest::expectException(
        FileDoesNotExistException::class,
        $checkMissingFile
    );

    Filesystem::checkCanChangeFile("{$dir}/replace.txt");
    chmod("{$dir}/replace.txt", 0444);

    if (!is_writable("{$dir}/replace.txt"))
    {
        $checkUnwritableFile = function () use ($dir): void {
            Filesystem::checkCanChangeFile("{$dir}/replace.txt");
        };

        SmokeTest::expectException(
            BadFilePermissionsException::class,
            $checkUnwritableFile
        );
    }

    # Make it writable again, as Windows won't let a read-only file be deleted, so the clean-up couldn't remove it.
    chmod("{$dir}/replace.txt", 0644);
});

$smoke->run("downloadFile", function () use ($dir) {
    # Only web URLs are allowed, so it can't be used to read local files.
    $localUrls = ["file:///etc/hostname", "/etc/hostname", "ftp://127.0.0.1/x", "php://filter/resource=/etc/hostname"];

    foreach ($localUrls as $url)
    {
        $downloadLocalUrl = function () use ($url): void {
            Filesystem::downloadFile($url);
        };

        SmokeTest::expectException(
            BadArgumentException::class,
            $downloadLocalUrl,
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
    $nullDevice = ['file', SmokeTest::nullDevice(), 'w'];

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

        $downloadRedirectToFile = function () use ($port): void {
            Filesystem::downloadFile("http://127.0.0.1:{$port}/to-local-file.php");
        };

        # Nor can a redirect point it at a local file.
        SmokeTest::expectException(
            FilesystemException::class,
            $downloadRedirectToFile
        );

        $downloadMissingPage = function () use ($port): void {
            Filesystem::downloadFile("http://127.0.0.1:{$port}/does-not-exist.txt");
        };

        # An HTTP error must fail, rather than "downloading" the error page.
        SmokeTest::expectException(
            FilesystemException::class,
            $downloadMissingPage
        );

        $downloadFromUnreachable = function (): void {
            Filesystem::downloadFile("http://127.0.0.1:1/x");
        };

        # As must a server that can't be reached, which also checks that the temporary files are cleaned up.
        SmokeTest::expectException(
            FilesystemException::class,
            $downloadFromUnreachable
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
        '$ignoreErrors = function (int $number, string $message): bool { return true; }; ' .
        'set_error_handler($ignoreErrors); ' .
        'try { Programster\\CoreLibs\\Filesystem::downloadFile("http://127.0.0.1:1/x"); echo "downloaded"; } ' .
        'catch (Exception $e) { echo get_class($e), ": ", $e->getMessage(); }';

    # Run it from a script file, rather than with -r, as shell quoting of code differs on Windows.
    $script = tempnam(sys_get_temp_dir(), "corelibs-no-curl-");
    file_put_contents($script, "<?php {$code}");

    $output = (string)shell_exec(
        escapeshellarg(PHP_BINARY) . " -d disable_functions=curl_version " . escapeshellarg($script) . " 2>&1"
    );

    unlink($script);
    $expected = FilesystemException::class . ": Failed to download http://127.0.0.1:1/x: fopen(";
    assert(str_starts_with($output, $expected), "downloadFile() without curl gave: {$output}");

    # Linux says "Connection refused", and Windows "the target machine actively refused it".
    assert(str_contains($output, "refused"), "downloadFile() without curl gave: {$output}");
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

    $unzipBadZip = function () use ($dir): void {
        Filesystem::unzip("{$dir}/bad.zip", "{$dir}/bad", false);
    };

    SmokeTest::expectException(
        FilesystemException::class,
        $unzipBadZip
    );

    # The destination is only created once the zip has opened, so an invalid zip doesn't leave it behind.
    assert(!file_exists("{$dir}/bad"));

    # These only need an absolute symlink to a folder that exists, which Windows can create too.
    if (SmokeTest::canCreateSymlinks("deleteDir() and zipDir() through a symlink", false))
    {
        # deleteDir() through a symlink used to empty the folder it points to, so it must now refuse, and touch nothing.
        symlink("{$dir}/ziptest", "{$dir}/ziplink");

        $deleteThroughLink = function () use ($dir): void {
            Filesystem::deleteDir("{$dir}/ziplink");
        };

        SmokeTest::expectException(BadArgumentException::class, $deleteThroughLink);

        $deleteThroughLinkWithSlash = function () use ($dir): void {
            Filesystem::deleteDir("{$dir}/ziplink/");
        };

        SmokeTest::expectException(BadArgumentException::class, $deleteThroughLinkWithSlash);
        assert(file_exists("{$dir}/ziptest/sub/a.txt") && is_link("{$dir}/ziplink"));

        # zipDir() with $deleteOnComplete on a symlink zips the folder, then removes only the link, keeping the folder.
        Filesystem::zipDir("{$dir}/ziplink/", "{$dir}/link.zip", true);
        assert(!is_link("{$dir}/ziplink") && file_exists("{$dir}/ziptest/sub/a.txt"));
        $linkZip = new ZipArchive();
        $linkZip->open("{$dir}/link.zip");
        assert($linkZip->locateName("ziplink/sub/a.txt") !== false);
        $linkZip->close();
    }

    $zipToMissingDirectory = function () use ($dir): void {
        Filesystem::zipDir("{$dir}/ziptest", "/nonexistent-dir/test.zip", false);
    };

    # The zip is only written when it is closed, so this checks that a failed close() is caught.
    SmokeTest::expectException(
        FilesystemException::class,
        $zipToMissingDirectory
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

    $deleteDeleted = function () use ($base): void {
        Filesystem::deleteDir($base);
    };

    SmokeTest::expectException(DirectoryDoesNotExistException::class, $deleteDeleted);

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
        $deleteTopLink = function () use ($linkPath): void {
            Filesystem::deleteDir($linkPath);
        };

        SmokeTest::expectException(
            BadArgumentException::class,
            $deleteTopLink,
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

    $emptyDeleted = function () use ($base): void {
        Filesystem::emptyDir($base);
    };

    SmokeTest::expectException(DirectoryDoesNotExistException::class, $emptyDeleted);

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
        $notWritable = FileDeletionProblemEnum::DIRECTORY_NOT_WRITABLE;
        $notListable = FileDeletionProblemEnum::DIRECTORY_NOT_LISTABLE;

        # Both report the paths as given, not resolved, so the caller recognises them, even through a symlink to it.
        $describeProblem = function (FileDeletionProblem $problem): array {
            return [$problem->path, $problem->directory, $problem->reason];
        };

        symlink($base, "{$dir}/delete-locked-link");

        $calls = [
            ["deleteDir", $base, $base],
            ["emptyDir", $base, $base],
            ["emptyDir", "{$base}/", $base],
            ["emptyDir", "{$dir}/delete-locked-link", "{$dir}/delete-locked-link"],

            # A relative path is made absolute, from the current directory (which getcwd() gives as a real path).
            ["emptyDir", "delete-locked", realpath($dir) . "/delete-locked"],
        ];

        $previousDirectory = getcwd();
        chdir($dir);

        foreach ($calls as [$method, $path, $reportedBase])
        {
            $problems = [];

            try
            {
                Filesystem::$method($path);
            }
            catch (DirectoryNotDeletableException $e)
            {
                $problems = array_map($describeProblem, $e->getProblems());
                # Which problem is given as the example depends on the order the directory lists them in.
                assert(str_contains($e->getMessage(), "as 2 thing(s) in it can't be deleted (e.g. "));
                assert(str_contains($e->getMessage(), "Call getProblems() on this exception for the full list."));
            }

            $expectedProblems = [
                ["{$reportedBase}/sub/locked/inner.txt", "{$reportedBase}/sub/locked", $notWritable],
                ["{$reportedBase}/sub/unreadable", "{$reportedBase}/sub/unreadable", $notListable],
            ];

            # The order the directory lists things in isn't guaranteed, so compare them sorted.
            sort($problems);
            assert($problems === $expectedProblems, "{$method}({$path}) reported the wrong problems");
        }

        chdir($previousDirectory);
        unlink("{$dir}/delete-locked-link");

        $deleteLocked = function () use ($base): void {
            Filesystem::deleteDir($base);
        };

        # It still extends BadFilePermissionsException, so existing catch blocks still work.
        SmokeTest::expectException(BadFilePermissionsException::class, $deleteLocked);

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

        $deleteFromLockedParent = function () use ($dir): void {
            Filesystem::deleteDir("{$dir}/delete-parent/child");
        };

        SmokeTest::expectException(
            DirectoryNotDeletableException::class,
            $deleteFromLockedParent,
            "(e.g. {$dir}/delete-parent/child, as {$dir}/delete-parent can't be written to)"
        );

        assert(file_get_contents("{$dir}/delete-parent/child/file.txt") === "f");
        Filesystem::emptyDir("{$dir}/delete-parent/child");
        assert(scandir("{$dir}/delete-parent/child") === [".", ".."]);
        chmod("{$dir}/delete-parent", 0755);
    }
}, needsSymlinks: true);

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

    $deleteLinkUpwards = function () use ($base): void {
        Filesystem::deleteDir("{$base}/container/inner/up", followSymlinks: true);
    };

    SmokeTest::expectException(
        BadArgumentException::class,
        $deleteLinkUpwards,
        "which contains the link itself"
    );

    assert(is_link("{$base}/container/inner/up") && is_dir("{$base}/container/inner"));

    $deleteLinkWithoutFollowing = function () use ($base): void {
        Filesystem::deleteDir("{$base}/container/inner/up");
    };

    # Without followSymlinks, a symlink is still refused, and the message mentions the option.
    SmokeTest::expectException(
        BadArgumentException::class,
        $deleteLinkWithoutFollowing,
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

        $deleteThroughLockedLink = function () use ($base): void {
            Filesystem::deleteDir("{$base}/start2", followSymlinks: true);
        };

        SmokeTest::expectException(
            DirectoryNotDeletableException::class,
            $deleteThroughLockedLink,
            # What is reached by following a link is given by its real path (e.g. macOS's /private/var/... for /var/...).
            "(e.g. " . realpath($base) . "/locked-parent/target, as " . realpath($base) . "/locked-parent can't be " .
            "written to)"
        );

        assert(file_get_contents("{$base}/start2/keep.txt") === "keep");
        assert(file_get_contents("{$base}/locked-parent/target/t.txt") === "t");
        chmod("{$base}/locked-parent", 0755);
    }
}, needsSymlinks: true);

$smoke->run("fileFilter", function () use ($dir) {
    $base = "{$dir}/filter";
    mkdir($base);

    $keepNonX = function (string $line): bool {
        return !str_contains($line, "x");
    };

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
    SmokeTest::assertPermissions("{$base}/in-place.txt", 0640);

    if ($ownedByOtherUser)
    {
        SmokeTest::assertOwnedByOtherUser("{$base}/in-place.txt", "fileFilter()");
    }

    if (SmokeTest::canCreateSymlinks("fileFilter() through a symlink"))
    {
        # The same file under another path (here a symlink) counts as in place.
        symlink("{$base}/in-place.txt", "{$base}/in-place-symlink.txt");

        $dropDefLine = function (string $line): bool {
            return $line !== "def\n";
        };

        Filesystem::fileFilter(
            "{$base}/in-place.txt",
            $dropDefLine,
            "{$base}/in-place-symlink.txt"
        );

        assert(file_get_contents("{$base}/in-place.txt") === "abc\n" && is_link("{$base}/in-place-symlink.txt"));
    }

    # The callback throwing part way through leaves the file untouched.
    file_put_contents("{$base}/throws.txt", "one\ntwo\nthree\n");

    $throwOnThirdLine = function (string $line): bool {
        return ($line === "three\n") ? throw new \RuntimeException("stop") : true;
    };

    $filterWithThrowingCallback = function () use ($base, $throwOnThirdLine): void {
        Filesystem::fileFilter(
            "{$base}/throws.txt",
            $throwOnThirdLine
        );
    };

    SmokeTest::expectException(\RuntimeException::class, $filterWithThrowingCallback);

    assert(file_get_contents("{$base}/throws.txt") === "one\ntwo\nthree\n");

    # To a new output file: it is created with the default permissions for a new file (not the temporary file's
    # 0600), and the input is left alone.
    file_put_contents("{$base}/source.txt", "abc\nxyz\n");
    Filesystem::fileFilter("{$base}/source.txt", $keepNonX, "{$base}/new-output.txt");
    clearstatcache();
    assert(file_get_contents("{$base}/new-output.txt") === "abc\n");
    SmokeTest::assertPermissions("{$base}/new-output.txt", (0666 & ~umask()));
    assert(file_get_contents("{$base}/source.txt") === "abc\nxyz\n");

    # To an existing output file: it is replaced, keeping its permissions.
    file_put_contents("{$base}/existing-output.txt", "old contents that are longer\n");
    chmod("{$base}/existing-output.txt", 0604);
    Filesystem::fileFilter("{$base}/source.txt", $keepNonX, "{$base}/existing-output.txt");
    clearstatcache();
    assert(file_get_contents("{$base}/existing-output.txt") === "abc\n");
    SmokeTest::assertPermissions("{$base}/existing-output.txt", 0604);

    # Nothing is left in the temporary directory, whether it worked or not.
    assert(glob(sys_get_temp_dir() . "/corelibs-tmp-*") === $tempFilesBefore);

    $filterMissingFile = function () use ($base, $keepNonX): void {
        Filesystem::fileFilter("{$base}/nope.txt", $keepNonX);
    };

    SmokeTest::expectException(
        FileDoesNotExistException::class,
        $filterMissingFile
    );

    if (SmokeTest::canTestPermissions("fileFilter() checking permissions before filtering"))
    {
        # A read-only file can't be filtered in place, and an output file can't be created in a read-only directory.
        # Both are found before the callback is run at all.
        $callbackRan = false;
        $recordingCallback = function (string $line) use (&$callbackRan) { $callbackRan = true; return true; };
        file_put_contents("{$base}/read-only.txt", "abc\n");
        chmod("{$base}/read-only.txt", 0444);

        $filterReadOnlyFile = function () use ($base, $recordingCallback): void {
            Filesystem::fileFilter("{$base}/read-only.txt", $recordingCallback);
        };

        SmokeTest::expectException(
            BadFilePermissionsException::class,
            $filterReadOnlyFile
        );

        mkdir("{$base}/read-only-dir");
        chmod("{$base}/read-only-dir", 0555);

        $filterIntoReadOnlyDirectory = function () use ($base, $recordingCallback): void {
            Filesystem::fileFilter("{$base}/source.txt", $recordingCallback, "{$base}/read-only-dir/out.txt");
        };

        SmokeTest::expectException(
            BadFilePermissionsException::class,
            $filterIntoReadOnlyDirectory,
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
    SmokeTest::assertSamePath(dirname($tmpDir1), sys_get_temp_dir());
    assert(str_starts_with(basename($tmpDir1), "corelibs-dir-"));
    assert(is_dir($tmpDir1) && scandir($tmpDir1) === [".", ".."] && $tmpDir1 !== $tmpDir2);

    # The mode is applied, reduced by the umask, as with mkdir().
    SmokeTest::assertPermissions($tmpDir1, (0777 & ~umask()));
    SmokeTest::assertPermissions($tmpDir2, (0700 & ~umask()));
    rmdir($tmpDir1);
    rmdir($tmpDir2);

    # With $overrideUmask, the mode is applied exactly. Set a known umask, so the difference can be seen.
    $oldUmask = umask(0022);
    $exactTmpDir = Filesystem::tmpDir("0777", true);
    clearstatcache();
    SmokeTest::assertPermissions($exactTmpDir, 0777);
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

        $zipUnreadable = function () use ($dir): void {
            Filesystem::zipDir("{$dir}/zip-unreadable", "{$dir}/unreadable.zip");
        };

        SmokeTest::expectException(
            FilesystemException::class,
            $zipUnreadable,
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

    # Listing gives real paths, so expect those (e.g. macOS's /private/var/... for /var/...).
    $base = realpath($base);
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
}, needsSymlinks: true);

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

    $unzipMissingZip = function () use ($base): void {
        Filesystem::unzip("{$base}/nope.zip", "{$base}/out");
    };

    # A missing zip, and a destination that is a file, are reported clearly.
    SmokeTest::expectException(
        FileDoesNotExistException::class,
        $unzipMissingZip
    );

    file_put_contents("{$base}/a-file", "not a directory");

    $unzipOntoFile = function () use ($base): void {
        Filesystem::unzip("{$base}/keep.zip", "{$base}/a-file", false);
    };

    SmokeTest::expectException(
        BadArgumentException::class,
        $unzipOntoFile,
        "as it isn't a directory"
    );

    assert(file_get_contents("{$base}/a-file") === "not a directory" && is_file("{$base}/keep.zip"));

    # Nothing is left of the temporary directory it extracts into, wherever that was.
    assert(glob("{$base}/new/nested/destination/.corelibs-unzip-*") === []);
    assert(glob(sys_get_temp_dir() . "/corelibs-unzip-*") === []);

    # A destination on a different filesystem from the system's temporary directory can't have the contents moved
    # there instantly, so it extracts into a hidden directory inside the destination instead. /dev/shm is usually a
    # separate filesystem, so check there if so.
    $isSeparateFilesystem = (
           is_dir("/dev/shm")
        && is_writable("/dev/shm")
        && stat("/dev/shm")['dev'] !== stat(sys_get_temp_dir())['dev']
    );

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

    $unzipIntoBlocked = function () use ($base): void {
        Filesystem::unzip("{$base}/keep.zip", "{$base}/blocked", false);
    };

    SmokeTest::expectException(
        FilesystemException::class,
        $unzipIntoBlocked,
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

    $unzipClashIntoNew = function () use ($base): void {
        Filesystem::unzip("{$base}/clash.zip", "{$base}/new-for-clash/deeper", false);
    };

    SmokeTest::expectException(
        FilesystemException::class,
        $unzipClashIntoNew,
        "Unable to unzip {$base}/clash.zip"
    );

    assert(!file_exists("{$base}/new-for-clash"));

    mkdir("{$base}/existing-for-clash");

    $unzipClashIntoExisting = function () use ($base): void {
        Filesystem::unzip("{$base}/clash.zip", "{$base}/existing-for-clash", false);
    };

    SmokeTest::expectException(
        FilesystemException::class,
        $unzipClashIntoExisting
    );

    assert(scandir("{$base}/existing-for-clash") === [".", ".."]);
    assert(glob(sys_get_temp_dir() . "/corelibs-unzip-*") === []);

    if (SmokeTest::canTestPermissions("unzip() reporting why it can't create, extract or delete"))
    {
        # A destination that can't be created gives the reason, even though the test runner has its own error handler.
        mkdir("{$base}/read-only");
        chmod("{$base}/read-only", 0555);

        $unzipIntoReadOnly = function () use ($base): void {
            Filesystem::unzip("{$base}/keep.zip", "{$base}/read-only/out", false);
        };

        SmokeTest::expectException(
            FilesystemException::class,
            $unzipIntoReadOnly,
            "Permission denied"
        );

        # A zip that can't be deleted afterwards is reported, but its contents have already been extracted.
        mkdir("{$base}/locked-zip");
        copy("{$base}/keep.zip", "{$base}/locked-zip/locked.zip");
        chmod("{$base}/locked-zip", 0555);

        $unzipLockedZip = function () use ($base): void {
            Filesystem::unzip("{$base}/locked-zip/locked.zip", "{$base}/from-locked");
        };

        SmokeTest::expectException(
            FilesystemException::class,
            $unzipLockedZip,
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

    $compareRelativePath = function () use ($dir): void {
        Filesystem::isOnSameFilesystem("relative/path", $dir);
    };

    SmokeTest::expectException(
        BadArgumentException::class,
        $compareRelativePath,
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
    clearstatcache();

    # Some filesystems (e.g. WSL 1's) drop the setuid and setgid bits from a file nobody can run, so only check the
    # capitals where they were kept.
    if ((fileperms("{$base}/file.txt") & 06000) === 06000)
    {
        assert(Filesystem::fileperms("{$base}/file.txt") === "-rwSr-Sr--");
    }

    chmod("{$base}/dir", 01777);
    assert(Filesystem::fileperms("{$base}/dir") === "drwxrwxrwt");
    chmod("{$base}/dir", 01776);
    assert(Filesystem::fileperms("{$base}/dir") === "drwxrwxrwT");

    # A symlink is reported as the link itself, even when it is broken.
    symlink("{$base}/file.txt", "{$base}/link");
    # A symlink's own permissions are ignored, and Linux always shows them as rwxrwxrwx, but macOS shows the umask's.
    assert(preg_match('/^l[rwx-]{9}$/', Filesystem::fileperms("{$base}/link")) === 1);
    symlink("{$base}/missing", "{$base}/broken-link");
    assert(preg_match('/^l[rwx-]{9}$/', Filesystem::fileperms("{$base}/broken-link")) === 1);

    if (function_exists("posix_mkfifo"))
    {
        posix_mkfifo("{$base}/pipe", 0600);
        assert(Filesystem::fileperms("{$base}/pipe") === "prw-------");
    }

    $getPermsOfMissing = function () use ($base): void {
        Filesystem::fileperms("{$base}/missing");
    };

    SmokeTest::expectException(
        FileDoesNotExistException::class,
        $getPermsOfMissing,
        "There is nothing at {$base}/missing"
    );
}, needsSymlinks: true);

$smoke->run("zipDir / unzip with symlinks", function () use ($dir) {
    $base = "{$dir}/zip-links";
    mkdir("{$base}/outside/deeper", 0777, true);

    # The messages give real paths, so expect those (e.g. macOS's /private/var/... for /var/...).
    $base = realpath($base);
    file_put_contents("{$base}/outside/o.txt", "outside");
    file_put_contents("{$base}/outside/deeper/d.txt", "deeper");
    mkdir("{$base}/project/real", 0777, true);
    file_put_contents("{$base}/project/real/f.txt", "inside");
    symlink("real", "{$base}/project/dir-link");
    symlink("real/f.txt", "{$base}/project/file-link");
    symlink("{$base}/project/real/f.txt", "{$base}/project/absolute-inside");

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

    # A broken symlink stops the zip being made, and no zip file is left behind.
    symlink("{$base}/project/missing", "{$base}/project/broken");

    $zipBrokenLink = function () use ($base): void {
        Filesystem::zipDir("{$base}/project", "{$base}/broken.zip", false);
    };

    SmokeTest::expectException(
        FilesystemException::class,
        $zipBrokenLink,
        "{$base}/project/broken (pointing at {$base}/project/missing)"
    );

    assert(!file_exists("{$base}/broken.zip") && is_link("{$base}/project/broken"));
    unlink("{$base}/project/broken");

    # Symlinks inside the folder are stored as symlinks relative to themselves, rather than followed.
    Filesystem::zipDir("{$base}/project", "{$base}/inside.zip", false);

    $expected = [
        "project/absolute-inside" => "real/f.txt",
        "project/dir-link" => "real",
        "project/file-link" => "real/f.txt",
    ];

    assert($storedLinks("{$base}/inside.zip") === $expected);

    # unzip() recreates them as symlinks, which work where it is extracted.
    Filesystem::unzip("{$base}/inside.zip", "{$base}/out-inside", false);
    assert(readlink("{$base}/out-inside/project/dir-link") === "real");
    assert(file_get_contents("{$base}/out-inside/project/dir-link/f.txt") === "inside");
    assert(file_get_contents("{$base}/out-inside/project/absolute-inside") === "inside");

    # A symlink pointing outside is stored as it is, so an untrusted unzip refuses it, leaving nothing behind.
    symlink("{$base}/outside", "{$base}/project/outside-dir");
    symlink("../outside/o.txt", "{$base}/project/outside-file");
    Filesystem::zipDir("{$base}/project", "{$base}/outside.zip", false);
    $links = $storedLinks("{$base}/outside.zip");
    assert($links["project/outside-dir"] === "{$base}/outside");
    assert($links["project/outside-file"] === "../outside/o.txt");

    $unzipLinkOutside = function () use ($base): void {
        Filesystem::unzip("{$base}/outside.zip", "{$base}/out-untrusted", false);
    };

    SmokeTest::expectException(
        FilesystemException::class,
        $unzipLinkOutside,
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

    $unzipClimbingOut = function () use ($base): void {
        Filesystem::unzip("{$base}/escaping.zip", "{$base}/out-escaping", false);
    };

    SmokeTest::expectException(
        FilesystemException::class,
        $unzipClimbingOut,
        "pointing to ../../../etc/passwd, which is outside"
    );

    assert(scandir("{$base}/out-escaping") === [".", ".."]);

    # As is one that only escapes through a symlink already in the destination, which leads outside it.
    mkdir("{$base}/out-through-link");
    symlink("{$base}/outside", "{$base}/out-through-link/existing-link");
    $throughZip = new ZipArchive();
    $throughZip->open("{$base}/through.zip", ZipArchive::CREATE);
    $throughZip->addFromString("escape", "existing-link/o.txt");
    $throughZip->setExternalAttributesName("escape", ZipArchive::OPSYS_UNIX, 0120777 << 16);
    $throughZip->close();

    $unzipThroughLink = function () use ($base): void {
        Filesystem::unzip("{$base}/through.zip", "{$base}/out-through-link", false);
    };

    SmokeTest::expectException(
        FilesystemException::class,
        $unzipThroughLink,
        "pointing to existing-link/o.txt, which is outside"
    );

    assert(!file_exists("{$base}/out-through-link/escape") && !is_link("{$base}/out-through-link/escape"));

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

    $zipLinkLoop = function () use ($base): void {
        Filesystem::zipDir("{$base}/project", "{$base}/loop.zip", false, followSymlinks: true);
    };

    SmokeTest::expectException(
        FilesystemException::class,
        $zipLinkLoop,
        "would go round in circles"
    );

    assert(!file_exists("{$base}/loop.zip"));

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
}, needsSymlinks: true);

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

        $addPrefix = function (string $entry) use ($prefix): string {
            return $prefix . $entry;
        };

        $expectedNames = array_map($addPrefix, $expected);
        $expectedNames = $includeFolder ? array_merge(["project/"], $expectedNames) : $expectedNames;
        sort($expectedNames);
        assert($names === $expectedNames, "{$name} the folder, the zip had: " . implode(", ", $names));
    }
});

$smoke->run("createReplacementFile locations, and replaceWithFile renaming", function () use ($dir) {
    $base = "{$dir}/replacement-locations";
    mkdir($base);

    # NEVER puts a hidden, private file next to the original.
    $nextTo = Filesystem::createReplacementFile("{$base}/a.txt", UseSystemTempDirEnum::NEVER);
    clearstatcache();
    SmokeTest::assertSamePath(dirname($nextTo), realpath($base));
    assert(str_starts_with(basename($nextTo), SmokeTest::tempnamPrefix(".corelibs-tmp-")));
    SmokeTest::assertPermissions($nextTo, 0600);

    # WHEN_SAME_FS uses the system's temporary directory when it is on the same filesystem as the original.
    $sameFs = Filesystem::createReplacementFile("{$base}/a.txt", UseSystemTempDirEnum::WHEN_SAME_FS);
    SmokeTest::assertSamePath(dirname($sameFs), sys_get_temp_dir());
    unlink($sameFs);

    if (is_dir("/dev/shm") && stat("/dev/shm")['dev'] !== stat(sys_get_temp_dir())['dev'])
    {
        $otherFs = Filesystem::createReplacementFile("/dev/shm/a.txt", UseSystemTempDirEnum::WHEN_SAME_FS);
        assert(dirname($otherFs) === "/dev/shm");
        unlink($otherFs);
    }

    $replaceInMissingDirectory = function () use ($base): void {
        Filesystem::createReplacementFile("{$base}/missing/a.txt");
    };

    SmokeTest::expectException(
        DirectoryDoesNotExistException::class,
        $replaceInMissingDirectory
    );

    # AUTO uses the system's temporary directory when it is on the same filesystem, and the file could be renamed
    # from there (the test directory is inside it here)...
    file_put_contents("{$base}/auto.txt", "x");
    $auto = Filesystem::createReplacementFile("{$base}/auto.txt", UseSystemTempDirEnum::AUTO);
    SmokeTest::assertSamePath(dirname($auto), sys_get_temp_dir());
    unlink($auto);

    # ...and next to the original when the system's temporary directory is on another (disk) filesystem, as renaming
    # from next to it is then the fastest way. /dev/shm is a separate filesystem in the test containers.
    $otherFilesystem = "/dev/shm/corelibs-replacement-" . getmypid();

    $canUseOtherFilesystem = (
           is_dir("/dev/shm")
        && stat("/dev/shm")['dev'] !== stat(sys_get_temp_dir())['dev']
        && !Filesystem::isTmpfs(sys_get_temp_dir())
    );

    if ($canUseOtherFilesystem)
    {
        mkdir($otherFilesystem);

        try
        {
            file_put_contents("{$otherFilesystem}/auto.txt", "x");
            $auto = Filesystem::createReplacementFile("{$otherFilesystem}/auto.txt", UseSystemTempDirEnum::AUTO);
            assert(dirname($auto) === $otherFilesystem && str_starts_with(basename($auto), ".corelibs-tmp-"));
            unlink($auto);

            # And one in the system's temporary directory (ALWAYS) is copied back there, so the original keeps its
            # inode.
            $inodeBefore = fileinode("{$otherFilesystem}/auto.txt");
            $tmp = Filesystem::createReplacementFile("{$otherFilesystem}/auto.txt", UseSystemTempDirEnum::ALWAYS);
            file_put_contents($tmp, "copied");
            Filesystem::replaceWithFile("{$otherFilesystem}/auto.txt", $tmp);
            clearstatcache();
            assert(fileinode("{$otherFilesystem}/auto.txt") === $inodeBefore);
            assert(file_get_contents("{$otherFilesystem}/auto.txt") === "copied" && !file_exists($tmp));
        }
        finally
        {
            Filesystem::deleteDir($otherFilesystem);
        }
    }

    # ...and otherwise (e.g. it has another hard link, so would be copied back) in the system's temporary directory.
    link("{$base}/auto.txt", "{$base}/auto-hard-link.txt");
    $auto = Filesystem::createReplacementFile("{$base}/auto.txt", UseSystemTempDirEnum::AUTO);
    SmokeTest::assertSamePath(dirname($auto), sys_get_temp_dir());
    unlink($auto);

    # A tmpfs (held in memory) only counts as having room if that much memory is available too. /dev/shm is a tmpfs
    # in the test containers, and the system's temporary directory isn't.
    $hasFreeMemory = new ReflectionMethod(Filesystem::class, "hasFreeMemory");

    if (is_dir("/dev/shm") && str_contains((string)@file_get_contents("/proc/mounts"), " /dev/shm tmpfs "))
    {
        assert(Filesystem::isTmpfs("/dev/shm") === true);
        assert(Filesystem::isTmpfs($base) === false);
        file_put_contents("{$base}/on-disk.txt", "x");
        assert(Filesystem::isTmpfs("{$base}/on-disk.txt") === false);
    }

    $checkTmpfsOfMissing = function () use ($base): void {
        Filesystem::isTmpfs("{$base}/missing");
    };

    SmokeTest::expectException(FileDoesNotExistException::class, $checkTmpfsOfMissing);

    assert($hasFreeMemory->invoke(null, 0) === true);

    # Where the free memory can't be read (anywhere but Linux), it counts as enough, so this can only be checked on
    # Linux.
    if (PHP_OS_FAMILY === "Linux")
    {
        assert($hasFreeMemory->invoke(null, PHP_INT_MAX) === false);
    }


    # /proc/mounts writes a space in a mount point as \040, which is turned back into a space.
    $decodeOctalEscape = new ReflectionMethod(Filesystem::class, "decodeOctalEscape");
    assert($decodeOctalEscape->invoke(null, ["\\040", "040"]) === " ");

    # A tmpfs temporary directory with room is used even when the file could be renamed, as copying back from memory
    # is about as fast. /dev/shm is used as the system's temporary directory here, through TMPDIR.
    $chooseAuto = function (string $tmpDirectory, string $filepath): string {
        $code = 'require ' . var_export(__DIR__ . '/../../vendor/autoload.php', true) . '; echo ' .
            'Programster\\CoreLibs\\Filesystem::createReplacementFile(' . var_export($filepath, true) .
            ', Programster\\CoreLibs\\Enums\\UseSystemTempDirEnum::AUTO);';

        $command = "TMPDIR=" . escapeshellarg($tmpDirectory) . " " . escapeshellarg(PHP_BINARY) . " -r " .
            escapeshellarg($code) . " 2>&1";

        return trim((string)shell_exec($command));
    };

    if (is_dir("/dev/shm") && is_writable("/dev/shm") && Filesystem::isTmpfs("/dev/shm"))
    {
        file_put_contents("{$base}/renamable.txt", "x");
        $chosen = $chooseAuto("/dev/shm", "{$base}/renamable.txt");
        assert(dirname($chosen) === "/dev/shm", "AUTO with a tmpfs temporary directory chose: {$chosen}");
        unlink($chosen);
    }

    # Unless that hasn't enough room, when it goes next to the original after all. run-all-php-versions.sh gives the
    # container a 1 MB filesystem, which is used as the system's temporary directory here, through TMPDIR.
    $smallFilesystem = getenv("CORELIBS_TEST_SMALL_FILESYSTEM");

    if ($smallFilesystem === false || !is_dir($smallFilesystem) || !is_writable($smallFilesystem))
    {
        SmokeTest::skip("createReplacementFile() AUTO avoiding a full /tmp", "needs the small filesystem");
    }
    else
    {
        file_put_contents("{$base}/big.txt", str_repeat("x", 2 * 1024 * 1024));
        link("{$base}/big.txt", "{$base}/big-hard-link.txt");

        $chosen = $chooseAuto($smallFilesystem, "{$base}/big.txt");
        SmokeTest::assertSamePath(dirname($chosen), realpath($base), "AUTO with a full temporary directory chose: {$chosen}");
        unlink($chosen);
    }

    # A temporary file next to the original is renamed over it, so it is a new file (a different inode), with the
    # original's permissions.
    file_put_contents("{$base}/a.txt", "old");
    chmod("{$base}/a.txt", 0640);
    $inodeBefore = fileinode("{$base}/a.txt");
    file_put_contents($nextTo, "new");
    Filesystem::replaceWithFile("{$base}/a.txt", $nextTo);
    clearstatcache();
    assert(file_get_contents("{$base}/a.txt") === "new");
    SmokeTest::assertPermissions("{$base}/a.txt", 0640);
    assert(fileinode("{$base}/a.txt") !== $inodeBefore && !file_exists($nextTo));

    # One with another hard link is copied back instead, so both names still share the new contents.
    link("{$base}/a.txt", "{$base}/a-hard-link.txt");
    $inodeBefore = fileinode("{$base}/a.txt");
    $tmp = Filesystem::createReplacementFile("{$base}/a.txt", UseSystemTempDirEnum::NEVER);
    file_put_contents($tmp, "newer");
    Filesystem::replaceWithFile("{$base}/a.txt", $tmp);
    clearstatcache();
    assert(fileinode("{$base}/a.txt") === $inodeBefore && file_get_contents("{$base}/a-hard-link.txt") === "newer");

    if (SmokeTest::canCreateSymlinks("replaceWithFile() through a symlink"))
    {
        # As is a symlink, which stays a symlink to the file it pointed to.
        file_put_contents("{$base}/target.txt", "old");
        symlink("target.txt", "{$base}/link.txt");
        $tmp = Filesystem::createReplacementFile("{$base}/link.txt", UseSystemTempDirEnum::NEVER);
        file_put_contents($tmp, "via link");
        Filesystem::replaceWithFile("{$base}/link.txt", $tmp);
        assert(is_link("{$base}/link.txt") && file_get_contents("{$base}/target.txt") === "via link");
    }

    # One in the system's temporary directory (ALWAYS) is renamed from there too when it is on the same filesystem (as
    # the test directory is here), unless SELinux is enabled.
    file_put_contents("{$base}/from-system-temp.txt", "old");
    $inodeBefore = fileinode("{$base}/from-system-temp.txt");
    $tmp = Filesystem::createReplacementFile("{$base}/from-system-temp.txt", UseSystemTempDirEnum::ALWAYS);
    file_put_contents($tmp, "renamed");
    Filesystem::replaceWithFile("{$base}/from-system-temp.txt", $tmp);
    clearstatcache();
    $wasRenamed = fileinode("{$base}/from-system-temp.txt") !== $inodeBefore;
    assert($wasRenamed === !file_exists("/sys/fs/selinux/enforce"));
    assert(file_get_contents("{$base}/from-system-temp.txt") === "renamed" && !file_exists($tmp));

    # With each PreserveOwnershipEnum, a file that already belongs to whoever is running this is renamed.
    foreach (PreserveOwnershipEnum::cases() as $preserveOwnership)
    {
        $file = "{$base}/{$preserveOwnership->name}.txt";
        file_put_contents($file, "old");
        $inodeBefore = fileinode($file);
        $tmp = Filesystem::createReplacementFile($file, UseSystemTempDirEnum::NEVER);
        file_put_contents($tmp, "new");
        Filesystem::replaceWithFile($file, $tmp, $preserveOwnership);
        clearstatcache();
        assert(fileinode($file) !== $inodeBefore && file_get_contents($file) === "new", $preserveOwnership->name);
    }

    # Renaming keeps the owner (as root), and a file owned by someone else is copied back when not running as root.
    file_put_contents("{$base}/owned.txt", "old");

    if (SmokeTest::giveToOtherUser("{$base}/owned.txt", "replaceWithFile() renaming"))
    {
        $tmp = Filesystem::createReplacementFile("{$base}/owned.txt", UseSystemTempDirEnum::NEVER);
        file_put_contents($tmp, "new");
        Filesystem::replaceWithFile("{$base}/owned.txt", $tmp);
        SmokeTest::assertOwnedByOtherUser("{$base}/owned.txt", "replaceWithFile() renaming");
        assert(file_get_contents("{$base}/owned.txt") === "new");
    }
});

$smoke->run("path helpers", function () use ($dir) {
    # normalisePath() keeps absolute paths absolute, and relative ones relative.
    $normalised = [
        "/a/./b/../c/" => "/a/c",
        "/../a" => "/a",
        # On Windows, a path starting with "//" is a UNC path (//server/share), so the first two are kept.
        "//a//b" => (PHP_OS_FAMILY === "Windows") ? "//a/b" : "/a/b",
        "/" => "/",
        "a/./b/../c" => "a/c",
        "a/../../b" => "../b",
        "../../a" => "../../a",
        "a/.." => ".",
        "" => ".",
    ];

    foreach ($normalised as $path => $expected)
    {
        assert(Filesystem::normalisePath($path) === $expected, "normalisePath({$path}) gave " . Filesystem::normalisePath($path));
    }

    # relativePathBetween() works between two absolute paths, or two relative ones.
    assert(Filesystem::relativePathBetween("/srv/a/b", "/srv/c/d.txt") === "../../c/d.txt");
    assert(Filesystem::relativePathBetween("/srv/a", "/srv/a") === ".");
    assert(Filesystem::relativePathBetween("/srv", "/srv/a/b.txt") === "a/b.txt");
    assert(Filesystem::relativePathBetween("project/a", "project/b.txt") === "../b.txt");
    assert(Filesystem::relativePathBetween(".", "real/f.txt") === "real/f.txt");

    $relativeFromRelativeTarget = function (): void {
        Filesystem::relativePathBetween("/srv/a", "b.txt");
    };

    SmokeTest::expectException(
        BadArgumentException::class,
        $relativeFromRelativeTarget,
        "must both be absolute, or both be relative"
    );

    $relativeFromRelativeStart = function (): void {
        Filesystem::relativePathBetween("../a", "b.txt");
    };

    SmokeTest::expectException(
        BadArgumentException::class,
        $relativeFromRelativeStart,
        "goes above where it starts"
    );

    # isWithinDirectory() doesn't count a directory whose name only starts the same.
    assert(Filesystem::isWithinDirectory("/a/b/c", "/a/b") && Filesystem::isWithinDirectory("/a/b", "/a/b/"));
    assert(!Filesystem::isWithinDirectory("/a/bc", "/a/b") && Filesystem::isWithinDirectory("/anything", "/"));

    # resolveLinkTarget() works out where a symlink's target leads, even for a broken one.
    mkdir("{$dir}/path-helpers/sub", 0777, true);
    $real = realpath("{$dir}/path-helpers");
    assert(Filesystem::resolveLinkTarget("{$real}/sub", "../x.txt") === Filesystem::normalisePath("{$real}/x.txt"));
    assert(Filesystem::resolveLinkTarget("{$real}/sub", "/elsewhere/./y.txt") === "/elsewhere/y.txt");

    if (SmokeTest::canCreateSymlinks("resolvePath() and isWithinDirectory() with symlinks"))
    {
        # resolvePath() follows the symlinks in the part of a path that exists, and tidies the rest.
        mkdir("{$real}/data");
        symlink("{$real}/data", "{$real}/link");
        assert(Filesystem::resolvePath("{$real}/link/new/../file.txt") === "{$real}/data/file.txt");
        assert(Filesystem::resolvePath("{$real}/sub") === "{$real}/sub");

        # isWithinDirectory() follows symlinks by default, so a path through a link is where the link leads.
        assert(Filesystem::isWithinDirectory("{$real}/link/file.txt", "{$real}/data"));
        assert(!Filesystem::isWithinDirectory("{$real}/link/file.txt", "{$real}/sub"));
        assert(Filesystem::isWithinDirectory("{$real}/data/file.txt", "{$real}/link"));

        # Without resolving, only the paths as written are compared.
        assert(!Filesystem::isWithinDirectory("{$real}/link/file.txt", "{$real}/data", false));

        # removeSymlink() removes a link (to a directory or a file) but not what it points to, and refuses anything else.
        symlink("{$real}/sub", "{$real}/dir-link");
        Filesystem::removeSymlink("{$real}/dir-link/");
        assert(!is_link("{$real}/dir-link") && is_dir("{$real}/sub"));
        file_put_contents("{$real}/real.txt", "x");

        $removeRealFile = function () use ($real): void {
            Filesystem::removeSymlink("{$real}/real.txt");
        };

        SmokeTest::expectException(
            BadArgumentException::class,
            $removeRealFile,
            "isn't a symlink"
        );

        assert(is_file("{$real}/real.txt"));
    }

    # canEnterDirectory() and canListDirectory() are true for a normal directory.
    assert(Filesystem::canEnterDirectory($real) && Filesystem::canListDirectory($real));
});

$smoke->run("getDirSize", function () use ($dir) {
    $base = "{$dir}/dir-size";
    mkdir("{$base}/sub", 0777, true);
    file_put_contents("{$base}/a.txt", str_repeat("a", 1000));
    file_put_contents("{$base}/sub/b.txt", str_repeat("b", 300));
    link("{$base}/a.txt", "{$base}/sub/a-hard-link.txt");

    if (SmokeTest::canCreateSymlinks("getDirSize() with a symlink"))
    {
        symlink("{$base}/a.txt", "{$base}/sub/a-symlink.txt");
    }

    # Adding up the sizes in PHP (used where du isn't available) gives the same as du does on Linux.
    $addUpSize = new ReflectionMethod(Filesystem::class, "addUpSize");
    $phpSize = $addUpSize->invoke(null, $base);
    assert(Filesystem::getDirSize($base) === (float)$phpSize, "du and PHP disagree on {$base}'s size");

    # A file on its own is just its size, and a missing path is an error.
    assert(Filesystem::getDirSize("{$base}/sub/b.txt") === 300.0);
    assert(Filesystem::getDirSize("{$base}/a.txt", DataSizeUnitEnum::KIB) === 1000 / 1024);

    $getSizeOfMissing = function () use ($base): void {
        Filesystem::getDirSize("{$base}/missing");
    };

    SmokeTest::expectException(FileDoesNotExistException::class, $getSizeOfMissing);
});

$smoke->run("unzip symlinks on Windows", function () use ($dir) {
    # On Windows, unzip() makes symlinks last, as absolute ones (or copies), since PHP there can only create a symlink
    # whose target exists. That route only runs on Windows, so call its steps directly, with a staged extraction: the
    # temporary directory holds the zip's contents, with each symlink as a file holding its target.
    $plan = new ReflectionMethod(Filesystem::class, "planWindowsSymlinks");
    $merge = new ReflectionMethod(Filesystem::class, "mergeDirectoryInto");
    $create = new ReflectionMethod(Filesystem::class, "createWindowsSymlinks");

    $stage = function (string $base): array {
        mkdir("{$base}/tmp/project/real", 0777, true);
        mkdir("{$base}/out");
        file_put_contents("{$base}/tmp/project/real/f.txt", "inside");

        $targets = [
            "project/dir-link" => "real",
            "project/file-link" => "real/f.txt",
            "project/to-file-link" => "file-link",
        ];

        foreach ($targets as $entry => $target)
        {
            file_put_contents("{$base}/tmp/{$entry}", $target);
        }

        return $targets;
    };

    # Links become absolute ones inside the destination, made once everything else is there, including one that
    # points at another link (which has to be made first).
    $base = "{$dir}/windows-links";
    $targets = $stage($base);
    $out = realpath("{$base}/out");
    $result = $plan->invoke(null, "{$base}/tmp", $out, $targets, false);
    assert($result['problems'] === [] && !file_exists("{$base}/tmp/project/dir-link"));
    $merge->invoke(null, "{$base}/tmp", $out);
    $create->invoke(null, $result['links']);
    # Windows reports the target with "\\", so compare them tidied up.
    $dirLinkTarget = Filesystem::normalisePath(readlink("{$out}/project/dir-link"));
    assert($dirLinkTarget === Filesystem::normalisePath("{$out}/project/real"));
    assert(file_get_contents("{$out}/project/dir-link/f.txt") === "inside");
    assert(file_get_contents("{$out}/project/to-file-link") === "inside");

    # With copying, links inside the zip become copies of what they point at.
    $base = "{$dir}/windows-copies";
    $targets = $stage($base);
    $out = realpath("{$base}/out");
    $result = $plan->invoke(null, "{$base}/tmp", $out, $targets, true);
    $merge->invoke(null, "{$base}/tmp", $out);
    $create->invoke(null, $result['links']);
    assert(!is_link("{$out}/project/dir-link") && file_get_contents("{$out}/project/dir-link/f.txt") === "inside");
    assert(!is_link("{$out}/project/file-link") && file_get_contents("{$out}/project/file-link") === "inside");
    assert(!is_link("{$out}/project/to-file-link") && file_get_contents("{$out}/project/to-file-link") === "inside");

    # A broken link is refused, and so (when copying) is one to a folder above it, leaving the placeholders in place.
    $base = "{$dir}/windows-refused";
    $targets = $stage($base) + ["project/broken" => "missing", "project/real/up" => ".."];
    file_put_contents("{$base}/tmp/project/broken", "missing");
    file_put_contents("{$base}/tmp/project/real/up", "..");
    $result = $plan->invoke(null, "{$base}/tmp", realpath("{$base}/out"), $targets, true);
    $problems = implode("\n", $result['problems']);
    assert(str_contains($problems, "project/broken pointing to") && str_contains($problems, "which won't exist"));
    assert(str_contains($problems, "project/real/up pointing to a folder above it"));
    assert(count($result['problems']) === 2 && file_exists("{$base}/tmp/project/dir-link"));
}, needsSymlinks: true, anySymlinks: false);

$smoke->run("replaceFileContents", function () use ($dir) {
    $base = "{$dir}/replace-contents";
    mkdir($base);
    file_put_contents("{$base}/a.txt", "old contents that are longer\n");
    chmod("{$base}/a.txt", 0640);

    # A string replaces the contents, keeping the file's permissions.
    Filesystem::replaceFileContents("{$base}/a.txt", "new\n");
    assert(file_get_contents("{$base}/a.txt") === "new\n");
    SmokeTest::assertPermissions("{$base}/a.txt", 0640);

    # An array is joined as it is, like file_put_contents(), so lines from file() keep their line endings.
    Filesystem::replaceFileContents("{$base}/a.txt", ["one\n", "two\n"]);
    assert(file_get_contents("{$base}/a.txt") === "one\ntwo\n");
    Filesystem::replaceFileContents("{$base}/a.txt", file("{$base}/a.txt"));
    assert(file_get_contents("{$base}/a.txt") === "one\ntwo\n");

    # Empty contents empty the file.
    Filesystem::replaceFileContents("{$base}/a.txt", "");
    assert(file_get_contents("{$base}/a.txt") === "");

    $replaceMistypedFile = function () use ($base): void {
        Filesystem::replaceFileContents("{$base}/mistyped.txt", "x");
    };

    # Unlike file_put_contents(), a missing file isn't created, and an array must only hold strings.
    SmokeTest::expectException(
        FileDoesNotExistException::class,
        $replaceMistypedFile
    );

    assert(!file_exists("{$base}/mistyped.txt"));

    $replaceWithBadLines = function () use ($base): void {
        Filesystem::replaceFileContents("{$base}/a.txt", ["one\n", 2]);
    };

    SmokeTest::expectException(
        BadArgumentException::class,
        $replaceWithBadLines,
        "must be a string"
    );

    # Nothing is left behind, next to the file or in the system's temporary directory.
    assert(array_values(array_diff(scandir($base), [".", ".."])) === ["a.txt"]);
});

$smoke->run("isTmpfs on each system", function () use ($dir) {
    # It works on every supported system (always false on Windows, which has no tmpfs), rather than throwing.
    $isTmpfs = Filesystem::isTmpfs($dir);
    assert(PHP_OS_FAMILY !== "Windows" || $isTmpfs === false);

    # The macOS and BSD route reads the mount command's output, so check that with sample output here.
    $parse = new ReflectionMethod(Filesystem::class, "parseMountCommandOutput");
    $typeAt = new ReflectionMethod(Filesystem::class, "filesystemTypeAt");

    $output = "/dev/disk3s1s1 on / (apfs, sealed, local, read-only, journaled)\n" .
        "devfs on /dev (devfs, local, nobrowse)\n" .
        "/dev/disk3s5 on /System/Volumes/Data (apfs, local, journaled, nobrowse)\n" .
        "tmpfs on /tmp (tmpfs, local)\n" .
        "/dev/disk4s1 on /Volumes/My Disk (hfs, local, nodev, nosuid, journaled)\n";

    $mounts = $parse->invoke(null, $output);
    assert($mounts[3] === ["/tmp", "tmpfs"] && $mounts[4] === ["/Volumes/My Disk", "hfs"]);
    assert($typeAt->invoke(null, "/tmp/work/file.txt", $mounts) === "tmpfs");
    assert($typeAt->invoke(null, "/Volumes/My Disk/file.txt", $mounts) === "hfs");
    assert($typeAt->invoke(null, "/Users/someone", $mounts) === "apfs");
    assert($typeAt->invoke(null, "/tmpfile", $mounts) === "apfs");
});

$smoke->run("getDirectories / getDirContents with an unreadable subfolder", function () use ($dir) {
    if (SmokeTest::canTestPermissions("getDirectories / getDirContents with an unreadable subfolder"))
    {
        $base = "{$dir}/list-unreadable";
        mkdir("{$base}/readable", 0777, true);
        mkdir("{$base}/locked/inner", 0777, true);
        touch("{$base}/readable/a.txt");
        touch("{$base}/locked/hidden.txt");
        chmod("{$base}/locked", 0000);

        try
        {
            # By default, an incomplete list is refused, and the message says which folder and how to allow it.
            $listContents = function () use ($base): void {
                Filesystem::getDirContents($base);
            };

            $listDirectories = function () use ($base): void {
                Filesystem::getDirectories($base, true);
            };

            SmokeTest::expectException(FilesystemException::class, $listContents, "Pass \$skipUnreadable as true");
            SmokeTest::expectException(FilesystemException::class, $listDirectories, "{$base}/locked");

            # Asked to, it leaves out what can't be read. The locked folder itself is still listed.
            $files = Filesystem::getDirContents($base, includePath: false, skipUnreadable: true);
            assert($files === ["a.txt"], "getDirContents() gave: " . implode(", ", $files));
            $directories = Filesystem::getDirectories($base, true, false, skipUnreadable: true);
            sort($directories);
            assert($directories === ["locked", "readable"], "getDirectories() gave: " . implode(", ", $directories));
            $aliasFiles = Filesystem::listDirContents($base, includePath: false, skipUnreadable: true);
            assert($aliasFiles === ["a.txt"]);

            # The folder asked for must always be readable, even when skipping.
            $listLocked = function () use ($base): void {
                Filesystem::getDirContents("{$base}/locked", skipUnreadable: true);
            };

            SmokeTest::expectException(FilesystemException::class, $listLocked);
        }
        finally
        {
            chmod("{$base}/locked", 0755);
        }
    }
});

$smoke->run("getDirContents / getDirectories paths keep symlinks", function () use ($dir) {
    $base = "{$dir}/list-paths";
    mkdir("{$base}/real/sub", 0777, true);
    mkdir("{$base}/elsewhere/target", 0777, true);
    touch("{$base}/real/sub/f.txt");
    touch("{$base}/elsewhere/beside-target.txt");
    symlink("{$base}/real", "{$base}/link");
    symlink("{$base}/elsewhere/target", "{$base}/link-to-target");

    # The paths come back with "/" separators, which on Windows differ from $dir's "\", so compare them tidied.
    $tidy = function (array $paths): array {
        return array_map([Filesystem::class, "normalisePath"], $paths);
    };

    # A symlink in the path given stays in the paths given back, rather than being resolved.
    assert(Filesystem::getDirContents("{$base}/link") === $tidy(["{$base}/link/sub/f.txt"]));
    assert(Filesystem::getDirectories("{$base}/link/") === $tidy(["{$base}/link/sub"]));
    assert(Filesystem::getDirContents("{$base}/./link//sub") === $tidy(["{$base}/link/sub/f.txt"]));

    # ".." after a real directory goes up from it, as written.
    assert(Filesystem::getDirContents("{$base}/link/sub/..") === $tidy(["{$base}/link/sub/f.txt"]));

    # ".." straight after a symlink goes up from where it leads, as the system does, so the paths still point at the
    # right files.
    $upFromLink = Filesystem::getDirContents("{$base}/link-to-target/..", false);
    assert(count($upFromLink) === 1 && str_ends_with($upFromLink[0], "/elsewhere/beside-target.txt"));
    assert(file_exists($upFromLink[0]));

    # With $resolveSymlinks, the real paths are given instead, including for what is found through a followed symlink.
    $realBase = realpath($base);
    assert(Filesystem::getDirContents("{$base}/link", resolveSymlinks: true) === $tidy(["{$realBase}/real/sub/f.txt"]));
    $resolvedDirectories = Filesystem::listDirectories("{$base}/link", resolveSymlinks: true);
    $message = "listDirectories() resolving symlinks gave: " . implode(", ", $resolvedDirectories);
    assert($resolvedDirectories === $tidy(["{$realBase}/real/sub"]), $message);
    symlink("{$base}/elsewhere", "{$base}/real/sub/to-elsewhere");
    $followed = Filesystem::getDirContents("{$base}/link", followSymlinks: true, resolveSymlinks: true);
    sort($followed);
    $expected = $tidy(["{$realBase}/elsewhere/beside-target.txt", "{$realBase}/real/sub/f.txt"]);
    assert($followed === $expected, "getDirContents() resolving symlinks gave: " . implode(", ", $followed));
    $kept = Filesystem::getDirContents("{$base}/link", followSymlinks: true);
    sort($kept);
    assert($kept === $tidy(["{$base}/link/sub/f.txt", "{$base}/link/sub/to-elsewhere/beside-target.txt"]));
    unlink("{$base}/real/sub/to-elsewhere");

    # A relative path is made absolute, from the current directory.
    $previousDirectory = getcwd();
    chdir($base);

    try
    {
        assert(Filesystem::getDirContents("link") === $tidy([getcwd() . "/link/sub/f.txt"]));
    }
    finally
    {
        chdir($previousDirectory);
    }
}, needsSymlinks: true, anySymlinks: false);

$smoke->run("zipDir() keeping relative symlinks as they are, as on each system", function () use ($dir) {
    # On Windows, readlink() gives the resolved absolute path, so zipDir() asks PowerShell for the stored target
    # instead. That only runs on Windows, but tidying what it gives can be checked here.
    $tidy = new ReflectionMethod(Filesystem::class, "tidyWindowsLinkTarget");
    assert($tidy->invoke(null, "..\\shared\\data.txt") === "../shared/data.txt");
    assert($tidy->invoke(null, "\\\\?\\C:\\data\\x.txt") === "C:/data/x.txt");
    assert($tidy->invoke(null, "\\\\?\\UNC\\server\\share\\x") === "//server/share/x");

    $base = realpath($dir) . "/zip-relative-links";
    mkdir("{$base}/project", 0777, true);
    file_put_contents("{$base}/outside.txt", "outside");
    file_put_contents("{$base}/project/inside.txt", "inside");

    $canTest = SmokeTest::makeRelativeSymlink("../outside.txt", "{$base}/project/to-outside")
        && SmokeTest::makeRelativeSymlink("inside.txt", "{$base}/project/to-inside");

    if (!$canTest)
    {
        SmokeTest::skip("zipDir() keeping relative symlinks as they are, as on each system", "needs relative symlinks");
    }
    else
    {
        # Both are stored with relative targets: the one outside as it is, and the one inside worked out again.
        Filesystem::zipDir("{$base}/project", "{$base}/project.zip", deleteOnComplete: false, includeFolder: false);
        $zip = new ZipArchive();
        $zip->open("{$base}/project.zip");
        assert($zip->getFromName("to-outside") === "../outside.txt", "to-outside: " . $zip->getFromName("to-outside"));
        assert($zip->getFromName("to-inside") === "inside.txt", "to-inside: " . $zip->getFromName("to-inside"));
        $zip->close();

        # A broken one is reported as broken, rather than as one that can't be read, on Windows too.
        file_put_contents("{$base}/soon-gone.txt", "x");
        SmokeTest::makeRelativeSymlink("../soon-gone.txt", "{$base}/project/broken");
        unlink("{$base}/soon-gone.txt");

        $zipWithBrokenLink = function () use ($base): void {
            Filesystem::zipDir("{$base}/project", "{$base}/broken.zip", deleteOnComplete: false);
        };

        SmokeTest::expectException(FilesystemException::class, $zipWithBrokenLink, "it has broken symlinks");
    }
}, needsSymlinks: true, anySymlinks: false);

$smoke->run("deleteDir() / emptyDir() in a sticky folder", function () {
    # In a sticky folder (e.g. /tmp), only a file's owner (or the folder's, or root) can delete it. That needs a file
    # someone else owns, which only root can make, and a normal user to test, so it uses the fixtures from
    # run-all-php-versions.sh.
    $fixtures = getenv("CORELIBS_TEST_FIXTURES");
    $sticky = ($fixtures === false) ? "" : "{$fixtures}/sticky";

    if ($sticky === "" || !is_dir($sticky))
    {
        SmokeTest::skip("deleteDir() / emptyDir() in a sticky folder", "needs the fixtures from run-all-php-versions.sh");
    }
    elseif (function_exists("posix_geteuid") && posix_geteuid() === 0)
    {
        SmokeTest::skip("deleteDir() / emptyDir() in a sticky folder", "needs a normal user, as root can delete anything");
    }
    else
    {
        # Our own file in it could be deleted, but nothing is, as root's can't be.
        $ours = "{$sticky}/ours-" . getmypid() . ".txt";
        file_put_contents($ours, "ours");
        $problems = [];

        try
        {
            Filesystem::emptyDir($sticky);
        }
        catch (DirectoryNotDeletableException $e)
        {
            $problems = $e->getProblems();
        }
        finally
        {
            $stillThere = file_exists($ours);
            @unlink($ours);
        }

        assert(count($problems) === 1, "expected 1 problem, got " . count($problems));
        assert($problems[0]->reason === FileDeletionProblemEnum::NOT_OWNED_IN_STICKY_DIRECTORY);
        assert($problems[0]->path === "{$sticky}/root-owned.txt");
        assert($stillThere, "emptyDir() deleted our file, despite refusing");
    }
});

$smoke->run("deleteDir() failing part way through", function () use ($dir) {
    # Some failures can't be seen beforehand (e.g. an immutable file), and containers can't usually make one, so give
    # the step that deletes a plan with something in it that has gone, as if another process deleted it.
    $runPlan = new ReflectionMethod(Filesystem::class, "runDeletionPlan");
    $base = "{$dir}/delete-part-way";
    mkdir($base);
    touch("{$base}/first.txt");

    $plan = [
        [FileDeletionOperationEnum::UNLINK, "{$base}/first.txt"],
        [FileDeletionOperationEnum::UNLINK, "{$base}/gone.txt"],
        [FileDeletionOperationEnum::RMDIR, $base],
    ];

    $runBrokenPlan = function () use ($runPlan, $base, $plan): void {
        $runPlan->invoke(null, $base, $plan);
    };

    # It stops at the failure, and says some may already have been deleted.
    SmokeTest::expectException(FilesystemException::class, $runBrokenPlan, "some of {$base} may already have been deleted");
    assert(!file_exists("{$base}/first.txt") && is_dir($base));
});

$smoke->run("UNC paths, as used for WSL's files from Windows", function () use ($dir) {
    # From Windows, WSL's files are reached with UNC paths (e.g. \\wsl.localhost\Ubuntu\home). The WSL CI job sets
    # CORELIBS_TEST_UNC_DIR to a folder there. Otherwise, the admin share (\\localhost\C$\...) is a UNC path to the
    # same files as C:\..., so that is used.
    $fromEnvironment = getenv("CORELIBS_TEST_UNC_DIR");
    $base = Filesystem::normalisePath(realpath($dir)) . "/unc-paths";
    $adminShare = (preg_match('#^([A-Za-z]):/(.*)$#', $base, $match) === 1) ? "//localhost/{$match[1]}$/{$match[2]}" : "";

    $unc = ($fromEnvironment !== false && $fromEnvironment !== "")
        ? Filesystem::normalisePath($fromEnvironment) . "/unc-paths-" . getmypid()
        : $adminShare;

    if (PHP_OS_FAMILY !== "Windows" || $unc === "" || !is_dir(dirname($unc)))
    {
        SmokeTest::skip("UNC paths, as used for WSL's files from Windows", "needs Windows, and a UNC share");
    }
    else
    {
        mkdir("{$unc}/real/sub", 0777, true);
        touch("{$unc}/real/sub/f.txt");
        mkdir("{$unc}/to-empty");
        touch("{$unc}/to-empty/x.txt");

        # The paths given back stay on the share, rather than being turned into a path on the current drive.
        $files = Filesystem::getDirContents("{$unc}/real");
        assert($files === ["{$unc}/real/sub/f.txt"], "getDirContents() gave: " . implode(", ", $files));
        $backslashed = str_replace("/", "\\", "{$unc}/real/sub/..");
        $tidied = Filesystem::getDirContents($backslashed);
        assert($tidied === ["{$unc}/real/sub/f.txt"], "getDirContents() with \\ gave: " . implode(", ", $tidied));

        # And deleting through one works on the share's files.
        Filesystem::emptyDir("{$unc}/to-empty");
        assert(!file_exists("{$unc}/to-empty/x.txt") && is_dir("{$unc}/to-empty"));
        Filesystem::deleteDir($unc);
        assert(!file_exists($unc));
    }
});

