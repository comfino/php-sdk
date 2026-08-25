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
 * Durable queue for idempotent outbound Comfino API calls, drained fairly across tenants.
 *
 * Two entry points:
 *   - {@see submit()} - the in-request "fast path": attempt the call once (the registered handler is expected to use
 *     minimal timeouts / no synchronous retries), and on transient failure persist it for later. Never blocks the shop
 *     request thread for long, and never loses the operation.
 *   - {@see process()} - the off-request drain: deliver pending requests, cycling tenants so that no merchant's backlog
 *     or outage can hold another merchant's work.
 *
 * The queue is platform-agnostic: persistence is delegated to {@see RetryQueueStorageInterface} and the actual HTTP
 * call to per-operation {@see RetryableOperationHandlerInterface} handlers.
 *
 * **How the drain became fair.** It used to walk the store front-to-back and `break` on the first transient failure, so
 * as not to hammer an endpoint that was already struggling. For one shop that is exactly right. In a queue shared by
 * many merchants it is head-of-line blocking with a merchant-shaped head: one shop whose credentials were rotated on
 * one side only, or whose Comfino account was suspended, failed on every attempt and stalled *every* other merchant's
 * cancellations behind it — indefinitely, because the stalled item stayed at the front. Three changes remove that:
 *
 *  1. **Round-robin across tenants.** {@see process()} takes a batch per tenant and interleaves them, so a batch is
 *     shared out instead of being consumed by whoever enqueued first.
 *  2. **Per-tenant pause.** A tenant that fails is dropped from *this drain's* rotation rather than stopping the drain.
 *     With the default threshold of one failure this reproduces the old stop-on-first-failure behavior exactly — for a
 *     single-tenant queue the two are indistinguishable — while confining it to the tenant that failed.
 *  3. **Per-item backoff.** A requeued request is scheduled forward with exponential delay and jitter, so it is skipped
 *     by the next drain instead of being retried immediately, and a hot item cannot consume a batch slot every run.
 */
final class OutboundRequestQueue
{
    /** @var array<string, RetryableOperationHandlerInterface> */
    private array $handlers = [];

    private ClockInterface $clock;
    private JitterInterface $jitter;

    /**
     * @param RetryQueueStorageInterface $storage Durable persistence for pending requests
     * @param TransientErrorClassifierInterface $classifier Decides what a delivery error means
     * @param ClockInterface|null $clock Injectable clock; defaults to {@see SystemClock}
     * @param LoggerInterface|null $logger Optional PSR-3 logger for drain diagnostics
     * @param DeadLetterReporterInterface|null $deadLetterReporter Notified when a request is dropped for good
     * @param int $maxAttempts Drain attempts allowed per request before it is dead-lettered
     * @param JitterInterface|null $jitter Injectable randomness for the per-item backoff; defaults to
     *                                     {@see SystemJitter}
     * @param int $baseRetryDelaySeconds First retry delay; doubles per attempt up to $maxRetryDelaySeconds
     * @param int $maxRetryDelaySeconds Ceiling for the per-item retry delay
     * @param int $maxConsecutiveTenantFailures Consecutive failures within one drain before that tenant's partition is
     *                                          paused for the rest of the run. The default of 1 reproduces the
     *                                          pre-3.0 stop-on-first-transient-failure behavior, scoped to one tenant
     *                                          instead of the whole queue; raise it to let a tenant absorb a flaky
     *                                          request before its partition is held
     * @param TenantPauseReporterInterface|null $pauseReporter Raises the operational alert behind a paused partition
     * @param int $tenantPauseSeconds How far forward a paused tenant's due requests are deferred, so the next drain
     *                                does not immediately re-attempt work that needs a human first
     */
    public function __construct(
        private readonly RetryQueueStorageInterface $storage,
        private readonly TransientErrorClassifierInterface $classifier,
        ?ClockInterface $clock = null,
        private readonly ?LoggerInterface $logger = null,
        private readonly ?DeadLetterReporterInterface $deadLetterReporter = null,
        private readonly int $maxAttempts = 10,
        ?JitterInterface $jitter = null,
        private readonly int $baseRetryDelaySeconds = 60,
        private readonly int $maxRetryDelaySeconds = 3600,
        private readonly int $maxConsecutiveTenantFailures = 1,
        private readonly ?TenantPauseReporterInterface $pauseReporter = null,
        private readonly int $tenantPauseSeconds = 900
    ) {
        if ($maxAttempts < 1) {
            throw new InvalidArgumentException('The maxAttempts must be at least 1.');
        }

        if ($maxConsecutiveTenantFailures < 1) {
            throw new InvalidArgumentException('The maxConsecutiveTenantFailures must be at least 1.');
        }

        if ($baseRetryDelaySeconds < 0 || $maxRetryDelaySeconds < 0) {
            throw new InvalidArgumentException('Retry delays cannot be negative.');
        }

        $this->clock = $clock ?? new SystemClock();
        $this->jitter = $jitter ?? new SystemJitter();
    }

