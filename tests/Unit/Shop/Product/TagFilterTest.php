<?php

/**
 * ComfinoPay PHP SDK
 *
 * Backend routines for e-commerce platforms integration with the ComfinoPay payment gateway API.
 *
 * @package Comfino\Tests\Unit\Shop\Product
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
 * @copyright Copyright (c) 2026 by ComfinoPay sp. z o.o.
 * @license https://opensource.org/licenses/BSD-3-Clause BSD 3-Clause License
 * @link https://github.com/comfino/php-sdk
 */

declare(strict_types=1);

namespace Comfino\Tests\Unit\Shop\Product;

use Comfino\Shop\Order\Cart\ProductInterface;
use Comfino\Shop\Product\TagFilter;
use Comfino\Tests\Unit\Support\ProductFilteringFixtures;
use PHPUnit\Framework\TestCase;

final class TagFilterTest extends TestCase
{
    use ProductFilteringFixtures;

    public function testProductTagsForTaggedUntaggedAndNonTaggableProducts(): void
    {
        $filter = new TagFilter();

        $this->assertSame(['tag-a', 'tag-b'], $filter->getProductTags(self::product('1', ['tag-a', 'tag-b'])));
        $this->assertSame([], $filter->getProductTags(self::product('2')));
        $this->assertSame([], $filter->getProductTags($this->createMock(ProductInterface::class)));
    }

    public function testProductLevelChecks(): void
    {
        $filter = new TagFilter();
        $product = self::product('1', ['tag-a', 'tag-b']);

        $this->assertTrue($filter->isProductAvailable($product, ['tag-c']));
        $this->assertFalse($filter->isProductAvailable($product, ['tag-b', 'tag-c']));
        $this->assertTrue($filter->isProductEligible($product, ['tag-b', 'tag-c']));
        $this->assertFalse($filter->isProductEligible($product, ['tag-c']));
        $this->assertTrue($filter->isProductAvailable(self::product('2'), ['tag-a']));
        $this->assertFalse($filter->isProductEligible(self::product('2'), ['tag-a']));
    }

    public function testBlockListRejectsCartWhenAnyProductHasExcludedTag(): void
    {
        $filter = new TagFilter();

        $this->assertFalse($filter->isCartValid(self::cart(self::product('1', ['tag-a']), self::product('2', ['no-paylater'])), ['no-paylater']));
        $this->assertTrue($filter->isCartValid(self::cart(self::product('1', ['tag-a']), self::product('2')), ['no-paylater']));
    }

    public function testOptInListRequiresEveryProductToHaveRequiredTag(): void
    {
        $filter = new TagFilter();

        $this->assertTrue($filter->isCartEligible(self::cart(self::product('1', ['tag-a']), self::product('2', ['tag-b', 'tag-x'])), ['tag-a', 'tag-b']));
        $this->assertFalse($filter->isCartEligible(self::cart(self::product('1', ['tag-a']), self::product('2')), ['tag-a']));
    }

    public function testTagNamesAreNormalized(): void
    {
        $this->assertSame(['raty0-12', 'promo'], TagFilter::normalizeTags([' raty0-12 ', '', 'promo', 'raty0-12', '<b>promo</b>']));
        $this->assertTrue((new TagFilter())->isProductEligible(self::product('1', [' raty0-12']), ['raty0-12 ']));
        $this->assertFalse((new TagFilter())->isProductEligible(self::product('1', ['Raty0-12']), ['raty0-12']));
    }

    public function testCaseInsensitiveModeMatchesMultibyteNames(): void
    {
        $filter = new TagFilter(caseInsensitive: true);
        $cart = self::cart(self::product('1', ['RATY0-12']), self::product('2', ['Łódź-Promo']));

        $this->assertTrue($filter->isCaseInsensitive());
        $this->assertTrue($filter->isProductEligible(self::product('1', ['Raty0-12']), ['raty0-12']));
        $this->assertFalse($filter->isProductAvailable(self::product('1', ['Raty0-12']), ['RATY0-12']));
        $this->assertTrue($filter->isCartEligible($cart, ['raty0-12', 'łódź-promo']));
        $this->assertFalse($filter->isCartValid($cart, ['ŁÓDŹ-PROMO']));
        $this->assertTrue((new TagFilter())->isCartValid($cart, ['ŁÓDŹ-PROMO']));
        $this->assertSame(['RATY0-12', 'Łódź-Promo'], $filter->getProductTags(self::product('1', ['RATY0-12', 'Łódź-Promo'])));
    }

    public function testEmptyListsAndEmptyCartAreNotRestrictive(): void
    {
        $filter = new TagFilter();
        $cart = self::cart(self::product('1'));

        $this->assertTrue($filter->isCartValid($cart, []));
        $this->assertTrue($filter->isCartEligible($cart, []));
        $this->assertTrue($filter->isCartValid(self::cart(), ['tag-a']));
        $this->assertTrue($filter->isCartEligible(self::cart(), ['tag-a']));
    }
}
