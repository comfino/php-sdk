<?php

/**
 * Comfino PHP SDK
 *
 * Backend routines for e-commerce platforms integration with the Comfino payment gateway API.
 *
 * @package Comfino\Backend\Store
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
 * @copyright Copyright (c) 2026 Comfino by Comperia.pl S.A.
 * @license https://opensource.org/licenses/BSD-3-Clause BSD 3-Clause License
 * @link https://github.com/comfino/php-sdk
 */

declare(strict_types=1);

namespace Comfino\Backend\Store;

/**
 * Turns a resilience key into one a PSR-6 pool will accept, without ever mapping two keys onto one row.
 *
 * PSR-6 reserves `{}()/\@:` and only guarantees 64 characters, while the keys these stores are handed are things like
 * `tenant-uuid|api-ecommerce.comfino.pl` and `status|203.0.113.7|tenant-uuid` — which contain reserved characters and
 * can exceed the length. Passing them through unchanged works on some pools and throws on others, which is the worst
 * of the available behaviors.
 *
 * **Sanitizing alone is not enough, and getting that wrong is a cross-tenant bug.** Replacing every disallowed
 * character with `_` maps `shop-a|host` and `shop-a/host` onto the same row — and for these two stores a shared row
 * means one tenant's breaker state or token bucket answering for another's. So every key carries a digest of the
 * *original* string, and the sanitized text in front of it is there purely, so a human reading the cache can tell which
 * merchant a row belongs to.
 */
final class Psr6StoreKey
{
    /** PSR-6 guarantees support for keys of at least this length. */
    private const MAX_LENGTH = 64;

    /** Hex characters of the digest kept. 12 hexes are 48 bits: collision-free for any plausible number of live keys. */
    private const DIGEST_LENGTH = 12;

    /**
     * All-static utility class — instantiation is intentionally disabled.
     *
     * @codeCoverageIgnore
     */
    private function __construct()
    {
    }

    /**
     * Returns a PSR-6-safe key that maps one-to-one onto $key.
     *
     * @param string $prefix Short namespace for the kind of state, so a breaker and a limiter cannot collide
     * @param string $key The caller's key
     */
    public static function build(string $prefix, string $key): string
    {
        $digest = substr(sha1($key), 0, self::DIGEST_LENGTH);
        $readable = preg_replace('/[^A-Za-z0-9_.]/', '_', $key);

        // Whatever is left after the prefix, the digest and the two separators can be spent on readability.
        $readableBudget = self::MAX_LENGTH - strlen($prefix) - self::DIGEST_LENGTH - 2;

        if ($readableBudget < 1) {
            return $prefix . '.' . $digest;
        }

        return $prefix . '.' . substr($readable, 0, $readableBudget) . '_' . $digest;
    }
}
