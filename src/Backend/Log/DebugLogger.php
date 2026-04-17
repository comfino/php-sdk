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
use Psr\Log\LoggerInterface;

/**
 * DebugLogger class for logging debug messages to a file.
 */
class DebugLogger extends Logger
{
    private static ?self $instance = null;
    private static ?LoggerInterface $logger = null;

    /**
     * Returns the singleton instance of the DebugLogger.
     *
     * @param string $logFilePath The path to the log file
     *
     * @return self The singleton instance of the DebugLogger
     */
    public static function getInstance(string $logFilePath): self
    {
        if (self::$instance === null) {
            self::$instance = new self($logFilePath);
        }

        return self::$instance;
    }

    /**
     * Initializes the DebugLogger with a logger instance.
     *
     * @param LoggerInterface $logger The logger instance
     */
    public static function init(LoggerInterface $logger): void
    {
        self::$logger = $logger;
    }

    /**
     * Resets the DebugLogger instance and logger.
     */
    public static function reset(): void
    {
        self::$instance = null;
        self::$logger = null;
    }

    /**
     * Private constructor to enforce a singleton pattern.
     *
     * @param string $logFilePath The path to the log file
     */
    private function __construct(private readonly string $logFilePath)
    {
    }

    /**
     * Returns the logger instance.
     *
     * @return LoggerInterface|null The logger instance or null if not initialized
     */
    public function getLogger(): ?LoggerInterface
    {
        return self::$logger;
    }

    /**
     * Logs an event with the provided prefix, message, and optional parameters.
     *
     * @param string $eventPrefix The prefix for the event
     * @param string $eventMessage The message for the event
     * @param array<string, mixed>|null $parameters Optional parameters for the event
     */
    public function logEvent(string $eventPrefix, string $eventMessage, ?array $parameters = null): void
    {
        self::$logger?->debug($eventPrefix . ': ' . $eventMessage, $parameters ?? []);
    }

    /**
     * Retrieves the last 'numLines' lines of the debug log.
     *
     * @param int $numLines The number of lines to retrieve
     * @return string The last 'numLines' lines of the debug log
     */
    public function getDebugLog(int $numLines): string
    {
        $actualLogPath = $this->findActualLogFile($this->logFilePath);

        if ($actualLogPath === null || !FileUtils::exists($actualLogPath)) {
            return '';
        }

        return implode('', FileUtils::readLastLines($actualLogPath, $numLines));
    }

    /**
     * Clears all log files associated with the debug logger.
     *
     * @return int The number of log files cleared
     */
    public function clearLogs(): int
    {
        return $this->clearLogFiles($this->logFilePath);
    }
}
