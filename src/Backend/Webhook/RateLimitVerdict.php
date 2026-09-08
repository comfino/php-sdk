<?php

/**
 * ComfinoPay PHP SDK
 *
 * Backend routines for e-commerce platforms integration with the ComfinoPay payment gateway API.
 *
 * @package Comfino\Backend\Webhook
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
 * @copyright Copyright (c) 2026 by ComfinoPay sp. z o.o.
 * @license https://opensource.org/licenses/BSD-3-Clause BSD 3-Clause License
 * @link https://github.com/comfino/php-sdk
 */

declare(strict_types=1);

namespace Comfino\Backend\Webhook;

/**
 * A rate limiter's answer, with enough details to write a correct HTTP 429.
 *
 * The old interface answered with a bare `bool`, which cannot produce a spec-correct rejection: RFC 9110 wants a
 * `Retry-After` on a 429, and a caller that is being throttled needs to know *when* to come back rather than being
 * left to guess. A boolean also cannot say how much headroom is left, so a well-behaved sender has no way to slow down
 * before it is rejected. Both facts exist inside every real limiter implementation; this object is how they get out.
 */
final class RateLimitVerdict
{
    /**
     * @param bool $accepted Whether the request may proceed
     * @param int $remaining Tokens left in the window after this call; 0 when unknown
     * @param int $retryAfterSeconds Seconds until the caller should retry; 0 when accepted or unknown
     * @param int $limit Window capacity, for the `X-RateLimit-Limit` header; 0 when unknown
     */
    public function __construct(
        public readonly bool $accepted,
        public readonly int $remaining = 0,
        public readonly int $retryAfterSeconds = 0,
        public readonly int $limit = 0
    ) {
    }

    /**
     * Builds an accepting verdict.
     *
     * @param int $remaining Tokens left in the window
     * @param int $limit Window capacity
     */
    public static function accept(int $remaining = 0, int $limit = 0): self
    {
        return new self(true, $remaining, 0, $limit);
    }

    /**
     * Builds a rejecting verdict.
     *
     * @param int $retryAfterSeconds Seconds until the caller should retry
     * @param int $limit Window capacity
     */
    public static function reject(int $retryAfterSeconds = 0, int $limit = 0): self
    {
        return new self(false, 0, $retryAfterSeconds, $limit);
    }

    /**
     * Returns the rate-limit response headers this verdict justifies.
     *
     * Only headers whose value the limiter actually reported are emitted: a `Retry-After: 0` or an
     * `X-RateLimit-Limit: 0` is worse than a missing header, because a caller reads it as a fact.
     *
     * @return array<string, string> Header name => value
     */
    public function toHeaders(): array
    {
        $headers = [];

        if ($this->limit > 0) {
            $headers['X-RateLimit-Limit'] = (string) $this->limit;
            $headers['X-RateLimit-Remaining'] = (string) max(0, $this->remaining);
        }

        if (!$this->accepted && $this->retryAfterSeconds > 0) {
            $headers['Retry-After'] = (string) $this->retryAfterSeconds;
        }

        return $headers;
    }
}
