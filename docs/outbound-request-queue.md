# Outbound Request Queue

The `Comfino\Backend\Queue` subsystem provides a durable FIFO retry queue for idempotent outbound Comfino API calls. It ships with a `cancel_order` handler and is designed to be extended with additional operation types (e.g., `report_error`).

## Overview

Without the queue, order cancellation calls happen synchronously inside the shop request thread. If the Comfino API is slow or unavailable, the thread blocks for the full client timeout, degrading UX and risking worker exhaustion.

The queue solves this with two entry points:

- **`submit()`** — fast path, called from the shop request thread. Attempts the call once using minimal timeouts; on transient failure it persists the request and returns immediately. The operation is never lost.
- **`process()`** — drain path, called from the platform cron and optionally at the tail of inbound webhook handling. Delivers pending requests oldest-first, cycling tenants so no merchant's backlog monopolizes a batch, and holds a failing merchant's partition rather than stopping the whole drain. For a single-shop plugin those two behaviors are indistinguishable from the previous "stop at the first transient failure".

The queue is platform-agnostic. Each platform provides exactly two things:

1. A `RetryQueueStorageInterface` implementation backed by its native durable store.
2. A scheduler entry (cron) that calls `OutboundRequestQueueProcessor::process()`.

### Multi-tenant hosts

Every entry carries an optional `tenantKey`, and it is worth passing in any process that serves more than one merchant.
It buys three things a shared, tenant-blind queue cannot have:

- **Correct deduplication.** `dedupKey()` includes the tenant, because order numbers are only unique within a shop.
  Without it, two merchants cancelling their own order "1042" collapse into one entry and one cancellation is lost.
- **Fairness.** `process()` cycles tenants instead of draining the table front-to-back, so a merchant with a large
  backlog cannot consume the whole batch.
- **Blast-radius containment.** A merchant whose key was rotated on one side only has its partition paused for the rest
  of the drain — with an alert — while every other merchant keeps draining. Previously that one merchant stalled the
  entire queue behind it, indefinitely, because the failing item stayed at the front.

---

## Wiring the queue via `OutboundRequestQueueFactory`

`OutboundRequestQueueFactory` assembles a fully-wired queue with the default Comfino error classifier and the pre-registered `cancel_order` handler.

```php
use Comfino\Backend\Factory\OutboundRequestQueueFactory;
use Comfino\Backend\Queue\OutboundRequestQueueProcessor;
use Psr\Log\LoggerInterface;

// 1. Build a minimal-timeout API client (Option A from the design: a dedicated second client).
//    maxRetries: 1 → RetryExecutor makes a single attempt, no escalation.
$minimalTimeoutClient = $apiClientFactory->createClient(
    apiKey: $apiKey,
    connectionTimeout: 1, // seconds
    transferTimeout: 2,
    maxRetries: 1,
);

// 2. Implement RetryQueueStorageInterface (see §"Implementing storage" below).
$storage = new MyRetryQueueStorage($dbConnection);

// 3. Optionally implement DeadLetterReporterInterface (see §"Dead-letter reporting").
$deadLetterReporter = new MyDeadLetterReporter($errorLogger);

// 4. Create the queue.
$queue = (new OutboundRequestQueueFactory())->create(
    storage: $storage,
    minimalTimeoutClient: $minimalTimeoutClient,
    logger: $psrLogger, // PSR-3, optional
    deadLetterReporter: $deadLetterReporter,
    maxAttempts: 10, // Default; override via platform config
);

// 5. Wrap in the processor (adds the cooldown gate).
$processor = new OutboundRequestQueueProcessor(
    queue: $queue,
    defaultBatchSize: 20,
    cooldownSeconds: 300,
);
```

### Submitting a cancellation (fast path)

Replace your direct `cancelOrder()` call with `submit()`:

