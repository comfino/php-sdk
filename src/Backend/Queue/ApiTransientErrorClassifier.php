<?php

/**
 * Comfino PHP SDK
 *
 * Backend routines for e-commerce platforms integration with the Comfino payment gateway API.
 *
 * @package Comfino\Backend\Queue
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
 * @copyright Copyright (c) 2026 Comfino by Comperia.pl S.A.
 * @license https://opensource.org/licenses/BSD-3-Clause BSD 3-Clause License
 * @link https://github.com/comfino/php-sdk
 */

declare(strict_types=1);

namespace Comfino\Backend\Queue;

use Comfino\Api\Exception\ConnectionTimeout;
use Comfino\Api\HttpErrorExceptionInterface;
use Comfino\Api\Retry\Psr18ErrorDetector;
use Throwable;

/**
 * Default classifier for Comfino API delivery errors, reusing the api-client's own retryability logic.
 *
 * Rules (first match wins):
 *   - PSR-18 network / cURL-timeout errors (via {@see Psr18ErrorDetector}) or {@see ConnectionTimeout} → Retry.
 *   - HTTP 5xx or 429 → Retry.
 *   - For configured "absorb-not-found" operations (default: cancel_order): HTTP 404 / 409 → TreatAsSuccess
 *     ("order already gone / already cancelled").
 *   - Any other HTTP 4xx → DropPermanent (retrying cannot help).
 *   - Any other (non-HTTP, unexpected) throwable → Retry (conservative; capped by the queue's max-attempts).
 */
final class ApiTransientErrorClassifier implements TransientErrorClassifierInterface
{
    private const HTTP_BAD_REQUEST = 400;
    private const HTTP_NOT_FOUND = 404;
    private const HTTP_CONFLICT = 409;
    private const HTTP_TOO_MANY_REQUESTS = 429;
    private const HTTP_INTERNAL_SERVER_ERROR = 500;

    private Psr18ErrorDetector $psr18ErrorDetector;

    /** @var string[] Operation types for which 404/409 means "already done" rather than failure. */
    private array $absorbNotFoundOperations;

    /**
     * @param string[]|null $absorbNotFoundOperations Defaults to ['cancel_order'].
     */
    public function __construct(?Psr18ErrorDetector $psr18ErrorDetector = null, ?array $absorbNotFoundOperations = null)
    {
        $this->psr18ErrorDetector = $psr18ErrorDetector ?? new Psr18ErrorDetector();
        $this->absorbNotFoundOperations = $absorbNotFoundOperations ?? ['cancel_order'];
    }

    public function classify(string $operationType, Throwable $error): QueueErrorDisposition
    {
        if ($error instanceof ConnectionTimeout || $this->psr18ErrorDetector->isRetryable($error)) {
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

            if ($statusCode >= self::HTTP_BAD_REQUEST) {
                return QueueErrorDisposition::DropPermanent;
            }
        }

        // Unknown, non-HTTP failure - retry conservatively; the queue's max-attempts limit prevents infinite loops.
        return QueueErrorDisposition::Retry;
    }
}
