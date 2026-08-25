<?php

/**
 * Comfino PHP SDK
 *
 * Backend routines for e-commerce platforms integration with the Comfino payment gateway API.
 *
 * @package Comfino\Backend\Log
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
 * @copyright Copyright (c) 2026 Comfino by Comperia.pl S.A.
 * @license https://opensource.org/licenses/BSD-3-Clause BSD 3-Clause License
 * @link https://github.com/comfino/php-sdk
 */

declare(strict_types=1);

namespace Comfino\Backend\Log;

/**
 * Pre-normalizes error messages and stack traces before they are transmitted.
 *
 * Goals:
 *   - Remove dynamic parts that prevent deduplication (timestamps, timeout values, "called in ... on line N" suffixes).
 *   - Strip shop-specific filesystem prefixes from messages and stack traces while keeping the plugin-root anchor (e.g.
 *     "plugins/comfino-payment-gateway", "modules/comfino") and the plugin-relative path below it — the API side's
 *     origin filter matches on that anchor text, so it must survive normalization.
 *   - Do NOT lowercase or strip long integers — those transformations are the API side's responsibility.
 *
 * The rules here intentionally do less than the API-side normalizer so that the raw transmitted message remains
 * human-readable; the API produces error_message_norm separately for fingerprinting.
 */
final class ErrorMessageNormalizer
{
    /**
     * Strip "Communication error [TIMESTAMP]:" timestamp bracket.
     * "Communication error [1729683272]: " → "Communication error: "
     */
    private const PATTERN_COMM_TIMESTAMP = '/Communication error \[\d+]:/';
    private const REPLACE_COMM_TIMESTAMP = 'Communication error:';

    /**
     * Normalize timeout durations: "after 8001 milliseconds" → "after {N}ms"
     */
    private const PATTERN_TIMEOUT = '/after \d+ milliseconds/';
    private const REPLACE_TIMEOUT = 'after {N}ms';

    /**
     * Normalize byte counts: "with 7952 bytes received" → "with {N} bytes"
     */
    private const PATTERN_BYTES = '/with \d+ bytes( received)?/';
    private const REPLACE_BYTES = 'with {N} bytes';

    /**
     * Strip the PHP ", called in /path/to/file.php on line 109" suffix.
     * This information is redundant with the stack trace and varies per shop.
     */
    private const PATTERN_CALLED_IN = '/, called in .+? on line \d+/s';

    /**
     * Known plugin root path patterns — strip the server-specific absolute filesystem prefix up to (but not including)
     * these anchors, keeping the anchor itself plus the plugin-relative path below it.
     *
     * The match starts at the leading slash of the path (whitespace-delimited), so any frame marker preceding the path
     * (e.g. "#0 ") is preserved. The anchor is captured and kept in the replacement so downstream consumers (notably
     * the API-side origin filter, which matches on "plugins/comfino", "modules/comfino", etc.) keep working after
     * normalization:
     *   #0 /home/user/.../wp-content/plugins/comfino-payment-gateway/src/Foo.php
     *     → #0 plugins/comfino-payment-gateway/src/Foo.php
     *   #1 /var/www/html/modules/comfino/src/Foo.php → #1 modules/comfino/src/Foo.php
     *   #2 /var/www/.../app/code/Comfino/ComfinoGateway/Model/Foo.php
     *     → #2 app/code/Comfino/ComfinoGateway/Model/Foo.php
     *   #3 /var/www/.../vendor/comfino/magento2/Model/Foo.php → #3 vendor/comfino/magento2/Model/Foo.php
     *
     * The last anchor matches any Composer-installed comfino package (vendor/comfino/<package>/) — Comfino plugins and
     * its dependencies are installed there.
     *
     * @var string[]
     */
    private const PLUGIN_ROOT_PATTERNS = [
        '~/\S*/(wp-content/plugins/comfino-payment-gateway/)~',
        '~/\S*/(modules/comfino/)~',
        '~/\S*/(app/code/Comfino/ComfinoGateway/)~',
        '~/\S*/(vendor/comfino/[^/\s]+/)~',
    ];

    /**
     * Normalize an error message string.
     *
     * Strips: communication-error timestamps, timeout/byte-count values, "called in … on line N" tails, and
     * server-specific filesystem prefixes (keeping the plugin-root anchor and relative path). Does NOT modify exception
     * class names, line numbers, or error descriptions.
     *
     * @param string $message Raw error message
     *
     * @return string Normalized error message
     */
    public function normalizeMessage(string $message): string
    {
        $message = self::pregReplace(self::PATTERN_COMM_TIMESTAMP, self::REPLACE_COMM_TIMESTAMP, $message);
        $message = self::pregReplace(self::PATTERN_TIMEOUT, self::REPLACE_TIMEOUT, $message);
        $message = self::pregReplace(self::PATTERN_BYTES, self::REPLACE_BYTES, $message);
        $message = self::pregReplace(self::PATTERN_CALLED_IN, '', $message);
        $message = self::stripServerPathPrefix($message);

        return trim($message);
    }

    /**
     * Strip shop-specific filesystem prefixes from a PHP stack trace, keeping the plugin-root anchor.
     *
     * Before: #0 /home/user/domains/shop.pl/public_html/wp-content/plugins/comfino-payment-gateway/src/Foo.php(42)
     * After:  #0 plugins/comfino-payment-gateway/src/Foo.php(42)
     *
     * Lines that do not contain a known plugin root are left unchanged.
     *
     * @param string $stackTrace Raw stack trace
     *
     * @return string Stack trace with shop-specific prefixes stripped
     */
    public function normalizeStackTrace(string $stackTrace): string
    {
        return self::stripServerPathPrefix($stackTrace);
    }

    /**
     * Replace the server-specific portion of any known plugin root path with just the anchor, in both directions:
     * the leading absolute prefix is dropped, the anchor and everything below it are kept.
     */
    private static function stripServerPathPrefix(string $subject): string
    {
        foreach (self::PLUGIN_ROOT_PATTERNS as $pattern) {
            $subject = self::pregReplace($pattern, '$1', $subject);
        }

        return $subject;
    }

    /**
     * Type-narrowing wrapper around preg_replace() for string subjects: falls back to the original subject on error
     * instead of returning preg_replace()'s string|array|null union.
     */
    private static function pregReplace(string $pattern, string $replacement, string $subject): string
    {
        return is_string($result = preg_replace($pattern, $replacement, $subject)) ? $result : $subject;
    }
}
