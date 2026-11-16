<?php

/**
 * ComfinoPay PHP SDK
 *
 * Backend routines for e-commerce platforms integration with the ComfinoPay payment gateway API.
 *
 * @package Comfino\Backend\Webhook
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
 * @copyright Copyright (c) 2026 by ComfinoPay sp. z o.o.
 * @license https://opensource.org/licenses/BSD-3-Clause BSD 3-Clause License
 * @link https://github.com/comfino/php-sdk
 */

declare(strict_types=1);

namespace Comfino\Backend\Webhook;

use InvalidArgumentException;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Resolves the effective client IP behind reverse-proxy services (e.g., Cloudflare, Nginx, AWS ALB).
 *
 * When REMOTE_ADDR belongs to a trusted-proxy range, the hop chain is read from X-Forwarded-For (or, when that header is
 * absent, X-Real-IP) from the right: each proxy appends the address it received the request from, so the entries on the
 * right were written by infrastructure the host trusts, while the leftmost ones are whatever the client sent. The first
 * entry from the right that is not itself a trusted proxy is the client. A malformed entry ends the search, and the
 * furthest hop verified so far is returned instead.
 *
 * CF-Connecting-IP is honored only when the hop that reached the host's infrastructure (REMOTE_ADDR, or the address
 * found in the chain) is a Cloudflare edge address ({@see IpUtils::CLOUDFLARE_CIDRS}). Anyone can send the header; only
 * a request that really came through Cloudflare carries a value Cloudflare wrote. Hosts not on Cloudflare can pass an
 * empty list as `$cloudflareCidrs`.
 *
 * A host whose proxy passes X-Forwarded-For or X-Real-IP through from the client unchanged should restrict the honored
 * headers with {@see self::withTrustedProxyHeaders()} to those the proxy really sets.
 *
 * Defaults to treating all local/private ranges as trusted proxies, which matches typical shop deployments where
 * PHP-FPM sits behind a local web server. Override via {@see self::withTrustedProxies()} when the application is
 * fronted by additional public-IP proxies.
 *
 * Two entry points are exposed so plugins can use whichever fits their stack:
 *   - {@see self::resolveFromRequest()} — PSR-7, for PSR-15 / Slim / Mezzio style integrations.
 *   - {@see self::resolveFromServerParams()} — raw $_SERVER + headers, for legacy shop platforms (PrestaShop,
 *     OpenCart, WooCommerce action hooks, plain PHP entry points).
 *
 * Example — ComfinoPay default setup:
 *
 *   $clientIp = ClientIpResolver::default()->resolveFromServerParams($_SERVER);
 *
 * Example — custom setup with additional trusted public-IP proxies (e.g., a cloud load balancer):
 *
 *   $resolver = ClientIpResolver::withTrustedProxies([...IpUtils::LOCAL_CIDRS, '10.20.0.0/16']);
 *   $clientIp = $resolver->resolveFromRequest($request);
 */
final class ClientIpResolver
{
    /** Forwarding headers the resolver understands. */
    private const FORWARDED_FOR_HEADERS = ['CF-Connecting-IP', 'X-Forwarded-For', 'X-Real-IP'];

    /** @var array<string, int> Lowercased names of the honored forwarding headers (as keys). */
    private readonly array $honoredHeaders;

    /**
     * @param string[] $trustedProxyCidrs CIDR ranges whose REMOTE_ADDR triggers proxy-header inspection. Defaults to
     *                                    all local/private ranges.
     * @param string[] $trustedHeaders Forwarding headers honored (case-insensitive), any of {@see self::FORWARDED_FOR_HEADERS}.
     *                                 Defaults to all of them.
     * @param string[] $cloudflareCidrs Edge ranges whose CF-Connecting-IP is believed. Defaults to Cloudflare's published
     *                                  ranges; pass an empty list to ignore the header entirely.
     *
     * @throws InvalidArgumentException When $trustedHeaders names a header the resolver does not understand
     */
    public function __construct(
        private readonly array $trustedProxyCidrs = IpUtils::LOCAL_CIDRS,
        array $trustedHeaders = self::FORWARDED_FOR_HEADERS,
        private readonly array $cloudflareCidrs = IpUtils::CLOUDFLARE_CIDRS
    ) {
        $known = array_map('strtolower', self::FORWARDED_FOR_HEADERS);
        $honored = array_map('strtolower', $trustedHeaders);
        $unknown = array_filter($trustedHeaders, static fn (string $name): bool => !in_array(strtolower($name), $known, true));

        if ($unknown !== []) {
            throw new InvalidArgumentException(sprintf(
                'Unsupported forwarding header(s): %s. Supported: %s.',
                implode(', ', $unknown),
                implode(', ', self::FORWARDED_FOR_HEADERS)
            ));
        }

        $this->honoredHeaders = array_flip($honored);
    }

