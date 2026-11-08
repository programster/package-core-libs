# Supported platforms

- [Linux, macOS and Windows](#linux-macos-and-windows)
- [WSL](#wsl)
- [Docker](#docker)

## Linux, macOS and Windows
Linux has first-class support: it is what this library is built and tested on, and every test runs on it automatically.
Windows and macOS are supported too, and their tests also run automatically (see the "Windows tests" and "macOS tests"
badges in the [README](../README.md)), but a few things work differently, or not at all, there:

| Method | On macOS | On Windows |
|---|---|---|
| `Core::getMemory()` | Only `TOTAL`, `AVAILABLE`, `FREE`, `USED` and the three `SWAP_` figures. The others throw `UnsupportedPlatformException`. `AVAILABLE` is worked out from `vm_stat`, so is an approximation | The same seven figures. `FREE` is the same as `AVAILABLE`, as Windows doesn't report them separately |
| `Core::getNumProcRunning()` | As on Linux | Matches window titles, as that is what `Core::setCliTitle()` sets on Windows |
| `Core::setClipboard()`, `getClipboard()` | Works, but untested | Works, but untested |
| `CliLib::getPasswordFromUserInput()` | As on Linux | Typing is hidden using PowerShell. Untested on a real console |
| `File` and `Directory` moves | As on Linux | A move to another drive copies each file's owner and permissions (ACLs) to its copy, using PowerShell. Setting another user as the owner needs administrator rights, so with `PreserveOwnershipEnum::YES` (the default), a move that can't keep every owner is undone (the original is left as it was). Pass `PREFERRED` to keep what it can, or `NO` |
| Anything that creates symlinks (`unzip()`, `Directory` moves, `File` moves of a symlink) | As on Linux | Needs admin rights or developer mode, as on any Windows program. PHP can't create a relative or broken symlink there, so `unzip()` creates absolute ones (or copies, with `$convertSymlinksOnWindows`), and refuses a zip with a broken one. PHP's `readlink()` there gives a link's resolved absolute path, so relative links can't be told apart: `Directory` and `File` moves create each symlink they move again with an absolute target. A broken one is left as it is when a `Directory` is moved on the same drive, and refused otherwise |
| Permissions and ownership (e.g. `Filesystem::mkdir()`'s `$permissions`, `fileperms()`) | As on Linux | Windows has no Unix permissions or owners, so these are mostly ignored. Only the read-only setting has an effect |
| `Filesystem::isTmpfs()` | Reads the `mount` command's output | Always false, as Windows has no tmpfs |

Each method's docblock gives the details.

Paths on Windows can use `\` or `/`. The path helpers (e.g. `Filesystem::normalisePath()`) and the directory listings
give paths with `/`, which Windows also accepts, so that paths from `realpath()` and ones you build come out the same.
Drive letters (`C:\data`) and UNC paths (`\\server\share`) are understood as absolute.

## WSL
Inside WSL, PHP is running on Linux, so this library behaves as it does on Linux. The "WSL tests" badge shows the
tests run in WSL's own filesystem, and PHP on Windows working with WSL's files through `\\wsl.localhost`, in both
WSL 1 and WSL 2. Two places behave differently, because they are really the other system's files:

| Where | What to expect |
|---|---|
| Windows drives seen from WSL (e.g. `/mnt/c`) | Unless the drive is mounted with the `metadata` option, Unix permissions and owners aren't stored there: `chmod()` and `chown()` have no lasting effect, and every file appears to belong to you. So checks that a copy kept its exact permissions (`$overrideUmask` on `File` and `Directory` moves) can fail there, and the moves that need them are refused. Moving files between there and WSL's own filesystem copies them, as they are different filesystems |
| WSL's files seen from Windows (e.g. `\\wsl.localhost\Ubuntu\home\...`) | PHP on Windows treats these as a network share on another drive, so moves between them and `C:` copy rather than rename, keeping owners and permissions where Windows can set them there (see the table above). Symlinks made inside WSL may not show as symlinks from Windows |

For the most predictable results, run PHP on the same side as the files: in WSL for files in WSL's filesystem, and on
Windows for files on Windows drives.

## Docker
In a container, `Core::getMemory()` reports the host's memory, as `/proc/meminfo` does, not any memory limit set on the
container. `Core::getNumProcessors()` uses `nproc`, which counts the processors the container may run on, so it
reflects `--cpuset-cpus`, but not a `--cpus` limit. Check the container's cgroup files (e.g. `/sys/fs/cgroup/memory.max`
and `/sys/fs/cgroup/cpu.max`) if you need the limits.
