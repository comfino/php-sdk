<?php

/**
 * ComfinoPay PHP SDK
 *
 * Backend routines for e-commerce platforms integration with the ComfinoPay payment gateway API.
 *
 * @package Comfino\Tests\Unit\Backend\Queue
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
 * @copyright Copyright (c) 2026 by ComfinoPay sp. z o.o.
 * @license https://opensource.org/licenses/BSD-3-Clause BSD 3-Clause License
 * @link https://github.com/comfino/php-sdk
 */

declare(strict_types=1);

namespace Comfino\Tests\Unit\Backend\Queue;

use Comfino\Backend\Queue\TenantAwareRetryableOperationHandlerInterface;
use Throwable;

/**
 * Tenant-aware counterpart of {@see ScriptedHandler}: records the tenant key each delivery was made on behalf of, so a
 * test can assert that the queue hands the handler the identity recorded on the queued request rather than the ambient
 * one. Outcomes are consumed in order (a Throwable to throw, null to succeed); once exhausted, every call succeeds.
 */
final class TenantAwareScriptedHandler implements TenantAwareRetryableOperationHandlerInterface
{
    /** @var array<int, Throwable|null> */
    private array $outcomes;

    /** @var array<int, array{payload: array<string, scalar>, tenantKey: string|null}> */
    public array $calls = [];

    /** Payloads delivered through the tenant-blind execute() path, which the queue must not use for this handler. */
    public int $tenantBlindCalls = 0;

    /**
     * @param array<int, Throwable|null> $outcomes
     */
    public function __construct(array $outcomes = [])
    {
        $this->outcomes = $outcomes;
    }

    /**
     * @throws Throwable
     */
    public function execute(array $payload): void
    {
        $this->tenantBlindCalls++;

        $this->executeForTenant($payload, null);
    }

    /**
     * @throws Throwable
     */
    public function executeForTenant(array $payload, ?string $tenantKey): void
    {
        $this->calls[] = ['payload' => $payload, 'tenantKey' => $tenantKey];

        $outcome = array_shift($this->outcomes);

        if ($outcome instanceof Throwable) {
            throw $outcome;
        }
    }

    /**
     * @return array<int, string|null> Tenant keys in delivery order
     */
    public function tenantKeys(): array
    {
        return array_map(static fn (array $call): ?string => $call['tenantKey'], $this->calls);
    }
}