    /**
     * Creates a resolver pre-configured to treat all local/private ranges as trusted proxies.
     *
     * Suitable for typical shop deployments where PHP-FPM is reached only via a local web server.
     */
    public static function default(): self
    {
        return new self();
    }

    /**
     * Creates a resolver with a custom set of trusted-proxy CIDR ranges.
     *
     * Use this when the application sits behind a non-local proxy (cloud load balancer, public-IP CDN edge, etc.)
     * whose address must be added to the trusted set in order for its forwarded headers to be honored.
     *
     * @param string[] $trustedProxyCidrs CIDR ranges whose REMOTE_ADDR triggers proxy-header inspection
     */
    public static function withTrustedProxies(array $trustedProxyCidrs): self
    {
        return new self($trustedProxyCidrs);
    }

    /**
     * Creates a resolver that honors only the listed forwarding headers.
     *
     * Use it when the proxy in front of the application overwrites some headers but passes others through from the
     * client, e.g. `['X-Forwarded-For']` behind a proxy that does not set CF-Connecting-IP or X-Real-IP itself.
     *
     * @param string[] $trustedProxyCidrs CIDR ranges whose REMOTE_ADDR triggers proxy-header inspection
     * @param string[] $trustedHeaders Any of 'CF-Connecting-IP', 'X-Forwarded-For', 'X-Real-IP'
     *
     * @throws InvalidArgumentException When a header is not one of those
     */
    public static function withTrustedProxyHeaders(array $trustedProxyCidrs, array $trustedHeaders): self
    {
        return new self($trustedProxyCidrs, $trustedHeaders);
    }

    /**
     * Resolves the effective client IP from a PSR-7 server request.
     *
     * Returns null when REMOTE_ADDR is missing or empty (e.g., a request constructed in tests without server params).
     */
    public function resolveFromRequest(ServerRequestInterface $request): ?string
    {
        $remoteAddr = $request->getServerParams()['REMOTE_ADDR'] ?? '';

        // Normalize header names to lowercase for case-insensitive lookup.
        $normalizedHeaders = [];

        foreach (self::FORWARDED_FOR_HEADERS as $name) {
            if ($request->hasHeader($name)) {
                /* All lines joined: a proxy that adds its own X-Forwarded-For line instead of appending to the client's
                   one must not leave the client-supplied first line in charge. */
                $normalizedHeaders[strtolower($name)] = $request->getHeaderLine($name);
            }
        }

        return $this->resolveFromNormalizedInputs($remoteAddr, $normalizedHeaders);
    }

    /**
     * Resolves the effective client IP from raw server params and headers — the PSR-7-free variant.
     *
     * Designed for legacy shop platforms that don't materialize a PSR-7 request object at the webhook entry point.
     * Pass $_SERVER as $serverParams; headers may be passed explicitly or, when omitted, are extracted from the
     * HTTP_* keys of $serverParams the same way PHP populates them itself.
     *
     * Header lookup is case-insensitive. Returns null when REMOTE_ADDR is missing or empty.
     *
     * @param array<string, mixed> $serverParams Typically $_SERVER. Must contain REMOTE_ADDR.
     * @param array<string, string> $headers Header name → value (single value per header). When empty, headers are
     *                                       extracted from HTTP_* entries of $serverParams.
     */
    public function resolveFromServerParams(array $serverParams, array $headers = []): ?string
    {
        $remoteAddr = isset($serverParams['REMOTE_ADDR']) ? (string) $serverParams['REMOTE_ADDR'] : '';

        /* Build a lowercase-keyed header map for case-insensitive lookup. When the caller does not supply headers,
           derive them from HTTP_* keys in $serverParams as PHP itself does. */
        $normalizedHeaders = $headers === []
            ? self::extractHeadersFromServerParams($serverParams)
            : self::normalizeHeaderKeys($headers);

        return $this->resolveFromNormalizedInputs($remoteAddr, $normalizedHeaders);
    }

