<?php

/**
 * Comfino PHP SDK
 *
 * Backend routines for e-commerce platforms integration with the Comfino payment gateway API.
 *
 * @package Comfino\Tests\Unit\Frontend
 * @author Artur Kozubski <akozubski@comperia.pl>
 * @copyright Copyright (c) 2026 Comfino by Comperia.pl S.A.
 * @license https://opensource.org/licenses/BSD-3-Clause BSD 3-Clause License
 * @link https://github.com/comfino/php-sdk
 */

declare(strict_types=1);

namespace Comfino\Tests\Unit\Frontend;

use Comfino\Frontend\FrontendHelper;
use Exception;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;

class FrontendHelperTest extends TestCase
{
    #[DataProvider('logoAuthKeyDataProvider')]
    public function testGetLogoAuthKey(
        string $platformCode,
        string $platformVersion,
        string $pluginVersion,
        int $buildTimestamp
    ): void {
        $authKey = FrontendHelper::getLogoAuthKey($platformCode, $platformVersion, $pluginVersion, $buildTimestamp);

        $this->assertNotEmpty($authKey);
        $this->assertStringContainsString($platformCode, $authKey);
    }

    #[DataProvider('logoAuthHashDataProvider')]
    public function testGetLogoAuthHash(
        string $platformCode,
        string $platformVersion,
        string $pluginVersion,
        int $buildTimestamp
    ): void {
        $authHash = FrontendHelper::getLogoAuthHash($platformCode, $platformVersion, $pluginVersion, $buildTimestamp);

        $this->assertNotEmpty($authHash);
        $this->assertNotFalse(base64_decode(urldecode($authHash))); // Verify it is URL encoded base64.
    }

    #[DataProvider('paywallLogoAuthHashDataProvider')]
    public function testGetPaywallLogoAuthHash(
        string $platformCode,
        string $platformVersion,
        string $pluginVersion,
        string $apiKey,
        string $widgetKey,
        int $buildTimestamp
    ): void {
        $authHash = FrontendHelper::getPaywallLogoAuthHash(
            $platformCode,
            $platformVersion,
            $pluginVersion,
            $apiKey,
            $widgetKey,
            $buildTimestamp
        );

        $this->assertNotEmpty($authHash);
        $this->assertNotFalse(base64_decode(urldecode($authHash))); // Verify it is URL encoded base64.

        // Paywall auth hash should be different from regular logo auth hash.
        $this->assertNotEquals(
            FrontendHelper::getLogoAuthHash($platformCode, $platformVersion, $pluginVersion, $buildTimestamp),
            $authHash
        );
    }

    /**
     * @param string[] $expectedContains
     */
    #[DataProvider('renderAdminLogoDataProvider')]
    public function testRenderAdminLogo(
        string $apiHost,
        string $platformCode,
        string $platformVersion,
        string $pluginVersion,
        int $buildTimestamp,
        string $style,
        string $alt,
        array $expectedContains
    ): void {
        $html = FrontendHelper::renderAdminLogo(
            $apiHost,
            $platformCode,
            $platformVersion,
            $pluginVersion,
            $buildTimestamp,
            $style,
            $alt
        );

        $this->assertStringStartsWith('<img', $html);
        $this->assertStringContainsString($apiHost, $html);
        $this->assertStringContainsString('v1/get-logo-url', $html);
        $this->assertStringContainsString('?auth=', $html);

        foreach ($expectedContains as $expected) {
            $this->assertStringContainsString($expected, $html);
        }
    }

    /**
     * @param string[] $expectedContains
     */
    #[DataProvider('renderPaywallLogoDataProvider')]
    public function testRenderPaywallLogo(
        string $apiHost,
        string $apiKey,
        string $widgetKey,
        string $platformCode,
        string $platformVersion,
        string $pluginVersion,
        int $buildTimestamp,
        string $style,
        string $alt,
        array $expectedContains
    ): void {
        $html = FrontendHelper::renderPaywallLogo(
            $apiHost,
            $apiKey,
            $widgetKey,
            $platformCode,
            $platformVersion,
            $pluginVersion,
            $buildTimestamp,
            $style,
            $alt
        );

        $this->assertStringStartsWith('<img', $html);
        $this->assertStringContainsString($apiHost, $html);
        $this->assertStringContainsString('v1/get-paywall-logo', $html);
        $this->assertStringContainsString('?auth=', $html);

        foreach ($expectedContains as $expected) {
            $this->assertStringContainsString($expected, $html);
        }
    }

