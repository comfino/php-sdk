<?php

/**
 * Comfino PHP SDK
 *
 * Backend routines for e-commerce platforms integration with the Comfino payment gateway API.
 *
 * @package Comfino\Tests\Unit\Backend\Webhook
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
 * @copyright Copyright (c) 2026 Comfino by Comperia.pl S.A.
 * @license https://opensource.org/licenses/BSD-3-Clause BSD 3-Clause License
 * @link https://github.com/comfino/php-sdk
 */

declare(strict_types=1);

namespace Comfino\Tests\Unit\Backend\Webhook;

use Comfino\Api\Exception\InvalidEndpoint;
use Comfino\Backend\Webhook\WebhookEndpoint;
use Psr\Http\Message\ServerRequestInterface;

/**
 * A tenant-blind endpoint that counts its invocations and optionally refuses every request.
 *
 * Refusing is how the fallback scan in {@see \Comfino\Backend\Webhook\WebhookManager::processRequest()} is exercised:
 * an endpoint that throws {@see InvalidEndpoint} is skipped and the next one is tried, which is the path where the rate
 * limiter used to be charged once per endpoint tried.
 */
final class RecordingEndpoint extends WebhookEndpoint
{
    public int $calls = 0;

    public function __construct(string $name, string $endpointUrl, private readonly bool $accepts = true)
    {
        parent::__construct($name, $endpointUrl);

        $this->methods = ['POST'];
    }

    /** @return array<string, mixed>|null */
    public function processRequest(ServerRequestInterface $serverRequest, ?string $endpointName = null): ?array
    {
        if (!$this->accepts) {
            throw new InvalidEndpoint('Not my request.');
        }

        $this->calls++;

        return null;
    }
}
