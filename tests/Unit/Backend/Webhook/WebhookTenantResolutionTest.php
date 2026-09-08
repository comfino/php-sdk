<?php

/**
 * ComfinoPay PHP SDK
 *
 * Backend routines for e-commerce platforms integration with the ComfinoPay payment gateway API.
 *
 * @package Comfino\Tests\Unit\Backend\Webhook
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
 * @copyright Copyright (c) 2026 by ComfinoPay sp. z o.o.
 * @license https://opensource.org/licenses/BSD-3-Clause BSD 3-Clause License
 * @link https://github.com/comfino/php-sdk
 */

declare(strict_types=1);

namespace Comfino\Tests\Unit\Backend\Webhook;

use Comfino\Api\SerializerInterface;
use Comfino\Backend\Webhook\RateLimitKey;
use Comfino\Backend\Webhook\RateLimitVerdict;
use Comfino\Backend\Webhook\StaticApiKeyResolver;
use Comfino\Backend\Webhook\TenantAwareRateLimiterInterface;
use Comfino\Backend\Webhook\TenantAwareReplayProtectionInterface;
use Comfino\Backend\Webhook\TenantWebhookContext;
use Comfino\Backend\Webhook\WebhookEndpointInterface;
use Comfino\Backend\Webhook\WebhookManager;
use Comfino\Backend\Webhook\WebhookTenantResolverInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestFactoryInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UriFactoryInterface;

/**
 * The property this whole change exists for: a signature that is authentic for merchant A must not authorize a request
 * routed to merchant B, even though both keys are configured in the same process.
 */
final class WebhookTenantResolutionTest extends TestCase
{
    private const SHOP_A_KEY = 'key_for_shop_a';
    private const SHOP_B_KEY = 'key_for_shop_b';

