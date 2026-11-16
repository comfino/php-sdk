<?php

/**
 * ComfinoPay PHP SDK
 *
 * Backend routines for e-commerce platforms integration with the ComfinoPay payment gateway API.
 *
 * @package Comfino\Tests\Unit\Backend\Health
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
 * @copyright Copyright (c) 2026 by ComfinoPay sp. z o.o.
 * @license https://opensource.org/licenses/BSD-3-Clause BSD 3-Clause License
 * @link https://github.com/comfino/php-sdk
 */

declare(strict_types=1);

namespace Comfino\Tests\Unit\Backend\Health;

use Cache\Adapter\PHPArray\ArrayCachePool;
use Comfino\Api\ApiContext;
use Comfino\Backend\Cache\ScopedCache;
use Comfino\Backend\Health\ApiHealthRecorder;
use Comfino\Backend\Health\HealthSnapshot;
use Comfino\Backend\Health\WebhookHealthRecorder;
use Comfino\Backend\Webhook\WebhookVerificationOutcome;
use Comfino\Tests\Unit\Backend\Queue\FixedClock;
use Nyholm\Psr7\Request;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class HealthRecorderTest extends TestCase
{
    private ScopedCache $cache;
    private FixedClock $clock;

    protected function setUp(): void
    {
        parent::setUp();

        $this->cache = new ScopedCache(new ArrayCachePool());
        $this->clock = new FixedClock(1_000_000);
    }

    public function testFailuresAccumulateAndASuccessResetsTheCounter(): void
    {
        $recorder = new ApiHealthRecorder($this->cache, $this->clock);
        $request = new Request('GET', 'https://api-ecommerce.comfino.pl/v1/widget-key');
        $context = new ApiContext('key', tenantKey: 'store_1');

        $recorder->onFailure($context, $request, new RuntimeException('down'), 1);
        $this->clock->advance(10);
        $recorder->onResponse($context, $request, new Response(503), 5.0);

        $health = $recorder->getHealth('store_1');

        $this->assertSame(2, $health->consecutiveFailures);
        $this->assertSame(1_000_010, $health->lastFailureAt);
        $this->assertSame('HTTP 503', $health->lastFailureClass);
        $this->assertNull($health->lastSuccessAt);

        $this->clock->advance(10);
        $recorder->onResponse($context, $request, new Response(401), 5.0);

        $health = $recorder->getHealth('store_1');

        $this->assertSame(0, $health->consecutiveFailures, 'A 4xx is an answer from a healthy API.');
        $this->assertSame(1_000_020, $health->lastSuccessAt);
        $this->assertSame(1_000_010, $health->lastFailureAt, 'The last failure is kept for display.');
    }

    public function testTenantsAreRecordedSeparately(): void
    {
        $recorder = new ApiHealthRecorder($this->cache, $this->clock);

        $recorder->onFailure(new ApiContext('key', tenantKey: 'store_1'), new Request('GET', 'https://x/'), new RuntimeException('down'), 1);

        $this->assertSame(1, $recorder->getHealth('store_1')->consecutiveFailures);
        $this->assertSame(0, $recorder->getHealth('store_2')->consecutiveFailures);
        $this->assertSame(RuntimeException::class, $recorder->getHealth('store_1')->lastFailureClass);
    }

    public function testSharedClientObserverCallsUseTheContextTenant(): void
    {
        $recorder = new ApiHealthRecorder($this->cache, $this->clock);
        $context = new ApiContext('key', tenantKey: 'store_9');

        $recorder->onFailure($context, new Request('GET', 'https://x/'), new RuntimeException('down'), 1);

        $this->assertSame(1, $recorder->getHealth('store_9')->consecutiveFailures);
    }

    public function testWebhookMismatchesAreCountedInARollingWindow(): void
    {
        $recorder = new WebhookHealthRecorder($this->cache, 3600, $this->clock);

        $recorder->onVerification(WebhookVerificationOutcome::SignatureMismatch, 'store_1', '203.0.113.7');
        $recorder->onVerification(WebhookVerificationOutcome::MissingSignature, 'store_1', '203.0.113.7');
        $this->clock->advance(3000);
        $recorder->onVerification(WebhookVerificationOutcome::SignatureMismatch, 'store_1', '203.0.113.7');
        $recorder->onVerification(WebhookVerificationOutcome::Verified, 'store_1', '203.0.113.7');

        $health = $recorder->getHealth('store_1');

        $this->assertSame(2, $health->recentMismatches);
        $this->assertSame(1_003_000, $health->lastVerifiedAt);

        $this->clock->advance(1000);

        $this->assertSame(1, $recorder->getHealth('store_1')->recentMismatches, 'The first mismatch left the window.');
    }

    public function testSnapshotCombinesBothRecorders(): void
    {
        $api = new ApiHealthRecorder($this->cache, $this->clock);
        $webhook = new WebhookHealthRecorder($this->cache, 3600, $this->clock);
        $api->onResponse(new ApiContext('key'), new Request('GET', 'https://x/'), new Response(200), 1.0);

        $snapshot = HealthSnapshot::capture($api, $webhook)->toArray();

        $this->assertSame(1_000_000, $snapshot['api']['last_success_at']);
        $this->assertSame(0, $snapshot['webhook']['recent_mismatches']);
        $this->assertNull($snapshot['tenant_key']);
    }
}
