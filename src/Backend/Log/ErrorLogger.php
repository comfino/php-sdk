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

use Comfino\Api\ClientInterface;
use Comfino\Api\Dto\Plugin\ShopPluginError;
use Comfino\Backend\FileUtils;
use Exception;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * ErrorLogger class for logging errors to a file.
 */
final class ErrorLogger extends Logger
{
    private const CATCH_ERRORS_MASK = E_ERROR | E_RECOVERABLE_ERROR | E_PARSE;
    private const ERROR_TYPES = [
        E_ERROR => 'E_ERROR',
        E_WARNING => 'E_WARNING',
        E_PARSE => 'E_PARSE',
        E_NOTICE => 'E_NOTICE',
        E_CORE_ERROR => 'E_CORE_ERROR',
        E_CORE_WARNING => 'E_CORE_WARNING',
        E_COMPILE_ERROR => 'E_COMPILE_ERROR',
        E_COMPILE_WARNING => 'E_COMPILE_WARNING',
        E_USER_ERROR => 'E_USER_ERROR',
        E_USER_WARNING => 'E_USER_WARNING',
        E_USER_NOTICE => 'E_USER_NOTICE',
        E_RECOVERABLE_ERROR => 'E_RECOVERABLE_ERROR',
        E_DEPRECATED => 'E_DEPRECATED',
        E_USER_DEPRECATED => 'E_USER_DEPRECATED',
    ];

    private static ?self $instance = null;
    private static ?LoggerInterface $logger = null;

    /**
     * Returns the singleton instance of the ErrorLogger.
     *
     * @param ClientInterface $apiClient API client instance
     * @param string $logFilePath Path to the log file
     * @param string $host Shop host name
     * @param string $platform Shop platform name
     * @param string $modulePath Path to the module directory
     * @param array<string, mixed> $environment Environment variables
     *
     * @return self The singleton instance of the ErrorLogger
     */
    public static function getInstance(
        ClientInterface $apiClient,
        string $logFilePath,
        string $host,
        string $platform,
        string $modulePath,
        array $environment
    ): self {
        if (self::$instance === null) {
            self::$instance = new self($apiClient, $logFilePath, $host, $platform, $modulePath, $environment);
        }

        return self::$instance;
    }

    /**
     * Initializes the ErrorLogger with a custom logger instance.
     *
     * @param LoggerInterface $logger Custom logger instance
     */
    public static function init(LoggerInterface $logger): void
    {
        self::$logger = $logger;
    }

    /**
     * Resets the ErrorLogger instance and logger to null.
     */
    public static function reset(): void
    {
        self::$instance = null;
        self::$logger = null;
    }

    /**
     * Private constructor to enforce a singleton pattern.
     *
     * @param ClientInterface $apiClient API client instance
     * @param string $logFilePath Path to the log file
     * @param string $host Shop host name
     * @param string $platform Shop platform name
     * @param string $modulePath Path to the module directory
     * @param array<string, mixed> $environment Environment variables
     */
    private function __construct(
        private readonly ClientInterface $apiClient,
        private readonly string $logFilePath,
        private readonly string $host,
        private readonly string $platform,
        private readonly string $modulePath,
        private readonly array $environment
    ) {
    }

    /**
     * Returns the logger instance.
     *
     * @return LoggerInterface|null
     */
    public function getLogger(): ?LoggerInterface
    {
        return self::$logger;
    }

    /**
     * Sends an error to the Comfino API with automatic context capture.
     *
     * Extracts the caller's class, method, file, and line number using debug_backtrace
     * and formats them into an error prefix. Passes the prefix to sendError().
     *
     * @param string $errorCode Error code
     * @param string $errorMessage Error message
     * @param string|null $apiRequestUrl API request URL
     * @param string|null $apiRequest API request payload
     * @param string|null $apiResponse API response payload
     * @param string|null $stackTrace Error stack trace
     */
    public function sendErrorWithContext(
        string $errorCode,
        string $errorMessage,
        ?string $apiRequestUrl = null,
        ?string $apiRequest = null,
        ?string $apiResponse = null,
        ?string $stackTrace = null
    ): void {
        $this->sendError(
            $this->formatContext($this->extractCallerContext()),
            $errorCode,
            $errorMessage,
            $apiRequestUrl,
            $apiRequest,
            $apiResponse,
            $stackTrace
        );
    }

