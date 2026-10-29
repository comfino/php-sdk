<?php

/**
 * ComfinoPay PHP SDK
 *
 * Backend routines for e-commerce platforms integration with the ComfinoPay payment gateway API.
 *
 * @package Comfino\Tests\Unit\Backend\Store
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
 * @copyright Copyright (c) 2026 by ComfinoPay sp. z o.o.
 * @license https://opensource.org/licenses/BSD-3-Clause BSD 3-Clause License
 * @link https://github.com/comfino/php-sdk
 */

declare(strict_types=1);

namespace Comfino\Tests\Unit\Backend\Store;

use Cache\Adapter\PHPArray\ArrayCachePool;
use Comfino\Api\CircuitBreaker\CircuitBreaker;
use Comfino\Api\CircuitBreaker\CircuitBreakerKey;
use Comfino\Api\CircuitBreaker\CircuitBreakerState;
use Comfino\Api\RateLimit\TokenBucket;
use Comfino\Api\RateLimit\TokenBucketRateLimiter;
use Comfino\Api\Support\FrozenClock;
use Comfino\Backend\Store\Psr6CircuitBreakerStore;
use Comfino\Backend\Store\Psr6StoreKey;
use Comfino\Backend\Store\Psr6TokenBucketStore;
use PHPUnit\Framework\TestCase;
use Psr\Cache\InvalidArgumentException;

/**
 * The shared stores that make the api-client's breaker and outbound limiter worth adopting on a fleet.
 *
 * With the process-local defaults each worker learns independently that ComfinoPay is down and each worker gets its own
 * full token bucket, which is most of the value of both features gone. These stores are the "shared" half; what they
 * cannot be is *exact*, because PSR-6 offers no compare-and-swap — and the tests below pin the honest boundary between
 * the two rather than pretending it is not there.
 */
final class Psr6StoresTest extends TestCase
{
    // -- the breaker store ------------------------------------------------------------------------

    public function testBreakerStateSurvivesARoundTrip(): void
    {
        $store = new Psr6CircuitBreakerStore(new ArrayCachePool());

        $store->set('key', new CircuitBreakerState(3, 1000.5, 1030.25));
        $state = $store->get('key');

        self::assertNotNull($state);
        self::assertSame(3, $state->consecutiveFailures);
        self::assertSame(1000.5, $state->openedAt);
        self::assertSame(1030.25, $state->probeStartedAt, 'The probe stamp must survive, or every worker re-probes.');
    }

    public function testAnAbsentBreakerKeyReadsAsNull(): void
    {
        $store = new Psr6CircuitBreakerStore(new ArrayCachePool());

        self::assertNull($store->get('nothing-here'));
    }

    public function testDeletingClosesTheBreaker(): void
    {
        $store = new Psr6CircuitBreakerStore(new ArrayCachePool());

        $store->set('key', new CircuitBreakerState(5, 1000.0));
        $store->delete('key');

        self::assertNull($store->get('key'));
    }

    /**
     * Two breakers over one pool is what two workers over one Redis look like, and the point of the store is that the
     * second one benefits from what the first learned.
     */
    public function testTwoWorkersSharingThePoolAgreeThatAHostIsDown(): void
    {
        $pool = new ArrayCachePool();
        $clock = new FrozenClock(1000.0);
        $key = CircuitBreakerKey::build('shop-a', 'api-ecommerce.comfino.pl');

        $workerA = new CircuitBreaker(new Psr6CircuitBreakerStore($pool), 2, 30, $clock);
        $workerB = new CircuitBreaker(new Psr6CircuitBreakerStore($pool), 2, 30, $clock);

        $workerA->recordFailure($key);
        $workerA->recordFailure($key);

        self::assertTrue($workerB->isOpen($key), 'The second worker must not have to learn the outage for itself.');
    }

    /**
     * The documented cost of a PSR-6 store: no swap, so `isExact()` is false. A host that needs the probe claimed
     * against a race implements the atomic interface against a backend that can do it.
     */
    public function testAPsr6BackedBreakerReportsItselfInexact(): void
    {
        $breaker = new CircuitBreaker(new Psr6CircuitBreakerStore(new ArrayCachePool()));

        self::assertFalse($breaker->isExact());
    }

    // -- the limiter store ------------------------------------------------------------------------

    public function testBucketStateSurvivesARoundTrip(): void
    {
        $store = new Psr6TokenBucketStore(new ArrayCachePool());

        $store->set('key', new TokenBucket(2.5, 1000.75));
        $bucket = $store->get('key');

        self::assertNotNull($bucket);
        self::assertSame(2.5, $bucket->tokens);
        self::assertSame(1000.75, $bucket->updatedAt);
    }

