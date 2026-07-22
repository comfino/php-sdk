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
     */
    public function __construct(
        public readonly int|string|null $id,
        public readonly string $operationType,
        public readonly array $payload,
        public readonly int $attempts,
        public readonly int $createdAt,
        public readonly ?string $lastError = null
    ) {
        if ($operationType === '') {
            throw new InvalidArgumentException('Queued request operation type must not be empty.');
        }
    }

    /**
     * Creates a fresh request (not yet persisted) for the given operation.
     *
     * @param array<string, scalar> $payload
     */
    public static function create(string $operationType, array $payload, int $createdAt): self
    {
        return new self(null, $operationType, $payload, 0, $createdAt, null);
    }

    /**
     * Returns a copy with the storage-assigned identifier set.
     */
    public function withId(int|string $id): self
    {
        return new self($id, $this->operationType, $this->payload, $this->attempts, $this->createdAt, $this->lastError);
    }

    /**
     * Returns a copy with the attempt counter incremented and the last error recorded.
     */
    public function withAttemptFailure(string $error): self
    {
        return new self(
            $this->id,
            $this->operationType,
            $this->payload,
            $this->attempts + 1,
            $this->createdAt,
            $error
        );
    }

    /**
     * Stable key identifying duplicate pending operations (same type + same payload).
     * Storage adapters use it to avoid enqueuing redundant entries (e.g., cancelling the same order twice).
     */
    public function dedupKey(): string
    {
        return $this->operationType . ':' . sha1((new Json())->serialize($this->payload));
    }

    /**
     * @return array{
     *     id: int|string|null,
     *     operationType: string,
     *     payload: array<string, scalar>,
     *     attempts: int,
     *     createdAt: int,
     *     lastError: string|null
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
            isset($data['lastError']) ? (string) $data['lastError'] : null
        );
    }
}
