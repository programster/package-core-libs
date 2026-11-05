<?php

/*
 * Library just for filesystem operations.
 */

namespace Programster\CoreLibs;


use Programster\CoreLibs\Exceptions\ExceptionBadFilePermissions;
use Programster\CoreLibs\Exceptions\ExceptionCommandFailed;
use Programster\CoreLibs\Exceptions\ExceptionFileAlreadyExists;
use Programster\CoreLibs\Exceptions\ExceptionFileDoesNotExist;
use Programster\CoreLibs\Exceptions\ExceptionMissingExtension;
use Programster\CoreLibs\Exceptions\ExceptionFilesystem;
use ZipArchive;

final class Filesystem
{
    private static array $s_fileLocks = [];


    /**
     * Retrieves a list of all the directories within the specified directory
     * It does not include the .. directory (dot directories)
     * This will NOT return any files
     * @param string $path - the path to the directory that we want to search.
     * @param bool $recursive - whether to recursively loop through the
     *                          directories to find more.
     * @param bool $includePath - set to true to include the full path to the
     *                            dir, not just the dir name.
     * @return array<string> - list of directories within the specified path.
     */
    public static function getDirectories(
        string $path,
        bool $recursive = false,
        bool $includePath = true
    ): array
    {
        $directories = array();
        $fpath     = realpath($path);
        $handle    = opendir($path);

        if ($handle)
        {
            while (false !== ($filename = readdir($handle)))
            {
                if (strcmp($filename, "..") != 0 &&
                    strcmp($filename, ".") != 0)
                {
                    if (is_dir($fpath . "/" . $filename))
                    {
                        if ($includePath)
                        {
                            $directories[] = $fpath . "/" . $filename;
                        }
                        else
                        {
                            $directories[] = $filename;
                        }

                        if ($recursive)
                        {
                            $subFilepath = $fpath . "/" . $filename;

                            $subFiles = self::getDirectories($subFilepath,
                                                             $recursive,
                                                             $includePath);

                            $directories = array_merge($directories, $subFiles);
                        }
                    }
                }
            }

            closedir($handle);
        }

        return $directories;
    }


    /**
     * Retrieves an array list of files/folders within the specified directory.
     *   Consider using the following instead:
     *   $directory = new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::CURRENT_AS_SELF);
     *   $iterator = new RecursiveIteratorIterator($directory, RecursiveIteratorIterator::CHILD_FIRST);
     *
     * @param string $dir - the path to the directory you wish to find the contents of.
     * @param bool $recursive - whether we go through into each subfolder and retrieve its contents.
     * @param bool $includePath - whether we output the path to the entry such as '/folder/text.txt'
     *                            instead of just 'text.txt'
     * @param bool $onlyFiles - whether we include the directory itself in the returned list
     * @param bool $includeHiddenFilesAndFolders - if false, then this will not include
     *                                          hidden files and folders (those starting with '.')
     * @return array - the names of all the files/folders within the directory.
     */
    public static function getDirContents(
        string $dir,
        bool $recursive   = true,
        bool $includePath = true,
        bool $onlyFiles   = true,
        bool $includeHiddenFilesAndFolders = true
    ): array
    {
        $fileNames = array();
        $fpath     = realpath($dir);
        $handle    = opendir($dir);

        if ($handle)
        {
            while (false !== ($fileName = readdir($handle)))
            {
                if ($includeHiddenFilesAndFolders === false && StringLib::startsWith($fileName, "."))
                {
                    continue;
                }

                if (strcmp($fileName, "..") != 0 && strcmp($fileName, ".") != 0)
                {
                    if (is_dir($fpath . "/" . $fileName))
                    {
                        if (!$onlyFiles)
                        {
                            if ($includePath)
                            {
                                $fileNames[] = $fpath . "/" . $fileName;
                            }
                            else
                            {
                                $fileNames[] = $fileName;
                            }
                        }

                        if ($recursive)
                        {
                            $subFilePath = $fpath . "/" . $fileName;

                            $subFiles = self::getDirContents(
                                $subFilePath,
                                $recursive,
                                $includePath,
                                $onlyFiles,
                                $includeHiddenFilesAndFolders
                            );

                            $fileNames = array_merge($fileNames, $subFiles);
                        }
                    }
                    else
                    {
                        if ($includePath)
                        {
                            $fileNames[] = $fpath . "/" . $fileName;
                        }
                        else
                        {
                            $fileNames[] = $fileName;
                        }
                    }
                }
            }

            closedir($handle);
        }

        return $fileNames;
    }


