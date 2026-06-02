<?php

/**
 * Comfino PHP SDK
 *
 * Backend routines for e-commerce platforms integration with the Comfino payment gateway API.
 *
 * @package Comfino\Backend\Log
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
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
     * Logs an event with automatic context capture from the calling location.
     *
     * Extracts the caller's class, method, file, and line number using debug_backtrace and formats them into a context
     * prefix like '[ClassName::methodName@file.php:123]'.
     *
     * @param string $eventMessage The message for the event
     * @param array<string, mixed>|null $parameters Optional parameters for the event
     */
    public function logEventWithContext(string $eventMessage, ?array $parameters = null): void
    {
        $this->logEvent($this->formatContext($this->extractCallerContext()), $eventMessage, $parameters);
    }

    /**
     * Logs an event only when both debug mode and service mode are active.
     *
     * @param string $eventPrefix The prefix for the event
     * @param string $eventMessage The message for the event
     * @param bool $debugMode Whether debug mode is enabled
     * @param ServiceModeCheckerInterface $serviceModeChecker Service mode checker
     * @param array<string, mixed>|null $parameters Optional parameters for the event
     */
    public function logEventConditional(
        string $eventPrefix,
        string $eventMessage,
        bool $debugMode,
        ServiceModeCheckerInterface $serviceModeChecker,
        ?array $parameters = null
    ): void {
        if ($debugMode && $serviceModeChecker->isServiceMode()) {
            $this->logEvent($eventPrefix, $eventMessage, $parameters);
        }
    }

    /**
     * Logs an event with automatic context capture only when both debug mode and service mode are active.
     *
     * @param string $eventMessage The message for the event
     * @param bool $debugMode Whether debug mode is enabled
     * @param ServiceModeCheckerInterface $serviceModeChecker Service mode checker
     * @param array<string, mixed>|null $parameters Optional parameters for the event
     */
    public function logEventConditionalWithContext(
        string $eventMessage,
        bool $debugMode,
        ServiceModeCheckerInterface $serviceModeChecker,
        ?array $parameters = null
    ): void {
        if ($debugMode && $serviceModeChecker->isServiceMode()) {
            $this->logEvent($this->formatContext($this->extractCallerContext()), $eventMessage, $parameters);
        }
    }

    /**
     * Extracts caller context from the call stack.
     *
     * @return array<string, mixed> Array with 'class', 'function', 'file', 'line' keys
     */
    private function extractCallerContext(): array
    {
        // backtrace[0] = extractCallerContext
        // backtrace[1] = logEventWithContext or logEventConditionalWithContext (direct callers)
        // backtrace[2] = actual caller
        $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 3);
        $caller = $trace[2] ?? [];

        return [
            'class' => $caller['class'] ?? null,
            'function' => $caller['function'] ?? null,
            'file' => basename($caller['file'] ?? ''),
            'line' => $caller['line'] ?? 0,
        ];
    }

    /**
     * Formats a context array into a log prefix string.
     *
     * @param array<string, mixed> $context Context array from extractCallerContext
     *
     * @return string Formatted context prefix like '[ClassName::method@file.php:123]'
     */
    private function formatContext(array $context): string
    {
        if (!empty($context['class'])) {
            return sprintf(
                '[%s::%s@%s:%d]',
                substr(strrchr($context['class'], '\\'), 1),
                $context['function'],
                $context['file'],
                $context['line']
            );
        }

        return sprintf('[%s@%s:%d]', $context['function'], $context['file'], $context['line']);
    }

    /**
     * Retrieves the last 'numLines' lines of the debug log.
     *
     * @param int $numLines The number of lines to retrieve
     *
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