```php
use Comfino\Backend\Queue\SubmitResult;

$result = $queue->submit('cancel_order', ['orderId' => $orderId]);

match ($result) {
    SubmitResult::SentImmediately => null, // Delivered or already canceled — done
    SubmitResult::Queued => null, // Will be delivered by the next drain — done
    SubmitResult::DroppedPermanent => $this->logger // Permanent error, logged; nothing more to do
        ->error('cancel_order dropped permanently', ['orderId' => $orderId]),
};
```

`submit()` never blocks the caller for longer than the minimal-timeout client's `transferTimeout`.

### Running the drain (cron + opportunistic)

```php
// In your cron handler (e.g., every 5 minutes):
$result = $processor->process();   // uses defaultBatchSize

// Opportunistic: at the tail of inbound webhook handling, swallow errors:
try {
    $processor->process(batchSize: 5);
} catch (\Throwable) {
    // Never let a drain failure interrupt the webhook response.
}
```

`process()` is safe to call concurrently — the cooldown gate suppresses a second drain for `cooldownSeconds` after a transient failure so concurrent cron + opportunistic drains do not hammer a down API. The gate fails open: if the cache is unavailable, the drain still runs.

### Inspecting drain results

```php
$result = $processor->process();

echo $result->processed;                 // Requests successfully delivered this run
echo $result->deadLettered;              // Requests dropped (max attempts exceeded or permanent error)
echo $result->requeued;                  // Requests kept for next run after a transient failure
echo $result->remaining;                 // Requests still pending in the store
echo $result->stoppedOnTransientFailure; // true → a tenant was paused and nothing got through: the API itself looks
                                         //         unreachable, so the cooldown gate engages
echo $result->skipped;                   // true → drain skipped due to active cooldown
print_r($result->pausedTenants);         // Tenants whose partitions were held for the rest of this run
echo $result->notDue;                    // Requests skipped because their per-item backoff had not elapsed
```

---

## Implementing `RetryQueueStorageInterface`

This is the only mandatory platform-specific code. Implement six methods over your platform's durable store.

Two contracts beyond ordering matter. **Tenant:** persist `QueuedRequest::$tenantKey` and honor it in `peekBatch()`,
`count()` and `pendingTenantKeys()`; a single-shop adapter can ignore the argument (it is always null for it) but must
accept it. **Due-at:** honoring the `$dueAt` gate is a SHOULD — the queue re-checks and skips a not-yet-due request
either way, so an adapter that ignores it stays correct, but it spends a batch slot on work it cannot do yet, which is
exactly what the per-item backoff exists to avoid.

