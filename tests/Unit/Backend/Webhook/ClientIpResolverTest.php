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

use Comfino\Backend\Webhook\ClientIpResolver;
use Comfino\Backend\Webhook\IpUtils;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;

final class ClientIpResolverTest extends TestCase
{
    /**
     * @param array<string, string> $serverParams Server params (REMOTE_ADDR, etc.)
     * @param array<string, string> $headers Header name → value (single value per header)
     */
    private function createRequest(array $serverParams = [], array $headers = []): ServerRequestInterface&MockObject
    {
        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getServerParams')->willReturn($serverParams);

        $request->method('hasHeader')->willReturnCallback(fn ($h) => isset($headers[$h]));
        $request->method('getHeader')->willReturnCallback(fn ($h) => isset($headers[$h]) ? [$headers[$h]] : []);

        return $request;
    }

    // ── resolveFromRequest: direct connections ────────────────────────────────

    public function testReturnsRemoteAddrWhenNotBehindTrustedProxy(): void
    {
        $resolver = ClientIpResolver::default();
        $request = $this->createRequest(['REMOTE_ADDR' => '8.8.8.8']);

        $this->assertSame('8.8.8.8', $resolver->resolveFromRequest($request));
    }

    public function testReturnsNullWhenRemoteAddrMissing(): void
    {
        $resolver = ClientIpResolver::default();
        $request = $this->createRequest();

        $this->assertNull($resolver->resolveFromRequest($request));
    }

    public function testReturnsNullWhenRemoteAddrEmpty(): void
    {
        $resolver = ClientIpResolver::default();
        $request = $this->createRequest(['REMOTE_ADDR' => '']);

        $this->assertNull($resolver->resolveFromRequest($request));
    }

    public function testForwardedHeadersIgnoredWhenRemoteAddrIsPublic(): void
    {
        // Headers are honored only when REMOTE_ADDR is in the trusted-proxy set.
        $resolver = ClientIpResolver::default();
        $request = $this->createRequest(
            ['REMOTE_ADDR' => '8.8.8.8'],
            ['CF-Connecting-IP' => '1.2.3.4', 'X-Forwarded-For' => '5.6.7.8'],
        );

        $this->assertSame('8.8.8.8', $resolver->resolveFromRequest($request));
    }

    // ── resolveFromRequest: header priority ───────────────────────────────────

    public function testCfConnectingIpHasHighestPriority(): void
    {
        $resolver = ClientIpResolver::default();
        $request = $this->createRequest(
            ['REMOTE_ADDR' => '10.0.0.1'],
            [
                'CF-Connecting-IP' => '1.1.1.1',
                'X-Forwarded-For'  => '2.2.2.2',
                'X-Real-IP'        => '3.3.3.3',
            ],
        );

        $this->assertSame('1.1.1.1', $resolver->resolveFromRequest($request));
    }

    public function testXForwardedForUsedWhenCfMissing(): void
    {
        $resolver = ClientIpResolver::default();
        $request = $this->createRequest(
            ['REMOTE_ADDR' => '10.0.0.1'],
            ['X-Forwarded-For' => '2.2.2.2', 'X-Real-IP' => '3.3.3.3'],
        );

        $this->assertSame('2.2.2.2', $resolver->resolveFromRequest($request));
    }

    public function testXRealIpUsedAsLastResort(): void
    {
        $resolver = ClientIpResolver::default();
        $request = $this->createRequest(
            ['REMOTE_ADDR' => '10.0.0.1'],
            ['X-Real-IP' => '3.3.3.3'],
        );

        $this->assertSame('3.3.3.3', $resolver->resolveFromRequest($request));
    }

    // ── resolveFromRequest: X-Forwarded-For chain handling ────────────────────

    public function testXForwardedForPicksLeftmostNonProxyEntry(): void
    {
        $resolver = ClientIpResolver::default();
        $request = $this->createRequest(
            ['REMOTE_ADDR' => '10.0.0.3'],
            ['X-Forwarded-For' => '94.152.189.231, 10.0.0.1, 10.0.0.2'],
        );

        $this->assertSame('94.152.189.231', $resolver->resolveFromRequest($request));
    }

    public function testXForwardedForSkipsInvalidEntries(): void
    {
        $resolver = ClientIpResolver::default();
        $request = $this->createRequest(
            ['REMOTE_ADDR' => '10.0.0.1'],
            ['X-Forwarded-For' => 'garbage, 94.152.189.231'],
        );

        $this->assertSame('94.152.189.231', $resolver->resolveFromRequest($request));
    }

    public function testXForwardedForFallsBackToRemoteAddrWhenAllEntriesAreProxies(): void
    {
        // If every entry in X-Forwarded-For is itself a trusted proxy, the resolver returns REMOTE_ADDR.
        $resolver = ClientIpResolver::default();
        $request = $this->createRequest(
            ['REMOTE_ADDR' => '10.0.0.3'],
            ['X-Forwarded-For' => '10.0.0.1, 10.0.0.2'],
        );

        $this->assertSame('10.0.0.3', $resolver->resolveFromRequest($request));
    }

    public function testInvalidCfConnectingIpFallsThroughToXForwardedFor(): void
    {
        $resolver = ClientIpResolver::default();
        $request = $this->createRequest(
            ['REMOTE_ADDR' => '10.0.0.1'],
            ['CF-Connecting-IP' => 'not-an-ip', 'X-Forwarded-For' => '94.152.189.231'],
        );

        $this->assertSame('94.152.189.231', $resolver->resolveFromRequest($request));
    }

