<?php

/**
 * ComfinoPay PHP SDK
 *
 * Backend routines for e-commerce platforms integration with the ComfinoPay payment gateway API.
 *
 * @package Comfino\Tests\Unit\Backend\Factory
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
 * @copyright Copyright (c) 2026 by ComfinoPay sp. z o.o.
 * @license https://opensource.org/licenses/BSD-3-Clause BSD 3-Clause License
 * @link https://github.com/comfino/php-sdk
 */

declare(strict_types=1);

namespace Comfino\Tests\Unit\Backend\Factory;

use Comfino\Api\Retry\TimeoutAwareClientInterface;
use LogicException;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Legacy timeout-aware transport double, recording the in-place reconfiguration the factory applies to it.
 */
final class RecordingTimeoutAwareClient implements TimeoutAwareClientInterface
{
    /** @var array{0: int, 1: int}|array{} */
    public array $appliedTimeouts = [];

    /** @inheritDoc */
    public function updateTimeouts(int $connectionTimeout, int $transferTimeout): void
    {
        $this->appliedTimeouts = [$connectionTimeout, $transferTimeout];
    }

    /** @inheritDoc */
    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        throw new LogicException('Not used by these tests.');
    }
}
