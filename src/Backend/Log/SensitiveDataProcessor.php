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

use Comfino\Auth\ExceptionSanitizer;
use Throwable;

/**
 * Sanitizes sensitive data from log records.
 *
 * Register it as a processor on the logger the SDK writes to. It accepts both record shapes: the array Monolog 1/2 and
 * plain PSR-3 bridges pass, and Monolog 3's immutable `LogRecord` (handled by duck typing, so there is no Monolog
 * dependency).
 *
 * - Credentials and national ids (keys matching {@see self::SENSITIVE_PATTERNS}) are replaced with `[REDACTED]`.
 * - Personal data from order payloads (the api-client's {@see ExceptionSanitizer::personalDataFieldNames()}, exact key
 *   match, case-insensitive) is replaced with `[REDACTED]`, so the log and the error report hide the same fields.
 * - Webhook signatures (`cr-signature`, `x-cr-signature`, and the `cr_signature` spelling WordPress uses) are cut to an
 *   8-character prefix: enough to correlate a delivery, not enough to replay it.
 * - Token-shaped string values are masked wherever they appear.
 */
final class SensitiveDataProcessor
{
    private const REDACTED = '[REDACTED]';
    private const SIGNATURE_PREFIX_LENGTH = 8;
    private const MAX_TRACE_LENGTH = 4096;
    private const MAX_PREVIOUS_DEPTH = 5;

    private const SENSITIVE_PATTERNS = [
        '/api[_-]?key/i',
        '/authorization/i',
        '/bearer/i',
        '/token/i',
        '/secret/i',
        '/password/i',
        '/passwd/i',
        '/pwd/i',
        '/card[_-]?number/i',
        '/cvv/i',
        '/cvc/i',
        '/ssn/i',
        '/pesel/i',
        '/nip/i',
        '/session[_-]?id/i',
        '/csrf[_-]?token/i',
    ];

    private const SENSITIVE_HEADERS = [
        'authorization',
        'api-key',
        'x-api-key',
    ];

    /** Matches the header in its HTTP spelling (`CR-Signature`) and in the normalized form WordPress hands plugins (`cr_signature`). */
    private const SIGNATURE_KEY_PATTERN = '/^(x[-_])?cr[-_]signature$/i';

    /** @var array<string, true> Lower-cased exact-match keys */
    private readonly array $exactSensitiveKeys;

    /**
     * @param string[] $additionalSensitiveKeys Platform-specific keys to redact, matched exactly (case-insensitive),
     *                                          e.g. Magento's `vat_id` or `telephone`
     */
    public function __construct(array $additionalSensitiveKeys = [])
    {
        $this->exactSensitiveKeys = array_fill_keys(
            array_map('strtolower', array_merge(ExceptionSanitizer::personalDataFieldNames(), $additionalSensitiveKeys)),
            true
        );
    }

    /**
     * Sanitizes a log record: an array (Monolog 1/2, PSR-3 bridges) or a Monolog 3 `LogRecord`, which is immutable, so a
     * modified copy is returned. Other values are returned unchanged.
     */
    public function __invoke(mixed $record): mixed
    {
        if (is_array($record)) {
            if (isset($record['message']) && is_string($record['message'])) {
                $record['message'] = ExceptionSanitizer::redactText($record['message']);
            }

            $record['context'] = $this->sanitize(is_array($record['context'] ?? null) ? $record['context'] : []);
            $record['extra'] = $this->sanitize(is_array($record['extra'] ?? null) ? $record['extra'] : []);

            return $record;
        }

        if (is_object($record) && method_exists($record, 'with')) {
            $properties = get_object_vars($record);

            if (is_array($properties['context'] ?? null) && is_array($properties['extra'] ?? null)) {
                return $record->with(
                    message: is_string($properties['message'] ?? null)
                        ? ExceptionSanitizer::redactText($properties['message'])
                        : ($properties['message'] ?? ''),
                    context: $this->sanitize($properties['context']),
                    extra: $this->sanitize($properties['extra'])
                );
            }
        }

        return $record;
    }

    /**
     * Sanitizes structured data with the same rules as a record's context.
     *
     * @param array<string|int, mixed> $data
     *
     * @return array<string|int, mixed>
     */
    public function sanitizeData(array $data): array
    {
        return $this->sanitize($data);
    }

