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

use Comfino\Api\Serializer\Json;
use InvalidArgumentException;

/**
 * Value object describing a single queued outbound API request awaiting (re)delivery.
 *
 * Instances are immutable; state transitions (recording a failed attempt) return a new instance. The {@see $id} is
 * assigned by the storage on enqueue and preserves FIFO ordering.
 *
 * Two fields exist for multi-tenant hosts, and both fix a real defect rather than adding a feature:
 *
 *  - {@see $tenantKey} partitions the queue. Without it, {@see dedupKey()} is the only discriminator, so two merchants
 *    cancelling the same order number collapse into one entry and one of the two cancellations is silently lost — and
 *    a drain has no way to be fair, because it cannot tell whose work it is looking at.
 *  - {@see $availableAt} paces one item. Without it, a requeued request is retried on the very next drain with no
 *    delay at all; the only pacing was a process-wide cooldown, which is neither per item nor per tenant.
 */
final class QueuedRequest
{
    /**
     * @param int|string|null $id Storage-assigned FIFO sequence identifier; null before persistence
     * @param string $operationType Registered handler key (e.g. "cancel_order")
     * @param array<string, scalar> $payload Operation arguments (must be JSON-serializable)
     * @param int $attempts Number of drain attempts made so far (the fast-path submit attempt is not counted)
     * @param int $createdAt Unix timestamp of the original enqueue
     * @param string|null $lastError Last failure class + message, for diagnostics
     * @param string|null $tenantKey Merchant this request belongs to; null in a single-tenant integration
     * @param int $availableAt Unix timestamp before which this request must not be attempted; 0 means "due now"
     */
    public function __construct(
        public readonly int|string|null $id,
        public readonly string $operationType,
        public readonly array $payload,
        public readonly int $attempts,
        public readonly int $createdAt,
        public readonly ?string $lastError = null,
        public readonly ?string $tenantKey = null,
        public readonly int $availableAt = 0
    ) {
        if ($operationType === '') {
            throw new InvalidArgumentException('Queued request operation type must not be empty.');
        }
    }

    /**
     * Creates a fresh request (not yet persisted) for the given operation.
     *
     * @param string $operationType Registered handler key
     * @param array<string, scalar> $payload Operation arguments
     * @param int $createdAt Unix timestamp of the enqueue
     * @param string|null $tenantKey Merchant this request belongs to
     */
    public static function create(
        string $operationType,
        array $payload,
        int $createdAt,
        ?string $tenantKey = null
    ): self {
        return new self(null, $operationType, $payload, 0, $createdAt, null, $tenantKey, 0);
    }

    /**
     * Returns a copy with the storage-assigned identifier set.
     *
     * @param int|string $id The identifier assigned by the storage
     */
    public function withId(int|string $id): self
    {
        return new self(
            $id,
            $this->operationType,
            $this->payload,
            $this->attempts,
            $this->createdAt,
            $this->lastError,
            $this->tenantKey,
            $this->availableAt
        );
    }

    /**
     * Returns a copy with the attempt counter incremented, the last error recorded, and the next attempt scheduled.
     *
     * @param string $error Failure class + message
     * @param int $availableAt Unix timestamp before which the next attempt must not happen; 0 means "due now"
     */
    public function withAttemptFailure(string $error, int $availableAt = 0): self
    {
        return new self(
            $this->id,
            $this->operationType,
            $this->payload,
            $this->attempts + 1,
            $this->createdAt,
            $error,
            $this->tenantKey,
            $availableAt
        );
    }

    /**
     * Returns a copy deferred to $availableAt without counting an attempt.
     *
     * Used when the failure is not the request's fault — a paused tenant, whose key is misconfigured — so burning the
     * attempt budget would dead-letter payment-relevant traffic for an operator's mistake.
     *
     * @param string $error Failure class + message, recorded for diagnostics
     * @param int $availableAt Unix timestamp before which the next attempt must not happen
     */
    public function deferredTo(string $error, int $availableAt): self
    {
        return new self(
            $this->id,
            $this->operationType,
            $this->payload,
            $this->attempts,
            $this->createdAt,
            $error,
            $this->tenantKey,
            $availableAt
        );
    }

    /**
     * Tells whether this request may be attempted at the given time.
     *
     * @param int $now Current Unix timestamp
     */
    public function isDue(int $now): bool
    {
        return $this->availableAt <= $now;
    }

    /**
     * Stable key identifying duplicate pending operations (same tenant + same type + same payload).
     * Storage adapters use it to avoid enqueuing redundant entries (e.g., cancelling the same order twice).
     *
     * The tenant is part of the key because order numbers are only unique within a shop: two merchants cancelling
     * their own order "1042" are two distinct operations, and collapsing them loses one merchant's cancellation.
     */
    public function dedupKey(): string
    {
        return ($this->tenantKey ?? '-') . ':' . $this->operationType . ':' .
            sha1((new Json())->serialize($this->payload));
    }

    /**
     * @return array{
     *     id: int|string|null,
     *     operationType: string,
     *     payload: array<string, scalar>,
     *     attempts: int,
     *     createdAt: int,
     *     lastError: string|null,
     *     tenantKey: string|null,
     *     availableAt: int
     * }
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'operationType' => $this->operationType,
            'payload' => $this->payload,
            'attempts' => $this->attempts,
            'createdAt' => $this->createdAt,
            'lastError' => $this->lastError,
            'tenantKey' => $this->tenantKey,
            'availableAt' => $this->availableAt,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            $data['id'] ?? null,
            (string) ($data['operationType'] ?? ''),
            (array) ($data['payload'] ?? []),
            (int) ($data['attempts'] ?? 0),
            (int) ($data['createdAt'] ?? 0),
            isset($data['lastError']) ? (string) $data['lastError'] : null,
            isset($data['tenantKey']) && $data['tenantKey'] !== '' ? (string) $data['tenantKey'] : null,
            (int) ($data['availableAt'] ?? 0)
        );
    }
}
