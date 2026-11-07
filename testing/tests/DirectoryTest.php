<?php

/*
 * Smoke tests for Directory. These are run by testing/main.php, which provides $smoke and $dir.
 */

use Programster\CoreLibs\Exceptions\DirectoryNotMovableException;
use Programster\CoreLibs\Enums\DirectoryMoveProblemEnum;
use Programster\CoreLibs\Enums\PreserveOwnershipEnum;
use Programster\CoreLibs\Exceptions\BadArgumentException;
use Programster\CoreLibs\Exceptions\BadFilePermissionsException;
use Programster\CoreLibs\Exceptions\DirectoryDoesNotExistException;
use Programster\CoreLibs\Exceptions\FileAlreadyExistsException;
use Programster\CoreLibs\Exceptions\FileDoesNotExistException;
use Programster\CoreLibs\Exceptions\FilesystemException;
use Programster\CoreLibs\Filesystem;
use Programster\CoreLibs\Models\File;
use Programster\CoreLibs\Models\AbstractMoveListener;
use Programster\CoreLibs\Exceptions\MoveCancelledException;
use Programster\CoreLibs\Models\Directory;
use Programster\CoreLibs\Models\MoveProgress;
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
    SmokeTest::assertSamePath($directory->getPath(), $moved);
    assert(!file_exists("{$base}/project"));

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

        # An absolute target can be written as the real path (e.g. macOS's /private/var/... for /var/...), so compare
        # those resolved. A relative one must be exactly as expected.
        if (Filesystem::isAbsolutePath($expectedTarget))
        {
            SmokeTest::assertSamePath($actualTarget, $expectedTarget, "{$link} points at {$actualTarget}");
        }
        else
        {
            assert($actualTarget === $expectedTarget, "{$link} points at {$actualTarget}");
        }
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
    SmokeTest::assertSamePath(readlink("{$renamed}/abs-inside"), "{$renamed}/sub/deep.txt");

    # Things that can't be done throw, and change nothing.
    mkdir("{$base}/archive/taken");
    $renamedDirectory = new Directory($renamed);
    $moveIntoItself = function () use ($renamedDirectory) { $renamedDirectory->move("renamed/sub"); };
    $renameOntoExisting = function () use ($renamedDirectory) { $renamedDirectory->rename("taken"); };
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

    assert(is_dir($renamed) && !file_exists("{$base}/archive/not-there"));
    SmokeTest::assertSamePath($renamedDirectory->getPath(), $renamed);

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

        # The messages give real paths (e.g. macOS's /private/var/... for /var/...).
        $realBase = realpath($base);

        $expectedMessages = [
            "2 problem(s) were found, so nothing was moved",
            "{$realBase}/locked/unreadable can't be listed",
            "{$realBase}/locked/read-only/abs-self needs updating, but {$realBase}/locked/read-only can't be written to",
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
}, needsSymlinks: true);

