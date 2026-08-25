<?php

/**
 * Comfino PHP SDK
 *
 * Backend routines for e-commerce platforms integration with the Comfino payment gateway API.
 *
 * @package Comfino\Tests\Unit\Backend\Factory
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
 * @copyright Copyright (c) 2026 Comfino by Comperia.pl S.A.
 * @license https://opensource.org/licenses/BSD-3-Clause BSD 3-Clause License
 * @link https://github.com/comfino/php-sdk
 */

declare(strict_types=1);

namespace Comfino\Tests\Unit\Backend\Factory;

use Comfino\Api\AbstractClient;
use Comfino\Api\ApiContext;
use Comfino\Api\Client;
use Comfino\Api\Retry\CallbackTimeoutAwareClient;
use Comfino\Api\Retry\ExponentialBackoffRetryPolicy;
use Comfino\Api\Retry\RetryExecutor;
use Comfino\Api\Retry\TimeoutConfig;
use Comfino\Backend\Factory\ApiClientFactory;
use Comfino\Platform\HostedConnectorInfo;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use Psr\Http\Client\ClientInterface;

final class ApiClientFactoryTest extends TestCase
{
    private ApiClientFactory $factory;
    private Psr17Factory $psr17;

    protected function setUp(): void
    {
        parent::setUp();

        $this->factory = new ApiClientFactory();
        $this->psr17 = new Psr17Factory();
    }

    public function testCreateClientBuildsACredentialBoundClient(): void
    {
        $client = $this->factory->createClient(
            $this->createMock(ClientInterface::class),
            $this->psr17,
            $this->psr17,
            'api_key'
        );

        self::assertSame('api_key', $client->getApiKey());
        self::assertSame(AbstractClient::PRODUCTION_API_BASE_URL, $client->getApiBaseUrl());
    }

    /**
     * The out-of-process shape: a connector that cannot honestly implement PlatformInfoInterface no longer has to
     * hand-assemble its own User-Agent to use this factory.
     */
    public function testCreateClientFromPlatformInfoAcceptsAHostedConnector(): void
    {
        $connectorInfo = new HostedConnectorInfo('IDO', '1.4.2', 'connector.example.com');

        $client = $this->factory->createClientFromPlatformInfo(
            $connectorInfo,
            'api_key',
            true,
            $this->createMock(ClientInterface::class),
            $this->psr17,
            $this->psr17
        );

        self::assertSame(AbstractClient::SANDBOX_API_BASE_URL, $client->getApiBaseUrl());
        self::assertSame($connectorInfo->getUserAgent(), self::customUserAgentOf($client));
    }

    /**
     * The stateless shape a long-lived multi-tenant host wants: no credential on the object, the tenant travels per
     * call, and one transport is shared across every tenant.
     */
    public function testCreateSharedClientHoldsNoCredential(): void
    {
        $shared = $this->factory->createSharedClient(
            $this->createMock(ClientInterface::class),
            $this->psr17,
            $this->psr17
        );

        self::assertFalse(
            method_exists($shared, 'getApiKey'),
            'A shared client must not carry a credential of its own.'
        );

        $boundToA = $shared->bind(new ApiContext('key_for_shop_a', tenantKey: 'shop_a'));
        $boundToB = $shared->bind(new ApiContext('key_for_shop_b', tenantKey: 'shop_b'));

        self::assertSame('key_for_shop_a', $boundToA->getApiKey());
        self::assertSame('key_for_shop_b', $boundToB->getApiKey());
        self::assertSame('shop_a', $boundToA->getContext()->tenantKey);
        self::assertSame($shared, $boundToA->getSharedClient());
        self::assertSame($shared, $boundToB->getSharedClient(), 'One transport is shared across every tenant.');
    }

    /**
     * Inconsistent timeouts must produce a working client rather than an exception: the policy requires the transfer
     * timeout to be at least three times the connection timeout, and a host passing 5/5 means "5 and 5", not "throw".
     */
    public function testInconsistentTimeoutsAreNormalizedRatherThanRejected(): void
    {
        $transport = new RecordingTimeoutAwareClient();

        $this->factory->createClient(
            $transport,
            $this->psr17,
            $this->psr17,
            'api_key',
            connectionTimeout: 5,
            transferTimeout: 5
        );

        self::assertSame([5, 15], $transport->appliedTimeouts);
    }

    public function testANonPositiveConnectionTimeoutIsRaisedToOne(): void
    {
        $transport = new RecordingTimeoutAwareClient();

        $this->factory->createClient(
            $transport,
            $this->psr17,
            $this->psr17,
            'api_key',
            connectionTimeout: 0,
            transferTimeout: 3
        );

        self::assertSame([1, 3], $transport->appliedTimeouts);
    }

    /**
     * A transport that can return a configured copy is asked for one instead of being mutated. Mutating a
     * container-shared transport leaves one tenant's timeouts applied to the next tenant's call, which is the
     * cross-tenant bug the newer interface exists to remove.
     */
    public function testATimeoutConfigurableTransportIsCopiedRatherThanMutated(): void
    {
        $built = [];

        $transport = new CallbackTimeoutAwareClient(
            function (TimeoutConfig $timeouts) use (&$built): ClientInterface {
                $built[] = [$timeouts->connectionTimeout, $timeouts->transferTimeout];

                return $this->createMock(ClientInterface::class);
            },
            new TimeoutConfig(1, 3)
        );

        $this->factory->createSharedClient(
            $transport,
            $this->psr17,
            $this->psr17,
            connectionTimeout: 2,
            transferTimeout: 8
        );

        self::assertSame([[1, 3], [2, 8]], $built);
        self::assertSame(
            [1, 3],
            [$transport->getTimeouts()->connectionTimeout, $transport->getTimeouts()->transferTimeout],
            'The transport handed in must be left exactly as the host configured it.'
        );
    }

    /**
     * A host on a tighter latency budget than the api-client's 15 s default must be able to say so - previously the
     * budget was inherited silently, with no way to size or disable it from this factory.
     */
    public function testTheTotalTransferBudgetCanBeSizedOrDisabled(): void
    {
        foreach ([5 => 5, 'disabled' => null] as $expected => $budget) {
            $client = $this->factory->createClient(
                $this->createMock(ClientInterface::class),
                $this->psr17,
                $this->psr17,
                'api_key',
                maxTotalTransferTimeout: $budget
            );

            self::assertSame(
                $budget,
                self::retryPolicyOf($client)->getMaxTotalTransferTimeout(),
                sprintf('Budget "%s" must reach the retry policy unchanged.', $expected)
            );
        }
    }

    /**
     * The factory's wiring of the client's collaborators is not exposed by any getter, so the assertions above read it
     * back through reflection rather than settling for a type check that the return type already guarantees.
     */
    private static function retryPolicyOf(Client $client): ExponentialBackoffRetryPolicy
    {
        $executor = (new ReflectionProperty(Client::class, 'retryExecutor'))->getValue($client);

        self::assertInstanceOf(RetryExecutor::class, $executor, 'The factory must wire a retry executor.');

        $policy = $executor->getRetryPolicy();

        self::assertInstanceOf(ExponentialBackoffRetryPolicy::class, $policy);

        return $policy;
    }

    private static function customUserAgentOf(Client $client): ?string
    {
        /** @var string|null $userAgent */
        $userAgent = (new ReflectionProperty(AbstractClient::class, 'customUserAgent'))->getValue($client);

        return $userAgent;
    }
}