    /**
     * Wrapper around the mkdir that will only execute it if the directory
     * doesn't already exist. Also, this defaults to being recursive so that it
     * will create all relevant parent directories
     *
     * @param string $dirPath - the path to the directory we wish to create
     * @param string|null $perms - optionally set the permissions to set for the directory. E.g. "0700" or "755". If
     * not provided, then this will default to "0755".
     * @param bool $recursive - override to false if you want to fail if parent
     *                          dirs don't exist.
     *
     * @return void
     * @throws ExceptionFilesystem - if the directory doesn't exist and could not be created.
     */
    public static function mkdir(
        string $dirPath,
        ?string $perms = null,
        bool $recursive = true
    ): void
    {
        $perms = $perms ?? "0755";
        $perms = octdec($perms);

        # Suppress the warning, as we throw an exception with the details instead. The second is_dir() check is in
        # case another process created the directory at the same time.
        if (!is_dir($dirPath) && !@mkdir($dirPath, $perms, $recursive) && !is_dir($dirPath))
        {
            $error = error_get_last()['message'] ?? 'unknown error';
            throw new ExceptionFilesystem("Failed to create the directory {$dirPath}: {$error}");
        }
    }


    /**
     * Deletes a directory even if it is not already empty. This resolves the
     *  issue with trying to use unlink on a non-empty dir.
     * @param string $dir - the path to the directory you wish to delete
     * @return void
     * @throws ExceptionFileDoesNotExist - if a directory does not exist at the path specified.
     */
    public static function deleteDir(string $dir): void
    {
        Filesystem::emptyDir($dir);
        rmdir($dir);
    }


    /**
     * Deletes all files from a directory, but leaves the directory in place.
     * @param string $dir - the path to the directory one wishes to empty.
     * @return void
     * @throws ExceptionFileDoesNotExist - if a directory does not exist at the path specified.
     */
    public static function emptyDir(string $dir): void
    {
        if (!is_dir($dir))
        {
            throw new ExceptionFileDoesNotExist("There is no directory at {$dir}.");
        }

        $objects = scandir($dir);

        foreach ($objects as $object)
        {
            if ($object != "." && $object != "..")
            {
                if (filetype($dir . "/" . $object) == "dir")
                {
                    self::deleteDir($dir . "/" . $object);
                }
                else
                {
                    unlink($dir . "/" . $object);
                }
            }
        }
    }


    /**
     * Lock a file so that only we can use it.
     * If $isBlocking is false, this returns false immediately if the lock can't be obtained. If true, it waits until
     * the lock can be obtained.
     * Note that this only allows other processes to see that you have locked it and does not
     * actually guarantee that it cannot be edited at the same time on Linux.
     * @param string $filePath - the full path to the file that we wish to lock
     * @param bool $isWriteLock - if false this will get a shared read lock, if true this
     *                               will get an exclusive write lock.
     * @param bool $isBlocking - if true will wait to get lock.
     * @return bool - true if we managed to lock the file, false otherwise.
     */
    public static function lockFile(string $filePath, bool $isWriteLock, bool $isBlocking): bool
    {
        # This creates the file if it doesn't exist.
        # This MUST be a+ instead of w or w+ as using w will result in the file being wiped.
        $fileHandle = fopen($filePath, 'a+');

        if ($fileHandle === false)
        {
            throw new ExceptionFilesystem("Failed to open {$filePath} in order to lock it.");
        }

        self::$s_fileLocks[$filePath] = $fileHandle;
        $lockParams = 0;

        if ($isWriteLock)
        {
            $lockParams =  $lockParams | LOCK_EX;
        }
        else
        {
            # read lock = shared lock
            $lockParams = $lockParams | LOCK_SH;
        }

        if (!$isBlocking)
        {
            $lockParams = $lockParams | LOCK_NB;
        }

        return flock(self::$s_fileLocks[$filePath], $lockParams);
    }


    /**
     * Unlock a file so that others may use it.
     * @param string $filePath - the full path to the file that we wish to unlock
     * @return void
     */
    public static function unlockFile(string $filePath): void
    {
        if (isset(self::$s_fileLocks[$filePath]))
        {
            fclose(self::$s_fileLocks[$filePath]);
            unset(self::$s_fileLocks[$filePath]);
        }
    }


    /**
     * Fetch the total size of a directory or file, including all
     * its subdirectories. This is the "apparent size" (the total of the file sizes),
     * rather than the space taken up on disk, which is rounded up to the filesystem's block size.
     * This only works on Linux, not Windows.
     * src: http://stackoverflow.com/questions/478121/php-get-directory-size
     * @param string $dirPath - the path to the directory we wish to get the
     *                          size of
     * @param DataSizeUnitEnum $unit - optionally specify the unit to return the size in. Defaults to bytes.
     * @return float - the size of the directory and all its contents, in the specified unit. E.g. 1.5 for 1536 bytes
     *                 in KiB.
     * @throws ExceptionCommandFailed - if the du command failed, such as if there is nothing at $dirPath.
     * @throws \Exception - if the output of du was not in the expected format.
     */
    public static function getDirSize(string $dirPath, DataSizeUnitEnum $unit = DataSizeUnitEnum::BYTES): float
    {
        # Output looks like: "4096\t/path/to/dir"
        $output = Core::runCommand('/usr/bin/du --bytes --summarize ' . escapeshellarg($dirPath));
        $size = explode("\t", $output)[0];

        if (!ctype_digit($size))
        {
            throw new \Exception("getDirSize: unexpected output from du: {$output}");
        }

        return $unit->convertFromBytes((int) $size);
    }


