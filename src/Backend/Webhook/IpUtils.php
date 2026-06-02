<?php

/**
 * Comfino PHP SDK
 *
 * Backend routines for e-commerce platforms integration with the Comfino payment gateway API.
 *
 * @package Comfino\Backend\Webhook
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
 * @copyright Copyright (c) 2026 Comfino by Comperia.pl S.A.
 * @license https://opensource.org/licenses/BSD-3-Clause BSD 3-Clause License
 * @link https://github.com/comfino/php-sdk
 */

declare(strict_types=1);

namespace Comfino\Backend\Webhook;

/**
 * Stateless helpers for IP address checks: CIDR membership, local/private detection, and Comfino-server matching.
 *
 * Exposes the same primitives used internally by {@see IpWhitelist} and {@see ClientIpResolver} so that shop
 * platforms and plugins can validate IPs against the SDK's rules without re-implementing CIDR matching or
 * maintaining their own list of private ranges.
 *
 * Example — verify that a webhook request really came from the Comfino server:
 *
 *   if (!IpUtils::isComfinoServerIp($clientIp) && !IpUtils::isLocalAddress($clientIp)) {
 *       throw new UnauthorizedHttpException('Unexpected source address.');
 *   }
 */
final class IpUtils
{
    /** Production IPv4 address of the Comfino notification server. */
    public const COMFINO_SERVER_IP = '94.152.189.231';

    /** CIDRs considered "private" — used both as default trusted-proxy ranges and as local-address detection. */
    public const LOCAL_CIDRS = [
        '127.0.0.0/8',    // IPv4 loopback
        '10.0.0.0/8',     // RFC 1918
        '172.16.0.0/12',  // RFC 1918
        '192.168.0.0/16', // RFC 1918
        '169.254.0.0/16', // IPv4 link-local
        '::1/128',        // IPv6 loopback
        'fc00::/7',       // IPv6 unique local (fc00:: – fdff::)
        'fe80::/10',      // IPv6 link-local
    ];

    /**
     * All-static utility class — instantiation is intentionally disabled.
     *
     * @codeCoverageIgnore
     */
    private function __construct()
    {
    }

    /**
     * Returns true if $ip falls inside the given CIDR block.
     *
     * Supports both IPv4 and IPv6. A bare address without a prefix length is treated as a /32 or /128.
     *
     * @param string $ip IP address to check
     * @param string $cidr CIDR block to check (CIDR: Classless Inter-Domain Routing)
     */
    public static function isInCidr(string $ip, string $cidr): bool
    {
        // Split the CIDR into network address and prefix length.
        [$network, $prefixStr] = array_pad(explode('/', $cidr, 2), 2, null);

        // Validate the IP and network address.
        $ipBin = @inet_pton($ip);
        $networkBin = @inet_pton((string) $network);

        if ($ipBin === false || $networkBin === false || strlen($ipBin) !== strlen($networkBin)) {
            return false;
        }

        // Calculate the number of bytes that are fully covered by the prefix.
        $byteLen = strlen($ipBin);
        $prefixLen = $prefixStr !== null ? (int) $prefixStr : $byteLen * 8;
        $fullBytes = (int) ($prefixLen / 8);
        $extraBits = $prefixLen % 8;

        // Compare the bytes that are fully covered by the prefix.
        if ($fullBytes > 0 && !str_starts_with($ipBin, substr($networkBin, 0, $fullBytes))) {
            return false;
        }

        // Compare the partially covered byte (if any).
        if ($extraBits > 0 && $fullBytes < $byteLen) {
            // The partially covered byte is in the last byte of the network address.
            $mask = 0xFF & (0xFF << (8 - $extraBits));

            if ((ord($ipBin[$fullBytes]) & $mask) !== (ord($networkBin[$fullBytes]) & $mask)) {
                // The partially covered byte does not match.
                return false;
            }
        }

        return true;
    }

    /**
     * Returns true if $ip falls inside any of the given CIDR blocks.
     *
     * @param string $ip IP address to check
     * @param string[] $cidrs List of CIDR blocks to check (CIDR: Classless Inter-Domain Routing)
     */
    public static function isInAnyCidr(string $ip, array $cidrs): bool
    {
        foreach ($cidrs as $cidr) {
            if (self::isInCidr($ip, $cidr)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Returns true if $ip is a loopback, RFC 1918, link-local, or IPv6 unique-local address.
     *
     * Useful for skipping audit logging or signature enforcement on in-firewall traffic.
     */
    public static function isLocalAddress(string $ip): bool
    {
        return self::isInAnyCidr($ip, self::LOCAL_CIDRS);
    }

    /**
     * Returns true if $ip matches the Comfino production notification server address.
     *
     * Convenience over a literal string comparison — keeps call sites readable and survives any future address change
     * in a single place.
     */
    public static function isComfinoServerIp(string $ip): bool
    {
        return $ip === self::COMFINO_SERVER_IP;
    }

    /**
     * Returns true if $ip is a syntactically valid IPv4 or IPv6 address.
     *
     * Thin wrapper over {@see filter_var()} provided for symmetry with the other helpers and to keep callers free
     * of the FILTER_VALIDATE_IP constant.
     */
    public static function isValidIp(string $ip): bool
    {
        return filter_var($ip, FILTER_VALIDATE_IP) !== false;
    }
}
