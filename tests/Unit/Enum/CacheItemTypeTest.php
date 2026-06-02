<?php

/**
 * Comfino PHP SDK
 *
 * Backend routines for e-commerce platforms integration with the Comfino payment gateway API.
 *
 * @package Comfino\Tests\Unit\Enum
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
 * @copyright Copyright (c) 2026 Comfino by Comperia.pl S.A.
 * @license https://opensource.org/licenses/BSD-3-Clause BSD 3-Clause License
 * @link https://github.com/comfino/php-sdk
 */

declare(strict_types=1);

namespace Comfino\Tests\Unit\Enum;

use Comfino\Enum\CacheItemType;
use PHPUnit\Framework\TestCase;

final class CacheItemTypeTest extends TestCase
{
    public function testAdminProductTypesValue(): void
    {
        $this->assertSame('admin_product_types', CacheItemType::ADMIN_PRODUCT_TYPES->value);
    }

    public function testAdminWidgetTypesValue(): void
    {
        $this->assertSame('admin_widget_types', CacheItemType::ADMIN_WIDGET_TYPES->value);
    }

    public function testFromReturnsCorrectCase(): void
    {
        $this->assertSame(CacheItemType::ADMIN_PRODUCT_TYPES, CacheItemType::from('admin_product_types'));
        $this->assertSame(CacheItemType::ADMIN_WIDGET_TYPES, CacheItemType::from('admin_widget_types'));
    }

    public function testTryFromReturnsNullForUnknownValue(): void
    {
        $this->assertNull(CacheItemType::tryFrom('unknown_type')); // @phpstan-ignore method.alreadyNarrowedType
    }

    public function testCasesReturnsAllCases(): void
    {
        $cases = CacheItemType::cases();

        $this->assertCount(2, $cases);
        $this->assertContains(CacheItemType::ADMIN_PRODUCT_TYPES, $cases);
        $this->assertContains(CacheItemType::ADMIN_WIDGET_TYPES, $cases);
    }
}
