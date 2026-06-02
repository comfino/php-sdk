<?php

/**
 * Comfino PHP SDK
 *
 * Backend routines for e-commerce platforms integration with the Comfino payment gateway API.
 *
 * @package Comfino\Tests\Unit\Backend\Payment
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
 * @copyright Copyright (c) 2026 Comfino by Comperia.pl S.A.
 * @license https://opensource.org/licenses/BSD-3-Clause BSD 3-Clause License
 * @link https://github.com/comfino/php-sdk
 */

declare(strict_types=1);

namespace Comfino\Tests\Unit\Backend\Payment;

use Comfino\Backend\Payment\ProductTypeFilterInterface;
use Comfino\Backend\Payment\ProductTypeFilterManager;
use Comfino\Enum\LoanType;
use Comfino\Shop\Cart;
use PHPUnit\Framework\TestCase;

final class ProductTypeFilterManagerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        ProductTypeFilterManager::reset();
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        ProductTypeFilterManager::reset();
    }

    public function testGetInstanceReturnsSameInstance(): void
    {
        $this->assertSame(ProductTypeFilterManager::getInstance(), ProductTypeFilterManager::getInstance());
    }

    public function testFiltersActiveReturnsFalseWhenNoFilters(): void
    {
        $this->assertFalse(ProductTypeFilterManager::getInstance()->filtersActive());
    }

    public function testFiltersActiveReturnsTrueAfterAddingFilter(): void
    {
        $manager = ProductTypeFilterManager::getInstance();
        $manager->addFilter($this->createMock(ProductTypeFilterInterface::class));

        $this->assertTrue($manager->filtersActive());
    }

    public function testGetFiltersReturnsAddedFilters(): void
    {
        $manager = ProductTypeFilterManager::getInstance();
        $filter1 = $this->createMock(ProductTypeFilterInterface::class);
        $filter2 = $this->createMock(ProductTypeFilterInterface::class);

        $manager->addFilter($filter1);
        $manager->addFilter($filter2);

        $this->assertSame([$filter1, $filter2], $manager->getFilters());
    }

    public function testGetAllowedProductTypesReturnsAllWhenNoFilters(): void
    {
        $manager = ProductTypeFilterManager::getInstance();
        $available = [LoanType::PAY_LATER, LoanType::INSTALLMENTS_ZERO_PERCENT];

        $this->assertSame($available, $manager->getAllowedProductTypes($available, $this->makeCart()));
    }

    public function testGetAllowedProductTypesAppliesSingleFilter(): void
    {
        $manager = ProductTypeFilterManager::getInstance();

        $filter = $this->createMock(ProductTypeFilterInterface::class);
        $filter->method('getAllowedProductTypes')->willReturn([LoanType::PAY_LATER]);

        $manager->addFilter($filter);

        $this->assertSame(
            [LoanType::PAY_LATER],
            $manager->getAllowedProductTypes(
                [LoanType::PAY_LATER, LoanType::INSTALLMENTS_ZERO_PERCENT],
                $this->makeCart()
            )
        );
    }

    public function testGetAllowedProductTypesIntersectsMultipleFilters(): void
    {
        $manager = ProductTypeFilterManager::getInstance();

        // Filter 1 allows PAY_LATER and INSTALLMENTS_ZERO_PERCENT.
        $filter1 = $this->createMock(ProductTypeFilterInterface::class);
        $filter1->method('getAllowedProductTypes')
            ->willReturn([LoanType::PAY_LATER, LoanType::INSTALLMENTS_ZERO_PERCENT]);

        // Filter 2 allows INSTALLMENTS_ZERO_PERCENT and CONVENIENT_INSTALLMENTS.
        $filter2 = $this->createMock(ProductTypeFilterInterface::class);
        $filter2->method('getAllowedProductTypes')
            ->willReturn([LoanType::INSTALLMENTS_ZERO_PERCENT, LoanType::CONVENIENT_INSTALLMENTS]);

        $manager->addFilter($filter1);
        $manager->addFilter($filter2);

        // Intersection: only INSTALLMENTS_ZERO_PERCENT is in both filter results.
        $this->assertSame(
            [LoanType::INSTALLMENTS_ZERO_PERCENT],
            $manager->getAllowedProductTypes(
                [LoanType::PAY_LATER, LoanType::INSTALLMENTS_ZERO_PERCENT, LoanType::CONVENIENT_INSTALLMENTS],
                $this->makeCart()
            )
        );
    }

    public function testGetAllowedProductTypesReturnsEmptyWhenFiltersHaveNoCommonTypes(): void
    {
        $manager = ProductTypeFilterManager::getInstance();

        $filter1 = $this->createMock(ProductTypeFilterInterface::class);
        $filter1->method('getAllowedProductTypes')->willReturn([LoanType::PAY_LATER]);

        $filter2 = $this->createMock(ProductTypeFilterInterface::class);
        $filter2->method('getAllowedProductTypes')->willReturn([LoanType::INSTALLMENTS_ZERO_PERCENT]);

        $manager->addFilter($filter1);
        $manager->addFilter($filter2);

        $this->assertSame(
            [],
            $manager->getAllowedProductTypes(
                [LoanType::PAY_LATER, LoanType::INSTALLMENTS_ZERO_PERCENT],
                $this->makeCart()
            )
        );
    }

    public function testResetClearsSingletonInstance(): void
    {
        $instance1 = ProductTypeFilterManager::getInstance();
        $instance1->addFilter($this->createMock(ProductTypeFilterInterface::class));

        ProductTypeFilterManager::reset();

        $instance2 = ProductTypeFilterManager::getInstance();

        $this->assertNotSame($instance1, $instance2);
        $this->assertFalse($instance2->filtersActive());
    }

    private function makeCart(int $totalValue = 5000): Cart
    {
        return new Cart(
            totalValue: $totalValue,
            totalNetValue: null,
            totalTaxValue: null,
            deliveryCost: 0,
            deliveryNetCost: null,
            deliveryTaxRate: null,
            deliveryTaxValue: null,
            cartItems: []
        );
    }
}