    /**
     * Masks sensitive values inside free text, e.g. a line read back from a log file that was written before this
     * processor was registered: JSON/`key=value` pairs whose key is sensitive, e-mail addresses, signature-shaped hex
     * strings and JWTs.
     */
    public function sanitizeText(string $text): string
    {
        // "key": "value" / "key":123 pairs in JSON fragments.
        $text = preg_replace_callback(
            '/"([A-Za-z0-9_-]+)"\s*:\s*("(?:[^"\\\\]|\\\\.)*"|-?[0-9.]+)/',
            fn (array $m): string => $this->isSensitiveKey($m[1]) || $this->isSignatureKey($m[1])
                ? '"' . $m[1] . '":"' . ($this->isSignatureKey($m[1]) ? $this->truncateSignature(trim($m[2], '"')) : self::REDACTED) . '"'
                : $m[0],
            $text
        );

        // key=value and key: value pairs in headers or query strings.
        $text = (string) preg_replace_callback(
            '/\b([A-Za-z0-9_-]+)(\s*[=:]\s*)([^\s&,;"]+)/',
            fn (array $m): string => match (true) {
                $this->isSignatureKey($m[1]) => $m[1] . $m[2] . $this->truncateSignature($m[3]),
                $this->isSensitiveKey($m[1]) => $m[1] . $m[2] . self::REDACTED,
                default => $m[0],
            },
            $text
        );

        $text = preg_replace('/[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}/', '[REDACTED_EMAIL]', $text);
        $text = preg_replace('/\beyJ[A-Za-z0-9_-]+\.eyJ[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+/', self::REDACTED, $text);

        // 64-hex values are SHA3-256 signatures or tokens.
        return preg_replace_callback(
            '/\b[a-f0-9]{64}\b/i',
            fn (array $m): string => $this->truncateSignature($m[0]),
            $text
        );
    }

    /**
     * @param array<string|int, mixed> $data
     *
     * @return array<string|int, mixed>
     */
    private function sanitize(array $data, bool $underSignatureKey = false): array
    {
        $sanitized = [];

        foreach ($data as $key => $value) {
            $isSignatureKey = $underSignatureKey || (is_string($key) && $this->isSignatureKey($key));

            if (!$isSignatureKey && is_string($key) && $this->isSensitiveKey($key)) {
                $sanitized[$key] = self::REDACTED;

                continue;
            }

            if (is_array($value)) {
                $sanitized[$key] = $this->sanitize($value, $isSignatureKey);
            } elseif ($value instanceof Throwable) {
                $sanitized[$key] = $this->describeThrowable($value);
            } elseif (is_string($value)) {
                $sanitized[$key] = $isSignatureKey ? $this->truncateSignature($value) : $this->sanitizeString($value);
            } else {
                $sanitized[$key] = $value;
            }
        }

        return $sanitized;
    }

    /**
     * Replaces an exception with plain data. API client exception messages can quote a response body, which holds
     * e-mail addresses and phone numbers, and a formatter would print the message and trace as they are.
     *
     * @return array<string, mixed>
     */
    private function describeThrowable(Throwable $error, int $depth = 0): array
    {
        $description = [
            'class' => get_class($error),
            'message' => ExceptionSanitizer::redactText($error->getMessage()),
            'code' => $error->getCode(),
            'file' => basename($error->getFile()) . ':' . $error->getLine(),
            'trace' => ExceptionSanitizer::truncate(ExceptionSanitizer::redactText($error->getTraceAsString()), self::MAX_TRACE_LENGTH),
        ];

        if ($depth < self::MAX_PREVIOUS_DEPTH && $error->getPrevious() !== null) {
            $description['previous'] = $this->describeThrowable($error->getPrevious(), $depth + 1);
        }

        return $description;
    }

    private function isSignatureKey(string $key): bool
    {
        return preg_match(self::SIGNATURE_KEY_PATTERN, $key) === 1;
    }

    private function isSensitiveKey(string $key): bool
    {
        $keyLower = strtolower($key);

        if (isset($this->exactSensitiveKeys[$keyLower]) || in_array($keyLower, self::SENSITIVE_HEADERS, true)) {
            return true;
        }

        foreach (self::SENSITIVE_PATTERNS as $pattern) {
            if (preg_match($pattern, $key)) {
                return true;
            }
        }

        return false;
    }

    private function truncateSignature(string $value): string
    {
        return strlen($value) > self::SIGNATURE_PREFIX_LENGTH ? substr($value, 0, self::SIGNATURE_PREFIX_LENGTH) . '…' : $value;
    }

    private function sanitizeString(string $value): string
    {
        if (strlen($value) < 8) {
            return $value;
        }

        if ($this->looksLikeSensitiveValue($value)) {
            return $this->maskString($value);
        }

        return $value;
    }

    private function looksLikeSensitiveValue(string $value): bool
    {
        if (preg_match('/^[a-zA-Z0-9_-]{32,}$/', $value)) {
            return true;
        }

        if (preg_match('/^eyJ[a-zA-Z0-9_-]+\.eyJ[a-zA-Z0-9_-]+\.[a-zA-Z0-9_-]+$/', $value)) {
            return true;
        }

        if (preg_match('/^[A-Za-z0-9+\/]{20,}={0,2}$/', $value) && strlen($value) > 30) {
            return true;
        }

        return false;
    }

    private function maskString(string $value): string
    {
        $length = strlen($value);

        if ($length <= 8) {
            return self::REDACTED;
        }

        return substr($value, 0, 4) . '...' . substr($value, -4);
    }
}
