<?php

/**
 * Comfino PHP SDK
 *
 * Backend routines for e-commerce platforms integration with the Comfino payment gateway API.
 *
 * @package Comfino\Backend\Payment
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
 * @copyright Copyright (c) 2026 Comfino by Comperia.pl S.A.
 * @license https://opensource.org/licenses/BSD-3-Clause BSD 3-Clause License
 * @link https://github.com/comfino/php-sdk
 */

declare(strict_types=1);

namespace Comfino\Backend\Payment;

use Comfino\Api\Exception\ResponseValidationError;
use Comfino\Api\Serializer\Json as JsonSerializer;
use Comfino\Api\SerializerInterface;
use Comfino\Enum\LoanType;

/**
 * Validates and canonicalizes the raw "installment term limits" JSON configuration.
 *
 * This is the generic, platform-agnostic counterpart to {@see AllowedProductsConfigBuilder}: it validates the raw
 * textarea value each plugin persists (JSON syntax, top-level array shape, known product type, positive term integers,
 * minTerm <= maxTerm, term dedup) and normalizes it to canonical JSON (null/empty fields omitted, no-op entries
 * dropped) so the stored value round-trips through {@see AllowedProductsConfigBuilder::fromPersistedArray()}.
 *
 * Failures are reported as stable keys plus ordered interpolation params (position, type, values) so each plugin can
 * render its own translated, customer-facing message without duplicating the validation logic.
 *
 * @phpstan-type ErrorEntry array{key: string, params: array<int, mixed>}
 */
final class AllowedProductsConfigValidator
{
    /** Top-level value is not valid JSON. Params: [json_last_error_msg]. */
    public const ERROR_INVALID_JSON = 'invalidJson';
    /** Top-level value is not a JSON array of entries. Params: []. */
    public const ERROR_NOT_ARRAY = 'notArray';
    /** An entry is not an object. Params: [position]. */
    public const ERROR_ENTRY_NOT_OBJECT = 'entryNotObject';
    /** An entry is missing the required "type" field. Params: [position]. */
    public const ERROR_MISSING_TYPE = 'missingType';
    /** An entry references an unknown product type. Params: [position, type]. */
    public const ERROR_UNKNOWN_TYPE = 'unknownType';
    /** minTerm/maxTerm is not a positive integer. Params: [position, fieldName]. */
    public const ERROR_FIELD_NOT_POSITIVE_INT = 'fieldNotPositiveInt';
    /** minTerm is greater than maxTerm. Params: [position, type, minTerm, maxTerm]. */
    public const ERROR_MIN_TERM_GREATER_THAN_MAX_TERM = 'minTermGreaterThanMaxTerm';
    /** "terms" is not a JSON array. Params: [position, type]. */
    public const ERROR_TERMS_NOT_ARRAY = 'termsNotArray';
    /** A "terms" element is not a positive integer. Params: [position, type, index]. */
    public const ERROR_TERM_NOT_POSITIVE_INT = 'termNotPositiveInt';

    private readonly SerializerInterface $serializer;

    public function __construct(?SerializerInterface $serializer = null)
    {
        $this->serializer = $serializer ?? new JsonSerializer();
    }

