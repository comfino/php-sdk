<?php

/**
 * Comfino PHP SDK
 *
 * Backend routines for e-commerce platforms integration with the Comfino payment gateway API.
 *
 * @package Comfino\Backend\Log
 * @author Artur Kozubski <akozubski@comperia.pl>
 * @copyright Copyright (c) 2026 Comfino by Comperia.pl S.A.
 * @license https://opensource.org/licenses/BSD-3-Clause BSD 3-Clause License
 * @link https://github.com/comfino/php-sdk
 */

declare(strict_types=1);

namespace Comfino\Backend\Log;

use Comfino\Backend\FileUtils;

/**
 * Abstract Logger class for logging messages to a file.
 */
abstract class Logger
{
    /**
     * Finds the actual log file path based on the provided log file path.
     *
     * @param string $logFilePath The path to the log file
     *
     * @return ?string The actual log file path or null if not found
     */
    protected function findActualLogFile(string $logFilePath): ?string
    {
        if (FileUtils::exists($logFilePath)) {
            return $logFilePath;
        }

        $dir = dirname($logFilePath);
        $filename = pathinfo($logFilePath, PATHINFO_FILENAME);
        $extension = pathinfo($logFilePath, PATHINFO_EXTENSION);

        $files = glob($dir . DIRECTORY_SEPARATOR . $filename . '-*.' . $extension);

        if (empty($files)) {
            return null;
        }

        usort($files, static fn ($filename1, $filename2) => filemtime($filename2) <=> filemtime($filename1));

        return $files[0];
    }

    /**
     * Finds all log files based on the provided log file path.
     *
     * @param string $logFilePath The path to the log file
     *
     * @return array<int, string> An array of log file paths
     */
    protected function findAllLogFiles(string $logFilePath): array
    {
        $filename = pathinfo($logFilePath, PATHINFO_FILENAME);
        $extension = pathinfo($logFilePath, PATHINFO_EXTENSION);

        return glob(dirname($logFilePath) . DIRECTORY_SEPARATOR . $filename . '-*.' . $extension) ?: [];
    }

    /**
     * Clears all log files based on the provided log file path.
     *
     * @param string $logFilePath The path to the log file
     *
     * @return int The number of deleted log files
     */
    protected function clearLogFiles(string $logFilePath): int
    {
        $deletedCount = 0;

        if (FileUtils::exists($logFilePath) && unlink($logFilePath)) {
            $deletedCount++;
        }

        foreach ($this->findAllLogFiles($logFilePath) as $file) {
            if (FileUtils::exists($file) && unlink($file)) {
                $deletedCount++;
            }
        }

        return $deletedCount;
    }
}
