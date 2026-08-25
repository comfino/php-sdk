<?php

/**
 * Comfino PHP SDK
 *
 * Backend routines for e-commerce platforms integration with the Comfino payment gateway API.
 *
 * @package Comfino\Tests\Unit\Shop\Order
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
 * @copyright Copyright (c) 2026 Comfino by Comperia.pl S.A.
 * @license https://opensource.org/licenses/BSD-3-Clause BSD 3-Clause License
 * @link https://github.com/comfino/php-sdk
 */

declare(strict_types=1);

namespace Comfino\Tests\Unit\Shop\Order;

use Comfino\Shop\Order\AbstractCustomerBuilder;
use Comfino\Shop\Order\Customer\ShippingAddressData;

/**
 * Concrete {@see AbstractCustomerBuilder} for tests whose extraction hooks return fixture data and which exposes the
 * parent's protected utility helpers for direct assertion.
 */
final class ConfigurableCustomerBuilder extends AbstractCustomerBuilder
{
    /**
     * @param array<string, mixed> $data
     */
    public function __construct(private readonly array $data = [])
    {
    }

    /** @return array{0: string, 1: string} */
    public function exposeParseFullName(string $fullName): array
    {
        return $this->parseFullName($fullName);
    }

    public function exposeNormalizePhoneNumber(string $phone): string
    {
        return $this->normalizePhoneNumber($phone);
    }

    public function exposeNormalizeTaxId(string $taxId): string
    {
        return $this->normalizeTaxId($taxId);
    }

    /** @return array{0: string, 1: string, 2: string} */
    public function exposeParseStreetAddress(string $fullStreet): array
    {
        return $this->parseStreetAddress($fullStreet);
    }

    protected function extractFirstName(mixed $platformOrder): string
    {
        return $this->data['firstName'] ?? '';
    }

    protected function extractLastName(mixed $platformOrder): string
    {
        return $this->data['lastName'] ?? '';
    }

    protected function extractEmail(mixed $platformOrder): string
    {
        return $this->data['email'] ?? '';
    }

    protected function extractPhone(mixed $platformOrder): string
    {
        return $this->data['phone'] ?? '';
    }

    protected function extractTaxId(mixed $platformOrder): string
    {
        return $this->data['taxId'] ?? '';
    }

    protected function extractShippingAddress(mixed $platformOrder): ?ShippingAddressData
    {
        return $this->data['shipping'] ?? null;
    }
}
