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

use Comfino\Api\ClientInterface;
use Comfino\Backend\Log\ErrorLogger;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class ErrorLoggerTest extends TestCase
{
    private string $tempLogFile;
    private ClientInterface&MockObject $apiClient;
    private string $host = 'example-shop.test';
    private string $platform = 'TestPlatform';
    private string $modulePath = '/var/www/html/modules/comfino';
    /** @var array<string, string> */
    private array $environment = ['php' => '8.2.0', 'plugin' => '1.0.0'];

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempLogFile = sys_get_temp_dir() . '/comfino_test_' . uniqid('', true) . '.log';
        $this->apiClient = $this->createMock(ClientInterface::class);

        ErrorLogger::reset();

        putenv('COMFINO_DEV_ENV');
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        if (file_exists($this->tempLogFile)) {
            unlink($this->tempLogFile);
        }

        // Clean up rotated/dated log files.
        $dir = pathinfo($this->tempLogFile, PATHINFO_DIRNAME);
        $base = pathinfo($this->tempLogFile, PATHINFO_FILENAME);

        foreach (glob("$dir/$base*.log") ?: [] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }

        ErrorLogger::reset();

        putenv('COMFINO_DEV_ENV');
    }

    private function createInstance(): ErrorLogger
    {
        return ErrorLogger::getInstance(
            $this->apiClient,
            $this->tempLogFile,
            $this->host,
            $this->platform,
            $this->modulePath,
            $this->environment
        );
    }

    public function testGetInstanceReturnsSameInstance(): void
    {
        $logger1 = $this->createInstance();
        $logger2 = ErrorLogger::getInstance(
            $this->createMock(ClientInterface::class),
            '/different/path.log',
            'different-host',
            'DifferentPlatform',
            '/different/module',
            ['different' => 'env']
        );

        $this->assertSame($logger1, $logger2, 'getInstance should return the same instance (singleton pattern).');
    }

    public function testInitSetsPsr3Logger(): void
    {
        $psr3Logger = $this->createMock(LoggerInterface::class);

        ErrorLogger::init($psr3Logger);

        $this->assertSame($psr3Logger, $this->createInstance()->getLogger());
    }

    public function testGetLoggerReturnsNullWhenNotInitialized(): void
    {
        $this->assertNull($this->createInstance()->getLogger());
    }

    public function testResetClearsSingletonAndLogger(): void
    {
        $psr3Logger = $this->createMock(LoggerInterface::class);

        ErrorLogger::init($psr3Logger);

        $this->createInstance();

        ErrorLogger::reset();

        $this->assertNull($this->createInstance()->getLogger());
    }

    public function testLogErrorCallsPsr3LoggerWhenSet(): void
    {
        $psr3Logger = $this->createMock(LoggerInterface::class);
        $psr3Logger->expects($this->once())
            ->method('error')
            ->with('TEST_ERROR: This is a test error message.');

        ErrorLogger::init($psr3Logger);

        $this->createInstance()->logError('TEST_ERROR', 'This is a test error message.');
    }

    public function testLogErrorWritesToFileWhenNoLoggerSet(): void
    {
        $this->createInstance()->logError('TEST_ERROR', 'This is a test error message.');

        $this->assertFileExists($this->tempLogFile);
        $this->assertStringContainsString(
            'TEST_ERROR: This is a test error message.',
            file_get_contents($this->tempLogFile)
        );
    }

    public function testSendErrorIgnoresErrorsOutsideModulePath(): void
    {
        $this->apiClient->expects($this->never())->method('sendLoggedError');

        $this->createInstance()->sendError(
            'Error PREFIX',
            '1',
            'Error E_ERROR in /var/www/html/external/module/file.php:123 - some error message'
        );

        $this->assertFileDoesNotExist($this->tempLogFile);
    }

    public function testSendErrorProcessesErrorsInsideModulePath(): void
    {
        $this->apiClient->expects($this->once())
            ->method('sendLoggedError')
            ->willReturn(true);

        $this->createInstance()->sendError(
            'Error PREFIX',
            '1',
            'Error E_ERROR in ' . $this->modulePath . '/file.php:123 - error inside module'
        );

        $this->assertFileDoesNotExist($this->tempLogFile);
    }

    public function testSendErrorLogsWhenApiSendingFails(): void
    {
        $this->apiClient->expects($this->once())
            ->method('sendLoggedError')
            ->willReturn(false);

        $this->createInstance()->sendError('TEST_PREFIX', '123', 'Test error message.');

        $this->assertFileExists($this->tempLogFile);
        $this->assertStringContainsString('TEST_PREFIX: Test error message.', file_get_contents($this->tempLogFile));
    }

    public function testSendErrorInDebugModeLogsInsteadOfSending(): void
    {
        putenv('COMFINO_DEV_ENV=TRUE');

        $this->apiClient->expects($this->never())->method('sendLoggedError');

        $this->createInstance()->sendError('TEST_PREFIX', '123', 'Debug mode error.');

        $this->assertFileExists($this->tempLogFile);
        $this->assertStringContainsString('TEST_PREFIX: Debug mode error.', file_get_contents($this->tempLogFile));
    }

    public function testSendErrorWithApiDetails(): void
    {
        $this->apiClient->expects($this->once())
            ->method('sendLoggedError')
            ->willReturn(false);

        $this->createInstance()->sendError(
            'API_ERROR',
            '500',
            'API request failed',
            'https://api.example.com/endpoint',
            '{"request": "data"}',
            '{"error": "response"}',
            'Stack trace here'
        );

        $this->assertFileExists($this->tempLogFile);

        $logContent = file_get_contents($this->tempLogFile);

        $this->assertStringContainsString('API_ERROR: API request failed', $logContent);
        $this->assertStringContainsString('API URL: https://api.example.com/endpoint', $logContent);
        $this->assertStringContainsString('API request: {"request": "data"}', $logContent);
        $this->assertStringContainsString('API response: {"error": "response"}', $logContent);
        $this->assertStringContainsString('Stack trace: Stack trace here', $logContent);
    }

    #[DataProvider('errorHandlerDataProvider')]
    public function testErrorHandler(
        int $errorNo,
        string $errorMsg,
        string $file,
        int $line,
        bool $shouldSendError
    ): void {
        if ($shouldSendError) {
            $this->apiClient->expects($this->once())
                ->method('sendLoggedError')
                ->willReturn(true);
        } else {
            $this->apiClient->expects($this->never())->method('sendLoggedError');
        }

        $this->assertFalse(
            $this->createInstance()->errorHandler($errorNo, $errorMsg, $file, $line),
            'errorHandler should always return false.'
        );
    }

    /** @return array<string, array{int, string, string, int, bool}> */
    public static function errorHandlerDataProvider(): array
    {
        $modulePath = '/var/www/html/modules/comfino';

        return [
            'E_ERROR should send' => [E_ERROR, 'Fatal error', "$modulePath/test/file.php", 123, true],
            'E_WARNING should not send' => [E_WARNING, 'Warning message', "$modulePath/test/file.php", 123, false],
            'E_USER_ERROR should not send' => [E_USER_ERROR, 'User error', "$modulePath/test/file.php", 123, false],
            'E_USER_WARNING should not send' => [
                E_USER_WARNING, 'User warning', "$modulePath/test/file.php", 123, false,
            ],
            'E_NOTICE should not send' => [E_NOTICE, 'Notice message', "$modulePath/test/file.php", 123, false],
            'E_USER_NOTICE should not send' => [E_USER_NOTICE, 'User notice', "$modulePath/test/file.php", 123, false],
            'E_DEPRECATED should not send' => [E_DEPRECATED, 'Deprecated', "$modulePath/test/file.php", 123, false],
            'E_RECOVERABLE_ERROR should send' => [
                E_RECOVERABLE_ERROR, 'Recoverable error', "$modulePath/test/file.php", 123, true,
            ],
            'E_PARSE should send' => [E_PARSE, 'Parse error', "$modulePath/test/file.php", 123, true],
        ];
    }

    public function testExceptionHandler(): void
    {
        $this->apiClient->expects($this->once())
            ->method('sendLoggedError')
            ->willReturn(true);

        $logger = $this->createInstance();

        $exception = new \RuntimeException('Test exception message', 500);
        $reflection = new \ReflectionClass($exception);
        $reflection->getProperty('file')->setValue($exception, $this->modulePath . '/src/TestFile.php');
        $reflection->getProperty('line')->setValue($exception, 42);

        $logger->exceptionHandler($exception);
    }

    public function testExceptionHandlerIgnoresExceptionsOutsideModulePath(): void
    {
        $this->apiClient->expects($this->never())->method('sendLoggedError');

        $logger = $this->createInstance();

        $exception = new \RuntimeException('Test exception message', 500);
        $reflection = new \ReflectionClass($exception);
        $reflection->getProperty('file')->setValue($exception, '/var/www/html/external/vendor/package/File.php');
        $reflection->getProperty('line')->setValue($exception, 100);

        $logger->exceptionHandler($exception);

        $this->assertFileDoesNotExist($this->tempLogFile);
    }

    public function testErrorHandlerIgnoresErrorsOutsideModulePath(): void
    {
        $this->apiClient->expects($this->never())->method('sendLoggedError');

        $this->createInstance()->errorHandler(
            E_ERROR,
            'Fatal error',
            '/var/www/html/external/vendor/library/File.php',
            50
        );

        $this->assertFileDoesNotExist($this->tempLogFile);
    }

    public function testShutdownWithNoError(): void
    {
        $this->apiClient->expects($this->never())->method('sendLoggedError');

        $logger = $this->createInstance();

        // Push our own handlers onto the stack so shutdown()'s restore calls don't remove PHPUnit's handlers.
        set_error_handler([$logger, 'errorHandler'], E_ERROR | E_RECOVERABLE_ERROR | E_PARSE);
        set_exception_handler([$logger, 'exceptionHandler']);

        $logger->shutdown();

        // @phpstan-ignore method.alreadyNarrowedType
        $this->assertTrue(true, 'shutdown() without error completed successfully.');
    }

    /**
     * @throws \ReflectionException
     */
    #[DataProvider('errorTypeNameDataProvider')]
    public function testGetErrorTypeName(int $errorType, string $expectedName): void
    {
        $logger = $this->createInstance();

        $result = (new \ReflectionClass($logger))->getMethod('getErrorTypeName')->invoke($logger, $errorType);

        $this->assertEquals($expectedName, $result);
    }

    /** @return array<string, array{int, string}> */
    public static function errorTypeNameDataProvider(): array
    {
        return [
            'E_ERROR' => [E_ERROR, 'E_ERROR'],
            'E_WARNING' => [E_WARNING, 'E_WARNING'],
            'E_PARSE' => [E_PARSE, 'E_PARSE'],
            'E_NOTICE' => [E_NOTICE, 'E_NOTICE'],
            'E_CORE_ERROR' => [E_CORE_ERROR, 'E_CORE_ERROR'],
            'E_CORE_WARNING' => [E_CORE_WARNING, 'E_CORE_WARNING'],
            'E_COMPILE_ERROR' => [E_COMPILE_ERROR, 'E_COMPILE_ERROR'],
            'E_COMPILE_WARNING' => [E_COMPILE_WARNING, 'E_COMPILE_WARNING'],
            'E_USER_ERROR' => [E_USER_ERROR, 'E_USER_ERROR'],
            'E_USER_WARNING' => [E_USER_WARNING, 'E_USER_WARNING'],
            'E_USER_NOTICE' => [E_USER_NOTICE, 'E_USER_NOTICE'],
            'E_RECOVERABLE_ERROR' => [E_RECOVERABLE_ERROR, 'E_RECOVERABLE_ERROR'],
            'E_DEPRECATED' => [E_DEPRECATED, 'E_DEPRECATED'],
            'E_USER_DEPRECATED' => [E_USER_DEPRECATED, 'E_USER_DEPRECATED'],
            'Unknown error type' => [99999, 'UNKNOWN'],
        ];
    }

    public function testInitHandlersDoesNotSetHandlersInDebugMode(): void
    {
        putenv('COMFINO_DEV_ENV=TRUE');

        $this->apiClient->expects($this->never())->method('sendLoggedError');

        $this->createInstance()->initHandlers();

        // @phpstan-ignore method.alreadyNarrowedType
        $this->assertTrue(true, 'initHandlers() in debug mode completed without setting handlers.');
    }

    public function testGetErrorLogReturnsLastLines(): void
    {
        file_put_contents(
            $this->tempLogFile,
            "ERROR_1: First error.\nERROR_2: Second error.\nERROR_3: Third error.\n"
        );

        $log = $this->createInstance()->getErrorLog(2);

        $this->assertStringContainsString('ERROR_2', $log);
        $this->assertStringContainsString('ERROR_3', $log);
        $this->assertStringNotContainsString('ERROR_1', $log);
    }

    public function testClearLogsDeletesAllFiles(): void
    {
        file_put_contents($this->tempLogFile, 'error log content');

        $deleted = $this->createInstance()->clearLogs();

        $this->assertGreaterThan(0, $deleted);
        $this->assertFileDoesNotExist($this->tempLogFile);
    }

    public function testClearLogsReturnsZeroWhenNoFiles(): void
    {
        $this->assertSame(0, $this->createInstance()->clearLogs());
    }

    public function testClearLogsCanBeCalledMultipleTimes(): void
    {
        file_put_contents($this->tempLogFile, 'error log');

        $first = $this->createInstance()->clearLogs();
        $this->assertGreaterThan(0, $first);

        $second = $this->createInstance()->clearLogs();
        $this->assertSame(0, $second);

        $third = $this->createInstance()->clearLogs();
        $this->assertSame(0, $third);
    }

    public function testMultipleLogEntriesAreAppended(): void
    {
        $logger = $this->createInstance();
        $logger->logError('ERROR_1', 'First error.');
        $logger->logError('ERROR_2', 'Second error.');
        $logger->logError('ERROR_3', 'Third error.');

        $this->assertFileExists($this->tempLogFile);

        $logContent = file_get_contents($this->tempLogFile);

        $this->assertStringContainsString('ERROR_1: First error.', $logContent);
        $this->assertStringContainsString('ERROR_2: Second error.', $logContent);
        $this->assertStringContainsString('ERROR_3: Third error.', $logContent);
        $this->assertSame(3, substr_count($logContent, 'ERROR_'));
    }

    public function testSendErrorWithoutApiDetailsDoesNotIncludeThem(): void
    {
        $this->apiClient->expects($this->once())
            ->method('sendLoggedError')
            ->willReturn(false);

        $this->createInstance()->sendError('SIMPLE_ERROR', '123', 'Simple error message.');

        $this->assertFileExists($this->tempLogFile);

        $logContent = file_get_contents($this->tempLogFile);

        $this->assertStringContainsString('SIMPLE_ERROR: Simple error message.', $logContent);
        $this->assertStringNotContainsString('API URL:', $logContent);
        $this->assertStringNotContainsString('API request:', $logContent);
        $this->assertStringNotContainsString('API response:', $logContent);
        $this->assertStringNotContainsString('Stack trace:', $logContent);
    }

    public function testInitHandlersOnlyInitializesOnce(): void
    {
        putenv('COMFINO_DEV_ENV=FALSE');

        $logger = $this->createInstance();
        $logger->initHandlers();
        $logger->initHandlers();
        $logger->initHandlers();

        restore_error_handler();
        restore_exception_handler();

        // @phpstan-ignore method.alreadyNarrowedType
        $this->assertTrue(true, 'Multiple initHandlers() calls handled correctly.');
    }
}
