<?php

/**
 * ComfinoPay PHP SDK
 *
 * Backend routines for e-commerce platforms integration with the ComfinoPay payment gateway API.
 *
 * @package Comfino\Tests\Unit\Backend\Webhook\Endpoint
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
 * @copyright Copyright (c) 2026 by ComfinoPay sp. z o.o.
 * @license https://opensource.org/licenses/BSD-3-Clause BSD 3-Clause License
 * @link https://github.com/comfino/php-sdk
 */

declare(strict_types=1);

namespace Comfino\Tests\Unit\Backend\Webhook\Endpoint;

use Comfino\Api\Exception\InvalidRequest;
use Comfino\Backend\Webhook\Endpoint\StatusNotification;
use Comfino\Backend\Webhook\StatusAdapterResolverInterface;
use Comfino\Backend\Webhook\TenantWebhookContext;
use Comfino\Backend\Webhook\VerifiedWebhookRequest;
use Comfino\Shop\Order\StatusManager;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;

/**
 * One `StatusNotification` serving many merchants.
 *
 * This is the shape that was impossible before 3.1: the endpoint had to be constructed with one merchant's
 * `StatusManager`, so a host serving many merchants rebuilt the endpoint — and the manager and the tenant resolver
 * around it — for every notification. What the tests below assert is that the *verified* tenant is what selects the
 * adapter, because that is the only tenant it is safe to act on: it was resolved before verification and the signature
 * was then checked against its key alone.
 */
final class StatusNotificationTenantTest extends TestCase
{
    private const URL = 'https://shop.example/hooks/status';

    protected function tearDown(): void
    {
        StatusManager::reset();

        parent::tearDown();
    }

    public function testTheAdapterIsResolvedFromTheVerifiedTenant(): void
    {
        $adapterA = new RecordingAdapter();
        $adapterB = new RecordingAdapter();

        $endpoint = $this->endpoint(new MapResolver(['shop-a' => $adapterA, 'shop-b' => $adapterB]));

        $endpoint->processTenantRequest($this->request('1042', 'ACCEPTED'), 'status', $this->verified('shop-b'));

        self::assertSame([], $adapterA->applied, 'Nothing may reach a merchant the request was not verified for.');
        self::assertSame([['1042', 'ACCEPTED']], $adapterB->applied);
    }

    /**
     * The same endpoint instance, two merchants, in either order — which is what makes it registerable once.
     */
    public function testOneEndpointServesTwoMerchantsInSequence(): void
    {
        $adapterA = new RecordingAdapter();
        $adapterB = new RecordingAdapter();

        $endpoint = $this->endpoint(new MapResolver(['shop-a' => $adapterA, 'shop-b' => $adapterB]));

        $endpoint->processTenantRequest($this->request('1', 'ACCEPTED'), 'status', $this->verified('shop-a'));
        $endpoint->processTenantRequest($this->request('2', 'REJECTED'), 'status', $this->verified('shop-b'));
        $endpoint->processTenantRequest($this->request('3', 'ACCEPTED'), 'status', $this->verified('shop-a'));

        self::assertSame([['1', 'ACCEPTED'], ['3', 'ACCEPTED']], $adapterA->applied);
        self::assertSame([['2', 'REJECTED']], $adapterB->applied);
    }

    /**
     * A tenant with nothing behind it anymore is acknowledged, not refused: the sender retries every non-2xx, and a
     * merchant that has been deprovisioned since the request was signed will not come back.
     */
    public function testAnUnresolvableAdapterIsAcknowledged(): void
    {
        $endpoint = $this->endpoint(new MapResolver([]));

        self::assertNull($endpoint->processTenantRequest($this->request('1042', 'ACCEPTED'), 'status', $this->verified('gone')));
    }

    /**
     * ...but a malformed notification is still a 400, whether or not there is an adapter to apply it to. Validating
     * only on the happy path would let a broken sender look healthy for every deprovisioned merchant.
     */
    public function testAMalformedNotificationIsStillRefusedWhenTheAdapterIsGone(): void
    {
        $endpoint = $this->endpoint(new MapResolver([]));

        $this->expectException(InvalidRequest::class);

        $endpoint->processTenantRequest($this->request('1042', null), 'status', $this->verified('gone'));
    }

    /**
     * The resolving shape has no tenant-blind entry point, and refusing loudly is the only safe answer: guessing which
     * merchant a notification belongs to is the cross-tenant bug this whole seam exists to remove.
     */
    public function testTheResolvingShapeRefusesATenantBlindCall(): void
    {
        $endpoint = $this->endpoint(new MapResolver(['shop-a' => new RecordingAdapter()]));

        $this->expectException(InvalidRequest::class);

        $endpoint->processRequest($this->request('1042', 'ACCEPTED'), 'status');
    }

    // -- the bound shape, unchanged --------------------------------------------------------------

    public function testABoundStatusManagerStillWorksThroughBothEntryPoints(): void
    {
        $adapter = new RecordingAdapter();
        $endpoint = $this->endpoint(StatusManager::create($adapter, 'shop-a'));

        $endpoint->processRequest($this->request('1', 'ACCEPTED'), 'status');
        $endpoint->processTenantRequest($this->request('2', 'ACCEPTED'), 'status', $this->verified('anything'));

        self::assertSame(
            [['1', 'ACCEPTED'], ['2', 'ACCEPTED']],
            $adapter->applied,
            'A bound endpoint ignores the tenant, because it was told which merchant it serves at construction.'
        );
    }

    public function testAnIgnoredStatusIsNotAppliedThroughTheResolvingShapeEither(): void
    {
        $adapter = new RecordingAdapter();
        $endpoint = new StatusNotification(
            'status',
            self::URL,
            new MapResolver(['shop-a' => $adapter]),
            [],
            ['ACCEPTED']
        );

        $endpoint->processTenantRequest($this->request('1', 'ACCEPTED'), 'status', $this->verified('shop-a'));

        self::assertSame([], $adapter->applied);
    }

    // -- plumbing --------------------------------------------------------------------------------

    private function endpoint(StatusManager|StatusAdapterResolverInterface $source): StatusNotification
    {
        return new StatusNotification('status', self::URL, $source, [], []);
    }

    private function request(string $externalId, ?string $status): ServerRequestInterface
    {
        $payload = $status === null
            ? ['externalId' => $externalId]
            : ['externalId' => $externalId, 'status' => $status];

        $request = new ServerRequest('POST', self::URL, ['Content-Type' => 'application/json'], (string) json_encode($payload));

        $request->getBody()->rewind();

        return $request;
    }

    private function verified(string $tenantKey): VerifiedWebhookRequest
    {
        return new VerifiedWebhookRequest(TenantWebhookContext::forKey($tenantKey, 'key-' . $tenantKey), 'received', 'calculated');
    }
}
