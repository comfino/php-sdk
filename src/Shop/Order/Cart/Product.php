<?php

/**
 * Comfino PHP SDK
 *
 * Backend routines for e-commerce platforms integration with the Comfino payment gateway API.
 *
 * @package Comfino\Shop\Order\Cart
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
 * @copyright Copyright (c) 2026 Comfino by Comperia.pl S.A.
 * @license https://opensource.org/licenses/BSD-3-Clause BSD 3-Clause License
 * @link https://github.com/comfino/php-sdk
 */

declare(strict_types=1);

namespace Comfino\Shop\Order\Cart;

/**
 * Represents a product in the shopping cart for the Comfino API.
 */
class Product implements ProductInterface
{
    /**
     * @param string $name Product name
     * @param int $price Product price
     * @param string|null $id Product ID
     * @param string|null $category Product category
     * @param string|null $ean Product EAN code
     * @param string|null $photoUrl Product photo URL
     * @param int[]|null $categoryIds Product category IDs
     * @param int|null $netPrice Net price of the product
     * @param int|null $taxRate Tax rate for the product
     * @param int|null $taxValue Tax value for the product
     */
    public function __construct(
        private readonly string $name,
        private readonly int $price,
        private readonly ?string $id = null,
        private readonly ?string $category = null,
        private readonly ?string $ean = null,
        private readonly ?string $photoUrl = null,
        private readonly ?array $categoryIds = null,
        private readonly ?int $netPrice = null,
        private readonly ?int $taxRate = null,
        private readonly ?int $taxValue = null
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return trim(html_entity_decode(strip_tags($this->name)));
    }

    /**
     * @inheritDoc
     */
    public function getPrice(): int
    {
        return $this->price;
    }

    /**
     * @inheritDoc
     */
    public function getNetPrice(): ?int
    {
        return $this->netPrice;
    }

    /**
     * @inheritDoc
     */
    public function getTaxRate(): ?int
    {
        return $this->taxRate;
    }

    /**
     * @inheritDoc
     */
    public function getTaxValue(): ?int
    {
        return $this->taxValue;
    }

    /**
     * @inheritDoc
     */
    public function getId(): ?string
    {
        return $this->id !== null ? trim(strip_tags($this->id)) : null;
    }

    /**
     * @inheritDoc
     */
    public function getCategory(): ?string
    {
        return $this->category !== null ? trim(strip_tags($this->category)) : null;
    }

    /**
     * @inheritDoc
     */
    public function getEan(): ?string
    {
        return $this->ean !== null ? trim(strip_tags($this->ean)) : null;
    }

    /**
     * @inheritDoc
     */
    public function getPhotoUrl(): ?string
    {
        return $this->photoUrl !== null ? trim(strip_tags($this->photoUrl)) : null;
    }

    /**
     * @inheritDoc
     */
    public function getCategoryIds(): ?array
    {
        return $this->categoryIds;
    }
}