```php
use Comfino\Backend\Queue\QueuedRequest;
use Comfino\Backend\Queue\RetryQueueStorageInterface;

class MyRetryQueueStorage implements RetryQueueStorageInterface
{
    public function __construct(private readonly \PDO $db) {}

    public function enqueue(QueuedRequest $request): void
    {
        // Dedup: skip if a pending entry with the same dedupKey() already exists.
        $dedupKey = $request->dedupKey();

        $exists = $this->db->prepare(
            'SELECT 1 FROM comfino_request_queue WHERE dedup_key = ? LIMIT 1'
        );
        $exists->execute([$dedupKey]);

        if ($exists->fetchColumn()) {
            return;
        }

        $stmt = $this->db->prepare(
            'INSERT INTO comfino_request_queue
             (operation_type, payload, attempts, dedup_key, last_error, created_at, tenant_key, available_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $request->operationType,
            json_encode($request->payload),
            $request->attempts,
            $dedupKey,
            $request->lastError,
            $request->createdAt,
            $request->tenantKey,
            $request->availableAt,
        ]);
    }

    public function peekBatch(int $limit, ?string $tenantKey = null, ?int $dueAt = null): array
    {
        /* Return oldest-first. Use row locking if your platform supports it to prevent concurrent drains from
           double-sending. */
        $sql = 'SELECT * FROM comfino_request_queue WHERE 1 = 1';
        $params = [];

        if ($tenantKey !== null) {
            $sql .= ' AND tenant_key = ?';
            $params[] = $tenantKey;
        }

        if ($dueAt !== null) {
            $sql .= ' AND available_at <= ?';
            $params[] = $dueAt;
        }

        $sql .= ' ORDER BY id ASC LIMIT ?';
        $params[] = $limit;

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        return array_map(
            fn(array $row) => new QueuedRequest(
                id: (int) $row['id'],
                operationType: $row['operation_type'],
                payload: json_decode($row['payload'], true),
                attempts: (int) $row['attempts'],
                createdAt: (int) $row['created_at'],
                lastError: $row['last_error'] ?? null,
                tenantKey: $row['tenant_key'] ?? null,
                availableAt: (int) ($row['available_at'] ?? 0),
            ),
            $stmt->fetchAll(\PDO::FETCH_ASSOC)
        );
    }

    public function update(QueuedRequest $request): void
    {
        $stmt = $this->db->prepare(
            'UPDATE comfino_request_queue SET attempts = ?, last_error = ?, available_at = ? WHERE id = ?'
        );
        $stmt->execute([$request->attempts, $request->lastError, $request->availableAt, $request->id]);
    }

    public function remove(QueuedRequest $request): void
    {
        $stmt = $this->db->prepare('DELETE FROM comfino_request_queue WHERE id = ?');
        $stmt->execute([$request->id]);
    }

    public function count(?string $tenantKey = null): int
    {
        if ($tenantKey === null) {
            return (int) $this->db->query('SELECT COUNT(*) FROM comfino_request_queue')->fetchColumn();
        }

        $stmt = $this->db->prepare('SELECT COUNT(*) FROM comfino_request_queue WHERE tenant_key = ?');
        $stmt->execute([$tenantKey]);

        return (int) $stmt->fetchColumn();
    }

    public function pendingTenantKeys(?int $dueAt = null): array
    {
        /* Oldest-waiting tenant first keeps starvation bounded. A single-shop store returns [null] when it has pending
           work and [] when it does not. */
        $sql = 'SELECT tenant_key FROM comfino_request_queue';
        $params = [];

        if ($dueAt !== null) {
            $sql .= ' WHERE available_at <= ?';
            $params[] = $dueAt;
        }

        $sql .= ' GROUP BY tenant_key ORDER BY MIN(id) ASC';

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        return array_map(
            static fn ($tenantKey): ?string => $tenantKey !== null ? (string) $tenantKey : null,
            $stmt->fetchAll(\PDO::FETCH_COLUMN)
        );
    }
}
```

### Recommended table schema (SQL)

```sql
CREATE TABLE comfino_request_queue (
    id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,  -- FIFO sequence
    operation_type VARCHAR(64)  NOT NULL,
    payload        TEXT         NOT NULL,                    -- JSON
    attempts       SMALLINT     NOT NULL DEFAULT 0,
    dedup_key      VARCHAR(128) NOT NULL,
    last_error     TEXT         NULL,
    created_at     INT UNSIGNED NOT NULL,
    tenant_key     VARCHAR(64)  NULL,                        -- Merchant partition; NULL for single-shop installs
    available_at   INT UNSIGNED NOT NULL DEFAULT 0,          -- Per-item backoff gate; 0 = due now
    locked_at      INT UNSIGNED NULL,                        -- Optional: row-claim timestamp
    UNIQUE KEY uq_dedup_key (dedup_key),
    KEY idx_drain (tenant_key, available_at, id)             -- The index the fair drain reads on
);
```

The `dedup_key` unique index enforces enqueue-time deduplication at the DB level as a safety net, complementing the application-level check in `enqueue()`.

### Concurrency: preventing double-sends

Two drains running simultaneously (cron + opportunistic) must not deliver the same request twice.
Options, in order of preference:

