<?php

/**
 * Comfino PHP SDK
 *
 * Backend routines for e-commerce platforms integration with the Comfino payment gateway API.
 *
 * @package Comfino\Backend
 * @author Artur Kozubski <akozubski@comperia.pl>
 * @copyright Copyright (c) 2026 Comfino by Comperia.pl S.A.
 * @license https://opensource.org/licenses/BSD-3-Clause BSD 3-Clause License
 * @link https://github.com/comfino/php-sdk
 */

declare(strict_types=1);

namespace Comfino\Backend;

/**
 * Utility functions for file operations.
 */
class FileUtils
{
    /**
     * Builds a file path from an array of components.
     *
     * @param string[] $components File path components
     *
     * @return string File path
     */
    public static function buildPathFromComponents(array $components): string
    {
        return implode(DIRECTORY_SEPARATOR, $components);
    }

    /**
     * Reads the contents of a file.
     *
     * @param string $filePath File path
     *
     * @return string File contents
     */
    public static function read(string $filePath): string
    {
        try {
            $file = new \SplFileObject($filePath, 'r');
        } catch (\Exception) {
            return '';
        }

        if (!$file->isReadable()) {
            return '';
        }

        return $file->fread($file->getSize());
    }

    /**
     * Reads the last lines of a file.
     *
     * @param string $filePath File path
     * @param int $numLines Number of lines to read
     *
     * @return array<int, string> Last lines of the file
     */
    public static function readLastLines(string $filePath, int $numLines): array
    {
        try {
            $file = new \SplFileObject($filePath, 'r');
        } catch (\Exception) {
            return [];
        }

        if (!$file->isReadable()) {
            return [];
        }

        $file->seek(PHP_INT_MAX);

        $lastLine = $file->key();

        return iterator_to_array(new \LimitIterator(
            $file,
            $lastLine > $numLines ? $lastLine - $numLines : 0,
            $lastLine ?: 1
        ));
    }

    /**
     * Writes content to a file.
     *
     * @param string $filePath File path
     * @param string $content Content to write
     */
    public static function write(string $filePath, string $content): void
    {
        (new \SplFileObject($filePath, 'w'))->fwrite($content);
    }

    /**
     * Appends content to a file.
     *
     * @param string $filePath File path
     * @param string $content Content to append
     */
    public static function append(string $filePath, string $content): void
    {
        (new \SplFileObject($filePath, 'a'))->fwrite($content);
    }

    /**
     * Checks if a file exists.
     *
     * @param string $filePath File path
     *
     * @return bool True if the file exists, false otherwise
     */
    public static function exists(string $filePath): bool
    {
        return (new \SplFileInfo($filePath))->isFile();
    }

    /**
     * Checks if a file is writable.
     *
     * @param string $filePath File path
     *
     * @return bool True if the file is writable, false otherwise
     */
    public static function isWritable(string $filePath): bool
    {
        $info = new \SplFileInfo($filePath);

        return $info->isFile() ? $info->isWritable() : is_writable(dirname($filePath));
    }

    /**
     * Checks if a file is readable.
     *
     * @param string $filePath File path
     *
     * @return bool True if the file is readable, false otherwise
     */
    public static function isReadable(string $filePath): bool
    {
        return (new \SplFileInfo($filePath))->isReadable();
    }
}
