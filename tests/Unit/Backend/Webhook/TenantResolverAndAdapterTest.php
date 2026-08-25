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

use Comfino\Backend\Webhook\LegacyRateLimiterAdapter;
use Comfino\Backend\Webhook\LegacyReplayProtectionAdapter;
use Comfino\Backend\Webhook\RateLimiterInterface;
use Comfino\Backend\Webhook\RateLimitKey;
use Comfino\Backend\Webhook\RateLimitVerdict;
use Comfino\Backend\Webhook\ReplayProtectionInterface;
use Comfino\Backend\Webhook\StaticApiKeyResolver;
use Comfino\Backend\Webhook\TenantWebhookContext;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;

final class TenantResolverAndAdapterTest extends TestCase
{
    // --- TenantWebhookContext ---

    /**
     * An empty key reduces the expected signature to a hash of the payload alone, which any caller can compute without
     * knowing a secret. A context carrying one would authorize forged requests, so it cannot be constructed.
     */
    public function testAContextCannotBeBuiltWithoutAUsableKey(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('has no usable API key');

        /** @phpstan-ignore-next-line Deliberately loose: hosts resolve an unconfigured key to null or '' */
        new TenantWebhookContext('shop_1', ['', null]);
    }

    public function testUnusableKeysAreDiscardedButUsableOnesSurvive(): void
    {
        /** @phpstan-ignore-next-line Deliberately loose: hosts resolve an unconfigured key to null or '' */
        $context = new TenantWebhookContext('shop_1', ['', 'real_key', null, 'previous_key']);

        self::assertSame(['real_key', 'previous_key'], $context->apiKeys);
        self::assertSame('real_key', $context->primaryApiKey());
    }

    public function testMetadataIsCarriedForTheHostWithoutTheSdkReadingIt(): void
    {
        $context = TenantWebhookContext::forKey('shop_1', 'key', ['sandbox' => true]);

        self::assertTrue($context->getMetadata('sandbox'));
        self::assertSame('fallback', $context->getMetadata('absent', 'fallback'));
    }

    // --- StaticApiKeyResolver ---

    public function testStaticResolverAnswersEveryRequestWithTheConfiguredKeys(): void
    {
        $resolver = new StaticApiKeyResolver(['key_1', 'key_2'], 'the_only_shop');
        $context = $resolver->resolve($this->createMock(ServerRequestInterface::class));

        self::assertNotNull($context);
        self::assertSame('the_only_shop', $context->tenantKey);
        self::assertSame(['key_1', 'key_2'], $context->apiKeys);
        self::assertTrue($resolver->hasUsableApiKey());
    }

    /**
     * A plugin whose API key is not configured yet must reject webhooks, not verify them against a hash of the payload
     * alone.
     */
    public function testStaticResolverWithNoUsableKeyResolvesToNull(): void
    {
        /** @phpstan-ignore-next-line Deliberately loose: hosts resolve an unconfigured key to null or '' */
        $resolver = new StaticApiKeyResolver([null, '']);

        self::assertNull($resolver->resolve($this->createMock(ServerRequestInterface::class)));
        self::assertNull($resolver->resolveByTenantKey());
        self::assertFalse($resolver->hasUsableApiKey());
    }

    /**
     * A null tenant key asks "the one tenant this integration serves", which this resolver can answer. A *different*
     * tenant it cannot — pretending otherwise would sign with the wrong merchant's key.
     */
    public function testStaticResolverAnswersOnlyForItsOwnTenant(): void
    {
        $resolver = new StaticApiKeyResolver(['key_1'], 'the_only_shop');

        self::assertNotNull($resolver->resolveByTenantKey());
        self::assertNotNull($resolver->resolveByTenantKey('the_only_shop'));
        self::assertNull($resolver->resolveByTenantKey('some_other_shop'));
    }

    // --- RateLimitVerdict ---