    /**
     * Registers the handler that performs the API call for one operation type.
     *
     * @param string $operationType Operation key used when submitting or enqueuing
     * @param RetryableOperationHandlerInterface $handler The handler performing the call
     */
    public function registerHandler(string $operationType, RetryableOperationHandlerInterface $handler): void
    {
        $this->handlers[$operationType] = $handler;
    }

    /**
     * Fast path: attempt now, enqueue on transient failure.
     *
     * @param string $operationType Registered operation key
     * @param array<string, scalar> $payload Operation arguments
     * @param string|null $tenantKey Merchant this operation belongs to; null in a single-tenant integration
     */
    public function submit(string $operationType, array $payload, ?string $tenantKey = null): SubmitResult
    {
        $handler = $this->handler($operationType);

        try {
            $handler->execute($payload);

            return SubmitResult::SentImmediately;
        } catch (Throwable $error) {
            return match ($this->classifier->classify($operationType, $error)) {
                QueueErrorDisposition::TreatAsSuccess => $this->onSubmitAbsorbed($operationType, $payload, $error),
                QueueErrorDisposition::DropPermanent => $this->onSubmitDropped(
                    $operationType,
                    $payload,
                    $error,
                    $tenantKey
                ),
                /* A refused credential defers exactly like a transient failure on the fast path: the operation is
                   sound and will go through once the key is fixed, so dropping it here would discard payment-relevant
                   traffic for a configuration mistake. The drain is where the tenant gets paused and alerted on. */
                QueueErrorDisposition::Retry, QueueErrorDisposition::PauseTenant => $this->onSubmitDeferred(
                    $operationType,
                    $payload,
                    $error,
                    $tenantKey
                ),
            };
        }
    }

    /**
     * Enqueue without attempting (pure deferred mode).
     *
     * @param string $operationType Registered operation key
     * @param array<string, scalar> $payload Operation arguments
     * @param string|null $tenantKey Merchant this operation belongs to; null in a single-tenant integration
     */
    public function enqueue(string $operationType, array $payload, ?string $tenantKey = null): void
    {
        $this->handler($operationType); // Validate a handler exists before persisting.

        $this->storage->enqueue(QueuedRequest::create($operationType, $payload, $this->clock->now(), $tenantKey));
    }

    /**
     * Drains up to $maxItems due requests, cycling tenants. Never throws - all delivery errors are caught.
     *
     * @param int $maxItems Total requests to attempt this run, shared out across tenants
     * @param string|null $tenantKey When given, drain only this tenant's partition - for an operator retrying one
     *                               merchant after fixing their credentials, without touching anyone else's queue
     */
    public function process(int $maxItems, ?string $tenantKey = null): QueueDrainResult
    {
        if ($maxItems < 1) {
            return QueueDrainResult::skipped($this->storage->count($tenantKey));
        }

        $now = $this->clock->now();
        $partitions = $this->duePartitions($maxItems, $now, $tenantKey);

        if ($partitions === []) {
            return new QueueDrainResult(remaining: $this->storage->count($tenantKey));
        }

        $state = new DrainState();
        $budget = $maxItems;

        /* Round-robin: one request per tenant per pass, so the batch is shared out rather than consumed by whichever
           tenant happens to sit at the front of the table. */
        while ($budget > 0 && $partitions !== []) {
            foreach (array_keys($partitions) as $partitionKey) {
                if ($budget < 1) {
                    break;
                }

                $request = array_shift($partitions[$partitionKey]);

                if ($request === null) {
                    unset($partitions[$partitionKey]);

                    continue;
                }

                $budget--;

                if (!$request->isDue($now)) {
                    /* Storage is allowed to ignore the due-at gate; skipping here keeps such an implementation correct
                       at the cost of a wasted slot, which is why honoring the gate is in the interface contract. */
                    $state->notDue++;

                    continue;
                }

                if (!$this->deliver($request, $state, $now)) {
                    // This tenant is paused for the rest of the run; the rotation continues without it.
                    unset($partitions[$partitionKey]);
                }

                if (isset($partitions[$partitionKey]) && $partitions[$partitionKey] === []) {
                    unset($partitions[$partitionKey]);
                }
            }
        }

        return new QueueDrainResult(
            processed: $state->processed,
            deadLettered: $state->deadLettered,
            requeued: $state->requeued,
            remaining: $this->storage->count($tenantKey),
            /* "The API looks down" is only a sound conclusion when nothing got through: if some tenant drained fine,
               the failures were that tenant's problem, and gating the next drain on them would punish everyone. */
            stoppedOnTransientFailure: $state->pausedTenants !== [] && $state->processed === 0,
            pausedTenants: $state->pausedTenants,
            notDue: $state->notDue
        );
    }

