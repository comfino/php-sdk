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

use Comfino\Backend\Clock\ClockInterface;
use Comfino\Backend\Clock\SystemClock;
use InvalidArgumentException;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Durable FIFO queue for idempotent outbound Comfino API calls.
 *
 * Two entry points:
 *   - {@see submit()} - the in-request "fast path": attempt the call once (the registered handler is expected to use
 *     minimal timeouts / no synchronous retries), and on transient failure persist it for later. Never blocks the shop
 *     request thread for long, and never loses the operation.
 *   - {@see process()} - the off-request drain: deliver pending requests one by one in FIFO order, stopping at the
 *     first transient failure (the API is probably down) so the tail is retried, in order, on the next run.
 *
 * The queue is platform-agnostic: persistence is delegated to {@see RetryQueueStorageInterface} and the actual HTTP
 * call to per-operation {@see RetryableOperationHandlerInterface} handlers.
 */
final class OutboundRequestQueue
{
    /** @var array<string, RetryableOperationHandlerInterface> */
    private array $handlers = [];

    private ClockInterface $clock;

    public function __construct(
        private readonly RetryQueueStorageInterface $storage,
        private readonly TransientErrorClassifierInterface $classifier,
        ?ClockInterface $clock = null,
        private readonly ?LoggerInterface $logger = null,
        private readonly ?DeadLetterReporterInterface $deadLetterReporter = null,
        private readonly int $maxAttempts = 10
    ) {
        if ($maxAttempts < 1) {
            throw new InvalidArgumentException('The maxAttempts must be at least 1.');
        }

        $this->clock = $clock ?? new SystemClock();
    }

    public function registerHandler(string $operationType, RetryableOperationHandlerInterface $handler): void
    {
        $this->handlers[$operationType] = $handler;
    }

    /**
     * Fast path: attempt now, enqueue on transient failure.
     *
     * @param array<string, scalar> $payload
     */
    public function submit(string $operationType, array $payload): SubmitResult
    {
        $handler = $this->handler($operationType);

        try {
            $handler->execute($payload);

            return SubmitResult::SentImmediately;
        } catch (Throwable $error) {
            return match ($this->classifier->classify($operationType, $error)) {
                QueueErrorDisposition::TreatAsSuccess => $this->onSubmitAbsorbed($operationType, $payload, $error),
                QueueErrorDisposition::DropPermanent => $this->onSubmitDropped($operationType, $payload, $error),
                QueueErrorDisposition::Retry => $this->onSubmitDeferred($operationType, $payload, $error),
            };
        }
    }

    /**
     * @param array<string, scalar> $payload
     */
    private function onSubmitAbsorbed(string $operationType, array $payload, Throwable $error): SubmitResult
    {
        $this->log('debug', '[REQUEST_QUEUE] submit absorbed non-failure', $operationType, $payload, $error);

        return SubmitResult::SentImmediately;
    }

    /**
     * @param array<string, scalar> $payload
     */
    private function onSubmitDropped(string $operationType, array $payload, Throwable $error): SubmitResult
    {
        $this->log('error', '[REQUEST_QUEUE] submit dropped (permanent)', $operationType, $payload, $error);

        $this->deadLetterReporter?->report(
            QueuedRequest::create($operationType, $payload, $this->clock->now()),
            $error
        );

        return SubmitResult::DroppedPermanent;
    }

    /**
     * @param array<string, scalar> $payload
     */
    private function onSubmitDeferred(string $operationType, array $payload, Throwable $error): SubmitResult
    {
        $this->enqueueRequest(
            QueuedRequest::create($operationType, $payload, $this->clock->now())
                ->withAttemptFailure($this->describe($error))
        );

        $this->log('warning', '[REQUEST_QUEUE] submit deferred to queue', $operationType, $payload, $error);

        return SubmitResult::Queued;
    }