    /**
     * Validates the raw JSON and, when valid, returns its canonical form.
     *
     * Validation stops at the first failure (matching the plugin's throw-on-first-error behavior); the returned
     * `errors` array then holds exactly one entry. On success `errors` is empty and `normalizedJson` holds the
     * canonical JSON — an empty string when the input is empty, an empty array, or contains only no-op entries.
     *
     * @param string $rawJson Raw textarea value as persisted by the shop
     *
     * @return array{valid: bool, errors: list<ErrorEntry>, normalizedJson: ?string}
     */
    public function validateAndNormalize(string $rawJson): array
    {
        $raw = trim($rawJson);

        if ($raw === '') {
            return self::success('');
        }

        try {
            $decoded = $this->serializer->unserialize($raw);
        } catch (ResponseValidationError $e) {
            return self::failure(self::ERROR_INVALID_JSON, [($e->getPrevious() ?? $e)->getMessage()]);
        }

        if (!is_array($decoded) || ($decoded !== [] && !array_is_list($decoded))) {
            return self::failure(self::ERROR_NOT_ARRAY, []);
        }

        if ($decoded === []) {
            return self::success('');
        }

        $normalized = [];

        foreach ($decoded as $index => $entry) {
            $position = (int) $index + 1;

            if (!is_array($entry)) {
                return self::failure(self::ERROR_ENTRY_NOT_OBJECT, [$position]);
            }

            $type = isset($entry['type']) ? (string) $entry['type'] : '';

            if ($type === '') {
                return self::failure(self::ERROR_MISSING_TYPE, [$position]);
            }

            if (!LoanType::fromApiValue($type)->isKnown()) {
                return self::failure(self::ERROR_UNKNOWN_TYPE, [$position, $type]);
            }

            $error = null;

            $minTerm = self::parseOptionalPositiveInt($entry['minTerm'] ?? null, $position, 'minTerm', $error);

            if ($error !== null) {
                return self::failure($error['key'], $error['params']);
            }

            $maxTerm = self::parseOptionalPositiveInt($entry['maxTerm'] ?? null, $position, 'maxTerm', $error);

            if ($error !== null) {
                return self::failure($error['key'], $error['params']);
            }

            if ($minTerm !== null && $maxTerm !== null && $minTerm > $maxTerm) {
                return self::failure(
                    self::ERROR_MIN_TERM_GREATER_THAN_MAX_TERM,
                    [$position, $type, $minTerm, $maxTerm]
                );
            }

            $terms = self::parseTerms($entry['terms'] ?? null, $position, $type, $error);

            if ($error !== null) {
                return self::failure($error['key'], $error['params']);
            }

            if ($minTerm === null && $maxTerm === null && $terms === null) {
                // Entry has no actual constraints — silently drop it instead of storing a no-op row.
                continue;
            }

            $canonical = ['type' => $type];

            if ($minTerm !== null) {
                $canonical['minTerm'] = $minTerm;
            }

            if ($maxTerm !== null) {
                $canonical['maxTerm'] = $maxTerm;
            }

            if ($terms !== null) {
                $canonical['terms'] = $terms;
            }

            $normalized[] = $canonical;
        }

        return self::success(
            $normalized === []
                ? ''
                : $this->serializer->serialize($normalized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );
    }

    /**
     * @param ErrorEntry|null $error Set to the failure descriptor on invalid input
     */
    private static function parseOptionalPositiveInt(
        mixed $value,
        int $position,
        string $fieldName,
        ?array &$error
    ): ?int {
        if ($value === null || $value === '') {
            return null;
        }

        if (!is_numeric($value) || (int) $value <= 0) {
            $error = ['key' => self::ERROR_FIELD_NOT_POSITIVE_INT, 'params' => [$position, $fieldName]];

            return null;
        }

        return (int) $value;
    }

    /**
     * @param ErrorEntry|null $error Set to the failure descriptor on invalid input
     *
     * @return int[]|null
     */
    private static function parseTerms(mixed $raw, int $position, string $type, ?array &$error): ?array
    {
        if ($raw === null || $raw === '' || $raw === []) {
            return null;
        }

        if (!is_array($raw)) {
            $error = ['key' => self::ERROR_TERMS_NOT_ARRAY, 'params' => [$position, $type]];

            return null;
        }

        $result = [];

        foreach ($raw as $idx => $term) {
            if (!is_numeric($term) || (int) $term <= 0) {
                $error = ['key' => self::ERROR_TERM_NOT_POSITIVE_INT, 'params' => [$position, $type, (int) $idx]];

                return null;
            }

            $result[] = (int) $term;
        }

        return array_values(array_unique($result));
    }

    /**
     * @return array{valid: true, errors: list<ErrorEntry>, normalizedJson: string}
     */
    private static function success(string $normalizedJson): array
    {
        return ['valid' => true, 'errors' => [], 'normalizedJson' => $normalizedJson];
    }

    /**
     * @param array<int, mixed> $params
     *
     * @return array{valid: false, errors: list<ErrorEntry>, normalizedJson: null}
     */
    private static function failure(string $key, array $params): array
    {
        return ['valid' => false, 'errors' => [['key' => $key, 'params' => $params]], 'normalizedJson' => null];
    }
}