    /**
     * Fetch the size of a file. Unlike PHP's filesize(), this throws an exception rather than returning false if it
     * fails, and it always gets the file's current size, rather than one PHP may have cached earlier in the script.
     * @param string $filepath - the path to the file to get the size of.
     * @param DataSizeUnitEnum $unit - optionally specify the unit to return the size in. Defaults to bytes.
     * @return float - the size of the file in the specified unit. E.g. 1.5 for a 1536 byte file in KiB.
     * @throws ExceptionFileDoesNotExist - if there is no file at $filepath, or it is a directory (use getDirSize()).
     * @throws ExceptionFilesystem - if the size of the file could not be determined.
     */
    public static function getFileSize(string $filepath, DataSizeUnitEnum $unit = DataSizeUnitEnum::BYTES): float
    {
        # PHP caches file information such as sizes, so clear it for this file, otherwise checking a file's size
        # before and after writing to it in the same script would give the old size.
        clearstatcache(true, $filepath);

        if (is_dir($filepath))
        {
            throw new ExceptionFileDoesNotExist(
                "{$filepath} is a directory, not a file. Please use getDirSize() to get the size of a directory."
            );
        }

        if (!is_file($filepath))
        {
            throw new ExceptionFileDoesNotExist("There is no file at {$filepath}.");
        }

        $numBytes = filesize($filepath);

        if ($numBytes === false)
        {
            throw new ExceptionFilesystem("Failed to get the size of {$filepath}.");
        }

        return $unit->convertFromBytes($numBytes);
    }


    /**
     * Zip a directory and all of its contents into a zip file.
     * @param string $sourceFolder - path to the folder we wish to zip up.
     * @param string $dest - path and name to give the zipfile e.g. (/tmp/my_zip.zip)
     * @param bool $deleteOnComplete - specify false if you want to keep the original uncompressed files after they have been zipped.
     * @return void
     * @throws ExceptionFileAlreadyExists
     * @throws ExceptionFileDoesNotExist
     * @throws ExceptionMissingExtension
     */
    public static function zipDir(string $sourceFolder, string $dest, bool $deleteOnComplete=true): void
    {
        if (!extension_loaded('zip') )
        {
            throw new ExceptionMissingExtension("Your PHP does not have the zip extension.");
        }

        if (!file_exists($sourceFolder))
        {
            throw new ExceptionFileDoesNotExist("Cannot zip non-existent folder");
        }

        if (file_exists($dest))
        {
            $msg =
                "A file already exists at {$dest}. We do not wish to risk overwriting data. " .
                "Please provide a destination filepath that doesn't already exist.";

            throw new ExceptionFileAlreadyExists($msg);
        }

        $zip = new ZipArchive();
        $zip->open($dest, ZipArchive::CREATE);

        $files = self::getDirContents($sourceFolder, true, true, true);

        $baseDir = basename($sourceFolder);
        $zip->addEmptyDir($baseDir);

        foreach ($files as $name => $filepath)
        {
            $relativePath = str_replace($sourceFolder, $baseDir, $filepath);
            $zip->addFile($filepath, $relativePath);
        }

        $zip->close();

        # Delete all files from "delete list"
        if ($deleteOnComplete)
        {
            self::deleteDir($sourceFolder);
        }
    }


    /**
     * Unzip a zip file and all of its contents into a directory.
     *
     * @param string $sourceZip - zip file to unzip.
     * @param string $destinationFolder - path to the folder we wish to unzip into.
     * @param bool $deleteOnComplete - specify false if you want to keep the original uncompressed files after they have
     * been zipped.
     * @throws ExceptionFileDoesNotExist
     * @throws ExceptionMissingExtension
     */
    public static function unzip(string $sourceZip, string $destinationFolder, bool $deleteOnComplete=true): void
    {
        if (!extension_loaded('zip') )
        {
            throw new ExceptionMissingExtension("Your PHP does not have the zip extension.");
        }

        if (!file_exists($destinationFolder))
        {
            mkdir($destinationFolder);
        }

        $zip = new ZipArchive();
        $open = $zip->open($sourceZip);

        if (!$open)
        {
            throw new \Exception('Unable to open zip file: ' . $sourceZip);
        }

        $unzip = $zip->extractTo($destinationFolder);

        if (!$unzip)
        {
            throw new \Exception('Unable to unzip file: ' . $sourceZip . ' to destination: ' . $destinationFolder);
        }

        $zip->close();

        if ($deleteOnComplete)
        {
            unlink($sourceZip);
        }
    }


