<?php

/**
 * Comfino PHP SDK
 *
 * Backend routines for e-commerce platforms integration with the Comfino payment gateway API.
 *
 * @package Comfino\Tests\Unit\Backend\Log
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
 * @copyright Copyright (c) 2026 Comfino by Comperia.pl S.A.
 * @license https://opensource.org/licenses/BSD-3-Clause BSD 3-Clause License
 * @link https://github.com/comfino/php-sdk
 */

declare(strict_types=1);

namespace Comfino\Tests\Unit\Backend\Log;

use Comfino\Backend\Log\ErrorMessageNormalizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ErrorMessageNormalizerTest extends TestCase
{
    private ErrorMessageNormalizer $normalizer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->normalizer = new ErrorMessageNormalizer();
    }

    #[DataProvider('messageProvider')]
    public function testNormalizeMessage(string $input, string $expected): void
    {
        $this->assertSame($expected, $this->normalizer->normalizeMessage($input));
    }

    /** @return array<string, array{string, string}> */
    public static function messageProvider(): array
    {
        return [
            'communication error timestamp stripped' => [
                'Communication error [1729683272]: No API key',
                'Communication error: No API key',
            ],
            'communication error other timestamp stripped' => [
                'Communication error [42]: timeout',
                'Communication error: timeout',
            ],
            'timeout value normalized' => [
                'Operation timed out after 8001 milliseconds',
                'Operation timed out after {N}ms',
            ],
            'short timeout normalized' => [
                'failed after 1 milliseconds',
                'failed after {N}ms',
            ],
            'byte count normalized' => [
                'response with 7952 bytes received',
                'response with {N} bytes',
            ],
            'byte count without received normalized' => [
                'truncated with 0 bytes',
                'truncated with {N} bytes',
            ],
            'timeout and bytes together' => [
                'Operation timed out after 8001 milliseconds with 0 bytes received',
                'Operation timed out after {N}ms with {N} bytes',
            ],
            'called in suffix stripped' => [
                'Argument is invalid, called in /var/www/html/foo.php on line 109',
                'Argument is invalid',
            ],
            'plain message untouched' => [
                'TypeError: expected int, got string',
                'TypeError: expected int, got string',
            ],
        ];
    }

    #[DataProvider('stackTraceProvider')]
    public function testNormalizeStackTrace(string $input, string $expected): void
    {
        $this->assertSame($expected, $this->normalizer->normalizeStackTrace($input));
    }

    /** @return array<string, array{string, string}> */
    public static function stackTraceProvider(): array
    {
        return [
            'woocommerce plugin root stripped, anchor kept' => [
                '#0 /home/user/public_html/wp-content/plugins/comfino-payment-gateway/src/Foo.php(42): bar()',
                '#0 wp-content/plugins/comfino-payment-gateway/src/Foo.php(42): bar()',
            ],
            'prestashop module root stripped, anchor kept' => [
                '#1 /var/www/html/modules/comfino/src/Bar.php(10): baz()',
                '#1 modules/comfino/src/Bar.php(10): baz()',
            ],
            'magento app/code module root stripped, anchor kept' => [
                '#2 /srv/app/code/Comfino/ComfinoGateway/Model/Qux.php(7): run()',
                '#2 app/code/Comfino/ComfinoGateway/Model/Qux.php(7): run()',
            ],
            'magento composer-installed plugin root stripped, anchor kept' => [
                '#3 /var/www/html/magento/vendor/comfino/magento2/Model/Qux.php(7): run()',
                '#3 vendor/comfino/magento2/Model/Qux.php(7): run()',
            ],
            'magento composer-installed hyva checkout root stripped, anchor kept' => [
                '#4 /var/www/html/magento/vendor/comfino/magento2-hyva-checkout/Block/Bar.php(3): go()',
                '#4 vendor/comfino/magento2-hyva-checkout/Block/Bar.php(3): go()',
            ],
            'composer-installed php-sdk root stripped, anchor kept' => [
                '#5 /var/www/html/magento/vendor/comfino/php-sdk/src/Backend/Log/ErrorLogger.php(99): x()',
                '#5 vendor/comfino/php-sdk/src/Backend/Log/ErrorLogger.php(99): x()',
            ],
            'unknown vendor path left unchanged' => [
                '#0 /var/www/html/vendor/other/lib/File.php(5): call()',
                '#0 /var/www/html/vendor/other/lib/File.php(5): call()',
            ],
        ];
    }

    public function testNormalizeMessageIsIdempotent(): void
    {
        $input = 'Communication error [1729683272]: timed out after 8001 milliseconds with 0 bytes received';

        $once = $this->normalizer->normalizeMessage($input);
        $twice = $this->normalizer->normalizeMessage($once);

        $this->assertSame($once, $twice);
    }

    public function testNormalizeStackTraceIsIdempotent(): void
    {
        $input = "#0 /var/www/html/modules/comfino/src/Foo.php(42): bar()\n" .
            '#1 /home/user/public_html/wp-content/plugins/comfino-payment-gateway/src/Baz.php(10): qux()';

        $once = $this->normalizer->normalizeStackTrace($input);
        $twice = $this->normalizer->normalizeStackTrace($once);

        $this->assertSame($once, $twice);
    }
}
