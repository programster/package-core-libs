<?php

/*
 * Smoke tests for File. These are run by testing/main.php, which provides $smoke and $dir.
 */

use Programster\CoreLibs\Enums\PreserveOwnershipEnum;
use Programster\CoreLibs\Exceptions\BadArgumentException;
use Programster\CoreLibs\Exceptions\DirectoryDoesNotExistException;
use Programster\CoreLibs\Exceptions\FileAlreadyExistsException;
use Programster\CoreLibs\Exceptions\FileDoesNotExistException;
use Programster\CoreLibs\Filesystem;
use Programster\CoreLibs\Models\CsvFile;
use Programster\CoreLibs\Models\File;
use Programster\CoreLibs\Testing\SmokeTest;

/** @var \Programster\CoreLibs\Testing\SmokeTest $smoke */
/** @var string $dir - a temporary directory for the tests to use, which is deleted afterwards. */

$smoke->run("File rename and move", function () use ($dir) {
    $base = "{$dir}/file-test";
    mkdir("{$base}/sub", 0777, true);
    file_put_contents("{$base}/a.txt", "data");

    # A plain name renames the file in its own directory.
    $file = new File("{$base}/a.txt");
    $file->rename("b.txt");
    assert($file->getFilepath() === "{$base}/b.txt" && file_get_contents("{$base}/b.txt") === "data");
    assert(!file_exists("{$base}/a.txt"));

    # Relative paths are relative to the file's directory, and absolute paths are used as they are.
    $file->rename("sub/c.txt");
    assert($file->getFilepath() === "{$base}/sub/c.txt" && is_file("{$base}/sub/c.txt"));
    $file->rename("../d.txt");
    assert($file->getFilepath() === "{$base}/d.txt" && is_file("{$base}/d.txt"));
    $file->rename("{$base}/sub/e.txt");
    assert($file->getFilepath() === "{$base}/sub/e.txt" && is_file("{$base}/sub/e.txt"));

    # Renaming to where the file already is does nothing.
    $file->rename("e.txt");
    $file->rename("../sub/e.txt");
    assert($file->getFilepath() === "{$base}/sub/e.txt" && is_file("{$base}/sub/e.txt"));

    # rename() doesn't overwrite, create directories, or take a directory.
    file_put_contents("{$base}/sub/taken.txt", "other");
    SmokeTest::expectException(FileAlreadyExistsException::class, fn() => $file->rename("taken.txt"));
    assert(file_get_contents("{$base}/sub/taken.txt") === "other" && is_file("{$base}/sub/e.txt"));
    SmokeTest::expectException(DirectoryDoesNotExistException::class, fn() => $file->rename("nope/f.txt"));
    SmokeTest::expectException(BadArgumentException::class, fn() => $file->rename("other/"));
    SmokeTest::expectException(BadArgumentException::class, fn() => $file->rename(""));
    assert($file->getFilepath() === "{$base}/sub/e.txt");

    # move() keeps the name, and only creates the directory if asked to.
    $file->move("..");
    assert($file->getFilepath() === "{$base}/e.txt" && is_file("{$base}/e.txt"));
    SmokeTest::expectException(
        DirectoryDoesNotExistException::class,
        fn() => $file->move("{$base}/new/deeper"),
        "Set \$createIfNotExists to true"
    );
    assert(!file_exists("{$base}/new"));
    $file->move("{$base}/new/deeper", true);
    assert($file->getFilepath() === "{$base}/new/deeper/e.txt" && is_file("{$base}/new/deeper/e.txt"));
    SmokeTest::expectException(BadArgumentException::class, fn() => $file->move("{$base}/sub/taken.txt"));
    file_put_contents("{$base}/sub/e.txt", "other");
    SmokeTest::expectException(FileAlreadyExistsException::class, fn() => $file->move("{$base}/sub"));
    assert(file_get_contents("{$base}/sub/e.txt") === "other");

    # With $overwriteExistingFile, an existing file is replaced, by both rename() and move().
    $file->rename("{$base}/sub/taken.txt", true);
    assert($file->getFilepath() === "{$base}/sub/taken.txt" && file_get_contents("{$base}/sub/taken.txt") === "data");
    file_put_contents("{$base}/taken.txt", "other");
    $file->move("..", overwriteExistingFile: true);
    assert($file->getFilepath() === "{$base}/taken.txt" && file_get_contents("{$base}/taken.txt") === "data");
    assert(!file_exists("{$base}/sub/taken.txt"));

    # A directory, or a symlink to one, is never replaced, even with $overwriteExistingFile.
    mkdir("{$base}/a-dir");
    symlink("{$base}/a-dir", "{$base}/dir-link");
    mkdir("{$base}/sub/taken.txt");
    $directoryClashes = [
        fn() => $file->rename("a-dir", true),
        fn() => $file->rename("dir-link", true),
        fn() => $file->move("sub", false, true),
    ];

    foreach ($directoryClashes as $clash)
    {
        SmokeTest::expectException(FileAlreadyExistsException::class, $clash, "a directory");
    }

    assert(is_link("{$base}/dir-link") && is_dir("{$base}/a-dir") && is_file("{$base}/taken.txt"));
    rmdir("{$base}/sub/taken.txt");

    # Replacing a symlink replaces the link, not the file it points to. A broken symlink still counts as existing.
    file_put_contents("{$base}/pointed-at.txt", "keep");
    symlink("{$base}/pointed-at.txt", "{$base}/file-link");
    $file->rename("file-link", true);
    assert(!is_link("{$base}/file-link") && file_get_contents("{$base}/file-link") === "data");
    assert(file_get_contents("{$base}/pointed-at.txt") === "keep");
    symlink("{$base}/missing.txt", "{$base}/broken-link");
    SmokeTest::expectException(FileAlreadyExistsException::class, fn() => $file->rename("broken-link"));
    $file->rename("broken-link", true);
    assert(!is_link("{$base}/broken-link") && file_get_contents("{$base}/broken-link") === "data");

    # Moving a symlink over the file it points to (or the other way round), or over a hard link, is refused, as the
    # data could be lost.
    file_put_contents("{$base}/real.txt", "precious");
    symlink("{$base}/real.txt", "{$base}/to-real");
    link("{$base}/real.txt", "{$base}/hard.txt");
    $sameFileCases = [
        ["{$base}/to-real", "real.txt"],
        ["{$base}/real.txt", "to-real"],
        ["{$base}/real.txt", "hard.txt"],
    ];

    foreach ($sameFileCases as [$source, $target])
    {
        SmokeTest::expectException(
            FileAlreadyExistsException::class,
            fn() => (new File($source))->rename($target, true),
            "they are the same file"
        );
    }

    assert(!is_link("{$base}/real.txt") && file_get_contents("{$base}/real.txt") === "precious");
    assert(is_link("{$base}/to-real") && file_get_contents("{$base}/hard.txt") === "precious");

    # A symlink with a relative target is recreated when moved to another directory, with its target worked out
    # again from there, so it stays relative and still points at the same place.
    mkdir("{$base}/links/deeper/still", 0777, true);
    mkdir("{$base}/shared");
    file_put_contents("{$base}/links/target.txt", "target");
    file_put_contents("{$base}/shared/data.txt", "shared");
    symlink("target.txt", "{$base}/links/relative-link");
    $relativeLink = new File("{$base}/links/relative-link");
    $relativeLink->move("deeper/still");
    assert($relativeLink->getFilepath() === "{$base}/links/deeper/still/relative-link");
    assert(readlink("{$base}/links/deeper/still/relative-link") === "../../target.txt");
    assert(file_get_contents("{$base}/links/deeper/still/relative-link") === "target");
    assert(!is_link("{$base}/links/relative-link"));

    # Moving it back up, and renaming it into another directory, work the same way.
    $relativeLink->rename("../../renamed-link");
    assert(readlink("{$base}/links/renamed-link") === "target.txt");

    # A target that goes up and across ("../shared/data.txt") is worked out the same way.
    symlink("../shared/data.txt", "{$base}/links/across-link");
    (new File("{$base}/links/across-link"))->move("deeper");
    assert(readlink("{$base}/links/deeper/across-link") === "../../shared/data.txt");
    assert(file_get_contents("{$base}/links/deeper/across-link") === "shared");

    # A target that goes through another symlink and then up ("sub/../x.txt", where sub is a symlink elsewhere) is
    # followed the way the system follows it: up from wherever sub leads, not from sub's own directory.
    mkdir("{$base}/other/inner", 0777, true);
    file_put_contents("{$base}/other/x.txt", "the real target");
    file_put_contents("{$base}/links/x.txt", "the wrong file");
    symlink("{$base}/other/inner", "{$base}/links/sub");
    symlink("sub/../x.txt", "{$base}/links/tricky-link");
    (new File("{$base}/links/tricky-link"))->move("deeper");
    assert(file_get_contents("{$base}/links/deeper/tricky-link") === "the real target");

    # A link whose target has gone since its File was created stays broken in the same way: it still points at the
    # same (missing) place. (A File can't be created for a link that is already broken, as it must exist.)
    file_put_contents("{$base}/links/soon-gone.txt", "gone");
    symlink("soon-gone.txt", "{$base}/links/broken-link");
    $brokenLink = new File("{$base}/links/broken-link");
    unlink("{$base}/links/soon-gone.txt");
    $brokenLink->move("deeper");
    assert(readlink("{$base}/links/deeper/broken-link") === "../soon-gone.txt");

    # Overwriting a file in the way replaces it with the recreated link.
    symlink("target.txt", "{$base}/links/overwrite-link");
    file_put_contents("{$base}/links/deeper/overwrite-link", "in the way");
    SmokeTest::expectException(
        FileAlreadyExistsException::class,
        function () use ($base) { (new File("{$base}/links/overwrite-link"))->move("deeper"); }
    );
    (new File("{$base}/links/overwrite-link"))->move("deeper", false, true);
    assert(readlink("{$base}/links/deeper/overwrite-link") === "../target.txt");

    # Renaming a relative link within its own directory keeps its target as it is.
    $renamedLink = new File("{$base}/links/renamed-link");
    $renamedLink->rename("renamed-again");
    assert(readlink("{$base}/links/renamed-again") === "target.txt");

    # A symlink with an absolute target still points to the same place wherever it goes, so it is moved as it is.
    symlink("{$base}/links/target.txt", "{$base}/links/absolute-link");
    (new File("{$base}/links/absolute-link"))->move("deeper");
    assert(readlink("{$base}/links/deeper/absolute-link") === "{$base}/links/target.txt");

    # Filesystem::moveFile() does the same as File::rename(), but a relative path is relative to the working directory.
    file_put_contents("{$base}/shortcut.txt", "shortcut");
    Filesystem::moveFile("{$base}/shortcut.txt", "{$base}/sub/shortcut-moved.txt");
    assert(file_get_contents("{$base}/sub/shortcut-moved.txt") === "shortcut" && !file_exists("{$base}/shortcut.txt"));
    $workingDirectory = getcwd();
    chdir("{$base}/sub");
    Filesystem::moveFile("shortcut-moved.txt", "../shortcut-back.txt");
    chdir($workingDirectory);
    assert(file_get_contents("{$base}/shortcut-back.txt") === "shortcut");
    file_put_contents("{$base}/sub/in-the-way.txt", "in the way");
    SmokeTest::expectException(
        FileAlreadyExistsException::class,
        function () use ($base) { Filesystem::moveFile("{$base}/shortcut-back.txt", "{$base}/sub/in-the-way.txt"); }
    );
    Filesystem::moveFile("{$base}/shortcut-back.txt", "{$base}/sub/in-the-way.txt", true);
    assert(file_get_contents("{$base}/sub/in-the-way.txt") === "shortcut");

    # Only existing files can be used.
    SmokeTest::expectException(FileDoesNotExistException::class, fn() => new File("{$base}/nope.txt"));
    SmokeTest::expectException(FileDoesNotExistException::class, fn() => new File("{$base}/sub"));

    # CsvFile gets these too, and keeps working after a move.
    file_put_contents("{$base}/data.csv", "a,b\n1,2\n");
    $csvFile = new CsvFile("{$base}/data.csv", true);
    $csvFile->move("sub");
    $csvFile->rename("renamed.csv");
    assert($csvFile->getFilepath() === "{$base}/sub/renamed.csv");
    assert($csvFile->toArray() === [["a" => "1", "b" => "2"]]);
});

