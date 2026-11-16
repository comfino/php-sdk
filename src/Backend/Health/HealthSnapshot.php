<?php

/**
 * ComfinoPay PHP SDK
 *
 * Backend routines for e-commerce platforms integration with the ComfinoPay payment gateway API.
 *
 * @package Comfino\Backend\Health
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
 * @copyright Copyright (c) 2026 by ComfinoPay sp. z o.o.
 * @license https://opensource.org/licenses/BSD-3-Clause BSD 3-Clause License
 * @link https://github.com/comfino/php-sdk
 */

declare(strict_types=1);

namespace Comfino\Backend\Health;

/**
 * Integration health of one tenant for an admin diagnostics panel: API calls and webhook verification.
 */
final class HealthSnapshot
{
    public function __construct(
        public readonly ApiHealth $api,
        public readonly WebhookHealth $webhook,
        public readonly ?string $tenantKey = null
    ) {
    }

    public static function capture(ApiHealthRecorder $apiRecorder, WebhookHealthRecorder $webhookRecorder, ?string $tenantKey = null): self
    {
        return new self($apiRecorder->getHealth($tenantKey), $webhookRecorder->getHealth($tenantKey), $tenantKey);
    }

    /**
     * @return array{tenant_key: ?string, api: array<string, mixed>, webhook: array<string, mixed>}
     */
    public function toArray(): array
    {
        return ['tenant_key' => $this->tenantKey, 'api' => $this->api->toArray(), 'webhook' => $this->webhook->toArray()];
    }
}
