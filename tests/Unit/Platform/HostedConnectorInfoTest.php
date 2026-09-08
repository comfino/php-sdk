<?php

/**
 * ComfinoPay PHP SDK
 *
 * Backend routines for e-commerce platforms integration with the ComfinoPay payment gateway API.
 *
 * @package Comfino\Tests\Unit\Platform
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
 * @copyright Copyright (c) 2026 by ComfinoPay sp. z o.o.
 * @license https://opensource.org/licenses/BSD-3-Clause BSD 3-Clause License
 * @link https://github.com/comfino/php-sdk
 */

declare(strict_types=1);

namespace Comfino\Tests\Unit\Platform;

use Comfino\Platform\HostedConnectorInfo;
use PHPUnit\Framework\TestCase;

final class HostedConnectorInfoTest extends TestCase
{
    public function testDefaultsToTheRunningInterpretersPhpVersion(): void
    {
        self::assertSame(PHP_VERSION, (new HostedConnectorInfo('IDO', '1.0.0'))->getPhpVersion());
    }

    public function testDefaultsTheNameSegmentToConnector(): void
    {
        self::assertSame('connector', (new HostedConnectorInfo('IDO', '1.0.0'))->getName());
    }

    /**
     * With no platform version to report, the connector's own version is the honest answer for the platform slot: a
     * deployment outside the shop has no separate platform build, and its version is what a support conversation
     * needs.
     */
    public function testFallsBackToItsOwnVersionForThePlatformSlot(): void
    {
        self::assertSame('1.4.2', (new HostedConnectorInfo('IDO', '1.4.2'))->getVersion());
    }

    public function testPrefersAKnownPlatformVersion(): void
    {
        self::assertSame('2026.1', (new HostedConnectorInfo('IDO', '1.4.2', null, 'pl', '2026.1'))->getVersion());
    }

    public function testGetUserAgentMatchesTheBuilder(): void
    {
        $info = new HostedConnectorInfo('IDO', '1.4.2', 'connector.example.com', 'pl', null, 'connector', '8.3.1');

        self::assertSame('IDO Comfino [1.4.2], connector [1.4.2], PHP [8.3.1], connector.example.com', $info->getUserAgent());
    }

    /**
     * The array form carries the data, not its rendering: an unknown value is null here, and only
     * {@see UserAgentBuilder} turns it into the string "unknown".
     */
    public function testToArrayKeepsUnknownValuesNull(): void
    {
        $info = new HostedConnectorInfo('IDO', '1.4.2', null, 'en', null, 'connector', '8.3.1');

        self::assertSame(
            [
                'code' => 'IDO',
                'name' => 'connector',
                'connectorVersion' => '1.4.2',
                'platformVersion' => null,
                'phpVersion' => '8.3.1',
                'domain' => null,
                'language' => 'en',
            ],
            $info->toArray()
        );
    }
}
