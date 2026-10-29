<?php

/**
 * ComfinoPay PHP SDK
 *
 * Backend routines for e-commerce platforms integration with the ComfinoPay payment gateway API.
 *
 * @package Comfino\Tests\Unit
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
 * @copyright Copyright (c) 2026 by ComfinoPay sp. z o.o.
 * @license https://opensource.org/licenses/BSD-3-Clause BSD 3-Clause License
 * @link https://github.com/comfino/php-sdk
 */

declare(strict_types=1);

namespace Comfino\Tests\Unit\Backend\Factory;

use Comfino\Backend\Factory\ApiClientFactory;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ApiClientFactoryBoundsTest extends TestCase
{
    /**
     * @return array<string, array{int, int, int, int}>
     */
    public static function timeoutProvider(): array
    {
        return [
            'non-positive values are raised' => [0, 0, 1, 3],
            'in-range values are unchanged' => [5, 15, 5, 15],
            'huge values are capped' => [600, 9999, 20, 60],
            'transfer ceiling lowers connect to keep the 3x rule' => [30, 60, 20, 60],
            'connect ceiling' => [30, 90, 20, 60],
        ];
    }

    #[DataProvider('timeoutProvider')]
    public function testTimeoutsAreClampedAndStayConsistent(int $connect, int $transfer, int $expectedConnect, int $expectedTransfer): void
    {
        $transport = new RecordingTimeoutAwareClient();
        $psr17 = new Psr17Factory();

        (new ApiClientFactory())->createClient($transport, $psr17, $psr17, 'api_key', connectionTimeout: $connect, transferTimeout: $transfer);

        $this->assertSame([$expectedConnect, $expectedTransfer], $transport->appliedTimeouts);
    }

    public function testAttemptCountIsClampedInsteadOfThrowing(): void
    {
        $this->expectNotToPerformAssertions();

        $psr17 = new Psr17Factory();
        $factory = new ApiClientFactory();

        foreach ([-1, 0, 1, 3, 50] as $attempts) {
            $factory->createClient(new RecordingTimeoutAwareClient(), $psr17, $psr17, 'api_key', maxRetries: $attempts);
            $factory->createSharedClient(new RecordingTimeoutAwareClient(), $psr17, $psr17, maxRetries: $attempts);
        }
    }

    public function testBoundsAreExposedForAdminValidation(): void
    {
        $this->assertSame(30, ApiClientFactory::MAX_CONNECTION_TIMEOUT);
        $this->assertSame(60, ApiClientFactory::MAX_TRANSFER_TIMEOUT);
        $this->assertSame(1, ApiClientFactory::MIN_ATTEMPTS);
        $this->assertSame(5, ApiClientFactory::MAX_ATTEMPTS);
    }
}