    /**
     * Number of pending requests in the store.
     *
     * @param string|null $tenantKey When given, count only this tenant's requests
     */
    public function pendingCount(?string $tenantKey = null): int
    {
        return $this->storage->count($tenantKey);
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
    private function onSubmitDropped(
        string $operationType,
        array $payload,
        Throwable $error,
        ?string $tenantKey
    ): SubmitResult {
        $this->log('error', '[REQUEST_QUEUE] submit dropped (permanent)', $operationType, $payload, $error, $tenantKey);

        $this->deadLetterReporter?->report(
            QueuedRequest::create($operationType, $payload, $this->clock->now(), $tenantKey),
            $error
        );

        return SubmitResult::DroppedPermanent;
    }

    /**
     * @param array<string, scalar> $payload
     */
    private function onSubmitDeferred(
        string $operationType,
        array $payload,
        Throwable $error,
        ?string $tenantKey
    ): SubmitResult {
        $now = $this->clock->now();

        $this->storage->enqueue(
            QueuedRequest::create($operationType, $payload, $now, $tenantKey)
                ->withAttemptFailure($this->describe($error), $this->nextAttemptAt($now, 1))
        );

        $this->log('warning', '[REQUEST_QUEUE] submit deferred to queue', $operationType, $payload, $error, $tenantKey);

        return SubmitResult::Queued;
    }

    /**
     * Builds the per-tenant batches this drain will interleave.
     *
     * Each tenant is asked for up to the whole batch size: the round-robin below is what bounds a tenant's share, and
     * fetching a full batch per tenant means a run still drains at full rate when only one tenant has work.
     *
     * @param int $maxItems Total batch size for the run
     * @param int $now Current Unix timestamp, used as the due-at gate
     * @param string|null $tenantKey When given, restrict the drain to this single partition
     *
     * @return array<string, QueuedRequest[]> Batches keyed by a partition key derived from the tenant
     */
    private function duePartitions(int $maxItems, int $now, ?string $tenantKey): array
    {
        $tenantKeys = $tenantKey !== null ? [$tenantKey] : $this->storage->pendingTenantKeys($now);

        $partitions = [];

        foreach ($tenantKeys as $key) {
            $batch = $this->storage->peekBatch($maxItems, $key, $now);

            if ($batch !== []) {
                $partitions[$this->partitionKey($key)] = $batch;
            }
        }

        return $partitions;
    }

    /**
     * Attempts one request and records what happened.
     *
     * @param QueuedRequest $request The request to deliver
     * @param DrainState $state Mutable tallies for this run
     * @param int $now Current Unix timestamp
     *
     * @return bool False when the tenant's partition must be dropped from this run's rotation
     */
    private function deliver(QueuedRequest $request, DrainState $state, int $now): bool
    {
        $handler = $this->handlers[$request->operationType] ?? null;

        if ($handler === null) {
            /* No handler registered for this operation type - cannot deliver. Drop so it does not block the queue
               head forever; the registration gap is a wiring bug worth surfacing. */
            $this->log(
                'error',
                '[REQUEST_QUEUE] No handler for queued operation.',
                $request->operationType,
                $request->payload,
                null,
                $request->tenantKey
            );

            $this->storage->remove($request);

            $state->deadLettered++;

            return true;
        }

        try {
            $handler->execute($request->payload);
            $this->storage->remove($request);

            $state->processed++;
            $state->clearFailures($request->tenantKey);

            return true;
        } catch (Throwable $error) {
            return $this->handleFailure($request, $error, $state, $now);
        }
    }

    /**
     * Applies the classifier's verdict to a failed delivery.
     *
     * @param QueuedRequest $request The request that failed
     * @param Throwable $error The failure
     * @param DrainState $state Mutable tallies for this run
     * @param int $now Current Unix timestamp
     *
     * @return bool False when the tenant's partition must be dropped from this run's rotation
     */
    private function handleFailure(QueuedRequest $request, Throwable $error, DrainState $state, int $now): bool
    {
        return match ($this->classifier->classify($request->operationType, $error)) {
            QueueErrorDisposition::TreatAsSuccess => $this->onDeliveryAbsorbed($request, $state),
            QueueErrorDisposition::DropPermanent => $this->onDeliveryDropped($request, $error, $state),
            QueueErrorDisposition::PauseTenant => $this->onDeliveryPaused($request, $error, $state, $now),
            QueueErrorDisposition::Retry => $this->onDeliveryRetried($request, $error, $state, $now)
        };
    }

    /**
     * The failure was not one: the operation already took effect on the API side, so the request leaves the queue.
     *
     * @param QueuedRequest $request The request that failed
     * @param DrainState $state Mutable tallies for this run
     *
     * @return bool Always true - the tenant's partition stays in this run's rotation
     */
    private function onDeliveryAbsorbed(QueuedRequest $request, DrainState $state): bool
    {
        $this->storage->remove($request);

        $state->processed++;
        $state->clearFailures($request->tenantKey);

        return true;
    }

    /**
     * A retry cannot help, so the request is dead-lettered rather than left to occupy a batch slot every run.
     *
     * @param QueuedRequest $request The request that failed
     * @param Throwable $error The failure
     * @param DrainState $state Mutable tallies for this run
     *
     * @return bool Always true - the tenant's partition stays in this run's rotation
     */
    private function onDeliveryDropped(QueuedRequest $request, Throwable $error, DrainState $state): bool
    {
        $this->deadLetter($request, $error, 'permanent error');

        $state->deadLettered++;

        return true;
    }

    /**
     * The API refused the tenant's credentials.
     *
     * The payload is fine and the key is wrong, so the attempt budget is not spent - burning it would dead-letter this
     * merchant's traffic for an operator's mistake. The request is deferred instead, and a human is told.
     *
     * @param QueuedRequest $request The request that failed
     * @param Throwable $error The failure
     * @param DrainState $state Mutable tallies for this run
     * @param int $now Current Unix timestamp
     *
     * @return bool Always false - the tenant's partition leaves this run's rotation
     */
    private function onDeliveryPaused(QueuedRequest $request, Throwable $error, DrainState $state, int $now): bool
    {
        $this->storage->update($request->deferredTo($this->describe($error), $now + $this->tenantPauseSeconds));

        $state->requeued++;

        $this->pauseTenant($request, $error, $state, 'credentials_rejected');

        return false;
    }

    /**
     * A transient failure: the request is deferred to a later attempt, unless it has now exhausted its budget.
     *
     * @param QueuedRequest $request The request that failed
     * @param Throwable $error The failure
     * @param DrainState $state Mutable tallies for this run
     * @param int $now Current Unix timestamp
     *
     * @return bool False when this tenant has failed often enough to leave the rotation for the rest of the run
     */
    private function onDeliveryRetried(QueuedRequest $request, Throwable $error, DrainState $state, int $now): bool
    {
        $failed = $request->withAttemptFailure(
            $this->describe($error),
            $this->nextAttemptAt($now, $request->attempts + 1)
        );

        if ($failed->attempts >= $this->maxAttempts) {
            $this->deadLetter($failed, $error, 'max_attempts_exceeded');

            $state->deadLettered++;

            return true;
        }

        $this->storage->update($failed);

        $state->requeued++;

        if ($state->recordFailure($request->tenantKey) < $this->maxConsecutiveTenantFailures) {
            return true;
        }

        /* Enough consecutive failures for this tenant to conclude the endpoint (or the account behind it) is not
           answering. Hold this partition for the rest of the run rather than hammering it - and, unlike the pre-3.0
           behavior, leave every other tenant draining. */
        $this->pauseTenant($request, $error, $state, 'consecutive_failures');

        return false;
    }

    /**
     * Records a tenant pause, logs it, and raises the operational alert.
     *
     * @param QueuedRequest $request The request whose failure triggered the pause
     * @param Throwable $error The failure
     * @param DrainState $state Mutable tallies for this run
     * @param string $reason Short machine-readable cause
     */
    private function pauseTenant(QueuedRequest $request, Throwable $error, DrainState $state, string $reason): void
    {
        $state->pauseTenant($request->tenantKey);

        $this->log(
            'warning',
            sprintf('[REQUEST_QUEUE] tenant partition paused (%s)', $reason),
            $request->operationType,
            $request->payload,
            $error,
            $request->tenantKey
        );

        try {
            $this->pauseReporter?->reportPaused($request->tenantKey, $request, $error, $reason);
        } catch (Throwable) {
            // An alerting channel that fails must not take the drain with it.
        }
    }

    /**
     * Returns the timestamp of the next attempt for a request that has now failed $attempts times.
     *
     * Exponential with full jitter, capped: the doubling keeps a persistently failing item from consuming a batch slot
     * every run, and the jitter matters because every installation schedules its drain on the same wall-clock cron
     * grid - without it, an outage affecting many shops ends with all of them retrying on the same tick.
     *
     * @param int $now Current Unix timestamp
     * @param int $attempts Attempts made so far, including the one that just failed
     *
     * @return int Unix timestamp before which the request must not be attempted
     */
    private function nextAttemptAt(int $now, int $attempts): int
    {
        if ($this->baseRetryDelaySeconds === 0) {
            return $now;
        }

        $exponent = max(0, min($attempts - 1, 30)); // Capped so the shift cannot overflow on a stuck item.
        $delay = min($this->baseRetryDelaySeconds << $exponent, $this->maxRetryDelaySeconds);

        return $now + $this->jitter->random($delay);
    }

    /**
     * Returns the handler for an operation type, or explains that none is registered.
     *
     * @param string $operationType The operation key
     */
    private function handler(string $operationType): RetryableOperationHandlerInterface
    {
        if (!isset($this->handlers[$operationType])) {
            throw new InvalidArgumentException(sprintf('No handler registered for operation "%s".', $operationType));
        }

        return $this->handlers[$operationType];
    }

    /**
     * Drops a request for good, logging it and notifying the dead-letter reporter.
     *
     * @param QueuedRequest $request The request being dropped
     * @param Throwable $error The failure that ended it
     * @param string $reason Human-readable cause, for the log line
     */
    private function deadLetter(QueuedRequest $request, Throwable $error, string $reason): void
    {
        $this->log(
            'error',
            sprintf('[REQUEST_QUEUE] dead-lettered (%s)', $reason),
            $request->operationType,
            $request->payload,
            $error,
            $request->tenantKey
        );
        $this->storage->remove($request);
        $this->deadLetterReporter?->report($request, $error);
    }

    /**
     * Renders a throwable as a single diagnostic line.
     */
    private function describe(Throwable $error): string
    {
        return get_class($error) . ': ' . $error->getMessage();
    }

    /**
     * Returns the array key standing for a tenant partition, since null is not usable as one.
     *
     * @param string|null $tenantKey The tenant, or null for the unscoped partition
     */
    private function partitionKey(?string $tenantKey): string
    {
        return $tenantKey ?? "\0unscoped";
    }

    /**
     * @param array<string, scalar> $payload
     */
    private function log(
        string $level,
        string $message,
        string $operationType,
        array $payload,
        ?Throwable $error = null,
        ?string $tenantKey = null
    ): void {
        $this->logger?->log($level, $message, [
            'operationType' => $operationType,
            'payload' => $payload,
            'tenantKey' => $tenantKey,
            'error' => $error !== null ? $this->describe($error) : null,
        ]);
    }
}