$smoke->run("File moves never replacing a file created during the move", function () use ($dir) {
    # A file that appears at the new path after moveTo()'s checks (as if made by another process) must not be
    # replaced. That can't be timed from here, so call the methods that do the final step with it already there.
    $base = "{$dir}/move-race";
    mkdir($base);
    file_put_contents("{$base}/mine.txt", "mine");
    file_put_contents("{$base}/theirs.txt", "theirs");
    $moveFile = new ReflectionMethod(File::class, "moveFile");

    SmokeTest::expectException(
        FileAlreadyExistsException::class,
        fn() => $moveFile->invoke(null, "{$base}/mine.txt", "{$base}/theirs.txt", false, PreserveOwnershipEnum::YES),
        "as something was created there while it was being moved"
    );

    assert(file_get_contents("{$base}/theirs.txt") === "theirs" && file_get_contents("{$base}/mine.txt") === "mine");

    # A broken symlink counts as something being there too.
    symlink("{$base}/missing", "{$base}/broken-link");
    SmokeTest::expectException(
        FileAlreadyExistsException::class,
        fn() => $moveFile->invoke(null, "{$base}/mine.txt", "{$base}/broken-link", false, PreserveOwnershipEnum::YES)
    );
    assert(is_link("{$base}/broken-link") && file_get_contents("{$base}/mine.txt") === "mine");

    # When nothing is there, it moves.
    $moveFile->invoke(null, "{$base}/mine.txt", "{$base}/moved.txt", false, PreserveOwnershipEnum::YES);
    assert(file_get_contents("{$base}/moved.txt") === "mine" && !file_exists("{$base}/mine.txt"));

    # Moving to another filesystem goes through a temporary name in the new directory, which is removed again
    # whether it works or not. /dev/shm is usually a separate filesystem, so check there if so.
    if (is_dir("/dev/shm") && stat("/dev/shm")['dev'] !== stat($base)['dev'])
    {
        $otherFilesystem = "/dev/shm/corelibs-move-race-" . getmypid();
        mkdir($otherFilesystem);

        try
        {
            file_put_contents("{$otherFilesystem}/theirs.txt", "theirs");

            SmokeTest::expectException(
                FileAlreadyExistsException::class,
                fn() => $moveFile->invoke(null, "{$base}/moved.txt", "{$otherFilesystem}/theirs.txt", false, PreserveOwnershipEnum::YES)
            );

            assert(file_get_contents("{$otherFilesystem}/theirs.txt") === "theirs");
            assert(file_get_contents("{$base}/moved.txt") === "mine");
            $moveFile->invoke(null, "{$base}/moved.txt", "{$otherFilesystem}/moved.txt", false, PreserveOwnershipEnum::YES);
            assert(file_get_contents("{$otherFilesystem}/moved.txt") === "mine" && !file_exists("{$base}/moved.txt"));
            assert(glob("{$otherFilesystem}/.corelibs-move-*") === []);
            $moveFile->invoke(null, "{$otherFilesystem}/moved.txt", "{$base}/moved.txt", false, PreserveOwnershipEnum::YES);

            # The copy keeps the permissions and modification time, and replacing works across filesystems too.
            chmod("{$base}/moved.txt", 0640);
            touch("{$base}/moved.txt", 1000000000);
            $moveFile->invoke(null, "{$base}/moved.txt", "{$otherFilesystem}/theirs.txt", true, PreserveOwnershipEnum::YES);
            assert(file_get_contents("{$otherFilesystem}/theirs.txt") === "mine" && !file_exists("{$base}/moved.txt"));
            assert((fileperms("{$otherFilesystem}/theirs.txt") & 0777) === 0640);
            assert(filemtime("{$otherFilesystem}/theirs.txt") === 1000000000);
            assert(glob("{$otherFilesystem}/.corelibs-move-*") === []);
            $moveFile->invoke(null, "{$otherFilesystem}/theirs.txt", "{$base}/moved.txt", false, PreserveOwnershipEnum::YES);
        }
        finally
        {
            Filesystem::deleteDir($otherFilesystem);
        }
    }

    # The same goes for a relative symlink moved into another directory, which is recreated rather than renamed.
    mkdir("{$base}/other");
    symlink("moved.txt", "{$base}/link");
    file_put_contents("{$base}/other/link", "theirs");
    $moveRelativeSymlink = new ReflectionMethod(File::class, "moveRelativeSymlink");

    SmokeTest::expectException(
        FileAlreadyExistsException::class,
        fn() => $moveRelativeSymlink->invoke(null, "{$base}/link", "{$base}/other/link", "moved.txt", false)
    );

    assert(file_get_contents("{$base}/other/link") === "theirs" && is_link("{$base}/link"));
});

$smoke->run("absolute paths, including Windows ones", function () {
    $isAbsolutePath = new ReflectionMethod(File::class, "isAbsolutePath");
    $isWindowsAbsolutePath = new ReflectionMethod(File::class, "isWindowsAbsolutePath");

    # The Windows forms, which are only absolute on Windows.
    foreach (["C:\\data\\bob.txt", "c:/data", "\\\\server\\share\\x", "\\data"] as $path)
    {
        assert($isWindowsAbsolutePath->invoke(null, $path) === true, "{$path} should be a Windows absolute path");
        assert($isAbsolutePath->invoke(null, $path) === (PHP_OS_FAMILY === "Windows"), "{$path} on " . PHP_OS_FAMILY);
    }

    # A drive letter without a slash is relative to that drive's current directory.
    foreach (["C:data", "data\\bob.txt", "bob.txt", "../bob.txt"] as $path)
    {
        assert($isWindowsAbsolutePath->invoke(null, $path) === false, "{$path} shouldn't be a Windows absolute path");
        assert($isAbsolutePath->invoke(null, $path) === false, "{$path} shouldn't be absolute");
    }

    assert($isAbsolutePath->invoke(null, "/srv/bob.txt") === true);
});

