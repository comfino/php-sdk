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
     * Logs a debug event. Caller location is always captured into $parameters['_caller'].
     *
     * The optional $eventType is rendered as a "[TYPE] " label prefixing the message while the message itself stays
     * clean for parsing; the machine-readable caller location lives in the PSR-3 context array, not the message.
     *
     * @param string $eventMessage The event message (no prefix — keep it clean)
     * @param array<string, mixed>|null $parameters Structured context for the log entry
     * @param string|null $eventType Optional semantic label, e.g. 'API_TIMEOUT'. Rendered as "[API_TIMEOUT] $message"
     * @param int $contextDepth Extra backtrace frames to skip; pass 1 per static facade layer between caller and here
     */
    public function logEvent(
        string $eventMessage,
        ?array $parameters = null,
        ?string $eventType = null,
        int $contextDepth = 0
    ): void {
        $params = $parameters ?? [];
        $params['_caller'] = $this->formatContext($this->extractCallerContext($contextDepth));

        $logMessage = $eventType !== null ? "[$eventType] $eventMessage" : $eventMessage;

        self::$logger?->debug($logMessage, $params);
    }

    /**
     * Logs a debug event only when both debug mode and service mode are active.
     *
     * @param string $eventMessage The event message (no prefix — keep it clean)
     * @param bool $debugMode Whether debug mode is enabled
     * @param ServiceModeCheckerInterface $serviceModeChecker Service mode checker
     * @param array<string, mixed>|null $parameters Structured context for the log entry
     * @param string|null $eventType Optional semantic label, e.g. 'API_TIMEOUT'
     * @param int $contextDepth Extra backtrace frames to skip; pass 1 per static facade layer between caller and here
     */
    public function logEventConditional(
        string $eventMessage,
        bool $debugMode,
        ServiceModeCheckerInterface $serviceModeChecker,
        ?array $parameters = null,
        ?string $eventType = null,
        int $contextDepth = 0
    ): void {
        if ($debugMode && $serviceModeChecker->isServiceMode()) {
            /* Capture context only when logging actually happens — avoids backtrace overhead for suppressed calls.
               +1 skips this method's frame, so the captured caller is the real caller, not logEventConditional. */
            $this->logEvent($eventMessage, $parameters, $eventType, $contextDepth + 1);
        }
    }

    /**
     * Extracts caller context from the call stack.
     *
     * @param int $extraDepth Additional backtrace frames to skip beyond the standard SDK call stack. Pass 1 for each
     *                        extra static facade layer between the real caller and logEvent().
     *
     * @return array<string, mixed> Array with 'class', 'function', 'file', 'line' keys
     */
    private function extractCallerContext(int $extraDepth = 0): array
    {
        /* Standard frame layout when calling logEvent() directly:
             backtrace[0] = extractCallerContext
             backtrace[1] = logEvent (this method)
             backtrace[2] = actual caller (target)
           Each extra static facade layer between the caller and logEvent() adds one frame ($extraDepth). */
        $depth = 2 + $extraDepth;
        $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, $depth + 1);
        $caller = $trace[$depth] ?? [];

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
