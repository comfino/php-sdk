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

use Comfino\Api\Serializer\Json;
use Comfino\Backend\Webhook\ClientIpResolver;
use Comfino\Backend\Webhook\HeaderReplayKeyExtractor;
use Comfino\Backend\Webhook\IpWhitelist;
use Comfino\Backend\Webhook\ReplayKeyExtractorInterface;
use Comfino\Backend\Webhook\StaticApiKeyResolver;
use Comfino\Backend\Webhook\TenantAwareRateLimiterInterface;
use Comfino\Backend\Webhook\TenantAwareReplayProtectionInterface;
use Comfino\Backend\Webhook\WebhookManager;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The manager's guards, over real PSR-7 objects rather than mocks — the request shape *is* the subject here.
 *
 * Four properties, each of which was wrong in 3.0.0 in a way no plugin could see:
 *
 * - **One request, one token.** The limiter used to be consumed inside the endpoint loop.
 * - **The caller is resolved once**, so the allow-list and the limiter cannot disagree about who called.
 * - **Replay identity is a decision**, not the signature by default forever.
 * - **The body cap applies to a request the host built**, not only to one read from globals.
 */
final class WebhookManagerGuardsTest extends TestCase
{
    private const API_KEY = 'the-merchants-api-key';
    private const BODY = '{"externalId":"1042","status":"ACCEPTED"}';

    // -- One request, one token ---------------------------------------------------------------

    /**
     * Three endpoints registered, two of which refuse the request: one token, charged for the endpoint that handled it.
     *
     * Before 3.1 this charged three — one per endpoint tried — against the names of endpoints that did not handle the
     * request, so the counters were wrong, misattributed, and dependent on registration order.
     */
    public function testARequestFallingThroughSeveralEndpointsIsChargedOnce(): void
    {
        $limiter = new RecordingRateLimiter();
        $manager = $this->manager(rateLimiter: $limiter);

        /* The URLs matter here: routing compares the request URI to the endpoint URL, exactly as
           WebhookEndpoint::endpointPathMatch() does, so only the third endpoint is the one this request is for. */
        $manager->registerEndpoint(new RecordingEndpoint('first', 'https://shop.example/hooks/first', accepts: false));
        $manager->registerEndpoint(
            new RecordingEndpoint('second', 'https://shop.example/hooks/second', accepts: false)
        );
        $handler = new RecordingEndpoint('third', 'https://shop.example/hooks/status');
        $manager->registerEndpoint($handler);

        $response = $manager->processRequest(null, $this->request());

        self::assertSame(201, $response->getStatusCode());
        self::assertSame(1, $handler->calls);
        self::assertCount(
            1,
            $limiter->consumed,
            'One request must cost one token, whatever the endpoint list looks like.'
        );
        self::assertSame('third', $limiter->consumed[0]->endpointName, 'Charged to the endpoint that handled it.');
    }

    public function testANamedEndpointIsStillChargedOnce(): void
    {
        $limiter = new RecordingRateLimiter();
        $manager = $this->manager(rateLimiter: $limiter);
        $manager->registerEndpoint(new RecordingEndpoint('status', '/hooks/status'));

        $manager->processRequest('status', $this->request());

        self::assertCount(1, $limiter->consumed);
        self::assertSame('status', $limiter->consumed[0]->endpointName);
    }

    public function testARejectedRequestNeverReachesAnEndpoint(): void
    {
        $manager = $this->manager(rateLimiter: new RecordingRateLimiter(accept: false));
        $endpoint = new RecordingEndpoint('status', '/hooks/status');
        $manager->registerEndpoint($endpoint);

        $response = $manager->processRequest('status', $this->request());

        self::assertSame(429, $response->getStatusCode());
        self::assertSame('30', $response->getHeaderLine('Retry-After'));
        self::assertSame(0, $endpoint->calls);
    }

    // -- One answer to "who called" ----------------------------------------------------------

    /**
     * Behind a trusted proxy the limiter must count the forwarded caller, not the proxy.
     *
     * This is the inconsistency that made the guards disagree: the allow-list resolved forwarding headers and the
     * limiter read `REMOTE_ADDR` raw, so behind one load balancer every caller in the world shared a bucket while the
     * allow-list saw them individually.
     */
    public function testTheRateLimitKeyCarriesTheResolvedCallerAddress(): void
    {
        $limiter = new RecordingRateLimiter();
        $manager = $this->manager(rateLimiter: $limiter);
        $manager->registerEndpoint(new RecordingEndpoint('status', '/hooks/status'));

        $manager->processRequest('status', $this->request(
            headers: ['X-Forwarded-For' => '203.0.113.7'],
            serverParams: ['REMOTE_ADDR' => '10.0.0.9']
        ));

        self::assertSame('203.0.113.7', $limiter->consumed[0]->clientIdentifier);
    }

    public function testTheResolvedAddressReachesTheEndpoint(): void
    {
        $manager = $this->manager();
        $endpoint = new RecordingTenantAwareEndpoint('status', '/hooks/status');
        $manager->registerEndpoint($endpoint);

        $manager->processRequest('status', $this->request(
            headers: ['X-Forwarded-For' => '203.0.113.7'],
            serverParams: ['REMOTE_ADDR' => '10.0.0.9']
        ));

        self::assertSame('203.0.113.7', $endpoint->seen[0]->clientIp);
    }

