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

use Comfino\Backend\Webhook\RateLimitKey;
use Comfino\Backend\Webhook\RateLimitVerdict;
use Comfino\Backend\Webhook\TenantAwareRateLimiterInterface;

/**
 * A limiter that records every key it is asked about, so "one request, one token" is an assertion rather than a hope.
 */
final class RecordingRateLimiter implements TenantAwareRateLimiterInterface
{
    /** @var RateLimitKey[] */
    public array $consumed = [];

    /** @var int[] */
    public array $costs = [];

    public function __construct(private readonly bool $accept = true)
    {
    }

    public function consume(RateLimitKey $key, int $tokens = 1): RateLimitVerdict
    {
        $this->consumed[] = $key;
        $this->costs[] = $tokens;

        return $this->accept ? RateLimitVerdict::accept(9, 10) : RateLimitVerdict::reject(30, 10);
    }
}