    public function testAnAcceptingVerdictPublishesHeadroomButNoRetryAfter(): void
    {
        $headers = RateLimitVerdict::accept(9, 10)->toHeaders();

        self::assertSame(['X-RateLimit-Limit' => '10', 'X-RateLimit-Remaining' => '9'], $headers);
    }

    /**
     * A `Retry-After: 0` or an `X-RateLimit-Limit: 0` is worse than a missing header, because a caller reads it as a
     * fact. Only what the limiter actually reported is emitted.
     */
    public function testAVerdictWithNothingToReportEmitsNoHeaders(): void
    {
        self::assertSame([], RateLimitVerdict::reject()->toHeaders());
    }

    public function testARejectingVerdictPublishesRetryAfter(): void
    {
        $headers = RateLimitVerdict::reject(30, 100)->toHeaders();

        self::assertSame('30', $headers['Retry-After']);
        self::assertSame('100', $headers['X-RateLimit-Limit']);
        self::assertSame('0', $headers['X-RateLimit-Remaining']);
    }

    // --- RateLimitKey ---

    /**
     * The tenant comes first, so a prefix scan can enumerate or purge one merchant's counters.
     */
    public function testTheStorageKeyLeadsWithTheTenant(): void
    {
        self::assertSame(
            'shop_1|status-notification|203.0.113.7',
            (new RateLimitKey('status-notification', '203.0.113.7', 'shop_1'))->toStorageKey()
        );
        self::assertSame(
            '-|status-notification|203.0.113.7',
            (new RateLimitKey('status-notification', '203.0.113.7'))->toStorageKey()
        );
    }

    // --- legacy adapters ---

    public function testTheLegacyLimiterAdapterTranslatesTheBooleanVerdict(): void
    {
        $legacy = $this->createMock(RateLimiterInterface::class);
        $legacy->method('isAllowed')->willReturnCallback(
            static fn (string $endpoint, string $client): bool => $client !== '203.0.113.9'
        );

        $adapter = new LegacyRateLimiterAdapter($legacy);

        self::assertTrue($adapter->consume(new RateLimitKey('e', '203.0.113.7', 'shop_1'))->accepted);
        self::assertFalse($adapter->consume(new RateLimitKey('e', '203.0.113.9', 'shop_1'))->accepted);
    }

    /**
     * The adapter cannot invent information the old interface never carried: a rejection has no Retry-After to report,
     * which is a faithful reflection of the wrapped limiter's knowledge and the reason to implement the new interface
     * directly.
     */
    public function testTheLegacyLimiterAdapterReportsNoRateLimitFacts(): void
    {
        $legacy = $this->createMock(RateLimiterInterface::class);
        $legacy->method('isAllowed')->willReturn(false);

        $verdict = (new LegacyRateLimiterAdapter($legacy))->consume(new RateLimitKey('e', 'ip'));

        self::assertSame([], $verdict->toHeaders());
    }

    public function testTheLegacyReplayAdapterForwardsTheSignatureAndDropsWhatItCannotCarry(): void
    {
        $seen = [];

        $legacy = $this->createMock(ReplayProtectionInterface::class);
        $legacy->method('isDuplicate')->willReturnCallback(
            static function (string $signature) use (&$seen): bool {
                $seen[] = $signature;

                return $signature === 'already_seen';
            }
        );

        $adapter = new LegacyReplayProtectionAdapter($legacy);

        self::assertTrue($adapter->isDuplicate('already_seen', 'shop_1'));
        self::assertFalse($adapter->isDuplicate('fresh', 'shop_1'));
        self::assertSame(['already_seen', 'fresh'], $seen);
    }

    /**
     * Purging a tenant from an unpartitioned store would delete other merchants' replay records — a correctness
     * regression dressed up as cleanup — so the adapter reports that it did nothing.
     */
    public function testTheLegacyReplayAdapterRefusesToPurgeAnUnpartitionedStore(): void
    {
        $adapter = new LegacyReplayProtectionAdapter($this->createMock(ReplayProtectionInterface::class));

        self::assertSame(0, $adapter->purgeTenant('shop_1'));
    }
}
