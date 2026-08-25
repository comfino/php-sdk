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

use Comfino\Backend\Webhook\IpWhitelist;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;

final class IpWhitelistTest extends TestCase
{
    /**
     * @param array<string, string> $headers Header name → value (single value per header)
     * @param array<string, string> $serverParams Server params (REMOTE_ADDR, etc.)
     */
    private function createRequest(array $serverParams = [], array $headers = []): ServerRequestInterface&MockObject
    {
        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getServerParams')->willReturn($serverParams);

        $request->method('hasHeader')->willReturnCallback(fn ($h) => isset($headers[$h]));
        $request->method('getHeader')->willReturnCallback(fn ($h) => isset($headers[$h]) ? [$headers[$h]] : []);

        return $request;
    }

    // ── forComfino factory ────────────────────────────────────────────────────

    public function testForComfinoAllowsComfinoIp(): void
    {
        $whitelist = IpWhitelist::forComfino();
        $request = $this->createRequest(['REMOTE_ADDR' => IpWhitelist::COMFINO_SERVER_IP]);

        $this->assertTrue($whitelist->isAllowed($request));
    }

    public function testForComfinoBlocksUnknownPublicIp(): void
    {
        $whitelist = IpWhitelist::forComfino();
        $request = $this->createRequest(['REMOTE_ADDR' => '8.8.8.8']);

        $this->assertFalse($whitelist->isAllowed($request));
    }

    // ── local address bypass ──────────────────────────────────────────────────

    public function testLoopbackIpAllowedByDefault(): void
    {
        $whitelist = new IpWhitelist([]);
        $request = $this->createRequest(['REMOTE_ADDR' => '127.0.0.1']);

        $this->assertTrue($whitelist->isAllowed($request));
    }

    public function testRfc1918IpAllowedByDefault(): void
    {
        $whitelist = new IpWhitelist([]);

        foreach (['10.0.0.1', '172.16.0.1', '192.168.1.100'] as $ip) {
            $this->assertTrue(
                $whitelist->isAllowed($this->createRequest(['REMOTE_ADDR' => $ip])),
                "Expected $ip to be allowed as a local address"
            );
        }
    }

    public function testIpv6LoopbackAllowedByDefault(): void
    {
        $whitelist = new IpWhitelist([]);
        $request = $this->createRequest(['REMOTE_ADDR' => '::1']);

        $this->assertTrue($whitelist->isAllowed($request));
    }

    public function testLocalIpBlockedWhenAllowLocalAddressesIsFalse(): void
    {
        $whitelist = new IpWhitelist([], allowLocalAddresses: false);
        $request = $this->createRequest(['REMOTE_ADDR' => '127.0.0.1']);

        $this->assertFalse($whitelist->isAllowed($request));
    }

    // ── direct connection (no proxy headers) ─────────────────────────────────

    public function testPublicIpInAllowedListIsAccepted(): void
    {
        $whitelist = new IpWhitelist(['94.152.189.231', '198.51.100.42']);
        $request = $this->createRequest(['REMOTE_ADDR' => '198.51.100.42']);

        $this->assertTrue($whitelist->isAllowed($request));
    }

    public function testPublicIpNotInAllowedListIsRejected(): void
    {
        $whitelist = new IpWhitelist(['94.152.189.231']);
        $request = $this->createRequest(['REMOTE_ADDR' => '1.2.3.4']);

        $this->assertFalse($whitelist->isAllowed($request));
    }

    // ── Cloudflare: CF-Connecting-IP ─────────────────────────────────────────

    public function testCfConnectingIpUsedWhenRemoteAddrIsTrustedProxy(): void
    {
        $whitelist = IpWhitelist::forComfino();

        // REMOTE_ADDR is a private/loopback IP → trusted proxy; real origin is the Comfino server.
        $request = $this->createRequest(
            ['REMOTE_ADDR' => '10.0.0.2'],
            ['CF-Connecting-IP' => IpWhitelist::COMFINO_SERVER_IP],
        );

        $this->assertTrue($whitelist->isAllowed($request));
    }

    public function testCfConnectingIpBlockedWhenNotInWhitelist(): void
    {
        $whitelist = IpWhitelist::forComfino();

        $request = $this->createRequest(
            ['REMOTE_ADDR' => '10.0.0.2'],
            ['CF-Connecting-IP' => '1.2.3.4'],
        );

        $this->assertFalse($whitelist->isAllowed($request));
    }

