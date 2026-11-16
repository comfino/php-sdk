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

use Closure;
use Comfino\Api\Serializer\Json;
use Comfino\Backend\Webhook\ReplayProtectionInterface;
use Comfino\Backend\Webhook\WebhookEndpointInterface;
use Comfino\Backend\Webhook\WebhookManager;
use Comfino\Backend\Webhook\WebhookVerificationObserverInterface;
use Comfino\Backend\Webhook\WebhookVerificationOutcome;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The signing oracle is gone (no response, accessor or observer carries a MAC computed by the manager), and every
 * authorization rejection looks the same from outside.
 */
final class WebhookVerificationHardeningTest extends TestCase
{
    private const API_KEY = 'merchant-api-key-0123456789';
    private const BODY = '{"externalId":"1042","status":"ACCEPTED"}';

    /** @var array<int, array{WebhookVerificationOutcome, ?string, ?string}> */
    private array $observed = [];

    protected function tearDown(): void
    {
        parent::tearDown();

        WebhookManager::reset();
    }

    public function testEachBranchRecordsItsOutcome(): void
    {
        $manager = $this->createManager();

        $manager->processRequest('status', $this->request(null));

        self::assertSame(WebhookVerificationOutcome::MissingSignature, $manager->getLastVerificationOutcome());

        $manager->processRequest('status', $this->request(str_repeat('a', 64)));

        self::assertSame(WebhookVerificationOutcome::SignatureMismatch, $manager->getLastVerificationOutcome());

        $manager->processRequest('status', $this->request($this->validSignature()));

        self::assertSame(WebhookVerificationOutcome::Verified, $manager->getLastVerificationOutcome());

        $unknownTenant = $this->createManager([]);
        $unknownTenant->processRequest('status', $this->request($this->validSignature()));

        self::assertSame(WebhookVerificationOutcome::UnknownTenant, $unknownTenant->getLastVerificationOutcome());
        self::assertSame(
            [
                WebhookVerificationOutcome::MissingSignature,
                WebhookVerificationOutcome::SignatureMismatch,
                WebhookVerificationOutcome::Verified,
                WebhookVerificationOutcome::UnknownTenant,
            ],
            array_column($this->observed, 0)
        );
        self::assertSame('203.0.113.7', $this->observed[0][2], 'The observer receives the resolved client address.');
    }

    public function testDuplicateDeliveryIsRecordedAsDuplicate(): void
    {
        $replay = $this->createMock(ReplayProtectionInterface::class);
        $replay->method('isDuplicate')->willReturn(true);

        $manager = $this->createManager([self::API_KEY], replayProtection: $replay);
        $manager->processRequest('status', $this->request($this->validSignature()));

        self::assertSame(WebhookVerificationOutcome::Duplicate, $manager->getLastVerificationOutcome());
    }

    public function testNoMacOfAnAttackerChosenBodyIsExposed(): void
    {
        $manager = $this->createManager();
        $response = $manager->processRequest('status', $this->request(str_repeat('a', 64)));

        $validMac = $this->validSignature();

        self::assertNull($manager->getCalculatedCrSignature());
        self::assertStringNotContainsString($validMac, (string) $response->getBody());
        self::assertStringNotContainsString($validMac, $response->getReasonPhrase());
        self::assertStringNotContainsString($validMac, serialize($this->observed));
    }

    public function testMissingAndWrongSignatureProduceIdenticalBodies(): void
    {
        $manager = $this->createManager();

        $missing = $manager->processRequest('status', $this->request(null));
        $wrong = $manager->processRequest('status', $this->request(str_repeat('a', 64)));
        $unknown = $this->createManager([])->processRequest('status', $this->request($this->validSignature()));

        self::assertSame('{"error":"Unauthorized."}', (string) $missing->getBody());
        self::assertSame((string) $missing->getBody(), (string) $wrong->getBody());
        self::assertSame((string) $missing->getBody(), (string) $unknown->getBody());

        // Status codes keep the 401/403 split by default.
        self::assertSame([401, 403], [$missing->getStatusCode(), $wrong->getStatusCode()]);
        self::assertSame(['Unauthorized', 'Forbidden'], [$missing->getReasonPhrase(), $wrong->getReasonPhrase()]);
    }

    /**
     * 401 and 403 keep their HTTP meaning: no usable credentials (missing signature, unknown tenant) is 401, a
     * signature that was presented and refused is 403. Only the body and reason phrase are uniform.
     */
    public function testStatusCodesKeepTheirHttpMeaning(): void
    {
        $manager = $this->createManager();

        $this->assertSame(401, $manager->processRequest('status', $this->request(null))->getStatusCode());
        $this->assertSame(403, $manager->processRequest('status', $this->request(str_repeat('a', 64)))->getStatusCode());
        $this->assertSame(401, $this->createManager([])->processRequest('status', $this->request($this->validSignature()))->getStatusCode());
    }

    public function testAThrowingObserverDoesNotFailTheRequest(): void
    {
        $observer = $this->createMock(WebhookVerificationObserverInterface::class);
        $observer->method('onVerification')->willThrowException(new RuntimeException('Store down.'));

        $response = $this->createManager([self::API_KEY], $observer)->processRequest(
            'status',
            $this->request($this->validSignature())
        );

        self::assertSame(204, $response->getStatusCode());
    }

    /**
     * @param string[] $apiKeys
     */
    private function createManager(
        array $apiKeys = [self::API_KEY],
        ?WebhookVerificationObserverInterface $observer = null,
        ?ReplayProtectionInterface $replayProtection = null
    ): WebhookManager {
        $observer ??= new class (function (WebhookVerificationOutcome $outcome, ?string $tenantKey, ?string $clientIp): void {
            $this->observed[] = [$outcome, $tenantKey, $clientIp];
        }) implements WebhookVerificationObserverInterface {
            public function __construct(private readonly Closure $record)
            {
            }

            public function onVerification(WebhookVerificationOutcome $outcome, ?string $tenantKey, ?string $clientIp): void
            {
                ($this->record)($outcome, $tenantKey, $clientIp);
            }
        };

        $factory = new Psr17Factory();
        $manager = new WebhookManager(
            'TestPlatform',
            '1.0.0',
            '1.0.0',
            $apiKeys,
            $factory,
            $factory,
            $factory,
            $factory,
            new Json(),
            $replayProtection,
            verificationObserver: $observer
        );

        $endpoint = $this->createMock(WebhookEndpointInterface::class);
        $endpoint->method('getName')->willReturn('status');
        $endpoint->method('getEndpointUrl')->willReturn('/status');
        $endpoint->method('getMethods')->willReturn(['PUT']);
        $endpoint->method('processRequest')->willReturn(null);

        $manager->registerEndpoint($endpoint);

        return $manager;
    }

    private function request(?string $signature): ServerRequest
    {
        $request = new ServerRequest('PUT', '/status', [], self::BODY, '1.1', ['REMOTE_ADDR' => '203.0.113.7']);

        return $signature !== null ? $request->withHeader('CR-Signature', $signature) : $request;
    }

    private function validSignature(): string
    {
        return hash('sha3-256', self::API_KEY . self::BODY);
    }
}