1. **Row locking** — `SELECT … FOR UPDATE` inside a transaction in `peekBatch()`. The strongest guarantee; use where your DB/ORM supports it.
2. **Lock column** — set `locked_at = NOW()` on claimed rows; have `peekBatch()` skip rows where `locked_at` is within the last N seconds.
3. **Advisory lock** — a named DB lock around the entire `process()` call.

The cooldown gate in `OutboundRequestQueueProcessor` provides a coarser-grained defense: after any transient failure it suppresses drains for `cooldownSeconds`, which in practice makes double-sends rare even without row locking.

---

## Implementing `DeadLetterReporterInterface` (optional)

When a request is dropped permanently (permanent API error on `submit()`, or `maxAttempts` exhausted during drain), the queue calls `$deadLetterReporter->report()` if one is wired. Use this to surface undeliverable cancellations in your platform's error tracker.

```php
use Comfino\Backend\Queue\DeadLetterReporterInterface;
use Comfino\Backend\Queue\QueuedRequest;

class MyDeadLetterReporter implements DeadLetterReporterInterface
{
    public function __construct(private readonly \Psr\Log\LoggerInterface $logger) {}

    public function report(QueuedRequest $request, \Throwable $error): void
    {
        $this->logger->critical('[REQUEST_QUEUE] Undeliverable request dead-lettered', [
            'operationType' => $request->operationType,
            'payload' => $request->payload,
            'attempts' => $request->attempts,
            'createdAt' => $request->createdAt,
            'lastError' => $request->lastError,
            'exception' => get_class($error) . ': ' . $error->getMessage(),
        ]);
    }
}
```

---

## Registering a custom operation type

The queue is generic. Beyond the built-in `cancel_order`, you can register any idempotent outbound call.

```php
use Comfino\Backend\Queue\RetryableOperationHandlerInterface;

class MySyncStockHandler implements RetryableOperationHandlerInterface
{
    public const OPERATION_TYPE = 'sync_stock';

    public function execute(array $payload): void
    {
        // Must throw on failure so the queue can classify the error.
        $this->apiClient->syncStock($payload['productId'], (int) $payload['quantity']);
    }
}

// Register after factory creation:
$queue->registerHandler(MySyncStockHandler::OPERATION_TYPE, new MySyncStockHandler($minimalTimeoutClient));

// Then submit as usual:
$queue->submit(MySyncStockHandler::OPERATION_TYPE, ['productId' => '42', 'quantity' => 5]);
```

### Error classification for custom operations

`ApiTransientErrorClassifier` applies to all operation types:

| Condition | Disposition |
|---|---|
| PSR-18 network error, cURL timeout, `ConnectionTimeout` | `Retry` |
| HTTP 5xx or 429 | `Retry` |
| HTTP 404 / 409 for `cancel_order` (and any op in `absorbNotFoundOperations`) | `TreatAsSuccess` |
| HTTP 401 / 403 | `PauseTenant` |
| Any other HTTP 4xx | `DropPermanent` |
| Any other (non-HTTP) throwable | `Retry` (conservative; capped by `maxAttempts`) |

If your operation needs different 404/409 semantics, pass a custom `$absorbNotFoundOperations` list to `ApiTransientErrorClassifier`, or supply your own `TransientErrorClassifierInterface` implementation to `OutboundRequestQueueFactory::create()`.

`PauseTenant` is not a variant of `DropPermanent`, and the distinction is the point. A 401 says the merchant's key is
wrong, not that the request is: the payload is fine, so it succeeds once the key is fixed, and until then *every*
request for that merchant fails identically. Dropping them one at a time silently discarded a merchant's entire
payment-relevant outbound stream while the queue reported itself healthy. Instead the request is deferred without
spending an attempt, the merchant's partition is held for the rest of the drain, and
`TenantPauseReporterInterface::reportPaused()` raises an alert a human can act on. Pass an implementation of it to
`OutboundRequestQueueFactory::create()` — a paused partition nobody hears about is the old bug with extra steps.

---

## Configuration knobs

