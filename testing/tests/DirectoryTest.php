<?php

/*
 * Smoke tests for Directory. These are run by testing/main.php, which provides $smoke and $dir.
 */

use Programster\CoreLibs\Enums\PreserveOwnershipEnum;
use Programster\CoreLibs\Exceptions\BadArgumentException;
use Programster\CoreLibs\Exceptions\BadFilePermissionsException;
use Programster\CoreLibs\Exceptions\DirectoryDoesNotExistException;
use Programster\CoreLibs\Exceptions\FileAlreadyExistsException;
use Programster\CoreLibs\Exceptions\FileDoesNotExistException;
use Programster\CoreLibs\Exceptions\FilesystemException;
use Programster\CoreLibs\Filesystem;
use Programster\CoreLibs\Models\File;
use Programster\CoreLibs\Models\Directory;
use Programster\CoreLibs\Testing\SmokeTest;

/** @var \Programster\CoreLibs\Testing\SmokeTest $smoke */
/** @var string $dir - a temporary directory for the tests to use, which is deleted afterwards. */

$smoke->run("Directory move and rename keep symlinks working", function () use ($dir) {
    $base = "{$dir}/directory-test";
    mkdir("{$base}/outside", 0777, true);
    mkdir("{$base}/project/sub", 0777, true);
    file_put_contents("{$base}/outside/o.txt", "outside");
    file_put_contents("{$base}/project/file.txt", "file");
    file_put_contents("{$base}/project/sub/deep.txt", "deep");

    # One of each kind of symlink.
    symlink("file.txt", "{$base}/project/rel-inside");
    symlink("../file.txt", "{$base}/project/sub/rel-inside-up");
    symlink("../outside/o.txt", "{$base}/project/rel-outside");
    symlink("{$base}/project/sub/deep.txt", "{$base}/project/abs-inside");
    symlink("{$base}/project", "{$base}/project/sub/abs-root");
    symlink("{$base}/outside/o.txt", "{$base}/project/abs-outside");
    symlink("../project/file.txt", "{$base}/project/out-and-back");
    symlink("missing.txt", "{$base}/project/broken");

    # Move it two levels down, into a directory that is created for it.
    $directory = new Directory("{$base}/project");
    $directory->move("archive/2026", true);
    $moved = "{$base}/archive/2026/project";
    assert($directory->getPath() === $moved && !file_exists("{$base}/project"));

    # Relative links inside stay exactly as they are. Relative links outside are worked out again. Absolute links
    # inside point at the new location, and absolute links outside are left as they are.
    $expectedTargets = [
        "rel-inside" => "file.txt",
        "sub/rel-inside-up" => "../file.txt",
        "rel-outside" => "../../../outside/o.txt",
        "abs-inside" => "{$moved}/sub/deep.txt",
        "sub/abs-root" => $moved,
        "abs-outside" => "{$base}/outside/o.txt",
        "out-and-back" => "../project/file.txt",
        "broken" => "missing.txt",
    ];

    foreach ($expectedTargets as $link => $expectedTarget)
    {
        $actualTarget = readlink("{$moved}/{$link}");
        assert($actualTarget === $expectedTarget, "{$link} points at {$actualTarget}");
    }

    assert(file_get_contents("{$moved}/rel-outside") === "outside");
    assert(file_get_contents("{$moved}/abs-inside") === "deep");
    assert(file_get_contents("{$moved}/sub/abs-root/file.txt") === "file");

    # Renaming it changes its name, so a relative link that leaves it and comes back in by name ("../project/...")
    # would now break, and is worked out again. Filesystem::moveDir() does the same as Directory::rename().
    Filesystem::moveDir($moved, "{$base}/archive/renamed");
    $renamed = "{$base}/archive/renamed";
    assert(readlink("{$renamed}/out-and-back") === "file.txt");
    assert(file_get_contents("{$renamed}/out-and-back") === "file");
    assert(readlink("{$renamed}/rel-outside") === "../../outside/o.txt");
    assert(readlink("{$renamed}/rel-inside") === "file.txt");
    assert(readlink("{$renamed}/abs-inside") === "{$renamed}/sub/deep.txt");

    # Things that can't be done throw, and change nothing.
    mkdir("{$base}/taken");
    $renamedDirectory = new Directory($renamed);
    $moveIntoItself = function () use ($renamedDirectory) { $renamedDirectory->move("renamed/sub"); };
    $renameOntoExisting = function () use ($renamedDirectory, $base) { $renamedDirectory->rename("{$base}/taken"); };
    $moveIntoMissing = function () use ($renamedDirectory) { $renamedDirectory->move("not-there"); };
    $useSymlink = function () use ($renamed) { new Directory("{$renamed}/sub/abs-root"); };
    $useMissing = function () use ($base) { new Directory("{$base}/nope"); };
    $refusals = [
        [BadArgumentException::class, $moveIntoItself],
        [FileAlreadyExistsException::class, $renameOntoExisting],
        [DirectoryDoesNotExistException::class, $moveIntoMissing],
        [BadArgumentException::class, $useSymlink],
        [DirectoryDoesNotExistException::class, $useMissing],
    ];

    foreach ($refusals as [$exceptionClass, $refusal])
    {
        SmokeTest::expectException($exceptionClass, $refusal);
    }

    # It is separate from FileDoesNotExistException, so each can be caught without the other.
    assert(!is_subclass_of(DirectoryDoesNotExistException::class, FileDoesNotExistException::class));

    assert(is_dir($renamed) && !file_exists("{$base}/archive/not-there") && $renamedDirectory->getPath() === $renamed);

    if (SmokeTest::canTestPermissions("Directory checking everything can be done before moving"))
    {
        # A subdirectory that can't be read, or a symlink that needs updating in one that can't be written to, stops
        # the move before anything changes, including creating the destination.
        mkdir("{$base}/locked/unreadable", 0777, true);
        mkdir("{$base}/locked/read-only");
        symlink("{$base}/locked/read-only", "{$base}/locked/read-only/abs-self");
        chmod("{$base}/locked/unreadable", 0000);
        chmod("{$base}/locked/read-only", 0555);
        $lockedDirectory = new Directory("{$base}/locked");
        $expectedMessages = [
            "2 problem(s) were found, so nothing was moved",
            "{$base}/locked/unreadable can't be listed",
            "{$base}/locked/read-only/abs-self needs updating, but {$base}/locked/read-only can't be written to",
        ];

        foreach ($expectedMessages as $expectedMessage)
        {
            SmokeTest::expectException(
                BadFilePermissionsException::class,
                function () use ($lockedDirectory) { $lockedDirectory->move("new-home", true); },
                $expectedMessage
            );
        }

        assert(is_dir("{$base}/locked") && !file_exists("{$base}/new-home"));
        chmod("{$base}/locked/unreadable", 0755);
        chmod("{$base}/locked/read-only", 0755);
    }

    # Remove everything this made, as it contains symlinks that point back into the test directory, which would
    # otherwise show up in other tests that list $dir.
    Filesystem::deleteDir($base);
});

