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

use Comfino\Backend\Cache\ScopedCache;
use Comfino\Backend\Clock\ClockInterface;
use Comfino\Backend\Clock\SystemClock;
use Comfino\Backend\Webhook\WebhookVerificationObserverInterface;
use Comfino\Backend\Webhook\WebhookVerificationOutcome;
use Throwable;

/**
 * Counts webhook signature mismatches per tenant in a rolling window and remembers the last verified delivery: the signal for
 * forged requests that replaces logging the calculated signature. Pass it to {@see \Comfino\Backend\Webhook\WebhookManager}
 * as $verificationObserver.
 *
 * Only signature mismatches are counted; a missing signature or unknown tenant is ordinary scanner noise. Writes are
 * best-effort and never throw into the request.
 */
final class WebhookHealthRecorder implements WebhookVerificationObserverInterface
{
    private const CACHE_KEY_PREFIX = 'health.webhook.';
    /** Cap on stored mismatch timestamps, so a flood cannot grow the cache entry without bound. */
    private const MAX_TRACKED_MISMATCHES = 200;

    public function __construct(
        private readonly ScopedCache $cache,
        private readonly int $windowSeconds = 86400,
        private readonly ClockInterface $clock = new SystemClock()
    ) {
    }

    public function onVerification(WebhookVerificationOutcome $outcome, ?string $tenantKey, ?string $clientIp): void
    {
        if ($outcome !== WebhookVerificationOutcome::Verified && $outcome !== WebhookVerificationOutcome::SignatureMismatch) {
            return;
        }

        $now = $this->clock->now();
        $data = $this->load($tenantKey, $now);

        if ($outcome === WebhookVerificationOutcome::Verified) {
            $data['lastVerifiedAt'] = $now;
        } else {
            $data['mismatches'][] = $now;
            $data['mismatches'] = array_slice($data['mismatches'], -self::MAX_TRACKED_MISMATCHES);
            $data['lastMismatchAt'] = $now;
        }

        try {
            $this->cache->set(self::cacheKey($tenantKey), $data);
        } catch (Throwable) {
            // Health recording is best-effort.
        }
    }

    public function getHealth(?string $tenantKey = null): WebhookHealth
    {
        $data = $this->load($tenantKey, $this->clock->now());

        return new WebhookHealth(count($data['mismatches']), $this->windowSeconds, $data['lastVerifiedAt'], $data['lastMismatchAt']);
    }

    /**
     * @return array{mismatches: list<int>, lastVerifiedAt: ?int, lastMismatchAt: ?int}
     */
    private function load(?string $tenantKey, int $now): array
    {
        $data = $this->cache->get(self::cacheKey($tenantKey));
        $data = is_array($data) ? $data : [];
        $windowStart = $now - $this->windowSeconds;

        return [
            'mismatches' => array_values(array_filter(
                array_map('intval', is_array($data['mismatches'] ?? null) ? $data['mismatches'] : []),
                static fn (int $at): bool => $at > $windowStart
            )),
            'lastVerifiedAt' => isset($data['lastVerifiedAt']) ? (int) $data['lastVerifiedAt'] : null,
            'lastMismatchAt' => isset($data['lastMismatchAt']) ? (int) $data['lastMismatchAt'] : null,
        ];
    }

    private static function cacheKey(?string $tenantKey): string
    {
        return self::CACHE_KEY_PREFIX . sha1($tenantKey ?? '');
    }
}