    /**
     * Shared resolution algorithm — operates on already-normalized inputs.
     *
     * Both public entry points reduce their input to a (REMOTE_ADDR, lowercase-keyed header map) pair and delegate
     * here, so the trusted-proxy / header-priority logic lives in exactly one place.
     *
     * @param string $remoteAddr Value of REMOTE_ADDR (empty string when missing).
     * @param array<string, string> $headers Header name (lowercased) → single value.
     */
    private function resolveFromNormalizedInputs(string $remoteAddr, array $headers): ?string
    {
        if ($remoteAddr === '') {
            return null;
        }

        $headers = array_intersect_key($headers, $this->honoredHeaders);

        // Not behind a trusted proxy: REMOTE_ADDR is the peer that reached the host.
        $peer = IpUtils::isInAnyCidr($remoteAddr, $this->trustedProxyCidrs)
            ? $this->resolveBehindTrustedProxies($remoteAddr, $headers)
            : $remoteAddr;

        // CF-Connecting-IP only counts when the peer is a Cloudflare edge; from anyone else it is client-chosen.
        if (isset($headers['cf-connecting-ip']) && IpUtils::isInAnyCidr($peer, $this->cloudflareCidrs)) {
            $ip = trim($headers['cf-connecting-ip']);

            if (IpUtils::isValidIp($ip)) {
                return $ip;
            }
        }

        return $peer;
    }

    /**
     * Walks the hop chain from the right and returns the first address that is not a trusted proxy.
     *
     * The chain is X-Forwarded-For or, when that is absent, X-Real-IP. When the walk meets a malformed entry or runs
     * out of entries, the furthest hop verified so far (at worst REMOTE_ADDR) is returned, never a client-supplied value.
     *
     * @param string $remoteAddr REMOTE_ADDR, already known to be a trusted proxy
     * @param array<string, string> $headers Honored headers, lowercased names
     */
    private function resolveBehindTrustedProxies(string $remoteAddr, array $headers): string
    {
        if (isset($headers['x-forwarded-for'])) {
            $chain = explode(',', $headers['x-forwarded-for']);
        } elseif (isset($headers['x-real-ip'])) {
            $chain = [$headers['x-real-ip']];
        } else {
            return $remoteAddr;
        }

        $furthestVerified = $remoteAddr;

        foreach (array_reverse(array_map('trim', $chain)) as $candidate) {
            if (!IpUtils::isValidIp($candidate)) {
                break;
            }

            if (!IpUtils::isInAnyCidr($candidate, $this->trustedProxyCidrs)) {
                return $candidate;
            }

            $furthestVerified = $candidate;
        }

        return $furthestVerified;
    }

    /**
     * Returns a lowercase-keyed header map extracted from HTTP_* entries of a $_SERVER-like array.
     *
     * @param array<string, mixed> $serverParams
     * @return array<string, string>
     */
    private static function extractHeadersFromServerParams(array $serverParams): array
    {
        $headers = [];

        foreach ($serverParams as $key => $value) {
            // Standard PHP convention: request headers are exposed as HTTP_<UPPERCASE_NAME_WITH_UNDERSCORES>.
            if (str_starts_with($key, 'HTTP_')) {
                $name = strtolower(str_replace('_', '-', substr($key, 5)));
                $headers[$name] = (string) $value;
            }
        }

        return $headers;
    }

    /**
     * Normalizes a caller-supplied header map to lowercase keys for case-insensitive lookup.
     *
     * @param array<string, string> $headers
     * @return array<string, string>
     */
    private static function normalizeHeaderKeys(array $headers): array
    {
        $normalized = [];

        foreach ($headers as $name => $value) {
            $normalized[strtolower($name)] = $value;
        }

        return $normalized;
    }
}
