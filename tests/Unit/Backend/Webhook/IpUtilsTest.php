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
use PHPUnit\Framework\TestCase;

final class IpUtilsTest extends TestCase
{
    // ── isInCidr: IPv4 ────────────────────────────────────────────────────────

    public function testIpv4InsideCidr(): void
    {
        $this->assertTrue(IpUtils::isInCidr('192.168.1.42', '192.168.0.0/16'));
        $this->assertTrue(IpUtils::isInCidr('10.0.0.1', '10.0.0.0/8'));
    }

    public function testIpv4OutsideCidr(): void
    {
        $this->assertFalse(IpUtils::isInCidr('11.0.0.1', '10.0.0.0/8'));
        $this->assertFalse(IpUtils::isInCidr('192.169.0.1', '192.168.0.0/16'));
    }

    public function testIpv4BareAddressTreatedAsSlash32(): void
    {
        // A bare address without a prefix is an exact match only.
        $this->assertTrue(IpUtils::isInCidr('203.0.113.7', '203.0.113.7'));
        $this->assertFalse(IpUtils::isInCidr('203.0.113.8', '203.0.113.7'));
    }

    public function testIpv4OddPrefixLength(): void
    {
        // /20 means the first 20 bits must match — last 12 bits are wildcard.
        $this->assertTrue(IpUtils::isInCidr('10.1.15.255', '10.1.0.0/20'));
        $this->assertFalse(IpUtils::isInCidr('10.1.16.0', '10.1.0.0/20'));
    }

    // ── isInCidr: IPv6 ────────────────────────────────────────────────────────

    public function testIpv6InsideCidr(): void
    {
        $this->assertTrue(IpUtils::isInCidr('::1', '::1/128'));
        $this->assertTrue(IpUtils::isInCidr('fd12:3456:789a::1', 'fc00::/7'));
        $this->assertTrue(IpUtils::isInCidr('fe80::1', 'fe80::/10'));
    }

    public function testIpv6OutsideCidr(): void
    {
        $this->assertFalse(IpUtils::isInCidr('2001:db8::1', 'fc00::/7'));
    }

    // ── isInCidr: malformed inputs ────────────────────────────────────────────

    public function testMalformedIpReturnsFalse(): void
    {
        $this->assertFalse(IpUtils::isInCidr('not-an-ip', '10.0.0.0/8'));
        $this->assertFalse(IpUtils::isInCidr('', '10.0.0.0/8'));
    }

    public function testMalformedCidrReturnsFalse(): void
    {
        $this->assertFalse(IpUtils::isInCidr('10.0.0.1', 'garbage/8'));
    }

    public function testMixedFamiliesReturnFalse(): void
    {
        // IPv4 cannot fall inside an IPv6 CIDR (and vice versa).
        $this->assertFalse(IpUtils::isInCidr('10.0.0.1', '::/0'));
        $this->assertFalse(IpUtils::isInCidr('::1', '0.0.0.0/0'));
    }

    // ── isInAnyCidr ───────────────────────────────────────────────────────────

    public function testIsInAnyCidrShortCircuits(): void
    {
        $this->assertTrue(IpUtils::isInAnyCidr('192.168.1.1', ['10.0.0.0/8', '192.168.0.0/16']));
        $this->assertFalse(IpUtils::isInAnyCidr('8.8.8.8', ['10.0.0.0/8', '192.168.0.0/16']));
    }

    public function testIsInAnyCidrWithEmptyListReturnsFalse(): void
    {
        $this->assertFalse(IpUtils::isInAnyCidr('10.0.0.1', []));
    }

    // ── isLocalAddress ────────────────────────────────────────────────────────

    public function testLocalAddressesAreRecognized(): void
    {
        $locals = ['127.0.0.1', '10.0.0.1', '172.16.0.1', '192.168.1.100', '169.254.0.1', '::1', 'fd00::1', 'fe80::1'];

        foreach ($locals as $ip) {
            $this->assertTrue(IpUtils::isLocalAddress($ip), "Expected $ip to be classified as local");
        }
    }

    public function testPublicAddressesAreNotLocal(): void
    {
        foreach (['8.8.8.8', '94.152.189.231', '2001:db8::1'] as $ip) {
            $this->assertFalse(IpUtils::isLocalAddress($ip), "Expected $ip to be classified as public");
        }
    }

    // ── isComfinoServerIp ─────────────────────────────────────────────────────

    public function testComfinoServerIpMatches(): void
    {
        $this->assertTrue(IpUtils::isComfinoServerIp(IpUtils::COMFINO_SERVER_IP));
        $this->assertFalse(IpUtils::isComfinoServerIp('1.2.3.4'));
    }

    // ── isValidIp ─────────────────────────────────────────────────────────────

    public function testIsValidIp(): void
    {
        $this->assertTrue(IpUtils::isValidIp('192.168.1.1'));
        $this->assertTrue(IpUtils::isValidIp('::1'));
        $this->assertFalse(IpUtils::isValidIp('999.999.999.999'));
        $this->assertFalse(IpUtils::isValidIp('not-an-ip'));
        $this->assertFalse(IpUtils::isValidIp(''));
    }
}