    /**
     * Applies the user-defined callback function to each line of a file.
     * This is inspired by array_walk (http://php.net/manual/en/function.array-walk.php)
     * @param string $filepath - path to the file to open and loop over.
     * @param \callable $callback - callback to execute on every line in the file.
     * @throws \Exception
     */
    public static function fileWalk(string $filepath, callable $callback): void
    {
        $handle = fopen($filepath, "r");

        if ($handle)
        {
            while (($line = fgets($handle)) !== false)
            {
                $callback($line);
            }

            fclose($handle);
        }
        else
        {
            throw new \Exception("fileWalk: Could not open file: " . $filepath);
        }
    }


    /**
     * Filters a file using the provided callback, in a memory efficient way so that it can process very large files.
     * This is inspired by array_filter (http://php.net/manual/en/function.array-filter.php)
     * @param string $inputFile - the path to the file to operate upon.
     * @param callable $filterCallback - the callback to execute on every line in the file to determine if kept or not.
     * Don't forget that this will include its line ending, so you may wish to use trim() before analysis.
     * @param string|null $outputFilepath - optionally specify an output filepath to place the filtered content into.
     * If this is not specified (or is the same file as $inputFile), then the existing file will be modified in-place.
     * Please note that when modifying in-place, this function relies on the ability to create and remove a temporary
     * file in a directory provided by a call to sys_get_temp_dir().
     * @param int|null $streamChunkSize - optionally specify the chunk size when streaming content from the temporary
     * file back into the original. This is only applicable when modifying the file in-place.
     * @return void
     * @throws ExceptionFilesystem
     */
    public static function fileFilter(
        string   $inputFile,
        callable $filterCallback,
        ?string  $outputFilepath = null,
        ?int     $streamChunkSize = null,
    ): void
    {
        // If the output file already exists, it may be the input file under a different path (e.g. relative path or
        // symlink), so compare the resolved paths. If it doesn't exist yet, it can't be the input file.
        $isInPlace = (
            $outputFilepath === null
            || (file_exists($outputFilepath) && realpath($outputFilepath) === realpath($inputFile))
        );

        if ($isInPlace)
        {
            // Open with r+ (read/write without truncating) so that we fail immediately, before doing any work, if we
            // don't have permission to write the filtered content back into the file later.
            $inputHandle = fopen($inputFile, "r+");

            if ($inputHandle === false)
            {
                throw new ExceptionFilesystem("Failed to open {$inputFile} for reading and writing.");
            }

            $tempFilename = tempnam(sys_get_temp_dir(), "filter-");

            if ($tempFilename === false)
            {
                throw new ExceptionFilesystem("Failed to create a temporary file to filter {$inputFile} into.");
            }
        }
        else
        {
            $inputHandle = fopen($inputFile, "r");

            if ($inputHandle === false)
            {
                throw new ExceptionFilesystem("Failed to open {$inputFile} for reading.");
            }

            $tempFilename = $outputFilepath;
        }

        $outputHandle = fopen($tempFilename, "w");

        if ($outputHandle === false)
        {
            throw new ExceptionFilesystem("Failed to open $tempFilename for writing.");
        }

        while (($line = fgets($inputHandle)) !== false)
        {
            if ($line === false)
            {
                throw new ExceptionFilesystem("Failed to read from input file handle.");
            }

            if ($filterCallback($line) === true)
            {
                // keeping the line
                $wroteLine = fwrite($outputHandle, $line);

                if ($wroteLine === false)
                {
                    throw new ExceptionFilesystem("Failed to write to {$tempFilename}.");
                }
            }
        }

        fclose($inputHandle);
        fclose($outputHandle);

        if ($isInPlace)
        {
            // user wished to replace the existing input file, copy the contents across and remove the temporary file
            $fromHandle = fopen($tempFilename, "r");

            if ($fromHandle === false)
            {
                throw new ExceptionFilesystem("Failed to open $tempFilename for reading.");
            }

            $toHandle = fopen($inputFile, "w");

            if ($toHandle === false)
            {
                throw new ExceptionFilesystem("Failed to open $inputFile for writing.");
            }

            if ($streamChunkSize !== null)
            {
                stream_set_chunk_size($fromHandle, $streamChunkSize);
            }

            $copiedBytes = stream_copy_to_stream($fromHandle, $toHandle);
            fclose($fromHandle);
            fclose($toHandle);

            $unlinked = unlink($tempFilename);

            if ($unlinked === false)
            {
                throw new ExceptionFilesystem("Failed to remove temporary file: {$tempFilename}");
            }
        }
        else
        {
            // user wished to output to a new file, do nothing and keep it as is
        }
    }