    /**
     * Shorthand alias for sendErrorWithContext().
     *
     * @param string $errorCode Error code
     * @param string $errorMessage Error message
     * @param string|null $apiRequestUrl API request URL
     * @param string|null $apiRequest API request payload
     * @param string|null $apiResponse API response payload
     * @param string|null $stackTrace Error stack trace
     */
    public function sendErrorAuto(
        string $errorCode,
        string $errorMessage,
        ?string $apiRequestUrl = null,
        ?string $apiRequest = null,
        ?string $apiResponse = null,
        ?string $stackTrace = null
    ): void {
        $this->sendErrorWithContext($errorCode, $errorMessage, $apiRequestUrl, $apiRequest, $apiResponse, $stackTrace);
    }

    /**
     * Sends an error to the Comfino API.
     *
     * @param string $errorPrefix Error prefix
     * @param string $errorCode Error code
     * @param string $errorMessage Error message
     * @param string|null $apiRequestUrl API request URL
     * @param string|null $apiRequest API request payload
     * @param string|null $apiResponse API response payload
     * @param string|null $stackTrace Error stack trace
     */
    public function sendError(
        string $errorPrefix,
        string $errorCode,
        string $errorMessage,
        ?string $apiRequestUrl = null,
        ?string $apiRequest = null,
        ?string $apiResponse = null,
        ?string $stackTrace = null
    ): void {
        $formattedErrorMessage = "$errorPrefix: $errorMessage";

        if (
            preg_match('/Error .*in |Exception .*in /', $formattedErrorMessage) &&
            !str_contains($formattedErrorMessage, $this->modulePath)
        ) {
            // Ignore errors and exceptions outside the module directory and SDK code.
            return;
        }

        if (getenv('COMFINO_DEV_ENV') === 'TRUE' && getenv('COMFINO_FORCE_ERRORS_SENDING') !== 'TRUE') {
            // Do not send errors in development environment unless forced.
            $errorsSendingDisabled = true;
        } else {
            $errorsSendingDisabled = false;
        }

        $error = new ShopPluginError(
            $this->host,
            $this->platform,
            $this->environment,
            $errorCode,
            $formattedErrorMessage,
            $apiRequestUrl,
            $apiRequest,
            $apiResponse,
            $stackTrace
        );

        if ($errorsSendingDisabled || !$this->apiClient->sendLoggedError($error)) {
            $requestInfo = [];

            if ($apiRequestUrl !== null) {
                $requestInfo[] = "API URL: $apiRequestUrl";
            }

            if ($apiRequest !== null) {
                $requestInfo[] = "API request: $apiRequest";
            }

            if ($apiResponse !== null) {
                $requestInfo[] = "API response: $apiResponse";
            }

            if (count($requestInfo) > 0) {
                $errorMessage .= "\n" . implode("\n", $requestInfo);
            }

            if ($stackTrace !== null) {
                $errorMessage .= "\nStack trace: $stackTrace";
            }

            $this->logError($errorPrefix, $errorMessage);
        }
    }

    /**
     * Extracts caller context from the call stack.
     *
     * @return array<string, mixed> Array with 'class', 'function', 'file', 'line' keys
     */
    private function extractCallerContext(): array
    {
        // Skip this method, sendErrorWithContext/sendErrorAuto, and sendError in the stack.
        // backtrace[0] = extractCallerContext
        // backtrace[1] = sendErrorWithContext or sendErrorAuto
        // backtrace[2] = sendError
        // backtrace[3] = actual caller
        $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 4);
        $caller = $trace[3] ?? [];

