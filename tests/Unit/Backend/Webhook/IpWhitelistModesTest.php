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

use Comfino\Backend\Webhook\IpUtils;
use Comfino\Backend\Webhook\IpWhitelist;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;

/**
 * What 3.1 added to the allow-list: ranges, the sandbox addresses, and a mode you can roll out in.
 *
 * Each of the three was a reason a host wrote its own {@see \Comfino\Backend\Webhook\IpWhitelistInterface} instead of
 * using this class — which is the test of whether an SDK seam is finished.
 */
final class IpWhitelistModesTest extends TestCase
{
    public function testAnEntryMayBeACidrRange(): void
    {
        $whitelist = new IpWhitelist(['198.51.100.0/24'], allowLocalAddresses: false);

        self::assertTrue($whitelist->isAllowed($this->requestFrom('198.51.100.42')));
        self::assertFalse($whitelist->isAllowed($this->requestFrom('198.51.101.42')));
    }

    public function testRangesAndBareAddressesMix(): void
    {
        $whitelist = new IpWhitelist(
            [IpUtils::COMFINO_SERVER_IP, '198.51.100.0/24'],
            allowLocalAddresses: false
        );

        self::assertTrue($whitelist->isAllowed($this->requestFrom(IpUtils::COMFINO_SERVER_IP)));
        self::assertTrue($whitelist->isAllowed($this->requestFrom('198.51.100.7')));
        self::assertFalse($whitelist->isAllowed($this->requestFrom('203.0.113.7')));
    }

    /**
     * The production-only list rejects the sandbox — which is the failure that made report mode necessary, since the
     * symptom is "the merchant's test payments never complete" rather than anything that looks like a security event.
     */
    public function testTheProductionListAloneRejectsSandboxNotifications(): void
    {
        $whitelist = IpWhitelist::forComfino(allowLocalAddresses: false);

        self::assertFalse($whitelist->isAllowed($this->requestFrom(IpUtils::COMFINO_SANDBOX_SERVER_IPS[0])));
    }

    public function testTheSandboxAddressesCanBeIncluded(): void
    {
        $whitelist = IpWhitelist::forComfino(allowLocalAddresses: false, includeSandbox: true);

        self::assertTrue($whitelist->isAllowed($this->requestFrom(IpUtils::COMFINO_SERVER_IP)));

        foreach (IpUtils::COMFINO_SANDBOX_SERVER_IPS as $sandboxIp) {
            self::assertTrue($whitelist->isAllowed($this->requestFrom($sandboxIp)));
        }

        self::assertFalse($whitelist->isAllowed($this->requestFrom('203.0.113.7')));
    }

    public function testReportModeAllowsTheRequestAndNamesTheAddress(): void
    {
        $logger = new CollectingLogger();
        $whitelist = IpWhitelist::forComfino(
            allowLocalAddresses: false,
            enforce: false,
            logger: $logger
        );

        self::assertTrue($whitelist->isAllowed($this->requestFrom('203.0.113.7')));
        self::assertFalse($whitelist->isEnforcing());
        self::assertCount(1, $logger->contexts);
        self::assertSame('203.0.113.7', $logger->contexts[0]['client_ip'] ?? null);
    }

    public function testEnforcingModeRejectsAndLogs(): void
    {
        $logger = new CollectingLogger();
        $whitelist = IpWhitelist::forComfino(allowLocalAddresses: false, logger: $logger);

        self::assertFalse($whitelist->isAllowed($this->requestFrom('203.0.113.7')));
        self::assertTrue($whitelist->isEnforcing());
        self::assertCount(1, $logger->contexts);
    }

    public function testAnAllowedAddressIsNotLogged(): void
    {
        $logger = new CollectingLogger();
        $whitelist = IpWhitelist::forComfino(allowLocalAddresses: false, logger: $logger);

        self::assertTrue($whitelist->isAllowed($this->requestFrom(IpUtils::COMFINO_SERVER_IP)));
        self::assertSame([], $logger->contexts);
    }

    /**
     * A request with no address is a mismatch, not an exemption: a check that passes when it cannot run is not a check.
     */
    public function testAnUndeterminableAddressIsAMismatch(): void
    {
        $whitelist = IpWhitelist::forComfino();

        self::assertFalse($whitelist->isAllowed(new ServerRequest('POST', 'https://shop.example/hooks/status')));
    }

    private function requestFrom(string $clientIp): ServerRequestInterface
    {
        return new ServerRequest(
            'POST',
            'https://shop.example/hooks/status',
            [],
            null,
            '1.1',
            ['REMOTE_ADDR' => $clientIp]
        );
    }
}
