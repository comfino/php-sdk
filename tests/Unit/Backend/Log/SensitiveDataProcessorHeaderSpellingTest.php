<?php

/**
 * ComfinoPay PHP SDK
 *
 * Backend routines for e-commerce platforms integration with the ComfinoPay payment gateway API.
 *
 * @package Comfino\Tests\Unit
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
 * @copyright Copyright (c) 2026 by ComfinoPay sp. z o.o.
 * @license https://opensource.org/licenses/BSD-3-Clause BSD 3-Clause License
 * @link https://github.com/comfino/php-sdk
 */

declare(strict_types=1);

namespace Comfino\Tests\Unit\Backend\Log;

use Comfino\Backend\Log\SensitiveDataProcessor;
use PHPUnit\Framework\TestCase;

final class SensitiveDataProcessorHeaderSpellingTest extends TestCase
{
    public function testEverySignatureSpellingBecomesAnEightCharacterPrefix(): void
    {
        $signature = str_repeat('ab12cd34', 8);
        $record = [
            'context' => ['cr_signature' => $signature, 'CR-Signature' => $signature, 'x_cr_signature' => $signature, 'X-CR-Signature' => $signature],
            'extra' => [],
        ];

        $result = (new SensitiveDataProcessor())($record);

        foreach (array_keys($record['context']) as $key) {
            $this->assertSame('ab12cd34…', $result['context'][$key], $key);
        }
    }
}