    /**
     * Create a temporary directory. This will be a randomly named directory in the system's
     * temporary directory folder (usually /tmp).
     * @param string $mode - the permissions the new temporary directory will have. Using the defaults from mkdir:
     * http://php.net/manual/en/function.mkdir.php
     * @return string - the full path to the temporary directory. E.g. /tmp/myRandomDir
     * @throws ExceptionFilesystem - if the temporary directory could not be created.
     */
    public static function tmpDir(string $mode = "0777"): string
    {
        # tempnam() is only used to get a unique name, so remove the file it creates and make a directory there.
        $tempName = tempnam(sys_get_temp_dir(), '');

        if ($tempName === false)
        {
            throw new ExceptionFilesystem("Failed to get a unique name for a temporary directory.");
        }

        unlink($tempName);
        mkdir($tempName, octdec($mode));

        if (is_dir($tempName) === FALSE)
        {
            throw new ExceptionFilesystem("Failed to create a temporary directory.");
        }

        return $tempName;
    }


    /**
     * Download a file from the provided URL
     * This will use curl if it is available, if not it will
     * fall back to using file_get_contents.
     * @param string $url
     * @return string - the full filepath to the downloaded file on the server.
     * @throws ExceptionFilesystem - if the temporary file could not be created/written to, or the download failed.
     */
    public static function downloadFile(string $url): string
    {
        $downloadedFilepath = tempnam(sys_get_temp_dir(), "");

        if ($downloadedFilepath === false)
        {
            throw new ExceptionFilesystem("Failed to create a temporary file to download {$url} into.");
        }

        // use curl if installed. Faster and probably more memory efficient.
        if (function_exists('curl_version'))
        {
            $fp = fopen($downloadedFilepath, 'w+');

            if ($fp === false)
            {
                throw new ExceptionFilesystem("Failed to open {$downloadedFilepath} for writing.");
            }

            $ch = curl_init(str_replace(" ", "%20", $url));
            curl_setopt($ch, CURLOPT_TIMEOUT, 50);
            curl_setopt($ch, CURLOPT_FILE, $fp);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            $downloaded = curl_exec($ch);
            $curlError = curl_error($ch);
            fclose($fp);

            if ($downloaded === false)
            {
                throw new ExceptionFilesystem("Failed to download {$url}: {$curlError}");
            }
        }
        else
        {
            $content = file_get_contents($url);

            if ($content === false)
            {
                throw new ExceptionFilesystem("Failed to download {$url}.");
            }

            if (file_put_contents($downloadedFilepath, $content) === false)
            {
                throw new ExceptionFilesystem("Failed to write the downloaded content to {$downloadedFilepath}.");
            }
        }

        return $downloadedFilepath;
    }


    /**
     * A more human-readable version of the fileperms function. This one will output in the "-rw-r--r--" style format
     * rather than a bunch of numbers. The code is taken straight from the examples section of
     * https://www.php.net/manual/en/function.fileperms.php
     * @param string $filepath - the path to the file/folder we wish to get the permissions of.
     * @return string - the file permissions string.
     */
    public static function fileperms(string $filepath): string
    {
        $perms = fileperms($filepath);

        $info = match ($perms & 0xF000) {
            0xC000 => 's',
            0xA000 => 'l',
            0x8000 => 'r',
            0x6000 => 'b',
            0x4000 => 'd',
            0x2000 => 'c',
            0x1000 => 'p',
            default => 'u',
        };

        // Owner
        $info .= (($perms & 0x0100) ? 'r' : '-');
        $info .= (($perms & 0x0080) ? 'w' : '-');
        $info .= (($perms & 0x0040) ?
                    (($perms & 0x0800) ? 's' : 'x' ) :
                    (($perms & 0x0800) ? 'S' : '-'));

        // Group
        $info .= (($perms & 0x0020) ? 'r' : '-');
        $info .= (($perms & 0x0010) ? 'w' : '-');
        $info .= (($perms & 0x0008) ?
                    (($perms & 0x0400) ? 's' : 'x' ) :
                    (($perms & 0x0400) ? 'S' : '-'));

        // World
        $info .= (($perms & 0x0004) ? 'r' : '-');
        $info .= (($perms & 0x0002) ? 'w' : '-');
        $info .= (($perms & 0x0001) ?
                    (($perms & 0x0200) ? 't' : 'x' ) :
                    (($perms & 0x0200) ? 'T' : '-'));

        return $info;
    }


    /**
     * Fetch the file extension of a specified filename or file path. E.g. "csv" or "txt"
     * @param string $filename - the name of the file or the full file path
     * @return string - the file extension.
     * @throws \Exception
     */
    public static function getFileExtension(string $filename): string
    {
        $parts = explode('.', $filename);
        return ArrayLib::getLastElement($parts);
    }


