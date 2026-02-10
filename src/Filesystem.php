<?php

/*
 * Library just for filesystem operations.
 */

namespace Programster\CoreLibs;


use Programster\CoreLibs\Exceptions\ExceptionBadFilePermissions;
use Programster\CoreLibs\Exceptions\ExceptionFileAlreadyExists;
use Programster\CoreLibs\Exceptions\ExceptionFileDoesNotExist;
use Programster\CoreLibs\Exceptions\ExceptionMissingExtension;
use Programster\CoreLibs\Exceptions\FilesystemException;
use Programster\CoreLibs\Exceptions\ExceptionMissingRequiredExtension;

class Filesystem
{
    /**
     * Retrieves a list of all the directories within the specified directory
     * It does not include the .. directory (dot directories)
     * This will NOT return any files
     * @param String $path - the path to the directory that we want to search.
     * @param bool $recursive - whether to recursively loop through the
     *                          directories to find more.
     * @param bool $includePath - set to true to include the full path to the
     *                            dir, not just the dir name.
     * @return Array<String> - list of directories within the specified path.
     */
    public static function getDirectories(
        $path,
        $recursive=false,
        $includePath=true
    )
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
     * @param string $dir - the path to the directory you wisht to find the contents of.
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
    ) : array
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
     * @param int $perms      - optionally set the permissions to set for the
     *                          directory
     * @param bool $recursive - override to false if you want to fail if parent
     *                          dirs dont exist.
     *
     * @return boolean - true if the directory now exists, false otherwise
     */
    public static function mkdir($dirPath, $perms=0755, $recursive=true)
    {
        $result = true;

        if (!is_dir($dirPath))
        {
            $result = mkdir($dirPath, $perms, $recursive);
        }

        return $result;
    }


    /**
     * Deletes a directory even if it is not already empty. This resolves the
     *  issue with trying to use unlink on a non-empty dir.
     * @param string $dir - the path to the directory you wish to delete
     * @return void
     * @throws ExceptionFileDoesNotExist - if a directory does not exist at the path specified.
     */
    public static function deleteDir(string $dir) : void
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
    public static function emptyDir(string $dir) : void
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
     * This will NOT block untill the file can be locked, but would return false immediately if
     * cannot grab the lock.
     * Note that this only allows other processes to see that you have locked it and does not
     * actually guarantee that it cannot be edited at the same time on Linux.
     * @param String $filePath - the full path to the file that we wish to lock
     * @param boolean $is_write_lock - if false this will get a shared read lock, if true this
     *                               will get an exclusive write lock.
     * @param boolean $is_blocking - if true will wait to get lock.
     * @return boolean - true if we managed to lock the file, false otherwise.
     */
    public static function lockFile($filePath, $is_write_lock, $is_blocking)
    {
        global $globals;

        # This creates the file if it doesnt exist.
        # we have to assign the file to a global variable, otherwise the file lock will be released
        # as soon as we exit this function.
        # This MUST be a+ instead of w or w+ as using w will result in the file being wiped.
        $globals['file_locks'][$filePath] = fopen($filePath, 'a+');

        $lock_params = 0;

        if ($is_write_lock)
        {
            $lock_params =  $lock_params | LOCK_EX;
        }
        else
        {
            # read lock = shared lock
            $lock_params = $lock_params | LOCK_SH;
        }

        if (!$is_blocking)
        {
            $lock_params = $lock_params | LOCK_NB;
        }

        $result = flock($globals['file_locks'][$filePath], $lock_params);

        return $result;
    }


    /**
     * Unlock a file so that others may use it.
     * @param String $filePath - the full path to the file that we wish to lock
     * @return void.
     */
    public static function unlockFile($filePath)
    {
        global $globals;

        if (isset($globals['file_locks'][$filePath]))
        {
            # This creates the file if it doesnt exist.
            fclose($globals['file_locks'][$filePath]);
            unset($globals['file_locks'][$filePath]);
        }
    }


    /**
     * Fetch the total size (in bytes) of a directory or file, including all
     * its subdirectories. This is "apparent size" and not the size on disk
     * which is to the block.
     * This only works on Linux, not Windows.
     * src: http://stackoverflow.com/questions/478121/php-get-directory-size
     * @param string $dirPath - the path to the directory we wish to get the
     *                          size of
     * @return int - the size in bytes of the directory and all its contents.
     */
    public static function getDirSize($dirPath)
    {
        $io = popen ( '/usr/bin/du --bytes --summarize ' . $dirPath, 'r' );
        $size = fgets ( $io, 4096);
        $size = substr ( $size, 0, strpos ( $size, "\t" ) );
        pclose ( $io );
        return $size;
    }


    /**
     * Creates an index of the files in the specified directory, by creating
     * symlinks to them, which are separated into folders having the first
     * letter.
     * WARNING - this will only index files that start with alphabetical
     * characters.
     * @param string $dirToIndex - the directory we wish to index.
     * @param string $indexLocation - where to stick the index.
     * @return void
     */
    function createFileIndex($dirToIndex, $indexLocation)
    {
        # Don't let the user place the index in the same folder being indexed,
        # otherwise the directory cannot be re-indexed later, otherwise we will
        # be indexing the index.
        if ($dirToIndex == $indexLocation)
        {
            $errMsg = 'Cannot place index in same folder being indexed!';
            throw new \Exception($errMsg);
        }

        # delete the old index if one already exists.
        if (file_exists($indexLocation))
        {
            self::deleteDir($indexLocation);
        }

        if (!mkdir($indexLocation))
        {
            $err = 'Failed to create index directory, check write permissions';
            throw new \Exception($err);
        }

        $files = scandir($dirToIndex);

        foreach ($files as $filename)
        {
            $first_letter = $filename[0];
            $placement_dir = $indexLocation . "/" . strtoupper($first_letter);

            if (ctype_alpha($first_letter))
            {
                # create the placement directory if it doesn't exist already
                mkdir($placement_dir);

                $newPath = $placement_dir . "/" . $filename;

                if (!is_link($newPath))
                {
                    symlink($dirToIndex . '/' . $filename, $newPath);
                }
            }
        }
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
    public static function zipDir(string $sourceFolder, string $dest, bool $deleteOnComplete=true) : void
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

        $zip = new \ZipArchive();
        $zip->open($dest, \ZipArchive::CREATE);

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
     * @param bool $deleteOnComplete - specify false if you want to keep the
     *                                 original uncompressed files after they
     *                                 have been zipped.
     */
    public static function unzip(string $sourceZip, string $destinationFolder, bool $deleteOnComplete=true)
    {
        if (!extension_loaded('zip') )
        {
            throw new ExceptionMissingExtension("Your PHP does not have the zip extension.");
        }

        if (!file_exists($destinationFolder))
        {
            mkdir($destinationFolder);
        }

        $zip = new \ZipArchive();
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
    public static function fileWalk($filepath, callable $callback)
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
     * Filters a file using the provided callback, in a memory efficient way so that it can
     *  process very large files. This is inspired by array_filter (http://php.net/manual/en/function.array-walk.php)
     * @param string $inputFile - the path to the file to operate upon.
     * @param callable $filterCallback - the callback to execute on every line in the file to determine if ketp or not.
     * Don't forget that this will include its line ending, so you may wish to use trim() before analysis.
     * @param string|null $outputFilepath - optionally specify an output filepath to place the filtered content into.
     * If this is not specified, then the existing file will be modified in-place. Please note that if not provided
     * this function relies on the ability to create and remove a temporary file in a directory provided by a call to
     * sys_get_temp_dir().
     * @param int|null $streamChunkSize - optionally specify the chunk size when streaming content from the temporary
     * file back into the original. This is not applicable when $outputFilepath was provided.
     * @return void
     * @throws FilesystemException
     */
    public static function fileFilter(
        string   $inputFile,
        callable $filterCallback,
        ?string  $outputFilepath = null,
        ?int     $streamChunkSize = null,
    ) : void
    {
        $inputHandle = \Safe\fopen($inputFile, "rw"); // use rw now to ensure we have permission to write later.

        if ($outputFilepath === null)
        {
            $tempFilename = tempnam(sys_get_temp_dir(), "filter-");
        }
        else
        {
            $tempFilename = $outputFilepath;
        }

        $outputHandle = fopen($tempFilename, "w");

        if ($outputHandle === false)
        {
            throw new FilesystemException("Failed to open $tempFilename for writing.");
        }

        while (($line = fgets($inputHandle)) !== false)
        {
            if ($line === false)
            {
                throw new FilesystemException("Failed to read from input file handle.");
            }

            if ($filterCallback($line) === true)
            {
                // keeping the line
                $wroteLine = fwrite($outputHandle, $line);

                if ($wroteLine === false)
                {
                    throw new FilesystemException("Failed to write to {$tempFilename}.");
                }
            }
        }

        fclose($inputHandle);
        fclose($outputHandle);

        if ($outputFilepath === null)
        {
            // user wished to replace the exisitng input file, copy the contents across and remove the temporary file
            $fromHandle = fopen($tempFilename, "r");

            if ($fromHandle === false)
            {
                throw new FilesystemException("Failed to open $tempFilename for reading.");
            }

            $toHandle = fopen($inputFile, "w");

            if ($toHandle === false)
            {
                throw new FilesystemException("Failed to open $inputFile for writing.");
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
                throw new FilesystemException("Failed to remove temporary file: {$tempFilename}");
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
     * @param octal $mode - the permissions the new temporary directory will have. Using the
     *                      defaults from mkdir: http://php.net/manual/en/function.mkdir.php
     * @return string - the full path to the temporary directory. E.g. /tmp/myRandomDir
     * @throws \Exception
     */
    public static function tmpDir($mode = 0777) : string
    {
        $tempName = tempnam(sys_get_temp_dir(), '');
        unlink($tempName);
        mkdir($tempName, $mode);

        if (is_dir($tempName) === FALSE)
        {
            throw new \Exception("Failed to create tmpdir.");
        }

        return $tempName;
    }


    /**
     * Download a file from the provided URL
     * This will use curl if it is available, if not it will
     * fallback to using file_get_contents.
     * @param string $url
     * @return string - the full filepath to the downloaded file on the server.
     */
    public static function downloadFile(string $url) : string
    {
        $downloadedFilepath = tempnam(sys_get_temp_dir(), "");

        // use curl if installed. Faster and probably more memory efficient.
        if (function_exists('curl_version'))
        {
            $fp = fopen($downloadedFilepath, 'w+');
            $ch = curl_init(str_replace(" ", "%20", $url));
            curl_setopt($ch, CURLOPT_TIMEOUT, 50);
            curl_setopt($ch, CURLOPT_FILE, $fp);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_exec($ch);
            curl_close($ch);
            fclose($fp);
        }
        else
        {
            $content = \Safe\file_get_contents($url);
            file_put_contents($downloadedFilepath, $content);
        }

        return $downloadedFilepath;
    }


    /**
     * A more human-readable version of the fileperms function. This one will output in the "-rw-r--r--" style format
     * rather than a bunch of numbers. The code taken straight from the examples section of
     * https://www.php.net/manual/en/function.fileperms.php
     * @param string $filepath - the path to the file/folder we wish to get the permissions of.
     * @return string - the file permissions string.
     */
    public static function fileperms(string $filepath) : string
    {
        $perms = fileperms($filepath);

        switch ($perms & 0xF000) {
            case 0xC000: // socket
                $info = 's';
                break;
            case 0xA000: // symbolic link
                $info = 'l';
                break;
            case 0x8000: // regular
                $info = 'r';
                break;
            case 0x6000: // block special
                $info = 'b';
                break;
            case 0x4000: // directory
                $info = 'd';
                break;
            case 0x2000: // character special
                $info = 'c';
                break;
            case 0x1000: // FIFO pipe
                $info = 'p';
                break;
            default: // unknown
                $info = 'u';
        }

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
     * @param String $filename - the name of the file or the full file path
     * @return String - the file extension.
     */
    public static function getFileExtension(string $filename) : string
    {
        $parts = explode('.', $filename);
        return ArrayLib::getLastElement($parts);
    }


    /**
     * Serve up a file to the user. E.g. have their browser download it. This is particularly useful for CSV report
     * downloads etc.
     * @param string $filepath - the path to the file you wish to serve up as a download
     * @param string $downloadFilename
     * @param string $mimetype - optionally manually specify the mimetype. Can be null to force not setting mimetype on
     * download. Defaults to "auto" which will have PHP try to dynamically figure out the mimetype of the file.
     * @throws Exception - if mimetype set to auto and PHP could not open mimetype database for determining mimetype.
     */
    public static function streamFileToBrowser(string $filepath, string $downloadFilename, ?string $mimetype="auto")
    {
        if ($mimetype !== null)
        {
            if ($mimetype === "auto")
            {
                $finfo = new \finfo(FILEINFO_MIME);

                if (!$finfo)
                {
                    throw new \Exception("Could not open fileinfo database in order to automatically determine the file's mimetype.");
                }

                $mimetype = $finfo->file($filepath);
            }

            header("Content-type: {$mimetype}");
        }

        header("Content-Length: " . filesize($filepath));
        header("Content-Disposition: attachment; filename={$downloadFilename}");
        header("Pragma: no-cache");
        header("Expires: 0");
        readfile($filepath);
    }


    /**
     * Returns whether the file specified contains only valid UTF-8 character encoding or not.
     * @param string $filepath - the path to the file that we wish to check.
     * @param int $chunkSize - the size of each chunk to check at a time. This is a balance between
     * memory and performance.
     * @return bool - true if valid, false if contains non-UTF8 characters.
     * @throws ExceptionBadFilePermissions - if file exists but cannot be read.
     * @throws ExceptionFileDoesNotExist - if file does not exist.
     * @throws \Exception - if something goes wrong with getting a file handle even though file exists and can be read.
     * @throws ExceptionMissingRequiredExtension if you do not have the required mbstring extension.
     */
    public static function isValidUtf8File(string $filepath, int $chunkSize = 8192): bool
    {
        if (extension_loaded('mbstring') === FALSE)
        {
            throw new ExceptionMissingRequiredExtension("The mbstring extension is not available.");
        }

        if (!file_exists($filepath))
        {
            throw new ExceptionFileDoesNotExist($filepath . " does not exist.");
        }

        if (!is_readable($filepath))
        {
            throw new ExceptionBadFilePermissions("File exists but cannot read it: $filepath");
        }

        $handle = fopen($filepath, 'rb');

        if ($handle === false)
        {
            throw new FilesystemException("Failed to get file handle for: $filepath");
        }

        while (!feof($handle))
        {
            $chunk = fread($handle, $chunkSize);

            if ($chunk === false)
            {
                fclose($handle);
                return false;
            }

            if ($chunk === '')
            {
                break;
            }

            if (!mb_check_encoding($chunk, 'UTF-8'))
            {
                fclose($handle);
                return false;
            }
        }

        fclose($handle);
        return true;
    }
}