    // ── withTrustedProxies ────────────────────────────────────────────────────

    public function testCustomTrustedProxyCidr(): void
    {
        // Public IP is normally not trusted; add its range explicitly.
        $resolver = ClientIpResolver::withTrustedProxies(['203.0.113.0/24']);
        $request = $this->createRequest(
            ['REMOTE_ADDR' => '203.0.113.5'],
            ['X-Forwarded-For' => '94.152.189.231'],
        );

        $this->assertSame('94.152.189.231', $resolver->resolveFromRequest($request));
    }

    public function testCustomTrustedProxyDoesNotImplicitlyTrustLocalRanges(): void
    {
        // When the caller overrides the trusted set, local ranges must be explicitly included.
        $resolver = ClientIpResolver::withTrustedProxies(['203.0.113.0/24']);
        $request = $this->createRequest(
            ['REMOTE_ADDR' => '127.0.0.1'],
            ['X-Forwarded-For' => '94.152.189.231'],
        );

        // 127.0.0.1 is not in the custom set → treated as the direct client.
        $this->assertSame('127.0.0.1', $resolver->resolveFromRequest($request));
    }

    // ── resolveFromServerParams: explicit headers ─────────────────────────────

    public function testResolveFromServerParamsWithExplicitHeaders(): void
    {
        $resolver = ClientIpResolver::default();

        $ip = $resolver->resolveFromServerParams(
            ['REMOTE_ADDR' => '10.0.0.1'],
            ['X-Forwarded-For' => '94.152.189.231'],
        );

        $this->assertSame('94.152.189.231', $ip);
    }

    public function testResolveFromServerParamsHeaderLookupIsCaseInsensitive(): void
    {
        $resolver = ClientIpResolver::default();

        $ip = $resolver->resolveFromServerParams(
            ['REMOTE_ADDR' => '10.0.0.1'],
            ['x-forwarded-FOR' => '94.152.189.231'],
        );

        $this->assertSame('94.152.189.231', $ip);
    }

    public function testResolveFromServerParamsReturnsNullWhenRemoteAddrMissing(): void
    {
        $resolver = ClientIpResolver::default();

        $this->assertNull($resolver->resolveFromServerParams([]));
        $this->assertNull($resolver->resolveFromServerParams(['REMOTE_ADDR' => '']));
    }

    public function testResolveFromServerParamsTrustsRemoteAddrWhenNotBehindProxy(): void
    {
        $resolver = ClientIpResolver::default();

        $ip = $resolver->resolveFromServerParams(
            ['REMOTE_ADDR' => '8.8.8.8'],
            ['X-Forwarded-For' => '1.2.3.4'],
        );

        $this->assertSame('8.8.8.8', $ip);
    }

    // ── resolveFromServerParams: HTTP_* extraction ────────────────────────────

    public function testResolveFromServerParamsExtractsHeadersFromHttpKeys(): void
    {
        $resolver = ClientIpResolver::default();

        // PHP exposes "X-Forwarded-For" as $_SERVER['HTTP_X_FORWARDED_FOR'].
        $ip = $resolver->resolveFromServerParams([
            'REMOTE_ADDR'              => '10.0.0.1',
            'HTTP_X_FORWARDED_FOR'     => '94.152.189.231',
            'HTTP_CF_CONNECTING_IP'    => '94.152.189.231',
        ]);

        // CF-Connecting-IP wins by priority.
        $this->assertSame('94.152.189.231', $ip);
    }

    public function testResolveFromServerParamsExplicitHeadersOverrideHttpKeys(): void
    {
        // When the caller supplies the headers array, HTTP_* keys are ignored.
        $resolver = ClientIpResolver::default();

        $ip = $resolver->resolveFromServerParams(
            [
                'REMOTE_ADDR'          => '10.0.0.1',
                'HTTP_X_FORWARDED_FOR' => '1.1.1.1',
            ],
            ['X-Forwarded-For' => '94.152.189.231'],
        );

        $this->assertSame('94.152.189.231', $ip);
    }

    // ── IPv6 ──────────────────────────────────────────────────────────────────

    public function testIpv6Resolution(): void
    {
        $resolver = ClientIpResolver::default();
        $request = $this->createRequest(
            ['REMOTE_ADDR' => '::1'],
            ['X-Forwarded-For' => '2001:db8::1'],
        );

        $this->assertSame('2001:db8::1', $resolver->resolveFromRequest($request));
    }

    // ── default trusted set ───────────────────────────────────────────────────

    public function testDefaultTrustedSetMatchesIpUtilsLocalCidrs(): void
    {
        // Verify the default factory uses the same trust set as IpUtils::LOCAL_CIDRS — sanity check that the two
        // public APIs stay in sync.
        $resolver = ClientIpResolver::default();

        foreach (['127.0.0.1', '10.0.0.1', '192.168.1.1', '::1', 'fd00::1', 'fe80::1'] as $proxyIp) {
            $request = $this->createRequest(
                ['REMOTE_ADDR' => $proxyIp],
                ['X-Forwarded-For' => '94.152.189.231'],
            );

            $this->assertSame(
                '94.152.189.231',
                $resolver->resolveFromRequest($request),
                "Expected $proxyIp to be treated as a trusted proxy",
            );
            $this->assertTrue(IpUtils::isLocalAddress($proxyIp));
        }
    }
}