    /**
     * Serve up a file to the user. This is particularly useful for CSV/PDF report downloads etc.
     * @param string $filepath - the path to the file you wish to serve up as a download
     * @param string $downloadFilename - the filename the browser should use for the file, e.g. if the user saves it.
     * This can contain any characters, including spaces, punctuation and non-ASCII characters such as "résumé.pdf".
     * @param string $mimetype - optionally manually specify the mimetype. Can be null to force not setting mimetype on
     * download. Defaults to "auto" which will have PHP try to dynamically figure out the mimetype of the file.
     * @param bool $asAttachment - specify whether the file should be downloaded as an attachment in the browser
     * (default), or set to false for the file to be "inline" so it just appears in the webview rather than being
     * downloaded. E.g. for a PDF, the PDF could just be shown in the browser and not automatically downloaded, with
     * the user choosing to download the file if they wish
     * @throws ExceptionFileDoesNotExist - if there is no file at $filepath.
     * @throws ExceptionBadFilePermissions - if the file exists but cannot be read.
     * @throws ExceptionFilesystem - if the mimetype or size of the file could not be determined, or the file could not
     * be read. These are checked before any headers are sent, apart from the file failing to be read.
     */
    public static function streamFileToBrowser(
        string $filepath,
        string $downloadFilename,
        ?string $mimetype="auto",
        bool $asAttachment=true
    ): void
    {
        # Check everything that could fail before sending any headers, so that a failure doesn't leave the browser with
        # a half-sent response.
        if (!is_file($filepath))
        {
            throw new ExceptionFileDoesNotExist("There is no file at {$filepath}.");
        }

        if (!is_readable($filepath))
        {
            throw new ExceptionBadFilePermissions("The file at {$filepath} exists but cannot be read.");
        }

        if ($mimetype === "auto")
        {
            $mimetype = (new \finfo(FILEINFO_MIME))->file($filepath);

            if ($mimetype === false)
            {
                throw new ExceptionFilesystem("Failed to determine the mimetype of {$filepath}.");
            }
        }

        $fileSize = filesize($filepath);

        if ($fileSize === false)
        {
            throw new ExceptionFilesystem("Failed to determine the size of {$filepath}.");
        }

        if ($mimetype !== null)
        {
            header("Content-type: {$mimetype}");
        }

        header("Content-Length: {$fileSize}");
        header("Content-Disposition: " . self::buildContentDisposition($asAttachment, $downloadFilename));
        header("Pragma: no-cache");
        header("Expires: 0");

        if (readfile($filepath) === false)
        {
            throw new ExceptionFilesystem("Failed to read {$filepath} in order to send it to the browser.");
        }
    }


    /**
     * Build the value for a Content-Disposition header, in the standard way (RFC 6266), so that any filename works.
     * This gives two versions of the filename: a quoted, ASCII-only fallback for very old clients, and the exact
     * filename, percent-encoded as UTF-8, which all current browsers use.
     * E.g. attachment; filename="r_sum_.pdf"; filename*=UTF-8''r%C3%A9sum%C3%A9.pdf
     * @param bool $asAttachment - true for "attachment" (download), false for "inline" (display in the browser).
     * @param string $filename - the filename the browser should use.
     * @return string - the value for the Content-Disposition header.
     */
    private static function buildContentDisposition(bool $asAttachment, string $filename): string
    {
        # Remove newlines and other control characters, as PHP refuses to send a header containing a newline, and
        # would drop the whole header (and thus the filename).
        $cleanFilename = preg_replace('/[\x00-\x1F\x7F]/', '', $filename);

        # The fallback must be plain ASCII, so replace anything else with an underscore. Do this per character if the
        # filename is valid UTF-8 (so "é" becomes one underscore), otherwise per byte.
        $asciiFallback = preg_replace('/[^\x20-\x7E]/u', '_', $cleanFilename) ?? preg_replace('/[^\x20-\x7E]/', '_', $cleanFilename);

        # Inside the quotes, backslashes and double quotes must be escaped.
        $quotedFallback = '"' . addcslashes($asciiFallback, '"\\') . '"';

        $type = ($asAttachment) ? "attachment" : "inline";
        $contentDisposition = "{$type}; filename={$quotedFallback}; filename*=UTF-8''" . rawurlencode($cleanFilename);
        return $contentDisposition;
    }