$smoke->run("Directory move to a different filesystem", function () use ($dir) {
    # /dev/shm is usually a separate (in-memory) filesystem, so use it as the destination when it is.
    $isSeparateFilesystem = (
           is_dir("/dev/shm")
        && is_writable("/dev/shm")
        && stat("/dev/shm")['dev'] !== stat($dir)['dev']
    );

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
            SmokeTest::assertSamePath($directory->getPath(), $moved);
            assert(!file_exists("{$base}/project"));
            assert(file_get_contents("{$moved}/file.txt") === "file");
            SmokeTest::assertPermissions("{$moved}/file.txt", 0640);
            SmokeTest::assertPermissions("{$moved}/sub", 0750);
            assert(filemtime("{$moved}/file.txt") === 1000000000);

            if ($ownedByOtherUser)
            {
                SmokeTest::assertOwnedByOtherUser("{$moved}/file.txt", $checkName);
            }

            # The symlinks follow the same rules as an ordinary move.
            assert(readlink("{$moved}/rel-inside") === "file.txt");
            assert(file_get_contents("{$moved}/rel-outside") === "outside");
            assert(!str_starts_with(readlink("{$moved}/rel-outside"), "/"));
            SmokeTest::assertSamePath(readlink("{$moved}/abs-inside"), "{$moved}/sub/deep.txt");
            SmokeTest::assertSamePath(readlink("{$moved}/abs-outside"), "{$base}/outside/o.txt");

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
            SmokeTest::assertPermissions("{$otherFilesystem}/group-writable/g.txt", 0644);
            SmokeTest::assertPermissions("{$otherFilesystem}/group-writable-exact/g.txt", 0664);
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

            # The exception is a DirectoryNotMovableException, whose getProblems() says what and why.
            try
            {
                $uncopyable->move($otherFilesystem);
                assert(false, "the move should have been refused");
            }
            catch (DirectoryNotMovableException $e)
            {
                $reasons = [];

                foreach ($e->getProblems() as $problem)
                {
                    $reasons[basename($problem->getPath())] = $problem->getReason();
                }

                ksort($reasons);

                $expectedReasons = [
                    "a.txt" => DirectoryMoveProblemEnum::HARD_LINK,
                    "b.txt" => DirectoryMoveProblemEnum::HARD_LINK,
                    "pipe" => DirectoryMoveProblemEnum::SPECIAL_FILE,
                ];

                assert($reasons === $expectedReasons);
                # Which problem comes first depends on the order the directory lists its contents in, so look at all of
                # them.
                $descriptions = [];

                foreach ($e->getProblems() as $problem)
                {
                    $descriptions[] = $problem->getDescription();
                }

                assert(str_contains(implode("\n", $descriptions), "names), which would become a separate"));
            }

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

                $moveRootOwnedFile = function () use ($fixtures, $otherFilesystem): void {
                    (new File("{$fixtures}/other-owner/root-owned.txt"))->move($otherFilesystem);
                };

                # Moving one of the files on its own is refused too, and both say how to get round it.
                SmokeTest::expectException(
                    \Programster\CoreLibs\Exceptions\BadFilePermissionsException::class,
                    $moveRootOwnedFile,
                    "Run this as root (e.g. with sudo) to keep its owner"
                );

                $moveRootOwnedDirectory = function () use ($fixtures, $otherFilesystem): void {
                    (new Directory("{$fixtures}/other-owner"))->move($otherFilesystem);
                };

                SmokeTest::expectException(
                    \Programster\CoreLibs\Exceptions\BadFilePermissionsException::class,
                    $moveRootOwnedDirectory,
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

                $foreignReasons = [
                    "other-owner" => DirectoryMoveProblemEnum::OWNED_BY_ANOTHER_USER,
                    "other-group" => DirectoryMoveProblemEnum::GROUP_NOT_A_MEMBER_OF,
                ];

                foreach ($foreignReasons as $fixture => $expectedReason)
                {
                    try
                    {
                        (new Directory("{$fixtures}/{$fixture}"))->move($otherFilesystem);
                        assert(false, "moving {$fixture} should have been refused");
                    }
                    catch (DirectoryNotMovableException $e)
                    {
                        $problemReasons = [];

                        foreach ($e->getProblems() as $problem)
                        {
                            $problemReasons[] = $problem->getReason();
                        }

                        assert(in_array($expectedReason, $problemReasons, true), "{$fixture}'s problems");
                    }
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
                SmokeTest::assertPermissions("{$otherFilesystem}/with-read-only/locked", 0555);
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

$smoke->run("Directory move keeping a link to a link", function () use ($dir) {
    # "to-b" points at "b" inside the directory, and "b" points outside it. Moving the directory must keep "to-b"
    # pointing at "b" (judged by its own target, not by following "b" outside), so it still works afterwards.
    $base = "{$dir}/link-chain";
    mkdir("{$base}/project", 0777, true);
    mkdir("{$base}/elsewhere");
    file_put_contents("{$base}/outside.txt", "outside");
    symlink("{$base}/outside.txt", "{$base}/project/b");
    symlink("{$base}/project/b", "{$base}/project/to-b");
    (new Directory("{$base}/project"))->move("{$base}/elsewhere");
    SmokeTest::assertSamePath(readlink("{$base}/elsewhere/project/to-b"), "{$base}/elsewhere/project/b");
    assert(file_get_contents("{$base}/elsewhere/project/to-b") === "outside");
}, needsSymlinks: true);

$smoke->run("moves to another drive on Windows keeping ownership", function () use ($dir) {
    # On Windows, a copy on another drive is given its original's owner and permissions with PowerShell. Setting the
    # owner to SYSTEM needs the restore privilege (administrators have it, as on GitHub's runners), so this checks that
    # it is switched on. GitHub's Windows runners have a D: drive, separate from the temporary directory's.
    $otherDrive = "D:/corelibs-ownership-" . getmypid();

    $canTest = (
           PHP_OS_FAMILY === "Windows"
        && is_dir("D:/")
        && is_writable("D:/")
        && !Filesystem::isOnSameFilesystem(sys_get_temp_dir(), "D:/")
    );

    if (!$canTest)
    {
        SmokeTest::skip("moves to another drive on Windows keeping ownership", "needs Windows with a separate D: drive");
    }
    else
    {
        $base = "{$dir}/windows-drives";
        mkdir("{$base}/folder", 0777, true);
        mkdir($otherDrive);
        file_put_contents("{$base}/file.txt", "file");
        file_put_contents("{$base}/folder/inside.txt", "inside");

        # Reads a path's owner and its own (not inherited) permissions, with PowerShell, given the path as an argument.
        $script = "{$base}/read-security.ps1";

        file_put_contents(
            $script,
            "param([string]\$path)\n" .
            "\$acl = Get-Acl -LiteralPath \$path\n" .
            "'OWNER ' + \$acl.Owner\n" .
            "\$acl.Access | Where-Object { -not \$_.IsInherited } | ForEach-Object { 'RULE ' + \$_.IdentityReference }\n"
        );

        $readSecurity = function (string $path) use ($script): string {
            $command = "powershell -NoProfile -NonInteractive -ExecutionPolicy Bypass -File " . escapeshellarg($script) .
                " " . escapeshellarg(str_replace("/", "\\", $path));

            exec($command, $output);
            return implode("\n", $output);
        };

        # Give the originals an owner only an administrator can set, and an extra permission of their own.
        foreach (["{$base}/file.txt", "{$base}/folder", "{$base}/folder/inside.txt"] as $original)
        {
            $windowsPath = escapeshellarg(str_replace("/", "\\", $original));
            exec("icacls {$windowsPath} /grant *S-1-5-32-545:(R) /Q", $ignored, $grantExit);
            exec("icacls {$windowsPath} /setowner *S-1-5-18 /Q", $ignored, $ownerExit);
            assert($grantExit === 0 && $ownerExit === 0, "couldn't set up {$original}'s security");
        }

        $expectedFile = $readSecurity("{$base}/file.txt");
        $expectedInside = $readSecurity("{$base}/folder/inside.txt");
        assert(str_contains($expectedFile, "OWNER NT AUTHORITY\\SYSTEM"), "set up as: {$expectedFile}");

        try
        {
            # With YES (the default), both keep their owner and their own permissions.
            (new File("{$base}/file.txt"))->move($otherDrive);
            (new Directory("{$base}/folder"))->move($otherDrive);
            assert(file_get_contents("{$otherDrive}/file.txt") === "file" && !file_exists("{$base}/file.txt"));
            assert(file_get_contents("{$otherDrive}/folder/inside.txt") === "inside" && !file_exists("{$base}/folder"));
            $copiedFile = $readSecurity("{$otherDrive}/file.txt");
            $copiedInside = $readSecurity("{$otherDrive}/folder/inside.txt");
            assert($copiedFile === $expectedFile, "the file's copy has: {$copiedFile}, not: {$expectedFile}");
            assert($copiedInside === $expectedInside, "the folder's copy has: {$copiedInside}, not: {$expectedInside}");
            assert(str_contains($readSecurity("{$otherDrive}/folder"), "OWNER NT AUTHORITY\\SYSTEM"));
        }
        finally
        {
            Filesystem::deleteDir($otherDrive);
        }
    }
});

$smoke->run("Directory moves recreating symlinks on Windows", function () {
    # Windows can't create a relative symlink, so a Directory move there recreates one as absolute. That only runs
    # on Windows, so check the rule directly. The paths don't need to exist.
    $newTarget = new ReflectionMethod(Directory::class, "newSymlinkTarget");
    $old = "/srv/old";
    $new = "/srv/archive/old";

    # A relative link that still works after the move is left alone (it isn't recreated, so can stay relative).
    assert($newTarget->invoke(null, "{$old}/sub", "../file.txt", $old, $new, true) === "../file.txt");

    # One that needs a new target gets an absolute one on Windows, and a relative one elsewhere.
    assert($newTarget->invoke(null, $old, "../outside.txt", $old, $new, true) === "/srv/outside.txt");
    assert($newTarget->invoke(null, $old, "../outside.txt", $old, $new, false) === "../../outside.txt");

    # When copying to another drive, every link is recreated, so even one that still works becomes absolute.
    assert($newTarget->invoke(null, "{$old}/sub", "../file.txt", $old, $new, true, true) === "{$new}/file.txt");

    # Absolute links are handled as before: inside ones follow the move, and outside ones are left alone.
    assert($newTarget->invoke(null, $old, "{$old}/file.txt", $old, $new, true) === "{$new}/file.txt");
    assert($newTarget->invoke(null, $old, "/elsewhere/x", $old, $new, true) === "/elsewhere/x");
});

$smoke->run("Directory moves with absolute symlinks", function () use ($dir) {
    # Only absolute links to things that exist, which Windows can create too, so this also runs there.
    $base = realpath($dir) . "/absolute-links";
    mkdir("{$base}/project/sub", 0777, true);
    mkdir("{$base}/moved-here");
    file_put_contents("{$base}/project/sub/file.txt", "inside");
    file_put_contents("{$base}/outside.txt", "outside");
    symlink("{$base}/project/sub/file.txt", "{$base}/project/to-inside");
    symlink("{$base}/outside.txt", "{$base}/project/to-outside");

    # On the same drive, the link to inside follows the move, and the one to outside still works.
    $directory = new Directory("{$base}/project");
    $directory->move("{$base}/moved-here");
    $moved = "{$base}/moved-here/project";
    SmokeTest::assertSamePath(readlink("{$moved}/to-inside"), "{$moved}/sub/file.txt");
    assert(file_get_contents("{$moved}/to-inside") === "inside");
    assert(file_get_contents("{$moved}/to-outside") === "outside");

    # To another drive (D: on GitHub's Windows runners, or /dev/shm elsewhere), the links are recreated, and still
    # work.
    $otherParent = (PHP_OS_FAMILY === "Windows") ? "D:" : "/dev/shm";
    $otherDrive = "{$otherParent}/corelibs-links-" . getmypid();
    $parent = dirname($otherDrive);
    $canCopy = is_dir($parent) && is_writable($parent) && !Filesystem::isOnSameFilesystem($base, $parent);

    if (!$canCopy)
    {
        SmokeTest::skip("Directory moves with absolute symlinks to another drive", "needs a second drive");
    }
    else
    {
        mkdir($otherDrive);

        try
        {
            $directory->move($otherDrive, preserveOwnership: PreserveOwnershipEnum::PREFERRED);
            $copied = "{$otherDrive}/project";
            assert(is_link("{$copied}/to-inside") && file_get_contents("{$copied}/to-inside") === "inside");
            SmokeTest::assertSamePath(readlink("{$copied}/to-inside"), "{$copied}/sub/file.txt");
            assert(file_get_contents("{$copied}/to-outside") === "outside" && !file_exists($moved));
        }
        finally
        {
            Filesystem::deleteDir($otherDrive);
        }
    }
}, needsSymlinks: true, anySymlinks: false);

$smoke->run("Directory moves with relative symlinks, as on each system", function () use ($dir) {
    $base = realpath($dir) . "/relative-directory-links";
    mkdir("{$base}/project", 0777, true);
    mkdir("{$base}/archive");
    file_put_contents("{$base}/outside.txt", "outside");
    file_put_contents("{$base}/project/inside.txt", "inside");

    $canTest = SmokeTest::makeRelativeSymlink("../outside.txt", "{$base}/project/to-outside")
        && SmokeTest::makeRelativeSymlink("inside.txt", "{$base}/project/to-inside");

    if (!$canTest)
    {
        SmokeTest::skip("Directory moves with relative symlinks, as on each system", "relative symlinks can't be made");
    }
    else
    {
        # Moved one level deeper on the same drive, both links still lead to the right files. On Windows, they are
        # created again with absolute targets, as whether they were relative can't be told there.
        (new Directory("{$base}/project"))->move("{$base}/archive");
        $moved = "{$base}/archive/project";
        assert(file_get_contents("{$moved}/to-outside") === "outside");
        assert(file_get_contents("{$moved}/to-inside") === "inside");
        SmokeTest::assertSamePath(Filesystem::resolvePath("{$moved}/to-inside"), "{$moved}/inside.txt");
    }
}, needsSymlinks: true, anySymlinks: false);

$smoke->run("Directory moves removing a partial copy", function () use ($dir) {
    # A move to another filesystem checks everything can be copied first, so a copy only fails part way for a reason
    # that can't be seen beforehand. So copy straight away (without the checks) with a file that can't be read.
    if (SmokeTest::canTestPermissions("Directory moves removing a partial copy"))
    {
        $copyThenDelete = new ReflectionMethod(Directory::class, "copyThenDelete");
        $base = realpath($dir) . "/partial-copy";
        mkdir("{$base}/source/sub", 0777, true);
        file_put_contents("{$base}/source/a.txt", "a");
        file_put_contents("{$base}/source/sub/locked.txt", "locked");
        chmod("{$base}/source/sub/locked.txt", 0000);

        $copyWithUnreadableFile = function () use ($copyThenDelete, $base): void {
            $settings = ["overrideUmask" => false, "preserveOwnership" => PreserveOwnershipEnum::NO];
            $settings["listener"] = null;
            $settings["resumable"] = false;
            $copyThenDelete->invoke(null, "{$base}/source", "{$base}/copy", $settings, new MoveProgress(2, 7));
        };

        try
        {
            # The copy fails, what was copied so far is removed, and the original is left as it was.
            SmokeTest::expectException(FilesystemException::class, $copyWithUnreadableFile, "the original was left");
            assert(!file_exists("{$base}/copy"), "the partial copy was left behind");
            assert(file_get_contents("{$base}/source/a.txt") === "a" && file_exists("{$base}/source/sub/locked.txt"));
        }
        finally
        {
            chmod("{$base}/source/sub/locked.txt", 0644);
        }
    }
});

# A listener that records every event, for the tests below. It can cancel the move at the given onFileMoved() call.
$makeRecordingListener = function (int $cancelAtFile = 0): AbstractMoveListener {
    return new class($cancelAtFile) extends AbstractMoveListener {
        private int $m_cancelAtFile;
        private array $m_events = [];

        public function __construct(int $cancelAtFile)
        {
            $this->m_cancelAtFile = $cancelAtFile;
        }

        public function onMoveStarting(MoveProgress $progress): void
        {
            $this->m_events[] = ["starting", $progress];
        }

        public function onFileStarting(MoveProgress $progress, string $path, int $bytes): void
        {
            $this->m_events[] = ["file starting", $progress, basename($path), $bytes];
        }

        public function onBytesCopied(MoveProgress $progress, string $path): void
        {
            $this->m_events[] = ["bytes copied", $progress, basename($path)];

            # A negative number cancels part way through a file, at that file's first chunk.
            if (-$progress->getFilesMoved() - 1 === $this->m_cancelAtFile)
            {
                throw new MoveCancelledException("cancelled part way through a file");
            }
        }

        public function onFileMoved(MoveProgress $progress, string $path): void
        {
            $this->m_events[] = ["file moved", $progress, basename($path)];

            if ($progress->getFilesMoved() === $this->m_cancelAtFile)
            {
                throw new MoveCancelledException("cancelled by the test");
            }
        }

        public function onMoveFinished(MoveProgress $progress): void
        {
            $this->m_events[] = ["finished", $progress];
        }

        public function getEvents() : array { return $this->m_events; }
    };
};

$smoke->run("move listeners on the same filesystem", function () use ($dir, $makeRecordingListener) {
    # Moving within one filesystem renames everything at once, so there are only the start and the finish.
    $base = "{$dir}/listener-same";
    mkdir("{$base}/project/sub", 0777, true);
    mkdir("{$base}/archive");
    file_put_contents("{$base}/project/a.txt", "12345");
    file_put_contents("{$base}/project/sub/b.txt", "123");
    $listener = $makeRecordingListener();
    (new Directory("{$base}/project"))->move("{$base}/archive", listener: $listener);
    $events = $listener->getEvents();
    assert(count($events) === 2 && $events[0][0] === "starting" && $events[1][0] === "finished");
    assert($events[0][1]->getTotalFiles() === 2 && $events[0][1]->getTotalBytes() === 8);
    assert($events[0][1]->getFilesMoved() === 0 && $events[1][1]->getBytesMoved() === 8);
    assert($events[1][1]->getPercentMoved() === 100.0);

    # A File move has the one file's events too.
    $fileListener = $makeRecordingListener();
    (new File("{$base}/archive/project/a.txt"))->move("{$base}", listener: $fileListener);
    $fileEvents = $fileListener->getEvents();
    $names = array_column($fileEvents, 0);
    assert($names === ["starting", "file starting", "file moved", "finished"], implode(", ", $names));
    assert($fileEvents[1][2] === "a.txt" && $fileEvents[1][3] === 5 && $fileEvents[3][1]->getBytesMoved() === 5);

    # Filesystem::moveDir() and moveFile() pass the listener on.
    $shortcutListener = $makeRecordingListener();
    Filesystem::moveDir("{$base}/archive/project", "{$base}/project-again", listener: $shortcutListener);
    Filesystem::moveFile("{$base}/a.txt", "{$base}/project-again/a.txt", listener: $shortcutListener);
    $shortcutNames = array_column($shortcutListener->getEvents(), 0);
    $expectedNames = ["starting", "finished", "starting", "file starting", "file moved", "finished"];
    assert($shortcutNames === $expectedNames, implode(", ", $shortcutNames));
    Filesystem::moveDir("{$base}/project-again", "{$base}/archive/project");

    # Nothing is called if the path doesn't change.
    $unchangedListener = $makeRecordingListener();
    (new Directory("{$base}/archive/project"))->move("{$base}/archive", listener: $unchangedListener);
    assert($unchangedListener->getEvents() === []);
});

$smoke->run("move listeners and cancelling across filesystems", function () use ($dir, $makeRecordingListener) {
    $otherFilesystem = "/dev/shm/corelibs-listener-" . getmypid();
    $hasOtherFilesystem = is_dir("/dev/shm") && is_writable("/dev/shm");
    $hasOtherFilesystem = $hasOtherFilesystem && !Filesystem::isOnSameFilesystem("/dev/shm", realpath($dir));

    if (!$hasOtherFilesystem)
    {
        SmokeTest::skip("move listeners and cancelling across filesystems", "needs /dev/shm on another filesystem");
    }
    else
    {
        $base = realpath($dir) . "/listener-copy";
        mkdir("{$base}/project/sub", 0777, true);
        file_put_contents("{$base}/project/a.txt", "12345");
        file_put_contents("{$base}/project/sub/b.txt", "123");
        file_put_contents("{$base}/project/sub/c.txt", "1");
        symlink("a.txt", "{$base}/project/link");
        mkdir($otherFilesystem);
        $leftovers = function () use ($otherFilesystem): array {
            $hidden = array_merge(glob("{$otherFilesystem}/.corelibs-move-*"), glob(sys_get_temp_dir() . "/.corelibs-move-*"));
            return $hidden;
        };

        try
        {
            # Cancelling part way removes what was copied, leaves the original, and passes the exception on as it is.
            $cancelling = $makeRecordingListener(2);
            $directory = new Directory("{$base}/project");

            $moveAndCancel = function () use ($directory, $otherFilesystem, $cancelling): void {
                $directory->move($otherFilesystem, preserveOwnership: PreserveOwnershipEnum::PREFERRED, listener: $cancelling);
            };

            SmokeTest::expectException(MoveCancelledException::class, $moveAndCancel, "cancelled by the test");
            assert(!file_exists("{$otherFilesystem}/project"), "the cancelled copy was left behind");
            assert($leftovers() === [], "a hidden copy was left behind");
            assert(file_get_contents("{$base}/project/sub/c.txt") === "1" && is_link("{$base}/project/link"));

            # Without cancelling, every file and symlink is reported, and the totals add up.
            $listener = $makeRecordingListener();
            $directory->move($otherFilesystem, preserveOwnership: PreserveOwnershipEnum::PREFERRED, listener: $listener);
            $events = $listener->getEvents();
            $moved = array_values(array_filter($events, function (array $event): bool { return $event[0] === "file moved"; }));
            $movedNames = array_column($moved, 2);
            sort($movedNames);
            assert($movedNames === ["a.txt", "b.txt", "c.txt", "link"], implode(", ", $movedNames));
            assert($events[0][1]->getTotalFiles() === 4 && $events[0][1]->getTotalBytes() === 9);
            $last = end($events);
            assert($last[0] === "finished" && $last[1]->getFilesMoved() === 4 && $last[1]->getBytesMoved() === 9);
            assert(file_get_contents("{$otherFilesystem}/project/sub/b.txt") === "123" && !file_exists("{$base}/project"));
            assert(readlink("{$otherFilesystem}/project/link") === "a.txt" && $leftovers() === []);
        }
        finally
        {
            Filesystem::deleteDir($otherFilesystem);
        }
    }
});

$smoke->run("moves choosing where to copy to", function () use ($dir) {
    # The copy goes in the system's temporary directory if that is on the destination's filesystem, so renaming it
    # into place is instant, and otherwise beside the destination. Never both copying and then copying again.
    $choose = new ReflectionMethod(Directory::class, "chooseCopyDirectory");
    $systemTmp = rtrim(sys_get_temp_dir(), "/");
    $sameAsTmp = is_writable($systemTmp) && !Filesystem::isSelinuxEnabled() && PHP_OS_FAMILY !== "Windows";
    $expected = $sameAsTmp ? $systemTmp : realpath($dir);
    assert($choose->invoke(null, realpath($dir)) === $expected, "chose " . $choose->invoke(null, realpath($dir)));

    if (is_dir("/dev/shm") && !Filesystem::isOnSameFilesystem("/dev/shm", $systemTmp))
    {
        assert($choose->invoke(null, "/dev/shm") === "/dev/shm");
    }
});

$smoke->run("moves cleaning up when PHP stops part way", function () use ($dir) {
    # A timeout is a fatal error, after which PHP still runs shutdown functions. exit() does too, so a listener that
    # exits part way through a copy stands in for one here, in a separate PHP process.
    $otherFilesystem = "/dev/shm/corelibs-shutdown-" . getmypid();
    $canTest = function_exists("proc_open") && is_dir("/dev/shm") && is_writable("/dev/shm");
    $canTest = $canTest && !Filesystem::isOnSameFilesystem("/dev/shm", realpath($dir));

    if (!$canTest)
    {
        SmokeTest::skip("moves cleaning up when PHP stops part way", "needs /dev/shm on another filesystem");
    }
    else
    {
        $base = realpath($dir) . "/listener-shutdown";
        mkdir("{$base}/project", 0777, true);
        file_put_contents("{$base}/project/a.txt", "a");
        file_put_contents("{$base}/project/b.txt", "b");
        mkdir($otherFilesystem);
        $autoload = var_export(realpath(__DIR__ . "/../../vendor/autoload.php"), true);
        $source = var_export("{$base}/project", true);
        $destination = var_export($otherFilesystem, true);

        $script = "<?php require {$autoload};\n" .
            "use Programster\\CoreLibs\\Models\\{AbstractMoveListener, Directory, MoveProgress};\n" .
            "use Programster\\CoreLibs\\Enums\\PreserveOwnershipEnum;\n" .
            "\$stopper = new class extends AbstractMoveListener {\n" .
            "    public function onFileMoved(MoveProgress \$progress, string \$path): void { exit(3); }\n" .
            "};\n" .
            "(new Directory({$source}))->move({$destination}, preserveOwnership: PreserveOwnershipEnum::PREFERRED, " .
            "listener: \$stopper);\n";

        $scriptPath = "{$base}/stop-part-way.php";
        file_put_contents($scriptPath, $script);
        exec(escapeshellarg(PHP_BINARY) . " " . escapeshellarg($scriptPath) . " 2>&1", $output, $exitCode);

        try
        {
            assert($exitCode === 3, "the script didn't stop where expected: " . implode("\n", $output));
            $hidden = array_merge(glob("{$otherFilesystem}/.corelibs-move-*"), glob(sys_get_temp_dir() . "/.corelibs-move-*"));
            assert(!file_exists("{$otherFilesystem}/project"), "the folder holding the name was left behind");
            assert($hidden === [], "the partial copy was left behind: " . implode(", ", $hidden));
            assert(file_get_contents("{$base}/project/b.txt") === "b", "the original was changed");
        }
        finally
        {
            Filesystem::deleteDir($otherFilesystem);
        }
    }
});

$smoke->run("resumable moves", function () use ($dir, $makeRecordingListener) {
    $otherFilesystem = "/dev/shm/corelibs-resume-" . getmypid();
    $canTest = is_dir("/dev/shm") && is_writable("/dev/shm");
    $canTest = $canTest && !Filesystem::isOnSameFilesystem("/dev/shm", realpath($dir));

    if (!$canTest)
    {
        SmokeTest::skip("resumable moves", "needs /dev/shm on another filesystem");
    }
    else
    {
        $base = realpath($dir) . "/resumable";
        mkdir("{$base}/project/sub", 0777, true);
        file_put_contents("{$base}/project/a.txt", "aaa");
        file_put_contents("{$base}/project/b.txt", "bbb");
        file_put_contents("{$base}/project/sub/c.txt", "ccc");
        mkdir($otherFilesystem);
        $hiddenCopies = function () use ($otherFilesystem): array {
            return array_merge(glob("{$otherFilesystem}/.corelibs-move-*"), glob(sys_get_temp_dir() . "/.corelibs-move-*"));
        };

        try
        {
            # Cancelled part way, a resumable move keeps what it copied (and its state), but not the empty directory
            # that held the new name.
            $directory = new Directory("{$base}/project");
            $settings = ["preserveOwnership" => PreserveOwnershipEnum::PREFERRED, "resumable" => true];

            $moveAndCancel = function () use ($directory, $otherFilesystem, $settings, $makeRecordingListener): void {
                $directory->move($otherFilesystem, ...$settings, listener: $makeRecordingListener(2));
            };

            SmokeTest::expectException(MoveCancelledException::class, $moveAndCancel);
            assert(!file_exists("{$otherFilesystem}/project") && file_exists("{$base}/project/a.txt"));
            $kept = array_values(array_filter($hiddenCopies(), "is_dir"));
            assert(count($kept) === 1 && count($hiddenCopies()) === 2, "kept: " . implode(", ", $hiddenCopies()));
            $copied = Filesystem::getDirContents($kept[0], includePath: false);
            assert(count($copied) === 2, "expected 2 files copied before cancelling: " . implode(", ", $copied));

            # Before resuming, change one file, delete one, and add one.
            $copiedInodes = [];

            foreach (["a.txt", "b.txt", "sub/c.txt"] as $name)
            {
                $copiedInodes[$name] = file_exists("{$kept[0]}/{$name}") ? fileinode("{$kept[0]}/{$name}") : 0;
            }

            file_put_contents("{$base}/project/b.txt", "changed");
            touch("{$base}/project/b.txt", time() + 10);
            unlink("{$base}/project/a.txt");
            file_put_contents("{$base}/project/d.txt", "ddd");
            $directory->move($otherFilesystem, ...$settings);
            $moved = "{$otherFilesystem}/project";

            # It carries on: the result matches the original as it is now, and nothing hidden is left.
            assert(Filesystem::getDirContents($moved, includePath: false) !== []);
            $finalFiles = Filesystem::getDirContents($moved, includePath: false);
            sort($finalFiles);
            assert($finalFiles === ["b.txt", "c.txt", "d.txt"], "ended with: " . implode(", ", $finalFiles));
            assert(file_get_contents("{$moved}/b.txt") === "changed" && file_get_contents("{$moved}/sub/c.txt") === "ccc");
            assert($hiddenCopies() === [] && !file_exists("{$base}/project"));

            # A file that was already copied, and hadn't changed, wasn't copied again.
            if ($copiedInodes["sub/c.txt"] !== 0)
            {
                assert(fileinode("{$moved}/sub/c.txt") === $copiedInodes["sub/c.txt"], "c.txt was copied again");
            }
        }
        finally
        {
            Filesystem::deleteDir($otherFilesystem);
        }
    }
});

$smoke->run("resumable moves after PHP is killed", function () use ($dir) {
    # When PHP is killed, nothing gets to clean up, so the empty directory holding the new name is left too. Resuming
    # accepts that one, as it is the same directory (by its inode), but not an empty directory someone else made.
    $otherFilesystem = "/dev/shm/corelibs-killed-" . getmypid();
    $canTest = function_exists("posix_kill") && is_dir("/dev/shm") && is_writable("/dev/shm");
    $canTest = $canTest && !Filesystem::isOnSameFilesystem("/dev/shm", realpath($dir));

    if (!$canTest)
    {
        SmokeTest::skip("resumable moves after PHP is killed", "needs posix_kill(), and /dev/shm on another filesystem");
    }
    else
    {
        $base = realpath($dir) . "/resumable-killed";
        mkdir("{$base}/project", 0777, true);
        file_put_contents("{$base}/project/a.txt", "a");
        file_put_contents("{$base}/project/b.txt", "b");
        mkdir($otherFilesystem);
        $autoload = var_export(realpath(__DIR__ . "/../../vendor/autoload.php"), true);
        $source = var_export("{$base}/project", true);
        $destination = var_export($otherFilesystem, true);

        # Kills itself (with SIGKILL, which can't be caught) after the first file is copied.
        $script = "<?php require {$autoload};\n" .
            "use Programster\\CoreLibs\\Models\\{AbstractMoveListener, Directory, MoveProgress};\n" .
            "use Programster\\CoreLibs\\Enums\\PreserveOwnershipEnum;\n" .
            "\$killer = new class extends AbstractMoveListener {\n" .
            "    public function onFileMoved(MoveProgress \$progress, string \$path): void { posix_kill(getmypid(), 9); }\n" .
            "};\n" .
            "(new Directory({$source}))->move({$destination}, preserveOwnership: PreserveOwnershipEnum::PREFERRED, " .
            "listener: \$killer, resumable: true);\n";

        $scriptPath = "{$base}/killed-part-way.php";
        file_put_contents($scriptPath, $script);
        $runKilledMove = function () use ($scriptPath): void {
            exec(escapeshellarg(PHP_BINARY) . " " . escapeshellarg($scriptPath) . " 2>&1");
        };

        try
        {
            $runKilledMove();
            assert(is_dir("{$otherFilesystem}/project"), "the empty directory holding the name wasn't left behind");

            # Someone else's empty directory there is refused, even though it looks the same.
            rmdir("{$otherFilesystem}/project");
            mkdir("{$otherFilesystem}/project");
            $directory = new Directory("{$base}/project");

            $resume = function () use ($directory, $otherFilesystem): void {
                $directory->move($otherFilesystem, preserveOwnership: PreserveOwnershipEnum::PREFERRED, resumable: true);
            };

            SmokeTest::expectException(FileAlreadyExistsException::class, $resume);

            # The one the earlier attempt made is accepted, and the move carries on.
            rmdir("{$otherFilesystem}/project");
            $runKilledMove();
            $resume();
            assert(file_get_contents("{$otherFilesystem}/project/b.txt") === "b" && !file_exists("{$base}/project"));
        }
        finally
        {
            Filesystem::deleteDir($otherFilesystem);
        }
    }
});

$smoke->run("move listeners during a big file", function () use ($dir, $makeRecordingListener) {
    # With a listener, a file is copied in 8 MiB chunks, so a 9 MiB one gives one onBytesCopied() part way through.
    $otherFilesystem = "/dev/shm/corelibs-chunks-" . getmypid();
    $canTest = is_dir("/dev/shm") && is_writable("/dev/shm");
    $canTest = $canTest && !Filesystem::isOnSameFilesystem("/dev/shm", realpath($dir));

    if (!$canTest)
    {
        SmokeTest::skip("move listeners during a big file", "needs /dev/shm on another filesystem");
    }
    else
    {
        $base = realpath($dir) . "/chunks";
        $megabyte = 1024 * 1024;
        mkdir("{$base}/project", 0777, true);
        file_put_contents("{$base}/project/big.bin", str_repeat("x", 9 * $megabyte));
        mkdir($otherFilesystem);
        $ownership = PreserveOwnershipEnum::PREFERRED;

        try
        {
            # A File move, cancelled part way through the file, leaves the original, and no partial copy.
            $file = new File("{$base}/project/big.bin");
            $cancelling = $makeRecordingListener(-1);

            $moveAndCancel = function () use ($file, $otherFilesystem, $ownership, $cancelling): void {
                $file->move($otherFilesystem, preserveOwnership: $ownership, listener: $cancelling);
            };

            SmokeTest::expectException(MoveCancelledException::class, $moveAndCancel, "part way through a file");
            assert(filesize("{$base}/project/big.bin") === 9 * $megabyte && !file_exists("{$otherFilesystem}/big.bin"));
            $hidden = array_merge(glob("{$otherFilesystem}/.corelibs-move-*"), glob(sys_get_temp_dir() . "/.corelibs-move-*"));
            assert($hidden === [], "a partial copy was left: " . implode(", ", $hidden));

            # A Directory move reports the chunk, then the whole file.
            $listener = $makeRecordingListener();
            (new Directory("{$base}/project"))->move($otherFilesystem, preserveOwnership: $ownership, listener: $listener);
            $events = $listener->getEvents();
            $names = array_column($events, 0);
            assert($names === ["starting", "file starting", "bytes copied", "file moved", "finished"], implode(", ", $names));
            assert($events[2][1]->getBytesMoved() === 8 * $megabyte && $events[2][1]->getFilesMoved() === 0);
            assert($events[3][1]->getBytesMoved() === 9 * $megabyte && $events[3][1]->getFilesMoved() === 1);
            assert(filesize("{$otherFilesystem}/project/big.bin") === 9 * $megabyte);
        }
        finally
        {
            Filesystem::deleteDir($otherFilesystem);
        }
    }
});

$smoke->run("Directory rename() taking only a name, and move() a new name", function () use ($dir) {
    $base = "{$dir}/rename-names";
    mkdir("{$base}/project", 0777, true);
    mkdir("{$base}/archive/project", 0777, true);
    $directory = new Directory("{$base}/project");

    # rename() only changes the name, in the same parent directory.
    foreach (["archive/project-2", "../project-2", "{$base}/project-2", ".", "..", ""] as $notAName)
    {
        $renameToPath = function () use ($directory, $notAName): void {
            $directory->rename($notAName);
        };

        SmokeTest::expectException(BadArgumentException::class, $renameToPath, "must be just a new name");
    }

    $directory->rename("project-2");
    assert(is_dir("{$base}/project-2") && !file_exists("{$base}/project"));

    # move() can give it a new name in the destination, e.g. when that already has something with its name.
    $directory->rename("project");
    $directory->move("{$base}/archive", newName: "project-2");
    assert(is_dir("{$base}/archive/project-2") && is_dir("{$base}/archive/project"));
});