    /**
     * Enqueue without attempting (pure deferred mode).
     *
     * @param array<string, scalar> $payload
     */
    public function enqueue(string $operationType, array $payload): void
    {
        $this->handler($operationType); // Validate a handler exists before persisting.

        $this->enqueueRequest(QueuedRequest::create($operationType, $payload, $this->clock->now()));
    }

    /**
     * Drains up to $maxItems pending requests in FIFO order. Never throws - all delivery errors are caught.
     */
    public function process(int $maxItems): QueueDrainResult
    {
        if ($maxItems < 1) {
            return QueueDrainResult::skipped($this->storage->count());
        }

        $processed = 0;
        $deadLettered = 0;
        $requeued = 0;
        $stopped = false;

        foreach ($this->storage->peekBatch($maxItems) as $request) {
            $handler = $this->handlers[$request->operationType] ?? null;

            if ($handler === null) {
                /* No handler registered for this operation type - cannot deliver. Drop so it does not block the queue
                   head forever; the registration gap is a wiring bug worth surfacing. */
                $this->log(
                    'error',
                    '[REQUEST_QUEUE] No handler for queued operation.',
                    $request->operationType,
                    $request->payload
                );

                $this->storage->remove($request);

                $deadLettered++;

                continue;
            }

            try {
                $handler->execute($request->payload);
                $this->storage->remove($request);

                $processed++;
            } catch (Throwable $error) {
                $disposition = $this->classifier->classify($request->operationType, $error);

                if ($disposition === QueueErrorDisposition::TreatAsSuccess) {
                    $this->storage->remove($request);

                    $processed++;

                    continue;
                }

                if ($disposition === QueueErrorDisposition::DropPermanent) {
                    $this->deadLetter($request, $error, 'permanent error');

                    $deadLettered++;

                    continue;
                }

                // Transient failure.
                $failed = $request->withAttemptFailure($this->describe($error));

                if ($failed->attempts >= $this->maxAttempts) {
                    $this->deadLetter($failed, $error, 'max attempts exceeded');

                    $deadLettered++;

                    continue;
                }

                $this->storage->update($failed);

                $requeued++;

                $stopped = true;

                /* The API is probably unavailable - stop here, so the rest of the queue is retried, in FIFO order,
                   on the next drain instead of hammering a struggling endpoint. */
                break;
            }
        }

        return new QueueDrainResult(
            processed: $processed,
            deadLettered: $deadLettered,
            requeued: $requeued,
            remaining: $this->storage->count(),
            stoppedOnTransientFailure: $stopped,
        );
    }

    /**
     * Number of pending requests in the store.
     */
    public function pendingCount(): int
    {
        return $this->storage->count();
    }

    private function handler(string $operationType): RetryableOperationHandlerInterface
    {
        if (!isset($this->handlers[$operationType])) {
            throw new InvalidArgumentException(sprintf('No handler registered for operation "%s".', $operationType));
        }

        return $this->handlers[$operationType];
    }

    private function enqueueRequest(QueuedRequest $request): void
    {
        $this->storage->enqueue($request);
    }

    private function deadLetter(QueuedRequest $request, Throwable $error, string $reason): void
    {
        $this->log(
            'error',
            sprintf('[REQUEST_QUEUE] dead-lettered (%s)', $reason),
            $request->operationType,
            $request->payload,
            $error
        );
        $this->storage->remove($request);
        $this->deadLetterReporter?->report($request, $error);
    }

    private function describe(Throwable $error): string
    {
        return get_class($error) . ': ' . $error->getMessage();
    }

    /**
     * @param array<string, scalar> $payload
     */
    private function log(
        string $level,
        string $message,
        string $operationType,
        array $payload,
        ?Throwable $error = null
    ): void {
        $this->logger?->log($level, $message, [
            'operationType' => $operationType,
            'payload' => $payload,
            'error' => $error !== null ? $this->describe($error) : null,
        ]);
    }
}
