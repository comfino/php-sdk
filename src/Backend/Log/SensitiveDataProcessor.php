<?php

/**
 * Comfino PHP SDK
 *
 * Backend routines for e-commerce platforms integration with the Comfino payment gateway API.
 *
 * @package Comfino\Backend\Log
 * @author Artur Kozubski <akozubski@comperia.pl>
 * @copyright Copyright (c) 2026 Comfino by Comperia.pl S.A.
 * @license https://opensource.org/licenses/BSD-3-Clause BSD 3-Clause License
 * @link https://github.com/comfino/php-sdk
 */

declare(strict_types=1);

namespace Comfino\Backend\Log;

/**
 * Sanitizes sensitive data from log records.
 *
 * This processor can be used with any PSR-3 logger or Monolog by registering it as a processor.
 * Platform SDKs using Monolog can cast this to Monolog\Processor\ProcessorInterface since this
 * class implements __invoke(array): array which is the actual interface contract.
 */
final class SensitiveDataProcessor
{
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

    private const UNMASKED_KEY_PATTERNS = [
        '/cr-signature/i',
    ];

    /**
     * @param array<string, mixed> $records
     *
     * @return array<string, mixed>
     */
    public function __invoke(array $records): array
    {
        $records['context'] = $this->sanitize($records['context'] ?? []);
        $records['extra'] = $this->sanitize($records['extra'] ?? []);

        return $records;
    }

    /**
     * @param array<string|int, mixed> $data
     *
     * @return array<string|int, mixed>
     */
    private function sanitize(array $data, string|int|null $parentKey = null): array
    {
        $sanitized = [];

        foreach ($data as $key => $value) {
            if (is_string($key) && $this->isSensitiveKey($key)) {
                $sanitized[$key] = '[REDACTED]';

                continue;
            }

            if (is_string($key) || is_string($parentKey)) {
                foreach (self::UNMASKED_KEY_PATTERNS as $keyPattern) {
                    if (
                        (is_string($key) && preg_match($keyPattern, $key)) ||
                        (is_string($parentKey) && preg_match($keyPattern, $parentKey))
                    ) {
                        $sanitized[$key] = $value;

                        continue 2;
                    }
                }
            }

            if (is_array($value)) {
                $sanitized[$key] = $this->sanitize($value, $key);
            } elseif (is_string($value)) {
                $sanitized[$key] = $this->sanitizeString($value);
            } else {
                $sanitized[$key] = $value;
            }
        }

        return $sanitized;
    }

    private function isSensitiveKey(string $key): bool
    {
        $keyLower = strtolower($key);

        if (in_array($keyLower, self::SENSITIVE_HEADERS, true)) {
            return true;
        }

        foreach (self::SENSITIVE_PATTERNS as $pattern) {
            if (preg_match($pattern, $key)) {
                return true;
            }
        }

        return false;
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
            return '[REDACTED]';
        }

        return substr($value, 0, 4) . '...' . substr($value, -4);
    }
}
