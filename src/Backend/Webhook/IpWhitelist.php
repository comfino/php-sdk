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

use Psr\Http\Message\ServerRequestInterface;

/**
 * IP whitelist that restricts webhook access to a list of known allowed addresses.
 *
 * Works correctly both for directly exposed hosts and for hosts sitting behind reverse-proxy services
 * (e.g., Cloudflare). When REMOTE_ADDR belongs to a trusted-proxy range, the real client IP is resolved via
 * {@see ClientIpResolver} from the following headers in priority order:
 *   1. CF-Connecting-IP — set by Cloudflare.
 *   2. X-Forwarded-For — leftmost non-trusted-proxy entry.
 *   3. X-Real-IP — set by Nginx and many other proxies.
 *
 * Local/private addresses (loopback, RFC 1918, link-local, IPv6 ULA) are accepted unconditionally by default so that
 * test environments and in-firewall deployments work without extra configuration.
 *
 * Example — Comfino production setup:
 *
 *   $whitelist = IpWhitelist::forComfino();
 *
 * Example — custom setup with extra IPs:
 *
 *   $whitelist = new IpWhitelist(['94.152.189.231', '198.51.100.42']);
 */
final class IpWhitelist implements IpWhitelistInterface
{
    /** Production IPv4 address of the Comfino notification server. */
    public const COMFINO_SERVER_IP = IpUtils::COMFINO_SERVER_IP;

    private readonly ClientIpResolver $ipResolver;

    /**
     * @param string[] $allowedIps Explicit list of allowed public IPv4/IPv6 addresses.
     * @param string[] $trustedProxyCidrs CIDR ranges whose REMOTE_ADDR triggers proxy-header inspection. Defaults to
     *                                    all local/private ranges.
     * @param bool $allowLocalAddresses Whether to automatically allow any local/private address. Set to false only
     *                                  when the webhook endpoint is publicly reachable and local traffic must also
     *                                  be authenticated.
     */
    public function __construct(
        private readonly array $allowedIps,
        array $trustedProxyCidrs = IpUtils::LOCAL_CIDRS,
        private readonly bool $allowLocalAddresses = true
    ) {
        $this->ipResolver = new ClientIpResolver($trustedProxyCidrs);
    }

    /**
     * Creates a whitelist pre-configured for the Comfino production server IP.
     *
     * Local/private addresses are allowed by default, so test environments work out of the box.
     */
    public static function forComfino(bool $allowLocalAddresses = true): self
    {
        return new self([self::COMFINO_SERVER_IP], allowLocalAddresses: $allowLocalAddresses);
    }

    public function isAllowed(ServerRequestInterface $request): bool
    {
        $clientIp = $this->ipResolver->resolveFromRequest($request) ?? '';

        if ($this->allowLocalAddresses && IpUtils::isLocalAddress($clientIp)) {
            return true;
        }

        return in_array($clientIp, $this->allowedIps, true);
    }
}