    /**
     * A host that has already resolved the caller into `REMOTE_ADDR` passes a resolver that trusts no proxy, and then
     * the SDK does not second-guess it.
     */
    public function testAResolverWithNoTrustedProxiesTakesRemoteAddrAsGiven(): void
    {
        $limiter = new RecordingRateLimiter();
        $manager = $this->manager(rateLimiter: $limiter, ipResolver: new ClientIpResolver([]));
        $manager->registerEndpoint(new RecordingEndpoint('status', '/hooks/status'));

        $manager->processRequest('status', $this->request(
            headers: ['X-Forwarded-For' => '198.51.100.1'],
            serverParams: ['REMOTE_ADDR' => '203.0.113.7']
        ));

        self::assertSame('203.0.113.7', $limiter->consumed[0]->clientIdentifier);
    }

    /**
     * The allow-list and the limiter, given one resolver, agree — which is the whole point of injecting it.
     */
    public function testTheAllowListAndTheLimiterJudgeTheSameAddress(): void
    {
        $resolver = ClientIpResolver::default();
        $limiter = new RecordingRateLimiter();
        $manager = $this->manager(
            rateLimiter: $limiter,
            ipWhitelist: new IpWhitelist(
                ['203.0.113.7'],
                allowLocalAddresses: false,
                ipResolver: $resolver
            ),
            ipResolver: $resolver
        );
        $manager->registerEndpoint(new RecordingEndpoint('status', '/hooks/status'));

        $allowed = $manager->processRequest('status', $this->request(
            headers: ['X-Forwarded-For' => '203.0.113.7'],
            serverParams: ['REMOTE_ADDR' => '10.0.0.9']
        ));

        self::assertSame(201, $allowed->getStatusCode());
        self::assertSame('203.0.113.7', $limiter->consumed[0]->clientIdentifier);

        $refused = $manager->processRequest('status', $this->request(
            headers: ['X-Forwarded-For' => '198.51.100.1'],
            serverParams: ['REMOTE_ADDR' => '10.0.0.9']
        ));

        self::assertSame(403, $refused->getStatusCode());
        self::assertCount(1, $limiter->consumed, 'A refused address must not spend the quota.');
    }

    // -- What counts as the same delivery ----------------------------------------------------

    public function testTheSignatureIsStillTheDefaultReplayKey(): void
    {
        $replay = new RecordingReplayProtection();
        $manager = $this->manager(replayProtection: $replay);
        $manager->registerEndpoint(new RecordingEndpoint('status', '/hooks/status'));

        $manager->processRequest('status', $this->request());

        self::assertSame([$this->signature()], $replay->checkedKeys);
        self::assertSame($this->signature(), $replay->markedKeys[0]['key']);
    }

    /**
     * The collision the default cannot avoid: two *distinct* deliveries with the same body key identically, so the
     * second is dropped as a replay. Pinned rather than fixed, because it is a property of the signature.
     */
    public function testTwoDeliveriesWithTheSameBodyCollideUnderTheDefaultKey(): void
    {
        $replay = new RecordingReplayProtection();
        $manager = $this->manager(replayProtection: $replay);
        $manager->registerEndpoint(new RecordingEndpoint('status', '/hooks/status'));

        self::assertSame(201, $manager->processRequest('status', $this->request())->getStatusCode());
        self::assertSame(
            403,
            $manager->processRequest('status', $this->request())->getStatusCode(),
            'This is why replay protection must not be enabled for status notifications without a delivery id.'
        );
    }

    /**
     * With a per-delivery identifier the same two requests are two deliveries again.
     */
    public function testAPerDeliveryHeaderSeparatesTwoIdenticalBodies(): void
    {
        $replay = new RecordingReplayProtection();
        $manager = $this->manager(
            replayProtection: $replay,
            replayKeyExtractor: new HeaderReplayKeyExtractor()
        );
        $manager->registerEndpoint(new RecordingEndpoint('status', '/hooks/status'));

        $first = $manager->processRequest('status', $this->request(headers: ['CR-Delivery-Id' => 'delivery-1']));
        $second = $manager->processRequest('status', $this->request(headers: ['CR-Delivery-Id' => 'delivery-2']));

        self::assertSame(201, $first->getStatusCode());
        self::assertSame(201, $second->getStatusCode());
        self::assertSame(['delivery-1', 'delivery-2'], $replay->checkedKeys);
    }

    public function testARedeliveryOfTheSameDeliveryIdIsRejected(): void
    {
        $manager = $this->manager(
            replayProtection: new RecordingReplayProtection(),
            replayKeyExtractor: new HeaderReplayKeyExtractor()
        );
        $manager->registerEndpoint(new RecordingEndpoint('status', '/hooks/status'));

        $manager->processRequest('status', $this->request(headers: ['CR-Delivery-Id' => 'delivery-1']));
        $again = $manager->processRequest('status', $this->request(headers: ['CR-Delivery-Id' => 'delivery-1']));

        self::assertSame(403, $again->getStatusCode());
    }