    /**
     * @param string[] $expectedKeys
     */
    #[DataProvider('prepareErrorDetailsDataProvider')]
    public function testPrepareErrorDetails(
        string $userErrorMessage,
        int $statusCode,
        bool $isDebugMode,
        Throwable $exception,
        bool $isTimeout,
        int $connectAttemptIdx,
        int $connectionTimeout,
        int $transferTimeout,
        ?string $url,
        ?string $requestBody,
        ?string $responseBody,
        array $expectedKeys
    ): void {
        $errorDetails = FrontendHelper::prepareErrorDetails(
            $userErrorMessage,
            $statusCode,
            $isDebugMode,
            $exception,
            $isTimeout,
            $connectAttemptIdx,
            $connectionTimeout,
            $transferTimeout,
            $url,
            $requestBody,
            $responseBody
        );

        foreach ($expectedKeys as $key) {
            $this->assertArrayHasKey($key, $errorDetails);
        }

        $this->assertEquals($userErrorMessage, $errorDetails['userErrorMessage']);
        $this->assertEquals($statusCode, $errorDetails['statusCode']);
        $this->assertEquals($isDebugMode, $errorDetails['isDebugMode']);

        // isTimeout might not be present if it's false and isDebugMode is true (due to array_filter).
        if (array_key_exists('isTimeout', $errorDetails)) {
            $this->assertEquals($isTimeout, $errorDetails['isTimeout']);
        }
    }

    public function testPrepareErrorDetailsDebugModeIncludesAllDetails(): void
    {
        $exception = new RuntimeException('Test error', 500);

        $errorDetails = FrontendHelper::prepareErrorDetails(
            'User error message',
            500,
            true,
            $exception,
            false,
            1,
            5,
            10,
            'https://api.example.com/endpoint',
            '{"request": "data"}',
            '{"response": "data"}'
        );

        $this->assertArrayHasKey('exceptionClass', $errorDetails);
        $this->assertArrayHasKey('errorMessage', $errorDetails);
        $this->assertArrayHasKey('errorCode', $errorDetails);
        $this->assertArrayHasKey('errorFile', $errorDetails);
        $this->assertArrayHasKey('errorLine', $errorDetails);
        $this->assertArrayHasKey('errorTrace', $errorDetails);
        $this->assertArrayHasKey('url', $errorDetails);
        $this->assertArrayHasKey('requestBody', $errorDetails);
        $this->assertArrayHasKey('responseBody', $errorDetails);

        $this->assertEquals(RuntimeException::class, $errorDetails['exceptionClass']);
        $this->assertEquals('Test error', $errorDetails['errorMessage']);
    }

    public function testPrepareErrorDetailsNonDebugModeExcludesSensitiveInfo(): void
    {
        $exception = new RuntimeException('Test error', 500);

        $errorDetails = FrontendHelper::prepareErrorDetails(
            'User error message',
            500,
            false,
            $exception,
            false,
            1,
            5,
            10,
            'https://api.example.com/endpoint',
            '{"request": "data"}',
            '{"response": "data"}'
        );

        $this->assertArrayNotHasKey('exceptionClass', $errorDetails);
        $this->assertArrayNotHasKey('errorMessage', $errorDetails);
        $this->assertArrayNotHasKey('errorFile', $errorDetails);
        $this->assertArrayNotHasKey('errorLine', $errorDetails);
        $this->assertArrayNotHasKey('errorTrace', $errorDetails);
        $this->assertArrayNotHasKey('url', $errorDetails);
        $this->assertArrayNotHasKey('requestBody', $errorDetails);
        $this->assertArrayNotHasKey('responseBody', $errorDetails);
    }

    public function testLogoAuthKeyWithDifferentVersionFormats(): void
    {
        $key1 = FrontendHelper::getLogoAuthKey('PLATFORM', '1.0.0', '1.0.0', 1234567890);
        $key2 = FrontendHelper::getLogoAuthKey('PLATFORM', '1.0', '1.0', 1234567890);
        $key3 = FrontendHelper::getLogoAuthKey('PLATFORM', '1', '1', 1234567890);

        // Different version formats should produce different keys.
        $this->assertNotEquals($key1, $key2);
        $this->assertNotEquals($key2, $key3);
    }

    public function testLogoAuthHashConsistency(): void
    {
        $hash1 = FrontendHelper::getLogoAuthHash('PLATFORM', '1.0.0', '1.0.0', 1234567890);
        $hash2 = FrontendHelper::getLogoAuthHash('PLATFORM', '1.0.0', '1.0.0', 1234567890);

        // Same inputs should produce same hash.
        $this->assertEquals($hash1, $hash2);
    }

    public function testPaywallLogoAuthHashConsistency(): void
    {
        $hash1 = FrontendHelper::getPaywallLogoAuthHash(
            'PLATFORM',
            '1.0.0',
            '1.0.0',
            'api-key',
            'widget-key',
            1234567890
        );
        $hash2 = FrontendHelper::getPaywallLogoAuthHash(
            'PLATFORM',
            '1.0.0',
            '1.0.0',
            'api-key',
            'widget-key',
            1234567890
        );

        // Same inputs should produce same hash.
        $this->assertEquals($hash1, $hash2);
    }

    /* Data providers */

