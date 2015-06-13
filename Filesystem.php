<?php

namespace Irap\CoreLibs;


/*
 * Library just for filesystem operations.
 */

 class Filesystem
 {
    /**
     * Retrieves an array list of files/folders within the specified directory.
     * Consider using the following instead:
     * $directory = new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::CURRENT_AS_SELF);
     * $iterator = new RecursiveIteratorIterator($directory, RecursiveIteratorIterator::CHILD_FIRST);
     * 
     * @param directoryPath - the path to the directory you wisht to find the contents of.
     * @param recursive     - whether we go through into each subfolder and retrieve its contents.
     * @param includePath   - whether we output the path to the entry such as '/folder/text.txt' 
     *                        instead of just 'text.txt'
     * @param onlyFiles     - whether we include the directory itself in the returned list
     * 
     * @return fileNames - the names of all the files/folders within the directory.
     */
    public static function get_dir_contents($dir, 
                                            $recursive   = true, 
                                            $includePath = true, 
                                            $onlyFiles   = true)
    {
        $fileNames = array();
        $fpath     = realpath($dir);
        $handle    = opendir($dir);

        if ($handle)
        {
            while (false !== ($fileName = readdir($handle)))
            {
                if (strcmp($fileName,"..")!=0 && strcmp($fileName,".")!=0)
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
                            $subFiles = self::get_dir_contents($fpath . "/" . $fileName, 
                                                                   $recursive, 
                                                                   $includePath, 
                                                                   $onlyFiles);

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
     * Wrapper around the mkdir that will only execute it if the directory doesn't already exist.
     * Also, this defaults to being recursive so that it will create all relevant parent directories
     * @param string $dirPath - the path to the directory we wish to create
     * @param int $perms - optionally set the permissions to set for the directory
     * @param bool $recursive - override to false if you want to fail if parent dirs dont exist.
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
     * Deletes a directory even if it is not already empty. This resolves the issue with
     * trying to use unlink on a non-empty dir.
     * @param String $dir - the path to the directory you wish to delete
     * @return void - changes your filesystem
     */
    public static function delete_dir($dir) 
    {
        if (is_dir($dir)) 
        {
            $objects = scandir($dir);
        
            foreach ($objects as $object) 
            {
                if ($object != "." && $object != "..") 
                {
                    if (filetype($dir . "/" . $object) == "dir")
                    {
                        self::delete_dir($dir . "/" . $object); 
                    }
                    else
                    {
                        unlink($dir . "/" . $object);
                    }
                }
            }
        
            reset($objects);
            rmdir($dir);
        }
    }


    /**
     * Lock a file so that only we can use it.
     * This will NOT block untill the file can be locked, but would return false immediately if
     * cannot grab the lock.
     * Note that this only allows other processes to see that you have locked it and does not
     * actually guarantee that it cannot be edited at the same time on Linux.
     * @param String $filePath - the full path to the file that we wish to lock
     * @return boolean - true if we managed to lock the file, false otherwise.
     */
    public static function lock_file($filePath)
    {
        global $globals;
        
        # This creates the file if it doesnt exist.
        # we have to assign the file to a global variable, otherwise the file lock will be released
        # as soon as we exit this function.
        # This MUST be a+ instead of w or w+ as using w will result in the file being wiped.
        $globals['file_locks'][$filePath] = fopen($filePath, 'a+');
        
        $result = flock($globals['file_locks'][$filePath], LOCK_NB | LOCK_EX); # LOCK_EX
        return $result;
    }
    
    
    /**
     * Unlock a file so that others may use it.
     * @param String $filePath - the full path to the file that we wish to lock
     * @return void.
     */
    public static function unlock_file($filePath)
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
     * Fetch the total size (in bytes) of a directory or file, including all its subdirectories
     * Note that this is "apparent size" and not the size on disk which is to the block.
     * This only works on Linux, not Windows.
     * src: http://stackoverflow.com/questions/478121/php-get-directory-size
     * @param string $dirPath - the path to the directory we wish to get the size of 
     * @return int - the size in bytes of the directory and all its contents.
     */ 
    function get_dir_size($dirPath)
    {
        $io = popen ( '/usr/bin/du --bytes --summarize ' . $dirPath, 'r' );
        $size = fgets ( $io, 4096);
        $size = substr ( $size, 0, strpos ( $size, "\t" ) );
        pclose ( $io );
        return $size;
    }
    
    
    /**
     * Creates an index of the files in the specified directory, by creating symlinks to them, which
     * are separated into folders having the first letter.
     * WARNING - this will only index files that start with alphabetical characters.
     * @param $directory_to_index - the directory we wish to index.
     * @param $index_location - where to stick the index.
     * @return void
     */
    function create_file_index($directory_to_index, $index_location)
    {
        # Don't let the user place the index in the same folder being indexed, otherwise the directory
        # cannot be re-indexed later, otherwise we will be indexing the index.
        if ($directory_to_index == $index_location)
        {
            throw new \Exception('Cannot place index in same folder being indexed!');
        }

        # delete the old index if one already exists.
        if (file_exists($index_location))
        {
            self::delete_dir($index_location);
        }
        
        if (!mkdir($index_location))
        {
            throw new \Exception('Failed to create index directory, check write permissions');
        }

        $files = scandir($directory_to_index);
        
        foreach ($files as $filename) 
        {
            $first_letter = $filename[0];
            $placement_dir = $index_location . "/" . strtoupper($first_letter);

            if (ctype_alpha($first_letter))
            {
                # create the placement directory if it doesn't exist already
                mkdir($placement_dir);

                $new_path = $placement_dir . "/" . $filename;
                
                if (!is_link($new_path)) 
                {
                    symlink($directory_to_index . '/' . $filename, $new_path);
                }
            }
        }
    }
 }