    /**
     * Returns whether the file specified contains only valid UTF-8 character encoding or not.
     * @param string $filepath - the path to the file that we wish to check.
     * @param int $chunkSize - the size of each chunk to check at a time. This is a balance between
     * memory and performance.
     * @return bool - true if valid, false if it contains non-UTF-8 characters.
     * @throws ExceptionBadFilePermissions - if file exists but cannot be read.
     * @throws ExceptionFileDoesNotExist - if file does not exist.
     * @throws ExceptionFilesystem - if something goes wrong with getting a file handle even though file exists and
     * can be read, or reading the file fails part way through.
     * @throws \InvalidArgumentException - if $chunkSize is less than 1.
     */
    public static function isValidUtf8File(string $filepath, int $chunkSize = 8192): bool
    {
        if ($chunkSize < 1)
        {
            throw new \InvalidArgumentException("isValidUtf8File: \$chunkSize must be at least 1.");
        }

        if (!file_exists($filepath))
        {
            throw new ExceptionFileDoesNotExist($filepath . " does not exist.");
        }

        if (!is_readable($filepath))
        {
            throw new ExceptionBadFilePermissions("The file exists but cannot be read: $filepath");
        }

        $handle = fopen($filepath, 'rb');

        if ($handle === false)
        {
            throw new ExceptionFilesystem("Failed to get file handle for: $filepath");
        }

        $isValid = true;
        $hasReachedEnd = false;

        # A character such as "é" is made of several bytes, so a chunk can end part way through one. Those bytes are
        # held back and checked along with the start of the next chunk, otherwise each half would look invalid.
        $unfinishedCharacter = '';

        while ($isValid && !$hasReachedEnd)
        {
            $chunk = fread($handle, $chunkSize);

            if ($chunk === false)
            {
                fclose($handle);
                throw new ExceptionFilesystem("Failed to read from {$filepath} part way through checking it.");
            }

            if ($chunk === '')
            {
                # We've reached the end of the file, so a character that was never finished really is invalid.
                $hasReachedEnd = true;
                $isValid = ($unfinishedCharacter === '');
            }
            else
            {
                $bytes = $unfinishedCharacter . $chunk;
                $unfinishedCharacter = self::getUnfinishedUtf8Character($bytes);
                $bytesToCheck = substr($bytes, 0, strlen($bytes) - strlen($unfinishedCharacter));
                $isValid = self::isValidUtf8($bytesToCheck);
            }
        }

        fclose($handle);
        return $isValid;
    }


    /**
     * Check whether a string is valid UTF-8, without needing the mbstring extension. This uses PCRE, which is always
     * part of PHP, and rejects the same things that mb_check_encoding() does, such as overlong encodings, surrogates,
     * and code points above U+10FFFF.
     * @param string $bytes - the bytes to check.
     * @return bool - true if the bytes are valid UTF-8 (an empty string is), false if not.
     */
    private static function isValidUtf8(string $bytes): bool
    {
        # With the u modifier, preg_match() returns false instead of matching if the subject isn't valid UTF-8.
        $isValid = (preg_match('//u', $bytes) === 1);
        return $isValid;
    }


    /**
     * Get the bytes of a UTF-8 character that the provided bytes end part way through, if any. E.g. if the bytes end
     * with only the first of the two bytes that make up "é", that byte is returned.
     * This only looks at the start of the last character, so it does not check whether the bytes are valid UTF-8.
     * @param string $bytes - the bytes to check the end of.
     * @return string - the bytes of the unfinished character at the end (at most 3), or an empty string if the bytes
     *                  don't end part way through a character.
     */
    private static function getUnfinishedUtf8Character(string $bytes): string
    {
        $unfinished = '';
        $numBytes = strlen($bytes);
        $hasFoundStart = false;

        # A UTF-8 character is at most 4 bytes long, so an unfinished one is at most 3 bytes. Walk backwards over
        # those, looking for the byte that starts the last character (continuation bytes are 10xxxxxx).
        for ($distanceFromEnd = 1; !$hasFoundStart && $distanceFromEnd <= min(3, $numBytes); $distanceFromEnd++)
        {
            $byte = ord($bytes[$numBytes - $distanceFromEnd]);

            if (($byte & 0xC0) !== 0x80)
            {
                $hasFoundStart = true;

                # The start byte tells us how long the character should be: 11110xxx = 4, 1110xxxx = 3, 110xxxxx = 2.
                $expectedLength = match (true) {
                    $byte >= 0xF0 => 4,
                    $byte >= 0xE0 => 3,
                    $byte >= 0xC0 => 2,
                    default => 1,
                };

                if ($expectedLength > $distanceFromEnd)
                {
                    $unfinished = substr($bytes, -$distanceFromEnd);
                }
            }
        }

        return $unfinished;
    }


