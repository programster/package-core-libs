<?php

/*
 * Smoke tests for File. These are run by testing/main.php, which provides $smoke and $dir.
 */

use Programster\CoreLibs\Exceptions\BadArgumentException;
use Programster\CoreLibs\Exceptions\FileAlreadyExistsException;
use Programster\CoreLibs\Exceptions\FileDoesNotExistException;
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
    SmokeTest::expectException(FileDoesNotExistException::class, fn() => $file->rename("nope/f.txt"));
    SmokeTest::expectException(BadArgumentException::class, fn() => $file->rename("other/"));
    SmokeTest::expectException(BadArgumentException::class, fn() => $file->rename(""));
    assert($file->getFilepath() === "{$base}/sub/e.txt");

    # move() keeps the name, and only creates the directory if asked to.
    $file->move("..");
    assert($file->getFilepath() === "{$base}/e.txt" && is_file("{$base}/e.txt"));
    SmokeTest::expectException(
        FileDoesNotExistException::class,
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

    # A symlink with a relative target can be renamed in place, but not moved into another directory, as it would then
    # point somewhere else. Nothing is moved or created if it is refused.
    mkdir("{$base}/links");
    mkdir("{$base}/links/elsewhere");
    file_put_contents("{$base}/links/target.txt", "target");
    symlink("target.txt", "{$base}/links/relative-link");
    $relativeLink = new File("{$base}/links/relative-link");
    $relativeRefusals = [
        function () use ($relativeLink) { $relativeLink->move("elsewhere"); },
        function () use ($relativeLink) { $relativeLink->move("not-yet-created", true); },
        function () use ($relativeLink) { $relativeLink->rename("elsewhere/renamed-link"); },
    ];

    foreach ($relativeRefusals as $refusal)
    {
        SmokeTest::expectException(BadArgumentException::class, $refusal, 'symlink to the relative path "target.txt"');
    }

    assert(!file_exists("{$base}/links/not-yet-created"));
    assert(file_get_contents("{$base}/links/relative-link") === "target");
    $relativeLink->rename("renamed-link");
    assert(readlink("{$base}/links/renamed-link") === "target.txt");
    assert(file_get_contents("{$base}/links/renamed-link") === "target");

    # A symlink with an absolute target still points to the same place wherever it goes, so it can be moved.
    symlink("{$base}/links/target.txt", "{$base}/links/absolute-link");
    $absoluteLink = new File("{$base}/links/absolute-link");
    $absoluteLink->move("elsewhere");
    assert(file_get_contents("{$base}/links/elsewhere/absolute-link") === "target");

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