    public function testCfConnectingIpIgnoredWhenRemoteAddrIsPublic(): void
    {
        // When REMOTE_ADDR is NOT a trusted proxy, forwarded headers must not be trusted.
        $whitelist = IpWhitelist::forComfino();

        $request = $this->createRequest(
            ['REMOTE_ADDR' => '8.8.8.8'],
            ['CF-Connecting-IP' => IpWhitelist::COMFINO_SERVER_IP],
        );

        $this->assertFalse($whitelist->isAllowed($request));
    }

    // ── X-Forwarded-For ───────────────────────────────────────────────────────

    public function testXForwardedForUsedWhenRemoteAddrIsTrustedProxy(): void
    {
        $whitelist = IpWhitelist::forComfino();

        $request = $this->createRequest(
            ['REMOTE_ADDR' => '192.168.0.1'],
            ['X-Forwarded-For' => IpWhitelist::COMFINO_SERVER_IP . ', 192.168.0.1'],
        );

        $this->assertTrue($whitelist->isAllowed($request));
    }

    public function testXForwardedForSkipsInternalProxyHops(): void
    {
        // Leftmost non-trusted entry is the real client — all internal hops are skipped.
        $whitelist = IpWhitelist::forComfino();

        $request = $this->createRequest(
            ['REMOTE_ADDR' => '10.0.0.3'],
            ['X-Forwarded-For' => IpWhitelist::COMFINO_SERVER_IP . ', 10.0.0.1, 10.0.0.2'],
        );

        $this->assertTrue($whitelist->isAllowed($request));
    }

    public function testXForwardedForIgnoredWhenRemoteAddrIsPublic(): void
    {
        $whitelist = IpWhitelist::forComfino();

        $request = $this->createRequest(
            ['REMOTE_ADDR' => '5.5.5.5'],
            ['X-Forwarded-For' => IpWhitelist::COMFINO_SERVER_IP],
        );

        $this->assertFalse($whitelist->isAllowed($request));
    }

    // ── X-Real-IP ─────────────────────────────────────────────────────────────

    public function testXRealIpUsedWhenRemoteAddrIsTrustedProxy(): void
    {
        $whitelist = IpWhitelist::forComfino();

        $request = $this->createRequest(
            ['REMOTE_ADDR' => '127.0.0.1'],
            ['X-Real-IP' => IpWhitelist::COMFINO_SERVER_IP],
        );

        $this->assertTrue($whitelist->isAllowed($request));
    }

    public function testXRealIpIgnoredWhenRemoteAddrIsPublic(): void
    {
        $whitelist = IpWhitelist::forComfino();

        $request = $this->createRequest(
            ['REMOTE_ADDR' => '9.9.9.9'],
            ['X-Real-IP' => IpWhitelist::COMFINO_SERVER_IP],
        );

        $this->assertFalse($whitelist->isAllowed($request));
    }

    // ── header priority order ─────────────────────────────────────────────────

    public function testCfConnectingIpTakesPriorityOverXForwardedFor(): void
    {
        // CF-Connecting-IP wins (blocked); X-Forwarded-For would have passed.
        $whitelist = IpWhitelist::forComfino();

        $request = $this->createRequest(
            ['REMOTE_ADDR' => '10.0.0.1'],
            [
                'CF-Connecting-IP' => '1.2.3.4',
                'X-Forwarded-For'  => IpWhitelist::COMFINO_SERVER_IP,
            ],
        );

        $this->assertFalse($whitelist->isAllowed($request));
    }

    // ── CIDR matching edge-cases ──────────────────────────────────────────────

    public function testCidrMatchingIpv4(): void
    {
        // Use a custom trusted-proxy CIDR to force header inspection for a non-private REMOTE_ADDR.
        $whitelist = new IpWhitelist(
            ['94.152.189.231'],
            trustedProxyCidrs: ['203.0.113.0/24'],
            allowLocalAddresses: false,
        );

        // REMOTE_ADDR is in the custom proxy CIDR → check X-Forwarded-For.
        $request = $this->createRequest(
            ['REMOTE_ADDR' => '203.0.113.5'],
            ['X-Forwarded-For' => '94.152.189.231'],
        );

        $this->assertTrue($whitelist->isAllowed($request));
    }

    public function testIpv6UlaIsConsideredLocal(): void
    {
        $whitelist = new IpWhitelist([]);
        $request = $this->createRequest(['REMOTE_ADDR' => 'fd12:3456:789a::1']);

        $this->assertTrue($whitelist->isAllowed($request));
    }

    public function testIpv6LinkLocalIsConsideredLocal(): void
    {
        $whitelist = new IpWhitelist([]);
        $request = $this->createRequest(['REMOTE_ADDR' => 'fe80::1']);

        $this->assertTrue($whitelist->isAllowed($request));
    }
}