    /** @return array<string, array{string, string, string, int, string}> */
    public static function logoAuthKeyDataProvider(): array
    {
        return [
            'Standard versions' => ['PLATFORM', '1.0.0', '1.0.0', 1234567890, 'PLATFORM'],
            'Different platform' => ['MAGENTO', '2.5.1', '3.2.1', 9876543210, 'MAGENTO'],
            'Single digit versions' => ['WOO', '1.2.3', '4.5.6', 1111111111, 'WOO'],
        ];
    }

    /** @return array<string, array{string, string, string, int}> */
    public static function logoAuthHashDataProvider(): array
    {
        return [
            'Standard case' => ['PLATFORM', '1.0.0', '1.0.0', 1234567890],
            'Different platform' => ['MAGENTO', '2.5.1', '3.2.1', 9876543210],
            'Recent timestamp' => ['WOO', '8.0.0', '2.0.0', time()],
        ];
    }

    /** @return array<string, array{string, string, string, string, string, int}> */
    public static function paywallLogoAuthHashDataProvider(): array
    {
        return [
            'Standard case' => ['PLATFORM', '1.0.0', '1.0.0', 'test-api-key', 'test-widget-key', 1234567890],
            'With special chars' => ['PLATFORM', '1.0.0', '1.0.0', 'api-key-123!@#', 'widget-key-456$%^', 1234567890],
            'Long keys' => ['PLATFORM', '1.0.0', '1.0.0', str_repeat('a', 100), str_repeat('b', 100), 1234567890],
        ];
    }

    /** @return array<string, array{string, string, string, string, int, string, string, string[]}> */
    public static function renderAdminLogoDataProvider(): array
    {
        return [
            'Basic logo' => [
                'https://api.example.com',
                'PLATFORM',
                '1.0.0',
                '1.0.0',
                1234567890,
                '',
                '',
                ['<img', 'src="https://api.example.com/v1/get-logo-url?auth=']
            ],
            'With style' => [
                'https://api.example.com',
                'PLATFORM',
                '1.0.0',
                '1.0.0',
                1234567890,
                'width: 100px;',
                '',
                ['style="width: 100px;"']
            ],
            'With alt text' => [
                'https://api.example.com',
                'PLATFORM',
                '1.0.0',
                '1.0.0',
                1234567890,
                '',
                'Comfino Logo',
                ['alt="Comfino Logo"']
            ],
            'With style and alt' => [
                'https://api.example.com',
                'PLATFORM',
                '1.0.0',
                '1.0.0',
                1234567890,
                'height: 50px;',
                'Payment Logo',
                ['style="height: 50px;"', 'alt="Payment Logo"']
            ],
        ];
    }

    /** @return array<string, array{string, string, string, string, string, string, int, string, string, string[]}> */
    public static function renderPaywallLogoDataProvider(): array
    {
        return [
            'Basic logo' => [
                'https://api.example.com',
                'api-key',
                'widget-key',
                'PLATFORM',
                '1.0.0',
                '1.0.0',
                1234567890,
                '',
                '',
                ['<img', 'src="https://api.example.com/v1/get-paywall-logo?auth=']
            ],
            'With style' => [
                'https://api.example.com',
                'api-key',
                'widget-key',
                'PLATFORM',
                '1.0.0',
                '1.0.0',
                1234567890,
                'width: 200px;',
                '',
                ['style="width: 200px;"']
            ],
            'With alt text' => [
                'https://api.example.com',
                'api-key',
                'widget-key',
                'PLATFORM',
                '1.0.0',
                '1.0.0',
                1234567890,
                '',
                'Paywall Logo',
                ['alt="Paywall Logo"']
            ],
        ];
    }

    /** @return array<
     *     string,
     *     array{string, int, bool, \Throwable, bool, int, int, int, string|null, string|null, string|null, string[]}
     *  >
     */
    public static function prepareErrorDetailsDataProvider(): array
    {
        return [
            'Debug mode with all details' => [
                'User error',
                500,
                true,
                new RuntimeException('Test', 500),
                false,
                1,
                5,
                10,
                'https://api.example.com',
                '{"test": "request"}',
                '{"test": "response"}',
                // isTimeout=false is filtered out by array_filter.
                ['userErrorMessage', 'statusCode', 'isDebugMode', 'exceptionClass'],
            ],
            'Non-debug mode' => [
                'User error',
                500,
                false,
                new RuntimeException('Test', 500),
                false,
                1,
                5,
                10,
                'https://api.example.com',
                '{"test": "request"}',
                '{"test": "response"}',
                ['userErrorMessage', 'statusCode', 'isDebugMode', 'isTimeout', 'errorCode']
            ],
            'Timeout error' => [
                'Connection timeout',
                408,
                true,
                new Exception('Timeout', 408),
                true,
                3,
                10,
                30,
                null,
                null,
                null,
                ['userErrorMessage', 'statusCode', 'isDebugMode', 'isTimeout']
            ],
        ];
    }
}