    /**
     * A delivery with no identifier is processed rather than deduplicated — the fail-open direction, and the reason
     * wiring the header extractor before the API sends the header is harmless.
     */
    public function testADeliveryWithNoIdentifierIsProcessedAndNotRecorded(): void
    {
        $replay = new RecordingReplayProtection();
        $manager = $this->manager(
            replayProtection: $replay,
            replayKeyExtractor: new HeaderReplayKeyExtractor()
        );
        $manager->registerEndpoint(new RecordingEndpoint('status', '/hooks/status'));

        self::assertSame(201, $manager->processRequest('status', $this->request())->getStatusCode());
        self::assertSame(201, $manager->processRequest('status', $this->request())->getStatusCode());
        self::assertSame([], $replay->checkedKeys);
        self::assertSame([], $replay->markedKeys);
    }

    public function testTheReplayKeyReachesTheEndpoint(): void
    {
        $manager = $this->manager(
            replayProtection: new RecordingReplayProtection(),
            replayKeyExtractor: new HeaderReplayKeyExtractor()
        );
        $endpoint = new RecordingTenantAwareEndpoint('status', '/hooks/status');
        $manager->registerEndpoint($endpoint);

        $manager->processRequest('status', $this->request(headers: ['CR-Delivery-Id' => 'delivery-1']));

        self::assertSame('delivery-1', $endpoint->seen[0]->replayKey);
    }

    // -- The verified tenant reaches the endpoint ---------------------------------------------

    public function testATenantAwareEndpointIsGivenTheVerifiedTenant(): void
    {
        $manager = $this->manager();
        $endpoint = new RecordingTenantAwareEndpoint('status', '/hooks/status');
        $manager->registerEndpoint($endpoint);

        $manager->processRequest('status', $this->request());

        self::assertCount(1, $endpoint->seen);
        self::assertSame(0, $endpoint->tenantBlindCalls, 'The tenant-aware method is the one that must be called.');
        self::assertSame('shop-1', $endpoint->seen[0]->tenantKey());
        self::assertSame(self::API_KEY, $endpoint->seen[0]->tenant->primaryApiKey());
    }

    public function testATenantBlindEndpointIsCalledExactlyAsBefore(): void
    {
        $manager = $this->manager();
        $endpoint = new RecordingEndpoint('status', '/hooks/status');
        $manager->registerEndpoint($endpoint);

        self::assertSame(201, $manager->processRequest('status', $this->request())->getStatusCode());
        self::assertSame(1, $endpoint->calls);
    }

    // -- The body cap on the path integrations actually take ----------------------------------

    public function testABodyOverTheCapIsRefusedOnAHostSuppliedRequest(): void
    {
        $manager = $this->manager(maxBodyBytes: 16);
        $endpoint = new RecordingEndpoint('status', '/hooks/status');
        $manager->registerEndpoint($endpoint);

        $response = $manager->processRequest('status', $this->request());

        self::assertSame(413, $response->getStatusCode());
        self::assertSame(0, $endpoint->calls, 'An oversized body must not be parsed, let alone handled.');
    }

    public function testABodyWithinTheCapIsProcessed(): void
    {
        $manager = $this->manager(maxBodyBytes: strlen(self::BODY));
        $manager->registerEndpoint(new RecordingEndpoint('status', '/hooks/status'));

        self::assertSame(201, $manager->processRequest('status', $this->request())->getStatusCode());
    }

    // -- plumbing --------------------------------------------------------------------------------

    private function manager(
        ?TenantAwareReplayProtectionInterface $replayProtection = null,
        ?TenantAwareRateLimiterInterface $rateLimiter = null,
        ?IpWhitelist $ipWhitelist = null,
        ?ClientIpResolver $ipResolver = null,
        ?ReplayKeyExtractorInterface $replayKeyExtractor = null,
        ?int $maxBodyBytes = null
    ): WebhookManager {
        $psr17 = new Psr17Factory();

        return new WebhookManager(
            'TestPlatform',
            '1.0.0',
            '2.0.0',
            new StaticApiKeyResolver([self::API_KEY], 'shop-1'),
            $psr17,
            $psr17,
            $psr17,
            $psr17,
            new Json(),
            $replayProtection,
            $rateLimiter,
            $ipWhitelist,
            null,
            1,
            $ipResolver,
            $replayKeyExtractor,
            $maxBodyBytes
        );
    }

    /**
     * @param array<string, string> $headers
     * @param array<string, string> $serverParams
     */
    private function request(array $headers = [], array $serverParams = []): ServerRequestInterface
    {
        $request = new ServerRequest(
            'POST',
            'https://shop.example/hooks/status',
            ['CR-Signature' => $this->signature(), 'Content-Type' => 'application/json'] + $headers,
            self::BODY,
            '1.1',
            $serverParams === [] ? ['REMOTE_ADDR' => '203.0.113.7'] : $serverParams
        );

        $request->getBody()->rewind();

        return $request;
    }

    private function signature(): string
    {
        return hash('sha3-256', self::API_KEY . self::BODY);
    }
}
