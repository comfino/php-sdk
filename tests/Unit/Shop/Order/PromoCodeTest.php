<?php

/**
 * ComfinoPay PHP SDK
 *
 * Backend routines for e-commerce platforms integration with the ComfinoPay payment gateway API.
 *
 * @package Comfino\Tests\Unit\Shop\Order
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
 * @copyright Copyright (c) 2026 by ComfinoPay sp. z o.o.
 * @license https://opensource.org/licenses/BSD-3-Clause BSD 3-Clause License
 * @link https://github.com/comfino/php-sdk
 */

declare(strict_types=1);

namespace Comfino\Tests\Unit\Shop\Order;

use Comfino\Shop\Order\PromoCode;
use PHPUnit\Framework\TestCase;

final class PromoCodeTest extends TestCase
{
    /**
     * @dataProvider normalizedProvider
     */
    public function testNormalize(mixed $value, ?string $expected): void
    {
        $this->assertSame($expected, PromoCode::normalize($value));
    }

    /**
     * @return array<string, array{0: mixed, 1: string|null}>
     */
    public static function normalizedProvider(): array
    {
        return [
            'plain code' => ['SUMMER10', 'SUMMER10'],
            'trimmed' => ["  SUMMER10 \t", 'SUMMER10'],
            'markup stripped' => ['<b>SUMMER10</b>', 'SUMMER10'],
            'null' => [null, null],
            'empty' => ['', null],
            'whitespace' => ["  \t", null],
            'markup only' => ['<b></b>', null],
            'decoded nbsp' => ['&nbsp;', null],
            'array value' => [['SUMMER10'], null],
            'int value' => [10, null],
            'max length' => [str_repeat('A', 64), str_repeat('A', 64)],
            'over max length' => [str_repeat('A', 65), null],
            'multibyte counted in characters' => [str_repeat('Ł', 64), str_repeat('Ł', 64)],
        ];
    }
}
