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

/**
 * Immutable outcome of term-limit resolution: per product type, either blocked or allowed with a term constraint.
 *
 * Resolutions from independent sources (global allowedProductsConfig, tag rules, category rules) are combined with
 * {@see merge()}, which intersects the constraints per type; a type whose merged constraint becomes unsatisfiable is
 * blocked. Types never mentioned are allowed with no constraint. The result maps to exactly one allowedProductsConfig
 * entry per type, which is what the API expects (it keeps only one entry per type).
 */
final class TermLimitResolution
{
    /**
     * @param array<string, TermConstraint> $constraints Satisfiable, non-trivial constraints of allowed types
     * @param array<string, true> $blockedProductTypes Blocked product type codes
     * @param array<string, LoanTypeInterface> $productTypes Product type instances by code (for DTO building)
     */
    private function __construct(
        private readonly array $constraints,
        private readonly array $blockedProductTypes,
        private readonly array $productTypes
    ) {
    }

    /**
     * Returns a resolution that blocks nothing and constrains nothing.
     */
    public static function empty(): self
    {
        return new self([], [], []);
    }

    /**
     * Converts an allowedProductsConfig DTO list (e.g. the merchant's global term limits) into a resolution.
     * Several entries for the same type are intersected.
     *
     * @param AllowedProductConfig[]|null $allowedProductsConfig
     */
    public static function fromAllowedProductsConfig(?array $allowedProductsConfig): self
    {
        $resolution = self::empty();

        foreach ($allowedProductsConfig ?? [] as $config) {
            $resolution = $resolution->withConstraint($config->type, TermConstraint::fromAllowedProductConfig($config));
        }

        return $resolution;
    }

    /**
     * Returns a copy with the constraint intersected into the type's current constraint.
     */
    public function withConstraint(LoanTypeInterface $productType, TermConstraint $constraint): self
    {
        $code = $productType->getValue();

        if (isset($this->blockedProductTypes[$code]) || $constraint->isUnconstrained()) {
            return $this;
        }

        $merged = isset($this->constraints[$code]) ? $this->constraints[$code]->intersect($constraint) : $constraint;

        if (!$merged->isSatisfiable()) {
            return $this->withBlocked($productType);
        }

        return new self(
            [...$this->constraints, $code => $merged],
            $this->blockedProductTypes,
            [...$this->productTypes, $code => $productType]
        );
    }

    /**
     * Returns a copy with the type blocked.
     */
    public function withBlocked(LoanTypeInterface $productType): self
    {
        $code = $productType->getValue();
        $constraints = $this->constraints;
        unset($constraints[$code]);

        return new self(
            $constraints,
            [...$this->blockedProductTypes, $code => true],
            [...$this->productTypes, $code => $productType]
        );
    }

    /**
     * Combines two resolutions: blocked types are united, constraints are intersected per type.
     */
    public function merge(self $other): self
    {
        $result = $this;

        foreach (array_keys($other->blockedProductTypes) as $code) {
            $result = $result->withBlocked($other->productTypes[$code]);
        }

        foreach ($other->constraints as $code => $constraint) {
            $result = $result->withConstraint($other->productTypes[$code], $constraint);
        }

        return $result;
    }

    public function isBlocked(LoanTypeInterface|string $productType): bool
    {
        return isset($this->blockedProductTypes[self::code($productType)]);
    }

    /**
     * Returns the term constraint of an allowed type (unconstrained when none applies), or null for a blocked type.
     */
    public function getConstraint(LoanTypeInterface|string $productType): ?TermConstraint
    {
        $code = self::code($productType);

        if (isset($this->blockedProductTypes[$code])) {
            return null;
        }

        return $this->constraints[$code] ?? TermConstraint::unconstrained();
    }

    /**
     * @return string[] Blocked product type codes
     */
    public function getBlockedProductTypes(): array
    {
        return array_keys($this->blockedProductTypes);
    }

    /**
     * Removes blocked types from the list, preserving order.
     *
     * @param LoanTypeInterface[] $productTypes
     *
     * @return LoanTypeInterface[]
     */
    public function filterProductTypes(array $productTypes): array
    {
        return array_values(array_filter(
            $productTypes,
            fn (LoanTypeInterface $productType): bool => !isset($this->blockedProductTypes[$productType->getValue()])
        ));
    }

    /**
     * Returns one allowedProductsConfig entry per constrained (and not blocked) type, or null when nothing is constrained.
     *
     * @return AllowedProductConfig[]|null
     */
    public function getAllowedProductsConfig(): ?array
    {
        if ($this->constraints === []) {
            return null;
        }

        $result = [];

        foreach ($this->constraints as $code => $constraint) {
            $result[] = $constraint->toAllowedProductConfig($this->productTypes[$code]);
        }

        return $result;
    }

    /**
     * @return array{blockedProductTypes: string[], constraints: array<string, array<string, mixed>>}
     */
    public function getAsArray(): array
    {
        return [
            'blockedProductTypes' => $this->getBlockedProductTypes(),
            'constraints' => array_map(static fn (TermConstraint $constraint): array => $constraint->getAsArray(), $this->constraints),
        ];
    }

    private static function code(LoanTypeInterface|string $productType): string
    {
        return $productType instanceof LoanTypeInterface ? $productType->getValue() : $productType;
    }
}
