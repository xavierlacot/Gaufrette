<?php

namespace Gaufrette\Adapter;

use Gaufrette\Adapter;
use Gaufrette\Checksum;
use Gaufrette\Path;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Adapter for the local filesystem
 *
 * @author Antoine Hérault <antoine.herault@gmail.com>
 */
class Local implements Adapter
{
    protected $directory;

    /**
     * Constructor
     *
     * @param  string  $directory Directory where the filesystem is located
     * @param  boolean $create    Whether to create the directory if it does not
     *                            exist (default FALSE)
     *
     * @throws RuntimeException if the specified directory does not exist and
     *                          could not be created
     */
    public function __construct($directory, $create = false)
    {
        $this->directory = $this->normalizePath($directory);
        $this->ensureDirectoryExists('', $create);
    }

    /**
     * {@InheritDoc}
     */
    public function read($key)
    {
        $content = file_get_contents($this->computePath($key));

        if (false === $content) {
            throw new \RuntimeException(sprintf('Could not read the \'%s\' file.', $key));
        }

        return $content;
    }

    /**
     * {@InheritDoc}
     */
    public function write($key, $content)
    {
        $path = $this->computePath($key);

        $this->ensureDirectoryExists(dirname($path), true);

        $numBytes = file_put_contents($this->computePath($key), $content);

        if (false === $numBytes) {
            throw new \RuntimeException(sprintf('Could not write the \'%s\' file.', $key));
        }

        return $numBytes;
    }

    /**
     * {@InheritDoc}
     */
    public function rename($key, $new)
    {
        if (!rename($this->computePath($key), $this->computePath($new))) {
            throw new \RuntimeException(sprintf('Could not rename the \'%s\' file to \'%s\'.', $key, $new));
        }
    }


    /**
     * {@InheritDoc}
     */
    public function copy($key, $new)
    {
        if (!copy($this->computePath($key), $this->computePath($new))) {
            throw new \RuntimeException(sprintf('Could not copy the \'%s\' file to \'%s\'.', $key, $new));
        }
    }

    /**
     * {@InheritDoc}
     */
    public function exists($key)
    {
        return file_exists($this->computePath($key));
    }

    /**
     * {@InheritDoc}
     */
    public function keys($key)
    {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(
                $this->computePath($key),
                FilesystemIterator::SKIP_DOTS | FilesystemIterator::UNIX_PATHS
            )
        );

        $files = iterator_to_array($iterator);

        $self = $this;
        return array_values(
            array_map(
                function($file) use ($self, $key) {
                    return $self->computeKey(strval($file));
                },
                $files
            )
        );
    }

    /**
     * {@InheritDoc}
     */
    public function mtime($key)
    {
        return filemtime($this->computePath($key));
    }

    /**
     * {@inheritDoc}
     */
    public function checksum($key)
    {
        return Checksum::fromFile($this->computePath($key));
    }

    /**
     * {@InheritDoc}
     */
    public function delete($key)
    {
        $path = $this->computePath($key);

        if (file_exists($path)) {
            if (is_dir($path)) {
                foreach (scandir($path) as $entry) {
                    if ($entry == '.' || $entry == '..') continue;

                    try {
                        $this->delete($key.DIRECTORY_SEPARATOR.$entry);
                    } catch (RuntimeException $e) {
                        // try to change the rights
                        chmod($key.DIRECTORY_SEPARATOR.$entry, 0777);
                        $this->delete($key.DIRECTORY_SEPARATOR.$entry);
                    }
                }

                if (!rmdir($path)) {
                    throw new \RuntimeException(sprintf('Could not remove the \'%s\' directory.', $key));
                }
            }
            else
            {
                if (!unlink($path)) {
                    throw new \RuntimeException(sprintf('Could not remove the \'%s\' file.', $key));
                }
            }
        }

    }

    /**
     * Computes the path from the specified key
     *
     * @param  string $key The key which for to compute the path
     *
     * @return string A path
     *
     * @throws OutOfBoundsException If the computed path is out of the
     *                              directory
     */
    public function computePath($key)
    {
        $path = $this->normalizePath($this->directory . '/' . $key);

        if (0 !== strpos($path, $this->directory)) {
            throw new \OutOfBoundsException(sprintf('The file \'%s\' is out of the filesystem.', $key));
        }

        return $path;
    }

    /**
     * Normalizes the given path
     *
     * @param  string $path
     *
     * @return string
     */
    public function normalizePath($path)
    {
        return Path::normalize($path);
    }

    /**
     * Computes the key from the specified path
     *
     * @param  string $path
     *
     * return string
     */
    public function computeKey($path)
    {
        $path = $this->normalizePath($path);
        if (0 !== strpos($path, $this->directory)) {
            throw new \OutOfBoundsException(sprintf('The path \'%s\' is out of the filesystem.', $path));
        }

        return ltrim(substr($path, strlen($this->directory)), '/');
    }

    /**
     * Ensures the specified directory exists, creates it if it does not
     *
     * @param  string  $directory Path of the directory to test
     * @param  boolean $create    Whether to create the directory if it does
     *                            not exist
     *
     * @throws RuntimeException if the directory does not exists and could not
     *                          be created
     */
    public function ensureDirectoryExists($directory, $create = false)
    {
        if (!is_dir($this->computePath($directory))) {
            if (!$create) {
                throw new \RuntimeException(sprintf('The directory \'%s\' does not exist.', $directory));
            }

            $this->createDirectory($directory);
        }
    }

    /**
     * Creates the specified directory and its parents
     *
     * @param  string $directory Path of the directory to create
     *
     * @throws InvalidArgumentException if the directory already exists
     * @throws RuntimeException         if the directory could not be created
     */
    public function createDirectory($directory)
    {
        $path = $this->computePath($directory);

        if (is_dir($path)) {
            throw new \InvalidArgumentException(sprintf('The directory \'%s\' already exists.', $directory));
        }

        $umask = umask(0);
        $created = mkdir($path, 0777, true);
        umask($umask);

        if (!$created) {
            throw new \RuntimeException(sprintf('The directory \'%s\' could not be created.', $directory));
        }
    }
}
