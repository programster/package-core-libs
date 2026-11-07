<?php

/*
 * A small test runner for the smoke tests in testing/tests/. Each test is a named callback, which
 * fails if it throws (including a failed assert()) or raises a warning, notice or deprecation.
 */

namespace Programster\CoreLibs\Testing;


final class SmokeTest
{
    private int $m_numPassed = 0;
    private array $m_failures = [];
    private array $m_warnings = [];

    # The user and group ID that giveToOtherUser() gives files to. It doesn't need to exist.
    public const OTHER_USER_ID = 12345;

    # Checks that couldn't be run in this environment (e.g. ones that need root), so they can be reported.
    private static array $s_skipped = [];

    # Whether each kind of symlink can be created here, found the first time it is needed (see canCreateSymlinks()).
    private static array $s_canCreateSymlinks = [];


    public function __construct()
    {
        error_reporting(-1);

        set_error_handler(function (int $errorNumber, string $message, string $file, int $line): bool {
            # Respect the @ operator, as the library deliberately uses it in a few places.
            if (error_reporting() & $errorNumber)
            {
                $this->m_warnings[] = "[E{$errorNumber}] {$message} @ " . basename($file) . ":{$line}";
            }

            return true;
        });
    }


    /**
     * Run one test, recording it as failed if it throws, or raises any warnings/deprecations.
     * @param string $label - the name of the test, shown if it fails.
     * @param callable $test - the test to run.
     * @param bool $needsSymlinks - true if the whole test is about symlinks, so it is skipped (and
     * reported as skipped) where they can't be created, e.g. on Windows without admin rights.
     * @param bool $anySymlinks - with $needsSymlinks, true (the default) if it needs relative and
     * broken symlinks too, or false if absolute ones to things that exist are enough (see
     * canCreateSymlinks()).
     * @return void
     */
    public function run(string $label, callable $test, bool $needsSymlinks = false, bool $anySymlinks = true): void
    {
        $numWarningsBefore = count($this->m_warnings);
        $error = "";
        $shouldRun = !$needsSymlinks || self::canCreateSymlinks($label, $anySymlinks);

        try
        {
            if ($shouldRun)
            {
                $test();
            }
        }
        catch (\Throwable $e)
        {
            $error = get_class($e) . ": " . $e->getMessage() . " @ " . basename($e->getFile()) . ":" . $e->getLine();
        }

        $newWarnings = array_slice($this->m_warnings, $numWarningsBefore);

        if (!$shouldRun)
        {
            # Recorded as skipped by canCreateSymlinks(), so neither passed nor failed.
        }
        elseif ($error === "" && count($newWarnings) === 0)
        {
            $this->m_numPassed++;
        }
        else
        {
            $this->m_failures[] = trim("{$label}: {$error} " . implode(" | ", $newWarnings));
        }
    }


    /**
     * Assert that a callback throws a specific type of exception.
     * @param string $exceptionClass - the class of exception that should be thrown.
     * @param callable $callback - the code that should throw it.
     * @param string $messageContains - optionally, text the exception's message must contain, for
     * when the same class of exception can be thrown for different reasons.
     * @return void
     * @throws \Throwable - if the callback throws a different exception, it is rethrown.
     * @throws \Exception - if the callback doesn't throw at all, or the message doesn't contain
     * $messageContains.
     */
    public static function expectException(
        string $exceptionClass,
        callable $callback,
        string $messageContains = ""
    ): void
    {
        $threwExpectedException = false;

        try
        {
            $callback();
        }
        catch (\Throwable $e)
        {
            if (!($e instanceof $exceptionClass))
            {
                throw $e;
            }

            if (!str_contains($e->getMessage(), $messageContains))
            {
                throw new \Exception(
                    "Expected the {$exceptionClass} message to contain \"{$messageContains}\", but it was: " .
                    $e->getMessage()
                );
            }

            $threwExpectedException = true;
        }

        if (!$threwExpectedException)
        {
            throw new \Exception("Expected {$exceptionClass} to be thrown, but nothing was thrown.");
        }
    }


