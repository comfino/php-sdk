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

use Comfino\Backend\Webhook\TenantAwareWebhookEndpointInterface;
use Comfino\Backend\Webhook\VerifiedWebhookRequest;
use Comfino\Backend\Webhook\WebhookEndpoint;
use Psr\Http\Message\ServerRequestInterface;

/**
 * An endpoint that records the verification outcome it was handed.
 *
 * The point of {@see TenantAwareWebhookEndpointInterface} is that an endpoint can be built once and told per request
 * whose merchant it is acting for, so what the tests assert is precisely what arrives here.
 */
final class RecordingTenantAwareEndpoint extends WebhookEndpoint implements TenantAwareWebhookEndpointInterface
{
    /** @var VerifiedWebhookRequest[] */
    public array $seen = [];

    public int $tenantBlindCalls = 0;

    public function __construct(string $name, string $endpointUrl)
    {
        parent::__construct($name, $endpointUrl);

        $this->methods = ['POST'];
    }

    /** @return array<string, mixed>|null */
    public function processRequest(ServerRequestInterface $serverRequest, ?string $endpointName = null): ?array
    {
        $this->tenantBlindCalls++;

        return null;
    }

    /** @return array<string, mixed>|null */
    public function processTenantRequest(ServerRequestInterface $serverRequest, ?string $endpointName, VerifiedWebhookRequest $verified): ?array
    {
        $this->seen[] = $verified;

        return null;
    }
}
