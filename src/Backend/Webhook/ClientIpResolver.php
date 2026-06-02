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
 * Resolves the effective client IP behind reverse-proxy services (e.g., Cloudflare, Nginx, AWS ALB).
 *
 * When REMOTE_ADDR belongs to a trusted-proxy range, the real client IP is resolved from the following headers in
 * priority order:
 *   1. CF-Connecting-IP — set by Cloudflare.
 *   2. X-Forwarded-For — leftmost non-trusted-proxy entry.
 *   3. X-Real-IP — set by Nginx and many other proxies.
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
 * Example — Comfino default setup:
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
    /** Headers consulted, in priority order, when REMOTE_ADDR belongs to a trusted-proxy range. */
    private const FORWARDED_FOR_HEADERS = ['CF-Connecting-IP', 'X-Forwarded-For', 'X-Real-IP'];

    /**
     * @param string[] $trustedProxyCidrs CIDR ranges whose REMOTE_ADDR triggers proxy-header inspection. Defaults to
     *                                    all local/private ranges.
     */
    public function __construct(private readonly array $trustedProxyCidrs = IpUtils::LOCAL_CIDRS)
    {
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
                $normalizedHeaders[strtolower($name)] = $request->getHeader($name)[0];
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

        if (!IpUtils::isInAnyCidr($remoteAddr, $this->trustedProxyCidrs)) {
            // Not behind a trusted proxy — trust REMOTE_ADDR directly.
            return $remoteAddr;
        }

        // CF-Connecting-IP (Cloudflare and compatible CDNs).
        if (isset($headers['cf-connecting-ip'])) {
            $ip = trim($headers['cf-connecting-ip']);

            if (IpUtils::isValidIp($ip)) {
                return $ip;
            }
        }

        /* X-Forwarded-For: pick the leftmost address that is not itself a trusted proxy, which is the original client
           as seen before any proxy in the chain. */
        if (isset($headers['x-forwarded-for'])) {
            foreach (array_map('trim', explode(',', $headers['x-forwarded-for'])) as $candidate) {
                if (
                    IpUtils::isValidIp($candidate) &&
                    !IpUtils::isInAnyCidr($candidate, $this->trustedProxyCidrs)
                ) {
                    return $candidate;
                }
            }
        }

        // X-Real-IP (Nginx and many other proxies).
        if (isset($headers['x-real-ip'])) {
            $ip = trim($headers['x-real-ip']);

            if (IpUtils::isValidIp($ip)) {
                return $ip;
            }
        }

        return $remoteAddr;
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
