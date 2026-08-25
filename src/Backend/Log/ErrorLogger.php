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
use Comfino\Api\Dto\Plugin\ErrorCategory;
use Comfino\Api\Dto\Plugin\ErrorSeverity;
use Comfino\Api\Dto\Plugin\OperationContext;
use Comfino\Api\Dto\Plugin\ShopPluginError;
use Comfino\Api\Request\ReportShopPluginError;
use Comfino\Api\Serializer\Json;
use Comfino\Api\SerializerInterface;
use Comfino\Backend\Clock\ClockInterface;
use Comfino\Backend\Clock\SystemClock;
use Comfino\Backend\FileUtils;
use Comfino\Backend\Queue\OutboundRequestQueue;
use Comfino\Backend\Queue\ReportErrorHandler;
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

    /** Environment keys promoted to dedicated ShopPluginError fields (removed from the environment array). */
    private const VERSION_ENV_KEYS = ['plugin_version', 'platform_version', 'shop_version', 'php_version'];

    /** @var array<string, self> One instance per tenant scope, keyed by the scope string. */
    private static array $instances = [];
    /** @var array<string, LoggerInterface> One PSR-3 logger per tenant scope, keyed by the scope string. */
    private static array $loggers = [];

    private bool $globalHandlersRegistered = false;
    private bool $shutdownHandlerRegistered = false;

    /**
     * Private constructor to enforce the per-scope instance pattern.
     *
     * @param ClientInterface $apiClient API client instance
     * @param string $logFilePath Path to the log file
     * @param string $host Shop host name
     * @param string $platform Shop platform name
     * @param string $modulePath Path to the module directory
     * @param array<string, mixed> $environment Supplementary environment variables (version keys promoted out)
     * @param string $pluginVersion Comfino plugin version
     * @param string $platformVersion E-commerce platform version
     * @param string $phpVersion PHP runtime version
     * @param ErrorMessageNormalizer $normalizer Strips dynamic parts from messages/stack traces before transmission
     * @param OutboundRequestQueue|null $outboundQueue Optional outbound request queue for fire-and-forget delivery
     * @param SerializerInterface $serializer Serializer for the queue payload encoding
     * @param ClockInterface $clock Clock for the error occurrence timestamp
     * @param string $scope Tenant scope this instance belongs to
     */
    private function __construct(
        private readonly ClientInterface $apiClient,
        private readonly string $logFilePath,
        private readonly string $host,
        private readonly string $platform,
        private readonly string $modulePath,
        private readonly array $environment,
        private readonly string $pluginVersion,
        private readonly string $platformVersion,
        private readonly string $phpVersion,
        private readonly ErrorMessageNormalizer $normalizer,
        private readonly ?OutboundRequestQueue $outboundQueue = null,
        private readonly SerializerInterface $serializer = new Json(),
        private readonly ClockInterface $clock = new SystemClock(),
        private readonly string $scope = ''
    ) {
    }

    /**
     * Retrieves a per-scope instance of the ErrorLogger.
     *
     * The version-bearing keys (plugin_version, platform_version / shop_version, php_version) are extracted from
     * $environment and promoted to dedicated {@see ShopPluginError} fields; the remaining keys are kept as
     * supplementary debug data.
     *
     * A distinct instance is kept for each $scope. Without that, the first tenant to call this method in a long-lived
     * process owned the reporting identity for every tenant after it: their errors were reported under the first
     * tenant's host, platform, environment and plugin version, and sent with the first tenant's API key. The default
     * empty scope preserves the original single-instance behavior for the single-shop plugins that do not need tenant
     * isolation.
     *
     * @param ClientInterface $apiClient API client instance
     * @param string $logFilePath Path to the log file
     * @param string $host Shop host name
     * @param string $platform Shop platform name
     * @param string $modulePath Path to the module directory
     * @param array<string, mixed> $environment Environment variables (version keys are promoted out)
     * @param OutboundRequestQueue|null $outboundQueue When provided, error reports are enqueued as pure fire-and-forget
     *                                                 instead of being sent inline. The queue must have a
     *                                                 {@see ReportErrorHandler} registered (done automatically by
     *                                                 {@see OutboundRequestQueueFactory})
     * @param SerializerInterface|null $serializer Serializer for the queue payload encoding; defaults to JSON
     * @param ClockInterface|null $clock Injectable clock for the error occurrence timestamp; defaults to SystemClock
     * @param string $scope Tenant scope discriminator (empty string = global/default scope)
     *
     * @return self The instance bound to $scope
     */
    public static function getInstance(
        ClientInterface $apiClient,
        string $logFilePath,
        string $host,
        string $platform,
        string $modulePath,
        array $environment,
        ?OutboundRequestQueue $outboundQueue = null,
        ?SerializerInterface $serializer = null,
        ?ClockInterface $clock = null,
        string $scope = ''
    ): self {
        if (!isset(self::$instances[$scope])) {
            $pluginVersion = (string) ($environment['plugin_version'] ?? '');
            $platformVersion = (string) ($environment['platform_version'] ?? $environment['shop_version'] ?? '');
            $phpVersion = (string) ($environment['php_version'] ?? '');

            $supplementaryEnv = array_diff_key($environment, array_flip(self::VERSION_ENV_KEYS));

            self::$instances[$scope] = new self(
                $apiClient,
                $logFilePath,
                $host,
                $platform,
                $modulePath,
                $supplementaryEnv,
                $pluginVersion,
                $platformVersion,
                $phpVersion,
                new ErrorMessageNormalizer(),
                $outboundQueue,
                $serializer ?? new Json(),
                $clock ?? new SystemClock(),
                $scope
            );
        }

        return self::$instances[$scope];
    }

    /**
     * Initializes the ErrorLogger with a custom logger instance for the given scope.
     *
     * The logger is per scope for the same reason the instance is: a shared destination means one tenant's errors land
     * in another tenant's log file, which is both a support problem and a data-separation one.
     *
     * @param LoggerInterface $logger Custom logger instance
     * @param string $scope Tenant scope discriminator (empty string = global/default scope)
     */
    public static function init(LoggerInterface $logger, string $scope = ''): void
    {
        self::$loggers[$scope] = $logger;
    }

    /**
     * Returns the scopes that currently hold a cached instance.
     *
     * Diagnostics for the retention of every scope-addressed class in the SDK shares: the cache keeps the first
     * instance built for a scope and never evicts it, so a host that builds per request must release per request.
     * Asserting this is empty at the end of a unit of work turns a forgotten `reset()` into a failing test rather
     * than a slow leak.
     *
     * @return string[] Scope discriminators, in insertion order
     */
    public static function scopes(): array
    {
        return array_keys(self::$instances);
    }

    /**
     * Returns how many scopes hold a cached instance.
     */
    public static function scopeCount(): int
    {
        return count(self::$instances);
    }

    /**
     * Drops cached instances and their loggers, unregistering any global handlers they installed.
     *
     * @param string|null $scope When given, only the instance bound to this scope is dropped; when null (default), all
     *                           cached instances (every scope) are dropped.
     */
    public static function reset(?string $scope = null): void
    {
        if ($scope === null) {
            foreach (self::$instances as $instance) {
                $instance->unregisterGlobalHandlers();
            }

            self::$instances = [];
            self::$loggers = [];

            return;
        }

        if (isset(self::$instances[$scope])) {
            self::$instances[$scope]->unregisterGlobalHandlers();
        }

        unset(self::$instances[$scope], self::$loggers[$scope]);
    }

    /**
     * Returns the tenant scope this instance is bound to.
     */
    public function getScope(): string
    {
        return $this->scope;
    }

    /**
     * Returns the PSR-3 logger registered for this instance's scope.
     *
     * @return LoggerInterface|null The scope's logger, or null when none was registered
     */
    public function getLogger(): ?LoggerInterface
    {
        return self::$loggers[$this->scope] ?? null;
    }

    /**
     * Reports an error to the Comfino API.
     *
     * Caller location (class::method@file:line) is captured automatically from the call stack and stored in
     * environment['caller'] for debugging. It does NOT appear in $errorMessage, keeping the message clean for
     * fingerprinting. The classification ({@see ErrorCategory}, {@see ErrorSeverity}, {@see OperationContext}) is
     * supplied explicitly so the API side never has to infer it from message substrings.
     *
     * @param ErrorCategory $category Structured technical category — never guessed from text
     * @param ErrorSeverity $severity Severity hint for the API classification pipeline
     * @param OperationContext $context What the plugin was doing when the error occurred
     * @param string $errorCode PHP error level (E_WARNING), exception class (TypeError), or HTTP status code (500)
     * @param string $errorMessage The error text — dynamic parts are normalized before transmission
     * @param string|null $apiRequestUrl Full URL for debug (host + query params)
     * @param string|null $apiRequest Request body
     * @param string|null $apiResponse Response body
     * @param string|null $stackTrace PHP stack trace (shop-specific path prefixes are stripped before transmission)
     */
    public function sendError(
        ErrorCategory $category,
        ErrorSeverity $severity,
        OperationContext $context,
        string $errorCode,
        string $errorMessage,
        ?string $apiRequestUrl = null,
        ?string $apiRequest = null,
        ?string $apiResponse = null,
        ?string $stackTrace = null,
        int $contextDepth = 0
    ): void {
        // Capture the occurrence timestamp immediately — before any queue delay or processing.
        $occurredAt = $this->clock->now();

        if (
            preg_match('/Error .*in |Exception .*in /', $errorMessage) &&
            !str_contains($errorMessage, $this->modulePath)
        ) {
            // Ignore errors and exceptions outside the module directory and SDK code.
            return;
        }

        /* Capture caller location as supplementary debug info — kept out of error_message so the message stays clean
           for fingerprinting. */
        $callEnv = array_merge($this->environment, [
            'caller' => $this->formatContext($this->extractCallerContext($contextDepth)),
        ]);

        $normalizedMessage = $this->normalizer->normalizeMessage($errorMessage);
        $normalizedTrace = $stackTrace !== null ? $this->normalizer->normalizeStackTrace($stackTrace) : null;

        $error = new ShopPluginError(
            $this->host,
            $this->platform,
            $this->pluginVersion,
            $this->platformVersion,
            $this->phpVersion,
            $category,
            $severity,
            $context,
            $errorCode,
            $normalizedMessage,
            $callEnv,
            ReportShopPluginError::extractApiEndpoint($apiRequestUrl),
            $apiRequestUrl,
            $apiRequest,
            $apiResponse,
            $normalizedTrace,
            $occurredAt
        );

        $logPrefix = "[{$category->value}][{$context->value}]";
        $logMessage = $this->buildLocalLogMessage(
            $errorMessage,
            $apiRequestUrl,
            $apiRequest,
            $apiResponse,
            $stackTrace
        );

        if (getenv('COMFINO_DEV_ENV') === 'TRUE' && getenv('COMFINO_FORCE_ERRORS_SENDING') !== 'TRUE') {
            // Dev environment with sending disabled: local log only.
            $this->logError($logPrefix, $logMessage);
        } elseif ($this->outboundQueue !== null) {
            /* Queue path (preferred): pure fire-and-forget — zero inline blocking. The local log is the immediate
               record; the drain delivers to the API asynchronously. */
            $this->outboundQueue->enqueue(
                ReportErrorHandler::OPERATION_TYPE,
                $error->toQueuePayload($this->serializer),
                /* The logger's scope is the queue's tenant: an error report is this merchant's outbound traffic, so it
                   belongs in this merchant's queue partition and must not be paced by another merchant's backlog. */
                $this->scope !== '' ? $this->scope : null
            );

            $this->logError($logPrefix, $logMessage);
        } else {
            // Legacy path: direct synchronous API call, unchanged for platforms not yet using the queue.
            try {
                $this->apiClient->sendLoggedError($error);
            } catch (Throwable) {
                $this->logError($logPrefix, $logMessage);
            }
        }
    }

    /**
     * Locally logs an error message with the specified prefix.
     *
     * @param string $errorPrefix Error prefix
     * @param string $errorMessage Error message
     */
    public function logError(string $errorPrefix, string $errorMessage): void
    {
        if (($logger = $this->getLogger()) !== null) {
            try {
                $logger->error("$errorPrefix: $errorMessage");
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

        $errorLevel = $this->getErrorTypeName($errorType);

        $this->sendError(
            ErrorCategory::PhpError,
            $this->mapErrorSeverity($errorType),
            OperationContext::Unknown,
            $errorLevel,
            "Error $errorLevel in $file:$line: $errorMessage"
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
        $exceptionClass = (new \ReflectionClass($exception))->getShortName();

        // Map the exception class to a structured ErrorCategory (most specific subtype first).
        $category = match (true) {
            $exception instanceof \ArgumentCountError => ErrorCategory::ExceptionArgCount,
            $exception instanceof \TypeError => ErrorCategory::ExceptionTypeError,
            $exception instanceof \ParseError => ErrorCategory::ExceptionParseError,
            $exception instanceof \DivisionByZeroError => ErrorCategory::ExceptionDivision,
            $exception instanceof \LogicException => ErrorCategory::ExceptionLogicError,
            $exception instanceof \Error => ErrorCategory::ExceptionError,
            // A Throwable that is not an \Error is necessarily an \Exception.
            default => ErrorCategory::ExceptionGeneric,
        };

        $this->sendError(
            $category,
            ErrorSeverity::Error,
            OperationContext::Unknown,
            $exceptionClass,
            sprintf(
                'Exception %s in %s:%d: %s',
                $exceptionClass,
                $exception->getFile(),
                $exception->getLine(),
                $exception->getMessage()
            ),
            null,
            null,
            null,
            $exception->getTraceAsString()
        );
    }

    /**
     * Installs this logger's PHP error, exception and shutdown handlers process-wide.
     *
     * **This is a process-global side effect, and it is opt-in for that reason.** The handlers it installs capture
     * every fatal in the process, not only the ones raised by Comfino code, and they report each one under *this*
     * instance's identity — its host, platform, environment and API key. In a single-tenant plugin that is exactly
     * right: the process serves one shop and dies at the end of the request. In a long-lived multi-tenant host it is
     * not: a fatal raised while serving merchant B is reported as merchant A's, under merchant A's key, if merchant A's
     * logger happened to register first.
     *
     * A multi-tenant host has two correct options. Either never call this and let the framework's own error handling
     * do the reporting, or bracket each unit of work with {@see withGlobalHandlers()} so the handlers belong to the
     * tenant currently being served. Never call it once at boot and leave it.
     *
     * Registration is idempotent per instance, and a second scope's logger may register its own handlers on top —
     * PHP's handlers stack, and {@see unregisterGlobalHandlers()} pops exactly the frame this call pushed.
     *
     * The shutdown handler cannot be unregistered (PHP offers no counterpart to `register_shutdown_function()`), so it
     * is disarmed instead: once {@see unregisterGlobalHandlers()} has run, {@see shutdown()} reports nothing.
     */
    public function registerGlobalHandlers(): void
    {
        if (getenv('COMFINO_DEV_ENV') === 'TRUE' && getenv('COMFINO_FORCE_ERRORS_HANDLING') !== 'TRUE') {
            // Do not handle errors and exceptions in development environment unless forced.
            return;
        }

        if ($this->globalHandlersRegistered) {
            return;
        }

        set_error_handler([$this, 'errorHandler'], self::CATCH_ERRORS_MASK);
        set_exception_handler([$this, 'exceptionHandler']);

        if (!$this->shutdownHandlerRegistered) {
            /* Registered at most once per instance: PHP has no way to remove a shutdown function, so re-registering on
               every register/unregister cycle in a worker would accumulate one callback per job for the life of the
               process. The $globalHandlersRegistered flag is what arms and disarms it. */
            register_shutdown_function([$this, 'shutdown']);

            $this->shutdownHandlerRegistered = true;
        }

        $this->globalHandlersRegistered = true;
    }

    /**
     * Removes the error and exception handlers installed by {@see registerGlobalHandlers()} and disarms the shutdown
     * reporter, restoring whatever handling was in place before.
     *
     * Call this when the unit of work that owned the handlers ends — the end of a queue job, the end of a webhook
     * request — so a later fatal is not attributed to a tenant that is no longer being served.
     */
    public function unregisterGlobalHandlers(): void
    {
        if (!$this->globalHandlersRegistered) {
            return;
        }

        restore_error_handler();
        restore_exception_handler();

        $this->globalHandlersRegistered = false;
    }

    /**
     * Runs the callback with this logger's global handlers installed, restoring the previous handling afterwards even
     * if the callback throws.
     *
     * This is the shape a multi-tenant worker wants: the handlers are live for exactly the span in which this tenant is
     * the one being served, so a fatal can only ever be reported under the identity that caused it.
     *
     * @template T
     * @param callable(): T $callback The unit of work to run under this logger's handlers
     *
     * @return T Whatever the callback returns
     */
    public function withGlobalHandlers(callable $callback)
    {
        $this->registerGlobalHandlers();

        try {
            return $callback();
        } finally {
            $this->unregisterGlobalHandlers();
        }
    }

    /**
     * Installs this logger's PHP error, exception and shutdown handlers process-wide.
     *
     * @deprecated Use {@see registerGlobalHandlers()}, whose name says that the effect is process-global. This alias
     *             is kept so existing plugin bootstraps keep working unchanged.
     */
    public function initHandlers(): void
    {
        $this->registerGlobalHandlers();
    }

    /**
     * Returns true while this instance's global handlers are installed.
     */
    public function hasGlobalHandlers(): bool
    {
        return $this->globalHandlersRegistered;
    }

    /**
     * Handles PHP shutdown.
     *
     * Reports nothing once {@see unregisterGlobalHandlers()} has run: the callback itself cannot be removed from PHP's
     * shutdown list, so the registration flag is what decides whether this instance still speaks for the process.
     */
    public function shutdown(): void
    {
        if (!$this->globalHandlersRegistered) {
            return;
        }

        if (($error = error_get_last()) !== null && ($error['type'] & self::CATCH_ERRORS_MASK)) {
            $errorLevel = $this->getErrorTypeName($error['type']);

            $this->sendError(
                ErrorCategory::PhpError,
                $this->mapErrorSeverity($error['type']),
                OperationContext::Unknown,
                $errorLevel,
                "Error $errorLevel in $error[file]:$error[line]: $error[message]"
            );
        }

        $this->unregisterGlobalHandlers();
    }

    /**
     * Builds the local log message body, appending API request/response details and the stack trace when present.
     *
     * @param string $errorMessage Error message
     * @param string|null $apiRequestUrl API request URL
     * @param string|null $apiRequest API request payload
     * @param string|null $apiResponse API response payload
     * @param string|null $stackTrace Error stack trace
     *
     * @return string The composed local log message
     */
    private function buildLocalLogMessage(
        string $errorMessage,
        ?string $apiRequestUrl,
        ?string $apiRequest,
        ?string $apiResponse,
        ?string $stackTrace
    ): string {
        $requestInfo = array_filter([
            $apiRequestUrl !== null ? "API URL: $apiRequestUrl" : null,
            $apiRequest !== null ? "API request: $apiRequest" : null,
            $apiResponse !== null ? "API response: $apiResponse" : null,
        ]);

        $logMessage = $errorMessage;

        if (count($requestInfo) > 0) {
            $logMessage .= "\n" . implode("\n", $requestInfo);
        }

        if ($stackTrace !== null) {
            $logMessage .= "\nStack trace: $stackTrace";
        }

        return $logMessage;
    }

    /**
     * Extracts caller context from the call stack.
     *
     * @param int $extraDepth Additional backtrace frames to skip beyond the standard SDK call stack. Pass 1 for each
     *                        extra static facade layer between the real caller and sendError().
     *
     * @return array<string, mixed> Array with 'class', 'function', 'file', 'line' keys
     */
    private function extractCallerContext(int $extraDepth = 0): array
    {
        /* Standard frame layout when calling sendError() directly:
             backtrace[0] = extractCallerContext
             backtrace[1] = sendError (this method)
             backtrace[2] = actual caller (target)
           Each extra static facade layer between the caller and sendError() adds one frame ($extraDepth). */
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

    /**
     * Maps a PHP error type to structured {@see ErrorSeverity}.
     *
     * @param int $errorType PHP error type constant
     *
     * @return ErrorSeverity The matching severity
     */
    private function mapErrorSeverity(int $errorType): ErrorSeverity
    {
        return match ($errorType) {
            E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR, E_RECOVERABLE_ERROR, E_PARSE, E_USER_ERROR => ErrorSeverity::Error,
            E_WARNING, E_CORE_WARNING, E_COMPILE_WARNING, E_USER_WARNING => ErrorSeverity::Warning,
            E_NOTICE, E_USER_NOTICE, E_DEPRECATED, E_USER_DEPRECATED => ErrorSeverity::Notice,
            default => ErrorSeverity::Error,
        };
    }
}
