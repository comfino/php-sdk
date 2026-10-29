<?php

/**
 * ComfinoPay PHP SDK
 *
 * Backend routines for e-commerce platforms integration with the ComfinoPay payment gateway API.
 *
 * @package Comfino\Backend\Log
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
 * @copyright Copyright (c) 2026 by ComfinoPay sp. z o.o.
 * @license https://opensource.org/licenses/BSD-3-Clause BSD 3-Clause License
 * @link https://github.com/comfino/php-sdk
 */

declare(strict_types=1);

namespace Comfino\Backend\Log;

/**
 * Turns an API error response body into text short and readable enough for a log line or an error report.
 *
 * An API error can be a whole HTML page (a framework debug page, a proxy error page): hundreds of kilobytes of CSS and scripts
 * around one useful sentence. HTML loses its non-text blocks and tags and is prefixed with {@see HTML_PREFIX}; any body is cut
 * at a character limit. JSON and plain text are kept, and so are null and the empty string.
 */
final class ResponseBodySummarizer
{
    public const HTML_PREFIX = '[HTML response] ';
    public const TRUNCATION_SUFFIX = '... [truncated]';
    public const DEFAULT_MAX_LENGTH = 2000;

    private const HTML_MARKER_PATTERN = '/<!doctype\s+html|<\s*(html|head|body|style|script)[\s>]/i';
    private const NON_TEXT_BLOCKS_PATTERN = '#<(style|script|svg|head)\b[^>]*>.*?</\1\s*>#is';
    private const PLACEHOLDER_PATTERN = '/^\[REDACTED: \d+ bytes, not JSON\]$/';

    private function __construct()
    {
    }

    public static function summarize(?string $body, int $maxLength = self::DEFAULT_MAX_LENGTH): ?string
    {
        if ($body === null || $body === '' || preg_match(self::PLACEHOLDER_PATTERN, $body) === 1) {
            return $body;
        }

        if (preg_match(self::HTML_MARKER_PATTERN, $body) === 1) {
            $text = preg_replace(self::NON_TEXT_BLOCKS_PATTERN, ' ', $body) ?? $body;
            $text = preg_replace('/<!--.*?-->/s', ' ', $text) ?? $text;
            $text = preg_replace('/<[^>]*>/', ' ', $text) ?? $text;
            $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $text = trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
            $body = self::HTML_PREFIX . $text;
        }

        if (mb_strlen($body, 'UTF-8') > $maxLength) {
            return mb_substr($body, 0, $maxLength, 'UTF-8') . self::TRUNCATION_SUFFIX;
        }

        return $body;
    }
}