    public function testAnAbsentBucketReadsAsNullSoTheKeyStartsFull(): void
    {
        $limiter = new TokenBucketRateLimiter(2, 1.0, new Psr6TokenBucketStore(new ArrayCachePool()), new FrozenClock(1000.0));

        self::assertSame(1, $limiter->reserve('fresh-key')->remaining);
    }

    public function testTwoWorkersSharingThePoolShareTheBudget(): void
    {
        $pool = new ArrayCachePool();
        $clock = new FrozenClock(1000.0);

        $workerA = new TokenBucketRateLimiter(2, 1.0, new Psr6TokenBucketStore($pool), $clock);
        $workerB = new TokenBucketRateLimiter(2, 1.0, new Psr6TokenBucketStore($pool), $clock);

        self::assertTrue($workerA->reserve('shop-a')->accepted);
        self::assertTrue($workerB->reserve('shop-a')->accepted);
        self::assertFalse($workerA->reserve('shop-a')->accepted, 'Sequentially the shared budget holds; it is only a genuine race the store cannot resolve.');
    }

    /**
     * The boundary, stated as a test so nobody has to infer it: shared, not exact. This is why
     * {@see Psr6TokenBucketStore} is documented as suitable for protecting a quota from a runaway loop and unsuitable
     * where the rate is a hard ceiling.
     */
    public function testAPsr6BackedLimiterReportsItselfInexact(): void
    {
        $limiter = new TokenBucketRateLimiter(2, 1.0, new Psr6TokenBucketStore(new ArrayCachePool()));

        self::assertFalse($limiter->isExact());
    }

    // -- keys -------------------------------------------------------------------------------------

    /**
     * PSR-6 reserves `{}()/\@:` and guarantees only 64 characters, while the keys these stores are handed contain `|`
     * and a hostname. Passing them through unchanged works on some pools and throws on others.
     */
    public function testKeysAreMadeSafeForAPsr6Pool(): void
    {
        $key = Psr6StoreKey::build('comfino_cb', 'shop-a|api-ecommerce.comfino.pl');

        self::assertMatchesRegularExpression('/^[A-Za-z0-9_.]+$/', $key);
        self::assertLessThanOrEqual(64, strlen($key));
    }

    public function testDistinctKeysStayDistinctEvenWhenSanitizationWouldCollapseThem(): void
    {
        self::assertNotSame(
            Psr6StoreKey::build('p', 'shop-a|host'),
            Psr6StoreKey::build('p', 'shop-a/host'),
            'The sanitizer maps both separators to "_"; the digest is what keeps two different keys apart.'
        );
    }

    public function testAKeyKeepsAReadableFragmentSoACacheRowCanBeRecognized(): void
    {
        self::assertStringContainsString('shop_a', Psr6StoreKey::build('comfino_cb', 'shop-a|api-ecommerce.comfino.pl'));
    }

    public function testALongKeyIsTruncatedAroundADigestAndStaysUnique(): void
    {
        $long = str_repeat('tenant-with-a-very-long-identifier', 4);

        $first = Psr6StoreKey::build('comfino_rl', $long . '|a');
        $second = Psr6StoreKey::build('comfino_rl', $long . '|b');

        self::assertLessThanOrEqual(64, strlen($first));
        self::assertNotSame($first, $second);
    }

    /**
     * @throws InvalidArgumentException
     */
    public function testTheTwoStoresCannotCollideOnTheSameKey(): void
    {
        $pool = new ArrayCachePool();
        $breakerStore = new Psr6CircuitBreakerStore($pool);
        $bucketStore = new Psr6TokenBucketStore($pool);

        $breakerStore->set('same-key', new CircuitBreakerState(4, 1000.0));
        $bucketStore->set('same-key', new TokenBucket(1.0, 1000.0));

        self::assertSame(4, $breakerStore->get('same-key')?->consecutiveFailures);
        self::assertSame(1.0, $bucketStore->get('same-key')?->tokens);
    }

    /**
     * A row written by something else must read as absent rather than crash. A cache is shared infrastructure, and a
     * limiter that throws on an unexpected value fails the request it was supposed to be protecting.
     *
     * @throws InvalidArgumentException
     */
    public function testAForeignRowReadsAsAbsent(): void
    {
        $pool = new ArrayCachePool();
        $item = $pool->getItem(Psr6StoreKey::build('comfino_rl', 'key'));
        $item->set('not what this store writes');
        $pool->save($item);

        $store = new Psr6TokenBucketStore($pool);

        self::assertNull($store->get('key'));
    }
}
