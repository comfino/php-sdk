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

use Comfino\Backend\Log\DebugLogger;
use Comfino\Backend\Log\ServiceModeCheckerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class DebugLoggerTest extends TestCase
{
    private string $tempLogFile;
    private string $tempLogDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempLogDir = sys_get_temp_dir() . '/comfino_debug_test_' . uniqid('', true);
        $this->tempLogFile = $this->tempLogDir . '/debug.log';

        DebugLogger::reset();
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        // Clean up temporary log files.
        if (is_dir($this->tempLogDir)) {
            foreach (glob($this->tempLogDir . '/*') ?: [] as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }

            foreach (glob($this->tempLogDir . '/.*') ?: [] as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }

            rmdir($this->tempLogDir);
        }

        DebugLogger::reset();
    }

    public function testGetInstanceReturnsSameInstance(): void
    {
        $logger1 = DebugLogger::getInstance($this->tempLogFile);
        $logger2 = DebugLogger::getInstance('/different/path.log');

        $this->assertSame($logger1, $logger2, 'getInstance should return the same instance (singleton pattern).');
    }

    public function testInitSetsLogger(): void
    {
        $psr3Logger = $this->createMock(LoggerInterface::class);

        DebugLogger::init($psr3Logger);

        $this->assertSame($psr3Logger, DebugLogger::getInstance($this->tempLogFile)->getLogger());
    }

    public function testGetLoggerReturnsNullWhenNotInitialized(): void
    {
        $this->assertNull(DebugLogger::getInstance($this->tempLogFile)->getLogger());
    }

    public function testLogEventRendersEventTypeLabelAndCapturesCaller(): void
    {
        $psr3Logger = $this->createMock(LoggerInterface::class);
        $psr3Logger->expects($this->once())
            ->method('debug')
            ->with(
                $this->equalTo('[TEST] Test message'),
                $this->callback(
                    static fn (array $params): bool => $params['key'] === 'value' && isset($params['_caller'])
                )
            );

        DebugLogger::init($psr3Logger);
        DebugLogger::getInstance($this->tempLogFile)->logEvent('Test message', ['key' => 'value'], 'TEST');
    }

    public function testLogEventDoesNothingWhenNoLoggerSet(): void
    {
        // Should not throw any exception when no logger is set.
        DebugLogger::getInstance($this->tempLogFile)->logEvent('Test message', ['key' => 'value'], 'TEST');

        // @phpstan-ignore method.alreadyNarrowedType
        $this->assertTrue(true, 'logEvent with no logger should not throw exceptions.');
    }

    public function testLogEventWithNullParametersStillCapturesCaller(): void
    {
        $psr3Logger = $this->createMock(LoggerInterface::class);
        $psr3Logger->expects($this->once())
            ->method('debug')
            ->with(
                $this->equalTo('[NULL_PARAMS] Message with null parameters'),
                $this->callback(static fn (array $params): bool => array_keys($params) === ['_caller'])
            );

        DebugLogger::init($psr3Logger);
        DebugLogger::getInstance($this->tempLogFile)->logEvent('Message with null parameters', null, 'NULL_PARAMS');
    }

    public function testLogEventWithoutEventTypeKeepsMessageClean(): void
    {
        $psr3Logger = $this->createMock(LoggerInterface::class);
        $psr3Logger->expects($this->once())
            ->method('debug')
            ->with(
                $this->equalTo('Simple message'),
                $this->callback(static fn (array $params): bool => isset($params['_caller']))
            );

        DebugLogger::init($psr3Logger);
        DebugLogger::getInstance($this->tempLogFile)->logEvent('Simple message');
    }

    public function testLogEventConditionalLogsWhenDebugAndServiceModeActive(): void
    {
        $psr3Logger = $this->createMock(LoggerInterface::class);
        $psr3Logger->expects($this->once())
            ->method('debug')
            ->with(
                $this->equalTo('[API_TIMEOUT] Timed out'),
                $this->callback(static fn (array $params): bool => isset($params['_caller']))
            );

        $checker = $this->createMock(ServiceModeCheckerInterface::class);
        $checker->method('isServiceMode')->willReturn(true);

        DebugLogger::init($psr3Logger);
        DebugLogger::getInstance($this->tempLogFile)
            ->logEventConditional('Timed out', true, $checker, null, 'API_TIMEOUT');
    }

    public function testLogEventConditionalSuppressedWhenDebugModeOff(): void
    {
        $psr3Logger = $this->createMock(LoggerInterface::class);
        $psr3Logger->expects($this->never())->method('debug');

        $checker = $this->createMock(ServiceModeCheckerInterface::class);
        $checker->expects($this->never())->method('isServiceMode');

        DebugLogger::init($psr3Logger);
        DebugLogger::getInstance($this->tempLogFile)
            ->logEventConditional('Suppressed', false, $checker, null, 'API_TIMEOUT');
    }

    public function testLogEventConditionalSuppressedWhenServiceModeOff(): void
    {
        $psr3Logger = $this->createMock(LoggerInterface::class);
        $psr3Logger->expects($this->never())->method('debug');

        $checker = $this->createMock(ServiceModeCheckerInterface::class);
        $checker->method('isServiceMode')->willReturn(false);

        DebugLogger::init($psr3Logger);
        DebugLogger::getInstance($this->tempLogFile)
            ->logEventConditional('Suppressed', true, $checker, null, 'API_TIMEOUT');
    }

    public function testResetClearsSingletonAndLogger(): void
    {
        $psr3Logger = $this->createMock(LoggerInterface::class);

        DebugLogger::init($psr3Logger);

        $logger1 = DebugLogger::getInstance($this->tempLogFile);

        $this->assertSame($psr3Logger, $logger1->getLogger());

        DebugLogger::reset();

        $logger2 = DebugLogger::getInstance($this->tempLogFile);

        $this->assertNull($logger2->getLogger());

        // After reset, a new instance is created (may or may not be the same object - depends on implementation).
    }

    public function testGetDebugLogReturnsEmptyWhenNoFileExists(): void
    {
        $this->assertEmpty(
            DebugLogger::getInstance($this->tempLogFile)->getDebugLog(10),
            'Should return empty string when no log file exists.'
        );
    }

    public function testGetDebugLogReturnsEmptyWhenLogFilePathIsEmpty(): void
    {
        $this->assertEmpty(DebugLogger::getInstance('')->getDebugLog(10));
    }

    public function testClearLogsReturnsZeroWhenNoFiles(): void
    {
        $this->assertEquals(0, DebugLogger::getInstance($this->tempLogFile)->clearLogs());
    }

    public function testClearLogsDeletesExistingFiles(): void
    {
        // Create the temp log directory and a fake log file.
        mkdir($this->tempLogDir, 0755, true);

        $logFile = $this->tempLogDir . '/debug-2026-04-05.log';

        file_put_contents($logFile, 'test log content');

        $deleted = DebugLogger::getInstance($this->tempLogFile)->clearLogs();

        $this->assertGreaterThan(0, $deleted);
        $this->assertFileDoesNotExist($logFile);
    }

    public function testMultipleLogEventsPassedToLogger(): void
    {
        $psr3Logger = $this->createMock(LoggerInterface::class);
        $psr3Logger->expects($this->exactly(3))->method('debug');

        DebugLogger::init($psr3Logger);

        $logger = DebugLogger::getInstance($this->tempLogFile);

        $logger->logEvent('First event', null, 'EVENT_1');
        $logger->logEvent('Second event', null, 'EVENT_2');
        $logger->logEvent('Third event', null, 'EVENT_3');
    }

    public function testGetDebugLogReturnsLastLines(): void
    {
        mkdir($this->tempLogDir, 0755, true);
        file_put_contents(
            $this->tempLogFile,
            "[LOG_1]: First log entry\n[LOG_2]: Second log entry\n[LOG_3]: Third log entry\n"
        );

        $log = DebugLogger::getInstance($this->tempLogFile)->getDebugLog(2);

        $this->assertStringContainsString('[LOG_2]', $log);
        $this->assertStringContainsString('[LOG_3]', $log);
        $this->assertStringNotContainsString('[LOG_1]', $log);
    }

    public function testGetDebugLogFindsRotatedLogFile(): void
    {
        mkdir($this->tempLogDir, 0755, true);
        $rotatedPath = $this->tempLogDir . '/debug-2026-04-15.log';
        file_put_contents($rotatedPath, "[ROTATED]: Message in rotated file\n");

        $log = DebugLogger::getInstance($this->tempLogFile)->getDebugLog(10);

        $this->assertStringContainsString('[ROTATED]: Message in rotated file', $log);
        $this->assertFileDoesNotExist($this->tempLogFile);
    }

    public function testClearLogsCanBeCalledMultipleTimes(): void
    {
        mkdir($this->tempLogDir, 0755, true);
        file_put_contents($this->tempLogDir . '/debug-2026-04-15.log', 'test log entry');

        $first = DebugLogger::getInstance($this->tempLogFile)->clearLogs();
        $this->assertGreaterThan(0, $first);

        $second = DebugLogger::getInstance($this->tempLogFile)->clearLogs();
        $this->assertSame(0, $second);

        $third = DebugLogger::getInstance($this->tempLogFile)->clearLogs();
        $this->assertSame(0, $third);
    }
}
