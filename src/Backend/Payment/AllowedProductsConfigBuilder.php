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

use Comfino\Api\Dto\Payment\AllowedProductConfig;
use Comfino\Enum\LoanType;

/**
 * Converts the persisted allowed-products configuration into AllowedProductConfig DTOs and back.
 */
final class AllowedProductsConfigBuilder
{
    /**
     * Builds an AllowedProductConfig DTO list from the raw persisted array.
     *
     * Expected input shape (as stored under COMFINO_ALLOWED_PRODUCTS_CONFIG):
     *   [['type' => 'PAY_LATER', 'maxTerm' => 6, 'minTerm' => 1, 'terms' => [3, 6]], ...]
     *
     * Entries without a 'type' key are skipped. Numeric coercion is applied to term values.
     *
     * @param mixed $persisted Raw value from StorageAdapter
     *
     * @return AllowedProductConfig[]|null Null when the input is empty or yields no valid entries
     */
    public static function fromPersistedArray(mixed $persisted): ?array
    {
        if (!is_array($persisted) || empty($persisted)) {
            return null;
        }

        $result = [];

        foreach ($persisted as $entry) {
            if (!is_array($entry) || empty($entry['type'])) {
                continue;
            }

            /* UnknownLoanType is the fail-soft path for API responses (forward compat with new server-side product
               types). For admin-saved config it is always a typo or a retired type — skip so we don't leak garbage
               into the iframe URL or order request. */
            $loanType = LoanType::fromApiValue((string) $entry['type']);

            if (!$loanType->isKnown()) {
                // Skip unknown loan types.
                continue;
            }

            // Skip entries with invalid minTerm or maxTerm values.
            $minTerm = isset($entry['minTerm']) && is_numeric($entry['minTerm']) ? (int) $entry['minTerm'] : null;
            $maxTerm = isset($entry['maxTerm']) && is_numeric($entry['maxTerm']) ? (int) $entry['maxTerm'] : null;

            if ($minTerm !== null && $maxTerm !== null && $minTerm > $maxTerm) {
                // Skip entries with invalid minTerm > maxTerm.
                continue;
            }

            $terms = null;

            if (isset($entry['terms']) && is_array($entry['terms'])) {
                // Filter out invalid term values.
                $terms = array_values(array_filter(
                    array_map('intval', $entry['terms']),
                    static fn (int $term): bool => $term > 0
                ));

                if ($terms === []) {
                    $terms = null;
                }
            }

            $result[] = new AllowedProductConfig($loanType, $maxTerm, $minTerm, $terms);
        }

        return !empty($result) ? $result : null;
    }

    /**
     * Converts a DTO list to a plain array suitable for JSON / frontend embedding.
     *
     * @param AllowedProductConfig[]|null $dtos DTO list to convert
     *
     * @return array<int, array{type: string, maxTerm?: int, minTerm?: int, terms?: int[]}>|null
     */
    public static function toFrontendArray(?array $dtos): ?array
    {
        if ($dtos === null || $dtos === []) {
            return null;
        }

        $result = [];

        foreach ($dtos as $dto) {
            $entry = ['type' => $dto->type->getValue()];

            if ($dto->minTerm !== null) {
                $entry['minTerm'] = $dto->minTerm;
            }

            if ($dto->maxTerm !== null) {
                $entry['maxTerm'] = $dto->maxTerm;
            }

            if ($dto->terms !== null) {
                $entry['terms'] = $dto->terms;
            }

            $result[] = $entry;
        }

        return $result;
    }
}
