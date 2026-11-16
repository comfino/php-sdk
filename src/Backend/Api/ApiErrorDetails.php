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

use Comfino\Api\Exception\ConnectionTimeout;
use Comfino\Api\HttpErrorExceptionInterface;
use Psr\Http\Client\NetworkExceptionInterface;
use Comfino\Backend\Log\ResponseBodySummarizer;
use Throwable;

/**
 * Request/response context extracted from an API client exception.
 *
 * Empty strings are normalized to null, so the fields can be passed straight to {@see \Comfino\Backend\Log\ErrorLogger::sendError()}.
 * Plugins that show the shopper an error page can also build it from these fields.
 */
final class ApiErrorDetails
{
    /**
     * @param string|null $url Request URL (HTTP errors) or request target (network errors)
     * @param string|null $requestBody Request body sent to the API
     * @param string|null $responseBody Response body received from the API (HTTP errors only)
     * @param int|null $statusCode HTTP status code, or null when no HTTP response was involved
     * @param bool $isTimeout Whether the exception is a {@see ConnectionTimeout}
     * @param bool $isNetworkError Whether the exception is a PSR-18 network error (no response received)
     * @param int|null $connectAttemptIdx Index of the failed connection attempt (timeouts only)
     * @param int|null $connectionTimeout Connection timeout in seconds used by the failed attempt (timeouts only)
     * @param int|null $transferTimeout Transfer timeout in seconds used by the failed attempt (timeouts only)
     */
    public function __construct(
        public readonly ?string $url = null,
        public readonly ?string $requestBody = null,
        public readonly ?string $responseBody = null,
        public readonly ?int $statusCode = null,
        public readonly bool $isTimeout = false,
        public readonly bool $isNetworkError = false,
        public readonly ?int $connectAttemptIdx = null,
        public readonly ?int $connectionTimeout = null,
        public readonly ?int $transferTimeout = null
    ) {
    }

    /**
     * Extracts the request/response context from any throwable. Exceptions that carry no HTTP context yield an empty details object.
     */
    public static function fromThrowable(Throwable $exception): self
    {
        if ($exception instanceof ConnectionTimeout) {
            return new self(
                self::nullIfEmpty($exception->getUrl()),
                self::nullIfEmpty($exception->getRequestBody()),
                ResponseBodySummarizer::summarize(self::nullIfEmpty($exception->getResponseBody())),
                $exception->getStatusCode(),
                isTimeout: true,
                connectAttemptIdx: $exception->getConnectAttemptIdx(),
                connectionTimeout: $exception->getConnectionTimeout(),
                transferTimeout: $exception->getTransferTimeout()
            );
        }

        if ($exception instanceof HttpErrorExceptionInterface) {
            return new self(
                self::nullIfEmpty($exception->getUrl()),
                self::nullIfEmpty($exception->getRequestBody()),
                ResponseBodySummarizer::summarize(self::nullIfEmpty($exception->getResponseBody())),
                $exception->getStatusCode()
            );
        }

        if ($exception instanceof NetworkExceptionInterface) {
            $request = $exception->getRequest();
            $body = $request->getBody();

            try {
                if ($body->isSeekable()) {
                    $body->rewind();
                }

                $requestBody = $body->getContents();
            } catch (Throwable) {
                $requestBody = '';
            }

            return new self(
                self::nullIfEmpty($request->getRequestTarget()),
                self::nullIfEmpty($requestBody),
                isNetworkError: true
            );
        }

        return new self();
    }

    private static function nullIfEmpty(string $value): ?string
    {
        return $value !== '' ? $value : null;
    }
}