        return [
            'class' => $caller['class'] ?? null,
            'function' => $caller['function'] ?? null,
            'file' => basename($caller['file'] ?? ''),
            'line' => $caller['line'] ?? 0,
        ];
    }

    /**
     * Formats a context array into an error prefix string.
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
     * Locally logs an error message with the specified prefix.
     *
     * @param string $errorPrefix Error prefix
     * @param string $errorMessage Error message
     */
    public function logError(string $errorPrefix, string $errorMessage): void
    {
        if (self::$logger !== null) {
            try {
                self::$logger->error("$errorPrefix: $errorMessage");
            } catch (Exception $e) {
                if (FileUtils::isWritable($this->logFilePath)) {
                    FileUtils::append($this->logFilePath, "$errorPrefix: $errorMessage");
                    FileUtils::append(
                        $this->logFilePath,
                        "Logger error: {$e->getMessage()} in {$e->getFile()}:{$e->getLine()}"
                    );
                }
            }
        } elseif (FileUtils::isWritable($this->logFilePath)) {
            FileUtils::append($this->logFilePath, "$errorPrefix: $errorMessage");
        }
    }

    /**
     * Retrieves the error log with the specified number of lines.
     *
     * @param int $numLines Number of lines to retrieve
     *
     * @return string Error log content
     */
    public function getErrorLog(int $numLines): string
    {
        $actualLogPath = $this->findActualLogFile($this->logFilePath);

        if ($actualLogPath === null || !FileUtils::exists($actualLogPath)) {
            return '';
        }

        return implode('', FileUtils::readLastLines($actualLogPath, $numLines));
    }

    /**
     * Clears the error log.
     *
     * @return int The number of lines cleared
     */
    public function clearLogs(): int
    {
        return $this->clearLogFiles($this->logFilePath);
    }

    /**
     * Handles PHP errors.
     *
     * @param int $errorType Error type
     * @param string $errorMessage Error message
     * @param string $file File where the error occurred
     * @param int $line Line number where the error occurred
     *
     * @return bool False to allow the error to be handled by other error handlers
     */
    public function errorHandler(int $errorType, string $errorMessage, string $file, int $line): bool
    {
        if (!($errorType & self::CATCH_ERRORS_MASK)) {
            return false;
        }

        $this->sendError(
            "Error {$this->getErrorTypeName($errorType)} in $file:$line",
            (string) $errorType,
            $errorMessage
        );

        return false;
    }

    /**
     * Handles PHP exceptions.
     *
     * @param Throwable $exception Exception object
     *
     * @return void
     */
    public function exceptionHandler(Throwable $exception): void
    {
        $this->sendError(
            'Exception ' . get_class($exception) . " in {$exception->getFile()}:{$exception->getLine()}",
            (string) $exception->getCode(),
            $exception->getMessage(),
            null,
            null,
            null,
            $exception->getTraceAsString()
        );
    }

    /**
     * Initializes error and exception handlers.
     */
    public function initHandlers(): void
    {
        if (getenv('COMFINO_DEV_ENV') === 'TRUE' && getenv('COMFINO_FORCE_ERRORS_HANDLING') !== 'TRUE') {
            // Do not handle errors and exceptions in development environment unless forced.
            return;
        }

        static $initialized = false;

        if (!$initialized) {
            set_error_handler([$this, 'errorHandler'], self::CATCH_ERRORS_MASK);
            set_exception_handler([$this, 'exceptionHandler']);
            register_shutdown_function([$this, 'shutdown']);

            $initialized = true;
        }
    }

    /**
     * Handles PHP shutdown.
     */
    public function shutdown(): void
    {
        if (($error = error_get_last()) !== null && ($error['type'] & self::CATCH_ERRORS_MASK)) {
            $this->sendError(
                "Error {$this->getErrorTypeName($error['type'])} in $error[file]:$error[line]",
                (string) $error['type'],
                $error['message']
            );
        }

        restore_error_handler();
        restore_exception_handler();
    }

    /**
     * Returns the error type name.
     *
     * @param int $errorType Error type numeric value
     *
     * @return string Error type name
     */
    private function getErrorTypeName(int $errorType): string
    {
        return self::ERROR_TYPES[$errorType] ?? 'UNKNOWN';
    }
}