$smoke->run("Directory move to a different filesystem", function () use ($dir) {
    # /dev/shm is usually a separate (in-memory) filesystem, so use it as the destination when it is.
    $isSeparateFilesystem = is_dir("/dev/shm")
        && is_writable("/dev/shm")
        && stat("/dev/shm")['dev'] !== stat($dir)['dev'];

    if ($isSeparateFilesystem)
    {
        $base = "{$dir}/cross-fs";
        $otherFilesystem = "/dev/shm/corelibs-smoke-" . getmypid();
        $oldUmask = umask(0022);

        try
        {
            mkdir("{$base}/project/sub", 0777, true);
            mkdir("{$base}/outside");
            file_put_contents("{$base}/outside/o.txt", "outside");
            file_put_contents("{$base}/project/file.txt", "file");
            file_put_contents("{$base}/project/sub/deep.txt", "deep");
            chmod("{$base}/project/file.txt", 0640);
            chmod("{$base}/project/sub", 0750);
            touch("{$base}/project/file.txt", 1000000000);
            symlink("file.txt", "{$base}/project/rel-inside");
            symlink("../outside/o.txt", "{$base}/project/rel-outside");
            symlink("{$base}/project/sub/deep.txt", "{$base}/project/abs-inside");
            symlink("{$base}/outside/o.txt", "{$base}/project/abs-outside");
            $checkName = "Directory::move() to a different filesystem";
            $ownedByOtherUser = SmokeTest::giveToOtherUser("{$base}/project/file.txt", $checkName);

            # It is copied, with everything kept the same, and the original is deleted.
            $directory = new Directory("{$base}/project");
            $directory->move("{$otherFilesystem}/archive", true);
            $moved = "{$otherFilesystem}/archive/project";
            clearstatcache();
            assert($directory->getPath() === $moved && !file_exists("{$base}/project"));
            assert(file_get_contents("{$moved}/file.txt") === "file");
            assert((fileperms("{$moved}/file.txt") & 07777) === 0640 && (fileperms("{$moved}/sub") & 07777) === 0750);
            assert(filemtime("{$moved}/file.txt") === 1000000000);

            if ($ownedByOtherUser)
            {
                SmokeTest::assertOwnedByOtherUser("{$moved}/file.txt", $checkName);
            }

            # The symlinks follow the same rules as an ordinary move.
            assert(readlink("{$moved}/rel-inside") === "file.txt");
            assert(file_get_contents("{$moved}/rel-outside") === "outside");
            assert(!str_starts_with(readlink("{$moved}/rel-outside"), "/"));
            assert(readlink("{$moved}/abs-inside") === "{$moved}/sub/deep.txt");
            assert(readlink("{$moved}/abs-outside") === "{$base}/outside/o.txt");

            # Without $overrideUmask, a copy's permissions are reduced by the umask, as for any new file, and the move
            # still goes ahead. With it, they are copied exactly.
            mkdir("{$base}/group-writable");
            mkdir("{$base}/group-writable-exact");
            file_put_contents("{$base}/group-writable/g.txt", "g");
            file_put_contents("{$base}/group-writable-exact/g.txt", "g");
            chmod("{$base}/group-writable/g.txt", 0664);
            chmod("{$base}/group-writable-exact/g.txt", 0664);
            (new Directory("{$base}/group-writable"))->move($otherFilesystem);
            (new Directory("{$base}/group-writable-exact"))->move($otherFilesystem, false, true);
            clearstatcache();
            assert((fileperms("{$otherFilesystem}/group-writable/g.txt") & 07777) === 0644);
            assert((fileperms("{$otherFilesystem}/group-writable-exact/g.txt") & 07777) === 0664);
            assert(!file_exists("{$base}/group-writable") && !file_exists("{$base}/group-writable-exact"));

            # Hard links and special files can't be copied as they are, so they stop the move.
            mkdir("{$base}/uncopyable");
            file_put_contents("{$base}/uncopyable/a.txt", "a");
            link("{$base}/uncopyable/a.txt", "{$base}/uncopyable/b.txt");
            posix_mkfifo("{$base}/uncopyable/pipe", 0644);
            $uncopyable = new Directory("{$base}/uncopyable");

            foreach (["is a hard link (it has 2 names)", "is a special file"] as $expectedMessage)
            {
                SmokeTest::expectException(
                    \Programster\CoreLibs\Exceptions\BadFilePermissionsException::class,
                    function () use ($uncopyable, $otherFilesystem) { $uncopyable->move($otherFilesystem); },
                    $expectedMessage
                );
            }

            assert(is_file("{$base}/uncopyable/a.txt") && !file_exists("{$otherFilesystem}/uncopyable"));

            # Files owned by another user, or by a group the current user isn't in, would change owner or group if
            # copied, so are refused. Only root can create these, so run-in-container.sh makes them before the
            # normal-user run, and says where with CORELIBS_TEST_FIXTURES.
            $fixtures = getenv("CORELIBS_TEST_FIXTURES");
            $foreignCheck = "Directory::move() refusing files owned by another user or group";

            if (!SmokeTest::canTestPermissions($foreignCheck))
            {
                # Root can keep any owner, so this only applies to other users. canTestPermissions() reports it.
            }
            elseif ($fixtures === false || !is_dir("{$fixtures}/other-owner"))
            {
                SmokeTest::skip($foreignCheck, "needs the fixtures from run-all-php-versions.sh");
            }
            else
            {
                $foreignCases = [
                    "other-owner" => "root-owned.txt is owned by user 0, so a copy",
                    "other-group" => "other-group.txt belongs to group 12345, which the current user isn't in",
                ];

                # Moving one of the files on its own is refused too, and both say how to get round it.
                SmokeTest::expectException(
                    \Programster\CoreLibs\Exceptions\BadFilePermissionsException::class,
                    fn() => (new File("{$fixtures}/other-owner/root-owned.txt"))->move($otherFilesystem),
                    "Run this as root (e.g. with sudo) to keep its owner"
                );

                SmokeTest::expectException(
                    \Programster\CoreLibs\Exceptions\BadFilePermissionsException::class,
                    fn() => (new Directory("{$fixtures}/other-owner"))->move($otherFilesystem),
                    "run this as root (e.g. with sudo), or pass PreserveOwnershipEnum::PREFERRED or NO"
                );

                foreach ($foreignCases as $fixture => $expectedMessage)
                {
                    $foreignDirectory = new Directory("{$fixtures}/{$fixture}");
                    SmokeTest::expectException(
                        \Programster\CoreLibs\Exceptions\BadFilePermissionsException::class,
                        function () use ($foreignDirectory, $otherFilesystem) {
                            $foreignDirectory->move($otherFilesystem);
                        },
                        $expectedMessage
                    );
                    assert(is_dir("{$fixtures}/{$fixture}") && !file_exists("{$otherFilesystem}/{$fixture}"));
                }

                # With PREFERRED or NO, they are moved anyway, and the copies belong to the current user and group.
                $movedWith = [
                    "other-owner" => [PreserveOwnershipEnum::PREFERRED, "root-owned.txt", "owned by root\n"],
                    "other-group" => [PreserveOwnershipEnum::NO, "other-group.txt", "group 12345\n"],
                ];

                foreach ($movedWith as $fixture => [$preserveOwnership, $file, $contents])
                {
                    $foreignDirectory = new Directory("{$fixtures}/{$fixture}");
                    $foreignDirectory->move($otherFilesystem, preserveOwnership: $preserveOwnership);
                    $copy = "{$otherFilesystem}/{$fixture}/{$file}";
                    assert(!file_exists("{$fixtures}/{$fixture}") && file_get_contents($copy) === $contents);
                    assert(fileowner($copy) === posix_geteuid() && filegroup($copy) === posix_getegid());
                }
            }

            # Not enough free space at the destination is found before anything is copied. run-all-php-versions.sh
            # gives the container a 1 MB filesystem for this, and says where with CORELIBS_TEST_SMALL_FILESYSTEM.
            $smallFilesystem = getenv("CORELIBS_TEST_SMALL_FILESYSTEM");

            if ($smallFilesystem === false || !is_dir($smallFilesystem) || !is_writable($smallFilesystem))
            {
                SmokeTest::skip(
                    "Directory::move() refusing when there isn't enough free space",
                    "needs the small filesystem from run-all-php-versions.sh"
                );
            }
            else
            {
                mkdir("{$base}/too-big");
                file_put_contents("{$base}/too-big/big.bin", str_repeat("x", 2 * 1024 * 1024));
                $tooBig = new Directory("{$base}/too-big");
                SmokeTest::expectException(
                    \Programster\CoreLibs\Exceptions\BadFilePermissionsException::class,
                    function () use ($tooBig, $smallFilesystem) { $tooBig->move($smallFilesystem); },
                    "but copying needs 2097152"
                );
                assert(is_file("{$base}/too-big/big.bin") && !file_exists("{$smallFilesystem}/too-big"));
            }

            # A read-only directory inside. Root can delete the original afterwards anyway, so it is copied, keeping
            # its permissions. Anyone else couldn't delete the original afterwards, so the move is refused up front,
            # rather than leaving two copies.
            mkdir("{$base}/with-read-only/locked", 0777, true);
            file_put_contents("{$base}/with-read-only/locked/r.txt", "r");
            chmod("{$base}/with-read-only/locked", 0555);
            $withReadOnly = new Directory("{$base}/with-read-only");
            $moveWithReadOnly = function () use ($withReadOnly, $otherFilesystem) {
                $withReadOnly->move($otherFilesystem);
            };

            if (SmokeTest::canTestPermissions("Directory::move() refusing a copy whose original couldn't be deleted"))
            {
                SmokeTest::expectException(
                    \Programster\CoreLibs\Exceptions\BadFilePermissionsException::class,
                    $moveWithReadOnly,
                    "locked can't be written to, so the original couldn't be deleted after copying"
                );
                assert(is_file("{$base}/with-read-only/locked/r.txt"));
                assert(!file_exists("{$otherFilesystem}/with-read-only"));
                chmod("{$base}/with-read-only/locked", 0755);
            }
            else
            {
                $moveWithReadOnly();
                clearstatcache();
                assert(file_get_contents("{$otherFilesystem}/with-read-only/locked/r.txt") === "r");
                assert((fileperms("{$otherFilesystem}/with-read-only/locked") & 07777) === 0555);
                assert(!file_exists("{$base}/with-read-only"));
            }
        }
        finally
        {
            umask($oldUmask);

            # Make everything removable again, then remove what this made on the other filesystem.
            if (is_dir($otherFilesystem))
            {
                shell_exec("chmod -R u+rwx " . escapeshellarg($otherFilesystem));
                Filesystem::deleteDir($otherFilesystem);
            }
        }
    }
});

