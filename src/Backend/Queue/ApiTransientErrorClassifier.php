<?php

/**
 * ComfinoPay PHP SDK
 *
 * Backend routines for e-commerce platforms integration with the ComfinoPay payment gateway API.
 *
 * @package Comfino\Backend\Queue
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
 * @copyright Copyright (c) 2026 by ComfinoPay sp. z o.o.
 * @license https://opensource.org/licenses/BSD-3-Clause BSD 3-Clause License
 * @link https://github.com/comfino/php-sdk
 */

declare(strict_types=1);

namespace Comfino\Backend\Queue;

use Comfino\Api\Exception\ConnectionTimeout;
use Comfino\Api\HttpErrorExceptionInterface;
use Comfino\Api\Retry\ErrorClassifier;
use Comfino\Api\Retry\Psr18ErrorDetector;
use Throwable;

/**
 * Default classifier for ComfinoPay API delivery errors, reusing the api-client's own retryability logic.
 *
 * Rules (first match wins):
 *   - PSR-18 network / cURL-timeout errors (via {@see ErrorClassifier}) or {@see ConnectionTimeout} → Retry.
 *   - HTTP 5xx or 429 → Retry.
 *   - For configured "absorb-not-found" operations (default: cancel_order): HTTP 404 / 409 → TreatAsSuccess
 *     ("order already gone / already cancelled").
 *   - HTTP 401 / 403 → PauseTenant ("this tenant's credentials are wrong"), not DropPermanent. The payload is fine, so
 *     the request succeeds once the key is fixed, and every request for that merchant fails identically until then -
 *     dropping them one at a time discarded a merchant's whole outbound stream while the queue looked healthy.
 *   - Any other HTTP 4xx → DropPermanent (retrying cannot help).
 *   - Any other (non-HTTP, unexpected) throwable → Retry (conservative; capped by the queue's max-attempts).
 */
final class ApiTransientErrorClassifier implements TransientErrorClassifierInterface
{
    private const HTTP_BAD_REQUEST = 400;
    private const HTTP_UNAUTHORIZED = 401;
    private const HTTP_FORBIDDEN = 403;
    private const HTTP_NOT_FOUND = 404;
    private const HTTP_CONFLICT = 409;
    private const HTTP_TOO_MANY_REQUESTS = 429;
    private const HTTP_INTERNAL_SERVER_ERROR = 500;

    private ErrorClassifier|Psr18ErrorDetector $errorClassifier;

    /** @var string[] Operation types for which 404/409 means "already done" rather than failure. */
    private array $absorbNotFoundOperations;

    /**
     * @param ErrorClassifier|Psr18ErrorDetector|null $errorClassifier Retryability oracle; defaults to the api-client's
     *                                                                 {@see ErrorClassifier}.
     *                                                                 A {@see Psr18ErrorDetector} is still accepted for
     *                                                                 callers that pass one, and delegates to the same
     *                                                                 classifier with the same verdict
     *
     * @param string[]|null $absorbNotFoundOperations Defaults to ['cancel_order'].
     */
    public function __construct(ErrorClassifier|Psr18ErrorDetector|null $errorClassifier = null, ?array $absorbNotFoundOperations = null)
    {
        $this->errorClassifier = $errorClassifier ?? new ErrorClassifier();
        $this->absorbNotFoundOperations = $absorbNotFoundOperations ?? ['cancel_order'];
    }

    public function classify(string $operationType, Throwable $error): QueueErrorDisposition
    {
        /* Queued operations are all idempotent - a cancellation and an error report can both be replayed - so the
           classifier is asked the idempotent question, which is what lets a 500 count as retryable. */
        if ($error instanceof ConnectionTimeout || $this->errorClassifier->isRetryable($error)) {
            return QueueErrorDisposition::Retry;
        }

        if ($error instanceof HttpErrorExceptionInterface) {
            $statusCode = $error->getStatusCode();

            if ($statusCode >= self::HTTP_INTERNAL_SERVER_ERROR || $statusCode === self::HTTP_TOO_MANY_REQUESTS) {
                return QueueErrorDisposition::Retry;
            }

            if (
                ($statusCode === self::HTTP_NOT_FOUND || $statusCode === self::HTTP_CONFLICT) &&
                in_array($operationType, $this->absorbNotFoundOperations, true)
            ) {
                return QueueErrorDisposition::TreatAsSuccess;
            }

            if ($statusCode === self::HTTP_UNAUTHORIZED || $statusCode === self::HTTP_FORBIDDEN) {
                /* A configuration event, not a bad request: hold this tenant's partition and alert, so the mistake is
                   fixed rather than absorbed one dropped cancellation at a time. */
                return QueueErrorDisposition::PauseTenant;
            }

            if ($statusCode >= self::HTTP_BAD_REQUEST) {
                return QueueErrorDisposition::DropPermanent;
            }
        }

        // Unknown, non-HTTP failure - retry conservatively; the queue's max-attempts limit prevents infinite loops.
        return QueueErrorDisposition::Retry;
    }
}