    /**
     * Create a symlink with a relative target, for tests of how the library handles one. PHP can't
     * create a relative symlink on Windows, but Windows' own mklink can, so that is used there.
     * @param string $target - the relative target, e.g. "../data.txt".
     * @param string $linkPath - where to create the link.
     * @return bool - true if it was created.
     */
    public static function makeRelativeSymlink(string $target, string $linkPath): bool
    {
        $made = false;

        if (PHP_OS_FAMILY === "Windows")
        {
            $windowsTarget = escapeshellarg(str_replace("/", "\\", $target));
            $windowsLinkPath = escapeshellarg(str_replace("/", "\\", $linkPath));
            $command = "mklink {$windowsLinkPath} {$windowsTarget}";
            exec("cmd /c {$command} 2>NUL", $output, $exitCode);
            $made = ($exitCode === 0);
        }
        else
        {
            $made = @symlink($target, $linkPath);
        }

        return $made;
    }


    /**
     * Give a file to another user and group (OTHER_USER_ID), so that a test can check the library
     * doesn't change a file's owner. Only root can do this, which the Docker test runs always are,
     * and Windows can't at all, as it has no Unix owners. Otherwise, this records the check as
     * skipped, with the reason, so that main.php can report it, rather than it passing without
     * checking anything.
     * @param string $filepath - the file to give to the other user.
     * @param string $checkName - what the ownership check is for, to show if it is skipped.
     * @return bool - true if the file now belongs to the other user and group, so the check can be
     * run.
     */
    public static function giveToOtherUser(string $filepath, string $checkName): bool
    {
        # Suppress the warnings, as failing just means we aren't root.
        $given = @chown($filepath, self::OTHER_USER_ID) && @chgrp($filepath, self::OTHER_USER_ID);
        clearstatcache();

        if (!$given)
        {
            # Windows has no Unix owners, so these checks can't run there at all. Elsewhere, they need root.
            $reason = match (PHP_OS_FAMILY) {
                "Windows" => "Windows doesn't have Unix owners and groups",
                "Linux" => "needs root, so is covered by the root run in run-all-php-versions.sh",
                default => "needs root, which the root run in run-all-php-versions.sh has, though only on Linux",
            };

            self::$s_skipped[] = "{$checkName} keeps the file's owner ({$reason})";
        }

        return $given;
    }


    /**
     * Record a check as skipped, because something it needs isn't available here (e.g. something only the Docker test
     * runs set up), so that main.php can report it, rather than it passing without checking anything.
     * @param string $checkName - what the check is for.
     * @param string $reason - what it needs, e.g. "needs the Docker test runs".
     * @return void
     */
    public static function skip(string $checkName, string $reason): void
    {
        self::$s_skipped[] = "{$checkName} ({$reason})";
    }


    /**
     * Check whether permission checks can be tested, which needs a normal user, as root can read,
     * write and delete anything regardless of permissions. If running as root (e.g. the first
     * Docker test run), this records the check as skipped, so that main.php can report it.
     * run-in-container.sh also runs the tests as a normal user, so these checks still run in
     * Docker.
     * @param string $checkName - what the permission check is for, to show if it is skipped.
     * @return bool - true if not running as root, so the check can be run.
     */
    public static function canTestPermissions(string $checkName): bool
    {
        # Windows has no Unix permissions to test. (Its read-only attribute would also stop the probe below being
        # deleted.)
        if (PHP_OS_FAMILY === "Windows")
        {
            $isRoot = false;
        }
        elseif (function_exists('posix_geteuid'))
        {
            $isRoot = posix_geteuid() === 0;
        }
        else
        {
            $probe = tempnam(sys_get_temp_dir(), "corelibs-root-check-");
            chmod($probe, 0444);
            $isRoot = is_writable($probe);
            unlink($probe);
        }

        if (PHP_OS_FAMILY === "Windows")
        {
            self::$s_skipped[] = "{$checkName} (needs Unix permissions, which Windows doesn't have)";
        }
        elseif ($isRoot)
        {
            self::$s_skipped[] = "{$checkName} (needs a normal user, as root ignores permissions, so is covered by " .
                "the normal-user run in run-all-php-versions.sh)";
        }

        return !$isRoot && PHP_OS_FAMILY !== "Windows";
    }


    /**
     * Assert that a file belongs to the other user and group that giveToOtherUser() gave it to.
     * @param string $filepath - the file to check.
     * @param string $checkName - what the ownership check is for, to show if it fails.
     * @return void
     */
    public static function assertOwnedByOtherUser(string $filepath, string $checkName): void
    {
        clearstatcache();

        assert(
            fileowner($filepath) === self::OTHER_USER_ID && filegroup($filepath) === self::OTHER_USER_ID,
            "{$checkName} changed the file's owner"
        );
    }