    private ServerRequestFactoryInterface&MockObject $serverRequestFactory;
    private StreamFactoryInterface&MockObject $streamFactory;
    private UriFactoryInterface&MockObject $uriFactory;
    private ResponseFactoryInterface&MockObject $responseFactory;
    private SerializerInterface&MockObject $serializer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->serverRequestFactory = $this->createMock(ServerRequestFactoryInterface::class);
        $this->streamFactory = $this->createMock(StreamFactoryInterface::class);
        $this->uriFactory = $this->createMock(UriFactoryInterface::class);
        $this->responseFactory = $this->createMock(ResponseFactoryInterface::class);
        $this->serializer = $this->createMock(SerializerInterface::class);
    }

    /**
     * A request naming shop B, signed with shop A's key, is rejected. Under the old flat key list this request was
     * *accepted*: the signature verified against shop A's key, and the endpoint then handled it as shop B's.
     */
    public function testASignatureFromOneTenantDoesNotAuthorizeAnother(): void
    {
        $body = '{"externalId":"1042","status":"CANCELLED"}';
        $request = $this->createRequest('POST', $body, hash('sha3-256', self::SHOP_A_KEY . $body), 'shop_b');

        $response = $this->createResponse();

        $this->responseFactory->expects($this->once())
            ->method('createResponse')
            ->with(403, 'Access not allowed. Failed comparison of CR-Signature and shop hash.')
            ->willReturn($response);

        $endpoint = $this->createEndpoint('status-notification');
        $endpoint->expects($this->never())->method('processRequest');

        $manager = $this->createManager($this->twoTenantResolver());
        $manager->registerEndpoint($endpoint);

        self::assertSame($response, $manager->processRequest('status-notification', $request));
    }

    public function testASignatureFromTheNamedTenantIsAccepted(): void
    {
        $body = '{"externalId":"1042","status":"CANCELLED"}';
        $request = $this->createRequest('POST', $body, hash('sha3-256', self::SHOP_B_KEY . $body), 'shop_b');

        $response = $this->createResponse();

        $this->responseFactory->method('createResponse')->with(201, 'Created')->willReturn($response);

        $endpoint = $this->createEndpoint('status-notification');
        $endpoint->expects($this->once())->method('processRequest')->willReturn(null);

        $manager = $this->createManager($this->twoTenantResolver());
        $manager->registerEndpoint($endpoint);

        self::assertSame($response, $manager->processRequest('status-notification', $request));
    }

    /**
     * A resolver returning null means "reject", never "try the other keys". A request naming an unknown installation is
     * unauthorized even when its signature is authentic for some tenant the host does serve.
     */
    public function testAnUnresolvableTenantIsRejectedRatherThanRetriedAgainstOtherKeys(): void
    {
        $body = '{"externalId":"1042"}';
        $request = $this->createRequest('POST', $body, hash('sha3-256', self::SHOP_A_KEY . $body), 'shop_unknown');

        $response = $this->createResponse();

        $this->responseFactory->expects($this->once())
            ->method('createResponse')
            ->with(401, 'Unauthorized request: unknown tenant or no API key configured.')
            ->willReturn($response);

        $manager = $this->createManager($this->twoTenantResolver());

        self::assertSame($response, $manager->processRequest(null, $request));
    }

    /**
     * Key rotation: during a rotation a merchant legitimately has two keys, and a webhook signed with either is theirs.
     * Both keys still belong to the same merchant, which is the distinction the flat list could not express.
     */
    public function testEitherOfATenantsRotatingKeysVerifies(): void
    {
        $body = '{"externalId":"7"}';
        $outgoingKey = 'shop_a_previous_key';
        $request = $this->createRequest('POST', $body, hash('sha3-256', $outgoingKey . $body), 'shop_a');

        $resolver = $this->createMock(WebhookTenantResolverInterface::class);
        $resolver->method('resolve')
            ->willReturn(new TenantWebhookContext('shop_a', [self::SHOP_A_KEY, $outgoingKey]));

        $response = $this->createResponse();

        $this->responseFactory->method('createResponse')->with(201, 'Created')->willReturn($response);

        $endpoint = $this->createEndpoint('status-notification');
        $endpoint->expects($this->once())->method('processRequest')->willReturn(null);

        $manager = $this->createManager($resolver);
        $manager->registerEndpoint($endpoint);

        self::assertSame($response, $manager->processRequest('status-notification', $request));
    }

    /**
     * The rate limit is counted against the resolved tenant. All ComfinoPay webhooks arrive from ComfinoPay's own
     * infrastructure, so without the tenant one merchant's burst would throttle every other merchant's notifications.
     */
    public function testTheRateLimiterIsGivenTheResolvedTenant(): void
    {
        $body = '{"externalId":"1042"}';
        $request = $this->createRequest(
            'POST',
            $body,
            hash('sha3-256', self::SHOP_B_KEY . $body),
            'shop_b',
            ['REMOTE_ADDR' => '203.0.113.7']
        );

        $seenKeys = [];

        $limiter = $this->createMock(TenantAwareRateLimiterInterface::class);
        $limiter->method('consume')
            ->willReturnCallback(function (RateLimitKey $key) use (&$seenKeys): RateLimitVerdict {
                $seenKeys[] = $key;

                return RateLimitVerdict::accept(9, 10);
            });

        $this->responseFactory->method('createResponse')->willReturn($this->createResponse());

        $endpoint = $this->createEndpoint('status-notification');
        $endpoint->method('processRequest')->willReturn(null);

        $manager = $this->createManager($this->twoTenantResolver(), null, $limiter);
        $manager->registerEndpoint($endpoint);
        $manager->processRequest('status-notification', $request);

        self::assertCount(1, $seenKeys);
        self::assertSame('shop_b', $seenKeys[0]->tenantKey);
        self::assertSame('status-notification', $seenKeys[0]->endpointName);
        self::assertSame('203.0.113.7', $seenKeys[0]->clientIdentifier);
    }

    /**
     * A 429 must tell the sender when to come back. The old boolean verdict could not, so the rejection carried no
     * Retry-After at all.
     */
    public function testARejectedRequestCarriesTheRateLimitHeaders(): void
    {
        $body = '{"externalId":"1042"}';
        $request = $this->createRequest('POST', $body, hash('sha3-256', self::SHOP_B_KEY . $body), 'shop_b');

        $limiter = $this->createMock(TenantAwareRateLimiterInterface::class);
        $limiter->method('consume')->willReturn(RateLimitVerdict::reject(30, 100));

        $headers = [];

        $response = $this->createMock(ResponseInterface::class);
        $response->method('withBody')->willReturnSelf();
        $response->method('withHeader')->willReturnCallback(
            function (string $name, string $value) use (&$headers, $response): ResponseInterface {
                $headers[$name] = $value;

                return $response;
            }
        );

        $this->responseFactory->method('createResponse')->with(429, 'Rate limit exceeded.')->willReturn($response);

        $endpoint = $this->createEndpoint('status-notification');
        $endpoint->expects($this->never())->method('processRequest');

        $manager = $this->createManager($this->twoTenantResolver(), null, $limiter);
        $manager->registerEndpoint($endpoint);

        self::assertSame($response, $manager->processRequest('status-notification', $request));
        self::assertSame('30', $headers['Retry-After']);
        self::assertSame('100', $headers['X-RateLimit-Limit']);
        self::assertSame('0', $headers['X-RateLimit-Remaining']);
    }

    /**
     * The replay store is partitioned by tenant, so a shared table can be reasoned about — and purged — per merchant.
     */
    public function testTheReplayStoreIsGivenTheResolvedTenantAndTheConfiguredTtl(): void
    {
        $body = '{"externalId":"1042"}';
        $signature = hash('sha3-256', self::SHOP_B_KEY . $body);
        $request = $this->createRequest('POST', $body, $signature, 'shop_b');

        $marked = [];

        $replay = $this->createMock(TenantAwareReplayProtectionInterface::class);
        $replay->method('isDuplicate')->willReturn(false);
        $replay->method('markProcessed')->willReturnCallback(
            function (string $sig, ?string $tenantKey, ?int $ttl) use (&$marked): void {
                $marked = ['signature' => $sig, 'tenantKey' => $tenantKey, 'ttl' => $ttl];
            }
        );

        $this->responseFactory->method('createResponse')->willReturn($this->createResponse());

        $endpoint = $this->createEndpoint('status-notification');
        $endpoint->method('processRequest')->willReturn(null);

        $manager = $this->createManager($this->twoTenantResolver(), $replay, null, 3600);
        $manager->registerEndpoint($endpoint);
        $manager->processRequest('status-notification', $request);

        self::assertSame(['signature' => $signature, 'tenantKey' => 'shop_b', 'ttl' => 3600], $marked);
    }

    public function testGetCrSignatureSignsWithTheNamedTenantsKey(): void
    {
        $manager = $this->createManager($this->twoTenantResolver());

        self::assertSame(hash('sha3-256', self::SHOP_A_KEY . 'vkey_value'), $manager->getCrSignature('vkey_value', 'shop_a'));
        self::assertSame(hash('sha3-256', self::SHOP_B_KEY . 'vkey_value'), $manager->getCrSignature('vkey_value', 'shop_b'));
    }

    /**
     * An array of keys keeps working and is wrapped in a StaticApiKeyResolver, so a single-shop plugin's wiring is
     * unchanged.
     */
    public function testAnArrayOfKeysStillWorksAndResolvesStatically(): void
    {
        $manager = $this->createManager([self::SHOP_A_KEY]);

        self::assertInstanceOf(StaticApiKeyResolver::class, $manager->getTenantResolver());
        self::assertSame(hash('sha3-256', self::SHOP_A_KEY . 'vkey_value'), $manager->getCrSignature('vkey_value'));
    }

    /**
     * @param string[]|WebhookTenantResolverInterface $apiKeys
     */
    private function createManager(
        array|WebhookTenantResolverInterface $apiKeys,
        ?TenantAwareReplayProtectionInterface $replayProtection = null,
        ?TenantAwareRateLimiterInterface $rateLimiter = null,
        ?int $replayTtlSeconds = null
    ): WebhookManager {
        return new WebhookManager(
            'TestPlatform',
            '1.0.0',
            '2.0.0',
            $apiKeys,
            $this->serverRequestFactory,
            $this->streamFactory,
            $this->uriFactory,
            $this->responseFactory,
            $this->serializer,
            $replayProtection,
            $rateLimiter,
            null,
            $replayTtlSeconds
        );
    }

    /**
     * A resolver reading the installation id from the request path — the shape a real multi-tenant host uses.
     */
    private function twoTenantResolver(): WebhookTenantResolverInterface
    {
        $keysByTenant = ['shop_a' => self::SHOP_A_KEY, 'shop_b' => self::SHOP_B_KEY];

        $resolver = $this->createMock(WebhookTenantResolverInterface::class);
        $resolver->method('resolve')->willReturnCallback(
            static function (ServerRequestInterface $request) use ($keysByTenant): ?TenantWebhookContext {
                $tenantKey = $request->getAttribute('installation_id');

                return isset($keysByTenant[$tenantKey])
                    ? TenantWebhookContext::forKey($tenantKey, $keysByTenant[$tenantKey])
                    : null;
            }
        );
        $resolver->method('resolveByTenantKey')->willReturnCallback(
            static fn (?string $tenantKey): ?TenantWebhookContext => isset($keysByTenant[$tenantKey])
                ? TenantWebhookContext::forKey($tenantKey, $keysByTenant[$tenantKey])
                : null
        );

        return $resolver;
    }

    /**
     * @param array<string, string> $serverParams
     */
    private function createRequest(
        string $method,
        string $body,
        string $signature,
        ?string $installationId,
        array $serverParams = []
    ): ServerRequestInterface&MockObject {
        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getMethod')->willReturn($method);
        $request->method('getQueryParams')->willReturn([]);
        $request->method('getServerParams')->willReturn($serverParams);
        $request->method('getAttribute')->willReturnCallback(static fn (string $name) => $name === 'installation_id' ? $installationId : null);
        $request->method('hasHeader')->willReturnCallback(static fn ($h): bool => $h === 'CR-Signature');
        $request->method('getHeader')->willReturnCallback(static fn ($h): array => $h === 'CR-Signature' ? [$signature] : []);

        $bodyStream = $this->createMock(StreamInterface::class);
        $bodyStream->method('getContents')->willReturn($body);
        $bodyStream->method('rewind');
        $request->method('getBody')->willReturn($bodyStream);

        return $request;
    }

    private function createEndpoint(string $name): WebhookEndpointInterface&MockObject
    {
        $endpoint = $this->createMock(WebhookEndpointInterface::class);
        $endpoint->method('getName')->willReturn($name);
        $endpoint->method('getEndpointUrl')->willReturn('/api/' . $name);
        $endpoint->method('getMethods')->willReturn(['POST']);

        return $endpoint;
    }

    private function createResponse(): ResponseInterface&MockObject
    {
        $response = $this->createMock(ResponseInterface::class);
        $response->method('withHeader')->willReturnSelf();
        $response->method('withBody')->willReturnSelf();

        return $response;
    }
}
