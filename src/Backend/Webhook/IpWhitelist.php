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
use Psr\Log\LoggerInterface;

/**
 * IP whitelist that restricts webhook access to a list of known allowed addresses **or ranges**.
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
 * **Entries may be CIDR ranges.** Until 3.1 the list was an exact string comparison, so a host that needed to allow a
 * network — its own ingress subnet, a CDN's egress range — could not express it and ended up writing its own
 * {@see IpWhitelistInterface}. Ranges and bare addresses can be mixed freely; a bare address is a /32 or /128.
 *
 * **Report mode.** With `$enforce = false` a request from an address that is not on the list is *allowed*, and the
 * mismatch is logged with the address that arrived. This exists because an allow-list is the kind of control that is
 * safest to roll out in observation first: the list is a hypothesis about who calls you, and the failure mode of a
 * wrong one is silent — notifications rejected, orders never updated, discovered days later. Run in report mode, read
 * the log, then enforce. A control an operator cannot roll out gradually is one they will not roll out.
 *
 * Example — Comfino production only:
 *
 *   $whitelist = IpWhitelist::forComfino();
 *
 * Example — production and sandbox, in observation mode, so the log says who really calls:
 *
 *   $whitelist = IpWhitelist::forComfino(includeSandbox: true, enforce: false, logger: $logger);
 *
 * Example — custom setup with an extra address and a range:
 *
 *   $whitelist = new IpWhitelist(['94.152.189.231', '198.51.100.0/24']);
 */
final class IpWhitelist implements IpWhitelistInterface
{
    /** Production IPv4 address of the Comfino notification server. */
    public const COMFINO_SERVER_IP = IpUtils::COMFINO_SERVER_IP;

    private readonly ClientIpResolver $ipResolver;

    /**
     * @param string[] $allowedIps Allowed public IPv4/IPv6 addresses or CIDR ranges.
     * @param string[] $trustedProxyCidrs CIDR ranges whose REMOTE_ADDR triggers proxy-header inspection. Defaults to
     *                                    all local/private ranges. Ignored when $ipResolver is given.
     * @param bool $allowLocalAddresses Whether to automatically allow any local/private address. Set to false only
     *                                  when the webhook endpoint is publicly reachable and local traffic must also
     *                                  be authenticated.
     * @param bool $enforce Whether a mismatch rejects the request. False puts the list in report-only mode: the
     *                      request is allowed and the mismatch is logged.
     * @param LoggerInterface|null $logger Where mismatches are reported. Without one, report mode allows silently —
     *                                     which is the one combination with no value, so pass a logger with it.
     * @param ClientIpResolver|null $ipResolver Resolver for the caller's address. Pass the *same instance* given to
     *                                          {@see WebhookManager} so the allow-list and the rate limiter cannot
     *                                          disagree about who called.
     */
    public function __construct(
        private readonly array $allowedIps,
        array $trustedProxyCidrs = IpUtils::LOCAL_CIDRS,
        private readonly bool $allowLocalAddresses = true,
        private readonly bool $enforce = true,
        private readonly ?LoggerInterface $logger = null,
        ?ClientIpResolver $ipResolver = null
    ) {
        $this->ipResolver = $ipResolver ?? new ClientIpResolver($trustedProxyCidrs);
    }

    /**
     * Creates a whitelist pre-configured for the Comfino notification servers.
     *
     * Local/private addresses are allowed by default, so test environments work out of the box.
     *
     * @param bool $allowLocalAddresses Whether local/private addresses are allowed unconditionally
     * @param bool $includeSandbox Whether the sandbox notification addresses are allowed as well. Needed by any
     *                             installation whose merchants can run a test payment — see
     *                             {@see IpUtils::COMFINO_SANDBOX_SERVER_IPS}, including how much those addresses are
     *                             worth trusting.
     * @param bool $enforce Whether a mismatch rejects the request, or is only reported
     * @param LoggerInterface|null $logger Where mismatches are reported
     */
    public static function forComfino(
        bool $allowLocalAddresses = true,
        bool $includeSandbox = false,
        bool $enforce = true,
        ?LoggerInterface $logger = null
    ): self {
        $allowedIps = $includeSandbox
            ? [self::COMFINO_SERVER_IP, ...IpUtils::COMFINO_SANDBOX_SERVER_IPS]
            : [self::COMFINO_SERVER_IP];

        return new self(
            $allowedIps,
            allowLocalAddresses: $allowLocalAddresses,
            enforce: $enforce,
            logger: $logger
        );
    }

    public function isAllowed(ServerRequestInterface $request): bool
    {
        $clientIp = $this->ipResolver->resolveFromRequest($request);

        if ($clientIp === null || $clientIp === '') {
            /* No address to judge. Treated as a mismatch rather than an exemption: a check that passes when it cannot
               run is not a check, and in report mode this still only produces a log line. */
            return $this->reject(null);
        }

        if ($this->allowLocalAddresses && IpUtils::isLocalAddress($clientIp)) {
            return true;
        }

        if (IpUtils::isInAnyCidr($clientIp, $this->allowedIps)) {
            return true;
        }

        return $this->reject($clientIp);
    }

    /**
     * Whether a mismatch actually rejects, rather than being reported and allowed.
     */
    public function isEnforcing(): bool
    {
        return $this->enforce;
    }

    /**
     * Reports a mismatch and answers, according to the mode.
     *
     * @param string|null $clientIp The address that arrived, or null when it could not be determined
     *
     * @return bool Whether the request may proceed
     */
    private function reject(?string $clientIp): bool
    {
        $this->logger?->warning(
            $this->enforce
                ? 'Comfino webhook rejected: source address is not on the allow-list.'
                : 'Comfino webhook from an address that is not on the allow-list; enforcement is off, so it passed.',
            ['client_ip' => $clientIp ?? '(unknown)']
        );

        return !$this->enforce;
    }
}
