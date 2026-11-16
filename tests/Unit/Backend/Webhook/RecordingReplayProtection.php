<?php

/**
 * ComfinoPay PHP SDK
 *
 * Backend routines for e-commerce platforms integration with the ComfinoPay payment gateway API.
 *
 * @package Comfino\Tests\Unit\Backend\Webhook
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
 * @copyright Copyright (c) 2026 by ComfinoPay sp. z o.o.
 * @license https://opensource.org/licenses/BSD-3-Clause BSD 3-Clause License
 * @link https://github.com/comfino/php-sdk
 */

declare(strict_types=1);

namespace Comfino\Tests\Unit\Backend\Webhook;

use Comfino\Backend\Webhook\TenantAwareReplayProtectionInterface;

/**
 * An in-memory replay store that remembers what it was asked and what it was told.
 */
final class RecordingReplayProtection implements TenantAwareReplayProtectionInterface
{
    /** @var array<string, true> */
    private array $processed = [];

    /** @var string[] */
    public array $checkedKeys = [];

    /** @var array<int, array{key: string, tenantKey: string|null, ttl: int|null}> */
    public array $markedKeys = [];

    public function isDuplicate(string $signature, ?string $tenantKey = null): bool
    {
        $this->checkedKeys[] = $signature;

        return isset($this->processed[$tenantKey . '|' . $signature]);
    }

    public function markProcessed(string $signature, ?string $tenantKey = null, ?int $ttlSeconds = null): void
    {
        $this->markedKeys[] = ['key' => $signature, 'tenantKey' => $tenantKey, 'ttl' => $ttlSeconds];
        $this->processed[$tenantKey . '|' . $signature] = true;
    }

    public function purgeTenant(?string $tenantKey = null): int
    {
        return 0;
    }
}