    /**
     * Converts a file to UTF-8, if it isn't already.
     * WARNING - this will overwrite/replace the file!
     *
     * It is not possible to reliably detect which encoding a file is in, as in single-byte encodings such as
     * Windows-1252, Windows-1251 and ISO-8859-2 every byte is valid, and the same byte means a different character in
     * each (e.g. 0xE6 is "æ" in Windows-1252, but "ж" in Windows-1251). So this only detects what can be detected
     * reliably, and otherwise relies on you telling it what encoding the file is in:
     *  1. If the file is already valid UTF-8, it is left untouched.
     *  2. If the file starts with a UTF-16 or UTF-32 byte order mark (BOM), it is converted from that encoding, and the
     *     BOM is removed. This overrides $sourceEncoding.
     *  3. Otherwise, the file is converted from $sourceEncoding.
     * The file is written to a temporary file first, and then swapped into place, so that a failure part way through
     * can never leave the original file half-written.
     *
     * @param string $inputFilepath - the path to the file to convert.
     * @param string $sourceEncoding - the encoding the file is in, if it is not UTF-8 and has no BOM. This must be an
     * encoding that the mbstring extension supports, e.g. "Windows-1252" (the default, common for Western European
     * text), "Windows-1251" (Cyrillic), "ISO-8859-2" (Central European), "ISO-8859-15" (Western European with the
     * euro sign) or "SJIS" (Japanese). Note that mbstring does NOT support "Windows-1250".
     * @return void
     * @throws \InvalidArgumentException - if $sourceEncoding is not an encoding that mbstring supports.
     * @throws ExceptionBadFilePermissions - if input file cannot be read (but does exist).
     * @throws ExceptionFileDoesNotExist - if input file does not exist.
     * @throws ExceptionMissingExtension - if missing the mbstring extension which is required for this function.
     * @throws ExceptionFilesystem - if the file is not valid in the encoding it is being converted from, or anything
     * else goes wrong that is not covered by the other specific exceptions.
     */
    public static function convertFileToUtf8(string $inputFilepath, string $sourceEncoding = 'Windows-1252'): void
    {
        if (extension_loaded('mbstring') === FALSE)
        {
            throw new ExceptionMissingExtension("The mbstring extension is not available.");
        }

        # Check the encoding is supported before doing anything, as mbstring throws a ValueError otherwise.
        try
        {
            mb_check_encoding('', $sourceEncoding);
        }
        catch (\ValueError $e)
        {
            throw new \InvalidArgumentException(
                "convertFileToUtf8: \"{$sourceEncoding}\" is not an encoding that the mbstring extension supports.",
                0,
                $e
            );
        }

        if (!is_file($inputFilepath))
        {
            throw new ExceptionFileDoesNotExist("$inputFilepath file does not exist.");
        }

        if (!is_readable($inputFilepath))
        {
            throw new ExceptionBadFilePermissions("$inputFilepath file exists but cannot be read.");
        }

        $content = file_get_contents($inputFilepath);

        if ($content === false)
        {
            throw new ExceptionFilesystem("Failed to read file: $inputFilepath");
        }

        if (self::isValidUtf8($content) === false)
        {
            # A byte order mark reliably tells us the encoding. Check the UTF-32 ones first, as the UTF-32LE BOM starts
            # with the same two bytes as the UTF-16LE one.
            $byteOrderMarks = [
                'UTF-32LE' => "\xFF\xFE\x00\x00",
                'UTF-32BE' => "\x00\x00\xFE\xFF",
                'UTF-16LE' => "\xFF\xFE",
                'UTF-16BE' => "\xFE\xFF",
            ];

            $encoding = $sourceEncoding;

            foreach ($byteOrderMarks as $bomEncoding => $byteOrderMark)
            {
                if ($encoding === $sourceEncoding && str_starts_with($content, $byteOrderMark))
                {
                    $encoding = $bomEncoding;
                    $content = substr($content, strlen($byteOrderMark));
                }
            }

            # For multi-byte encodings such as UTF-16, this catches a file that isn't really in that encoding. For
            # single-byte encodings such as Windows-1252, every byte is valid, so this can't catch the wrong encoding.
            if (mb_check_encoding($content, $encoding) === false)
            {
                throw new ExceptionFilesystem("$inputFilepath is not valid $encoding, so it cannot be converted from it.");
            }

            $converted = mb_convert_encoding($content, 'UTF-8', $encoding);
            self::replaceFileContents($inputFilepath, $converted);
        }
    }


    /**
     * Replace the contents of a file safely, by writing to a temporary file in the same directory and then swapping
     * it into place, so that a failure part way through (e.g. the disk filling up) can never leave the file
     * half-written. The file keeps its permissions.
     * @param string $filepath - the path to the file to replace the contents of.
     * @param string $contents - the new contents for the file.
     * @return void
     * @throws ExceptionFilesystem - if the new contents could not be written, or the file could not be replaced.
     */
    private static function replaceFileContents(string $filepath, string $contents): void
    {
        # Create the temporary file in the same directory, so that the rename() below is a simple swap.
        $tempFilepath = tempnam(dirname($filepath), '.' . basename($filepath) . '.');

        if ($tempFilepath === false)
        {
            throw new ExceptionFilesystem("Failed to create a temporary file to write the new contents of $filepath to.");
        }

        $bytesWritten = file_put_contents($tempFilepath, $contents);

        # tempnam() creates the file with 0600 permissions, so copy across the original file's permissions.
        $permissions = fileperms($filepath);

        if ($bytesWritten !== strlen($contents) || $permissions === false || !chmod($tempFilepath, $permissions & 0777))
        {
            unlink($tempFilepath);
            throw new ExceptionFilesystem("Failed to write the new contents of $filepath to a temporary file.");
        }

        if (!rename($tempFilepath, $filepath))
        {
            unlink($tempFilepath);
            throw new ExceptionFilesystem("Failed to replace $filepath with its new contents.");
        }
    }
}
