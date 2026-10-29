<?php

/**
 * ComfinoPay PHP SDK
 *
 * Backend routines for e-commerce platforms integration with the ComfinoPay payment gateway API.
 *
 * @package Comfino\Backend\Api
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
 * @copyright Copyright (c) 2026 by ComfinoPay sp. z o.o.
 * @license https://opensource.org/licenses/BSD-3-Clause BSD 3-Clause License
 * @link https://github.com/comfino/php-sdk
 */

declare(strict_types=1);

namespace Comfino\Backend\Api;

use Closure;
use Comfino\Api\Dto\Plugin\ErrorCategory;
use Comfino\Api\Dto\Plugin\ErrorSeverity;
use Comfino\Api\Dto\Plugin\OperationContext;
use Comfino\Backend\Log\DebugLogger;
use Comfino\Backend\Log\ErrorLogger;
use Throwable;

/**
 * Logs and reports an exception raised by an API client call.
 *
 * Writes the debug events (API_TIMEOUT for timeouts, API_NETWORK_ERROR for network errors, API_ERROR always) and sends
 * one structured error report. The two policy hooks let the host decide what is reported and at which severity without
 * re-implementing the extraction.
 *
 * Usage:
 *
 *     $details = (new ApiErrorProcessor($errorLogger, $debugMode ? $debugLogger : null))
 *         ->process(OperationContext::OrderCreation, ErrorCategory::ApiCommunication, ErrorSeverity::Critical, $e);
 */
final class ApiErrorProcessor
{
    /**
     * @param ErrorLogger $errorLogger Logger that sends the error report
     * @param DebugLogger|null $debugLogger Logger for the debug events, or null to skip them (debug mode off)
     * @param (Closure(Throwable): bool)|null $isReportable Returns false for exceptions that are logged locally only; null reports every exception
     * @param (Closure(Throwable, ErrorSeverity): ErrorSeverity)|null $resolveSeverity Maps the exception and the caller's severity to the reported
     *                                                                                 one; null keeps the caller's
     */
    public function __construct(
        private readonly ErrorLogger $errorLogger,
        private readonly ?DebugLogger $debugLogger = null,
        private readonly ?Closure $isReportable = null,
        private readonly ?Closure $resolveSeverity = null
    ) {
    }

    /**
     * Logs the debug events for the exception and sends the error report.
     *
     * @param OperationContext $context What the plugin was doing when the call failed
     * @param ErrorCategory $category Technical category of the failure
     * @param ErrorSeverity $severity Severity requested by the caller (subject to the $resolveSeverity hook)
     * @param Throwable $exception Exception raised by the API client
     * @param int $contextDepth Extra stack frames between the real caller and this method (e.g. a static facade), so
     *                          the recorded caller is the call site rather than the facade
     *
     * @return ApiErrorDetails The extracted request/response context
     */
    public function process(
        OperationContext $context,
        ErrorCategory $category,
        ErrorSeverity $severity,
        Throwable $exception,
        int $contextDepth = 0
    ): ApiErrorDetails {
        $details = ApiErrorDetails::fromThrowable($exception);

        // +1 skips this method's frame in the loggers' caller capture.
        $loggerDepth = $contextDepth + 1;

        if ($this->debugLogger !== null) {
            if ($details->isTimeout) {
                $previous = $exception->getPrevious();

                $this->debugLogger->logEvent(
                    $context->value,
                    [
                        'exception' => $previous !== null ? get_class($previous) : '',
                        'code' => $previous !== null ? $previous->getCode() : 0,
                        'connect_attempt_idx' => $details->connectAttemptIdx,
                        'connection_timeout' => $details->connectionTimeout,
                        'transfer_timeout' => $details->transferTimeout,
                    ],
                    'API_TIMEOUT',
                    $loggerDepth
                );
            } elseif ($details->isNetworkError) {
                $this->debugLogger->logEvent(
                    "$context->value [{$exception->getMessage()}]",
                    null,
                    'API_NETWORK_ERROR',
                    $loggerDepth
                );
            }

            $this->debugLogger->logEvent(
                $context->value,
                [
                    'exception' => get_class($exception),
                    'error_message' => $exception->getMessage(),
                    'error_code' => $exception->getCode(),
                    'error_file' => $exception->getFile(),
                    'error_line' => $exception->getLine(),
                    'error_trace' => $exception->getTraceAsString(),
                ],
                'API_ERROR',
                $loggerDepth
            );
        }

        if ($this->isReportable !== null && !($this->isReportable)($exception)) {
            return $details;
        }

        $this->errorLogger->sendError(
            $category,
            $this->resolveSeverity !== null ? ($this->resolveSeverity)($exception, $severity) : $severity,
            $context,
            (string) $exception->getCode(),
            $exception->getMessage(),
            $details->url,
            $details->requestBody,
            $details->responseBody,
            $exception->getTraceAsString(),
            $loggerDepth
        );

        return $details;
    }
}