    /**
     * Assert a file or directory's Unix permissions, or record the check as skipped on Windows,
     * which doesn't have them.
     * @param string $path - the file or directory.
     * @param int $permissions - the permissions it should have, e.g. 0640. The setuid, setgid and
     * sticky bits are only compared if this includes any of them (e.g. 02755).
     * @param string $message - optionally, what to say if it fails.
     * @return void
     */
    public static function assertPermissions(string $path, int $permissions, string $message = ""): void
    {
        if (PHP_OS_FAMILY === "Windows")
        {
            self::$s_skipped[] = "permission checks (Windows doesn't have Unix permissions)";
        }
        else
        {
            clearstatcache(true, $path);
            # Only compare the setuid, setgid and sticky bits if the expected permissions include any.
            $mask = ($permissions > 0777) ? 07777 : 0777;
            $actual = fileperms($path) & $mask;
            $description = ($message !== "") ? $message : "{$path} has permissions " . decoct($actual);
            assert($actual === $permissions, $description . ", not " . decoct($permissions));
        }
    }


    /**
     * Assert that two paths lead to the same place, comparing them after resolving them, so that
     * differences that don't matter (e.g. Windows' short names such as "RUNNER~1", or "\\" and "/")
     * don't fail the check.
     * @param string $actual - the path being checked.
     * @param string $expected - the path it should be.
     * @param string $message - optionally, what to say if it fails.
     * @return void
     */
    public static function assertSamePath(string $actual, string $expected, string $message = ""): void
    {
        $resolvedActual = \Programster\CoreLibs\Filesystem::resolvePath($actual);
        $resolvedExpected = \Programster\CoreLibs\Filesystem::resolvePath($expected);
        $description = ($message !== "") ? $message : "{$actual} isn't {$expected}";
        assert($resolvedActual === $resolvedExpected, $description);
    }


    /**
     * Check whether symlinks can be created here. If not, record the check as skipped, so that
     * main.php can report it. On Windows, symlinks need admin rights or developer mode, and even
     * then, PHP can only create one whose target exists, and resolves a relative target from the
     * current directory rather than the link's folder.
     * @param string $checkName - what the check is for, to show if it is skipped.
     * @param bool $anyKind - true (the default) to need any kind of symlink, including relative
     * and broken ones, as most symlink tests make. False to only need an absolute symlink to
     * something that exists.
     * @return bool - true if symlinks of that kind can be created.
     */
    public static function canCreateSymlinks(string $checkName, bool $anyKind = true): bool
    {
        $kind = $anyKind ? "any" : "absolute";

        if (!isset(self::$s_canCreateSymlinks[$kind]))
        {
            $probeDirectory = sys_get_temp_dir() . "/corelibs-symlink-check-" . getmypid();
            @mkdir($probeDirectory);

            # A relative link to something that doesn't exist, or an absolute link to something that does.
            $target = $anyKind ? "missing-target" : __FILE__;
            $created = @symlink($target, "{$probeDirectory}/link");
            self::$s_canCreateSymlinks[$kind] = $created && @readlink("{$probeDirectory}/link") === $target;

            if ($created)
            {
                @unlink("{$probeDirectory}/link");
            }

            @rmdir($probeDirectory);
        }

        if (!self::$s_canCreateSymlinks[$kind])
        {
            $needs = $anyKind ? "relative and broken symlinks" : "symlinks";
            self::$s_skipped[] = "{$checkName} (needs {$needs}, which can't be created here)";
        }

        return self::$s_canCreateSymlinks[$kind];
    }


    /**
     * Get the start of the name tempnam() gives a file with a prefix. Windows only uses the first
     * three characters of the prefix (e.g. "corelibs-tmp-" gives "cor1A2B.tmp").
     * @param string $prefix - the prefix given to tempnam().
     * @return string - what the file's name starts with.
     */
    public static function tempnamPrefix(string $prefix): string
    {
        return (PHP_OS_FAMILY === "Windows") ? substr($prefix, 0, 3) : $prefix;
    }


    /**
     * Get the path of the system's null device, which discards anything written to it.
     * @return string - "NUL" on Windows, or "/dev/null" elsewhere.
     */
    public static function nullDevice(): string
    {
        return (PHP_OS_FAMILY === "Windows") ? "NUL" : "/dev/null";
    }


    # Accessors
    public function getNumPassed(): int { return $this->m_numPassed; }
    public function getFailures(): array { return $this->m_failures; }
    public static function getSkipped(): array { return array_values(array_unique(self::$s_skipped)); }
}
