<?php

/**
 * ComfinoPay PHP SDK
 *
 * Backend routines for e-commerce platforms integration with the ComfinoPay payment gateway API.
 *
 * @package Comfino\Backend\Payment\TermLimit
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
 * @copyright Copyright (c) 2026 by ComfinoPay sp. z o.o.
 * @license https://opensource.org/licenses/BSD-3-Clause BSD 3-Clause License
 * @link https://github.com/comfino/php-sdk
 */

declare(strict_types=1);

namespace Comfino\Backend\Payment\TermLimit;

use Comfino\Api\Dto\Payment\AllowedProductConfig;
use Comfino\Enum\LoanTypeInterface;
use InvalidArgumentException;

/**
 * Immutable set of allowed loan terms (instalment counts) for one product type, with the same semantics as a single
 * allowedProductsConfig entry on the API side: minTerm, maxTerm and the terms whitelist must all hold.
 *
 * Canonical form: a whitelist absorbs the bounds (terms are pre-filtered and minTerm/maxTerm dropped), so an instance
 * is either a range (minTerm/maxTerm, either side open) or a finite set. An empty set or minTerm > maxTerm is
 * unsatisfiable - no term is allowed.
 */
final class TermConstraint
{
    /** Upper bound used when an open-ended range has to be enumerated into an explicit whitelist during union. */
    public const DEFAULT_TERM_CEILING = 120;

    /**
     * @param int[]|null $terms Sorted, unique, positive term whitelist (null = any term within the bounds)
     */
    private function __construct(public readonly ?int $minTerm, public readonly ?int $maxTerm, public readonly ?array $terms)
    {
    }

    /**
     * Creates a canonical constraint.
     *
     * @param int[]|null $terms Explicit whitelist of allowed terms (null = all terms within the bounds)
     *
     * @throws InvalidArgumentException When a bound or a whitelisted term is not a positive integer
     */
    public static function of(?int $minTerm = null, ?int $maxTerm = null, ?array $terms = null): self
    {
        foreach ([$minTerm, $maxTerm, ...($terms ?? [])] as $value) {
            if ($value !== null && $value <= 0) {
                throw new InvalidArgumentException('Loan terms must be positive integers.');
            }
        }

        if ($terms === null) {
            return new self($minTerm, $maxTerm, null);
        }

        $terms = array_values(array_unique($terms));
        sort($terms);

        return new self(
            null,
            null,
            array_values(array_filter(
                $terms,
                static fn (int $term): bool => ($minTerm === null || $term >= $minTerm) && ($maxTerm === null || $term <= $maxTerm)
            ))
        );
    }

    /**
     * Returns a constraint allowing every term.
     */
    public static function unconstrained(): self
    {
        return new self(null, null, null);
    }

    /**
     * Creates a constraint from an API allowedProductsConfig entry (the product type is ignored).
     */
    public static function fromAllowedProductConfig(AllowedProductConfig $config): self
    {
        return self::of($config->minTerm, $config->maxTerm, $config->terms);
    }

    /**
     * Converts the constraint to an API allowedProductsConfig entry for the given product type.
     */
    public function toAllowedProductConfig(LoanTypeInterface $productType): AllowedProductConfig
    {
        return new AllowedProductConfig($productType, $this->maxTerm, $this->minTerm, $this->terms);
    }

    public function isUnconstrained(): bool
    {
        return $this->minTerm === null && $this->maxTerm === null && $this->terms === null;
    }

    public function isSatisfiable(): bool
    {
        if ($this->terms !== null) {
            return $this->terms !== [];
        }

        return $this->minTerm === null || $this->maxTerm === null || $this->minTerm <= $this->maxTerm;
    }

    public function allows(int $term): bool
    {
        if ($this->terms !== null) {
            return in_array($term, $this->terms, true);
        }

        return ($this->minTerm === null || $term >= $this->minTerm) && ($this->maxTerm === null || $term <= $this->maxTerm);
    }

    /**
     * Returns the terms allowed by both constraints (always exact; may be unsatisfiable).
     */
    public function intersect(self $other): self
    {
        $minTerm = self::maxOfNullable($this->minTerm, $other->minTerm);
        $maxTerm = self::minOfNullable($this->maxTerm, $other->maxTerm);

        if ($this->terms !== null && $other->terms !== null) {
            $terms = array_values(array_intersect($this->terms, $other->terms));
        } else {
            $terms = $this->terms ?? $other->terms;
        }

        return self::of($minTerm, $maxTerm, $terms);
    }

    /**
     * Returns the terms allowed by at least one of the constraints.
     *
     * Overlapping or adjacent ranges merge into a range. Any other combination is enumerated into a whitelist, with
     * open-ended ranges cut at $termCeiling (raised to the largest bound or term involved); a contiguous result is
     * compressed back into a range, open-ended again when it reaches the ceiling of an open-ended input.
     */
    public function union(self $other, int $termCeiling = self::DEFAULT_TERM_CEILING): self
    {
        if ($this->isUnconstrained() || $other->isUnconstrained()) {
            return self::unconstrained();
        }

        if (!$this->isSatisfiable()) {
            return $other;
        }

        if (!$other->isSatisfiable()) {
            return $this;
        }

        if ($this->terms === null && $other->terms === null) {
            $low = max($this->minTerm ?? 1, $other->minTerm ?? 1);
            $high = min($this->maxTerm ?? PHP_INT_MAX, $other->maxTerm ?? PHP_INT_MAX);

            if ($low <= $high || $low - $high === 1) {
                // Overlapping or adjacent ranges - the hull is exact.
                return self::of(
                    $this->minTerm === null || $other->minTerm === null ? null : min($this->minTerm, $other->minTerm),
                    $this->maxTerm === null || $other->maxTerm === null ? null : max($this->maxTerm, $other->maxTerm)
                );
            }
        }

        $termCeiling = max([$termCeiling, ...array_filter(
            [$this->minTerm, $this->maxTerm, $other->minTerm, $other->maxTerm, ...($this->terms ?? []), ...($other->terms ?? [])],
            static fn (?int $value): bool => $value !== null
        )]);

        $terms = array_values(array_unique([...$this->enumerate($termCeiling), ...$other->enumerate($termCeiling)]));

        sort($terms);

        // Sorted, so the extremes are the first and last elements.
        $first = min($terms);
        $last = max($terms);

        if ($last - $first + 1 === count($terms)) {
            $openEnded = ($this->terms === null && $this->maxTerm === null) || ($other->terms === null && $other->maxTerm === null);

            return self::of($first > 1 ? $first : null, $openEnded && $last === $termCeiling ? null : $last);
        }

        return self::of(null, null, $terms);
    }

    /**
     * @return array{minTerm?: int, maxTerm?: int, terms?: int[]}
     */
    public function getAsArray(): array
    {
        return array_filter(
            ['minTerm' => $this->minTerm, 'maxTerm' => $this->maxTerm, 'terms' => $this->terms],
            static fn ($value): bool => $value !== null
        );
    }

    /**
     * @return int[]
     */
    private function enumerate(int $termCeiling): array
    {
        if ($this->terms !== null) {
            return $this->terms;
        }

        $low = $this->minTerm ?? 1;
        $high = $this->maxTerm ?? $termCeiling;

        return $low <= $high ? range($low, $high) : [];
    }

    private static function maxOfNullable(?int $first, ?int $second): ?int
    {
        return $first === null ? $second : ($second === null ? $first : max($first, $second));
    }

    private static function minOfNullable(?int $first, ?int $second): ?int
    {
        return $first === null ? $second : ($second === null ? $first : min($first, $second));
    }
}