| Knob | Default | Where to set |
|---|---|---|
| `maxAttempts` | `10` | `OutboundRequestQueueFactory::create()` |
| `baseRetryDelaySeconds` | `60` | `OutboundRequestQueueFactory::create()` — first per-item retry delay; doubles per attempt |
| `maxRetryDelaySeconds` | `3600` | `OutboundRequestQueueFactory::create()` — ceiling for the per-item delay |
| `maxConsecutiveTenantFailures` | `1` | `OutboundRequestQueueFactory::create()` — `1` reproduces the pre-3.0 stop-on-first-failure behavior, scoped to one tenant |
| `tenantPauseSeconds` | `900` | `OutboundRequestQueueFactory::create()` — how far a paused tenant's requests are deferred |
| `cooldownSeconds` | `300` | `OutboundRequestQueueProcessor` constructor |
| `defaultBatchSize` | `20` | `OutboundRequestQueueProcessor` constructor |
| `tenantKey` | `null` (drain every tenant fairly) | `OutboundRequestQueueProcessor` constructor |
| Connect timeout (fast path) | `1 s` | `ApiClientFactory::createClient(connectionTimeout: 1)` |
| Transfer timeout (fast path) | `2 s` | `ApiClientFactory::createClient(transferTimeout: 2)` |
| Connect timeout (drain) | `2 s` | `ApiClientFactory::createClient(connectionTimeout: 2)` |
| Transfer timeout (drain) | `5 s` | `ApiClientFactory::createClient(transferTimeout: 5)` |

Both the fast-path client and the drain client should use `maxRetries: 1` (one attempt, no escalation inside the client). The durable queue is the retry mechanism.

---

## Observability

Every significant state transition emits a PSR-3 log entry via the optional `$logger` passed to `OutboundRequestQueueFactory::create()`. All messages are prefixed `[REQUEST_QUEUE]`.

| Event | Level |
|---|---|
| Fast path succeeded | — (no log) |
| Fast path absorbed (404/409 on cancel) | `debug` |
| Fast path deferred to queue (transient) | `warning` |
| Fast path dropped (permanent) | `error` |
| Drain: no handler registered for queued op | `error` |
| Drain: request dead-lettered | `error` |
| Drain: tenant partition paused | `warning` |
| Cooldown gate suppressed a drain | — (skipped via `QueueDrainResult::$skipped`) |

Dead-lettered requests additionally trigger `DeadLetterReporterInterface::report()` for platform-level alerting, and a
paused partition triggers `TenantPauseReporterInterface::reportPaused()`. Every log entry carries `tenantKey` in its
context, so a shared queue's log can be read per merchant.

---

## Class reference

| Class / Interface | Role |
|---|---|
| `OutboundRequestQueue` | Core queue — `submit()` and `process()` |
| `OutboundRequestQueueProcessor` | Scheduler/opportunistic entrypoint with cooldown gate |
| `OutboundRequestQueueFactory` | Assembles the queue with defaults |
| `RetryQueueStorageInterface` | Platform-implemented durable FIFO storage |
| `RetryableOperationHandlerInterface` | Per-operation HTTP call (throws on failure) |
| `CancelOrderHandler` | Built-in handler for `cancel_order` |
| `TransientErrorClassifierInterface` | Maps `Throwable` to `QueueErrorDisposition` |
| `ApiTransientErrorClassifier` | Default classifier (reuses api-client rules) |
| `DeadLetterReporterInterface` | Notified when a request gives up |
| `TenantPauseReporterInterface` | Notified when a tenant's partition is paused |
| `QueuedRequest` | Value object for one queued request |
| `QueueErrorDisposition` | `Retry` / `DropPermanent` / `TreatAsSuccess` / `PauseTenant` |
| `SubmitResult` | `SentImmediately` / `Queued` / `DroppedPermanent` |
| `QueueDrainResult` | Summary of one `process()` run |
| `ClockInterface` / `SystemClock` | Testable clock abstraction |
