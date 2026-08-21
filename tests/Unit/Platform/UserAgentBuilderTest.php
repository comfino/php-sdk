<?php

/**
 * Comfino PHP SDK
 *
 * Backend routines for e-commerce platforms integration with the Comfino payment gateway API.
 *
 * @package Comfino\Tests\Unit\Platform
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
 * @copyright Copyright (c) 2026 Comfino by Comperia.pl S.A.
 * @license https://opensource.org/licenses/BSD-3-Clause BSD 3-Clause License
 * @link https://github.com/comfino/php-sdk
 */

declare(strict_types=1);

namespace Comfino\Tests\Unit\Platform;

use Comfino\Platform\ConnectorInfoInterface;
use Comfino\Platform\HostedConnectorInfo;
use Comfino\Platform\PlatformInfoInterface;
use Comfino\Platform\UserAgentBuilder;
use PHPUnit\Framework\TestCase;

final class UserAgentBuilderTest extends TestCase
{
    /**
     * The exact string the plugin path used to build by hand inside ApiClientFactory. Asserted literally, because this
     * value reaches Comfino's API logs and a support conversation depends on its shape.
     */
    public function testBuildsThePlatformShape(): void
    {
        $platformInfo = $this->createMock(PlatformInfoInterface::class);
        $platformInfo->method('getCode')->willReturn('MG');
        $platformInfo->method('getPluginVersion')->willReturn('4.0.0');
        $platformInfo->method('getName')->willReturn('Magento');
        $platformInfo->method('getVersion')->willReturn('2.4.7');
        $platformInfo->method('getPhpVersion')->willReturn('8.2.10');
        $platformInfo->method('getDomain')->willReturn('myshop.pl');

        self::assertSame(
            'MG Comfino [4.0.0], Magento [2.4.7], PHP [8.2.10], myshop.pl',
            UserAgentBuilder::build($platformInfo)
        );
    }

    public function testBuildsTheHostedConnectorShape(): void
    {
        $info = new HostedConnectorInfo('IDO', '1.4.2', 'connector.example.com', 'pl', null, 'connector', '8.3.1');

        self::assertSame(
            'IDO Comfino [1.4.2], connector [1.4.2], PHP [8.3.1], connector.example.com',
            UserAgentBuilder::build($info)
        );
    }

    /**
     * A connector that cannot name its own host must still produce a parseable string: the segment count is fixed and
     * the unknown value is named, rather than the string being silently short one field.
     */
    public function testRendersUnknownForAbsentValues(): void
    {
        $info = new HostedConnectorInfo('IDO', '1.4.2', null, 'pl', null, 'connector', '8.3.1');

        self::assertSame(
            'IDO Comfino [1.4.2], connector [1.4.2], PHP [8.3.1], unknown',
            UserAgentBuilder::build($info)
        );
    }

    /**
     * Platforms reading these values from configuration routinely resolve an unset option to '' rather than to null,
     * and "PHP []" in an API log is worse than useless.
     */
    public function testTreatsEmptyStringAsUnknown(): void
    {
        $info = $this->createMock(ConnectorInfoInterface::class);
        $info->method('getCode')->willReturn('PS');
        $info->method('getPluginVersion')->willReturn('3.1.0');
        $info->method('getName')->willReturn('PrestaShop');
        $info->method('getVersion')->willReturn('');
        $info->method('getPhpVersion')->willReturn('');
        $info->method('getDomain')->willReturn('');

        self::assertSame(
            'PS Comfino [3.1.0], PrestaShop [unknown], PHP [unknown], unknown',
            UserAgentBuilder::build($info)
        );
    }

    /**
     * An out-of-process connector that does learn the merchant's platform version should report it, which is the
     * only reason the parameter exists.
     */
    public function testHostedConnectorReportsAKnownPlatformVersion(): void
    {
        $info = new HostedConnectorInfo('IDO', '1.4.2', 'connector.example.com', 'pl', '2026.1', 'IdoSell', '8.3.1');

        self::assertSame(
            'IDO Comfino [1.4.2], IdoSell [2026.1], PHP [8.3.1], connector.example.com',
            UserAgentBuilder::build($info)
        );
    }
}
