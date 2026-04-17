<?php

/**
 * Comfino PHP SDK
 *
 * Backend routines for e-commerce platforms integration with the Comfino payment gateway API.
 *
 * @package Comfino\Tests\Unit\Backend\Log
 * @author Artur Kozubski <akozubski@comperia.pl>
 * @copyright Copyright (c) 2026 Comfino by Comperia.pl S.A.
 * @license https://opensource.org/licenses/BSD-3-Clause BSD 3-Clause License
 * @link https://github.com/comfino/php-sdk
 */

declare(strict_types=1);

namespace Comfino\Tests\Unit\Backend\Log;

use PHPUnit\Framework\TestCase;

final class LoggerTest extends TestCase
{
    private string $tmpDir;
    private ConcreteLogger $logger;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmpDir = sys_get_temp_dir() . '/comfino_logger_test_' . uniqid('', true);

        mkdir($this->tmpDir, 0755, true);

        $this->logger = new ConcreteLogger();
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        array_map('unlink', glob($this->tmpDir . '/*') ?: []);
        rmdir($this->tmpDir);
    }

    public function testFindActualLogFileReturnsPathWhenFileExists(): void
    {
        $path = $this->tmpDir . '/app.log';

        file_put_contents($path, 'log data');

        $this->assertSame($path, $this->logger->exposeFindActualLogFile($path));
    }

    public function testFindActualLogFileReturnsNullWhenNoFilesExist(): void
    {
        $this->assertNull($this->logger->exposeFindActualLogFile($this->tmpDir . '/nonexistent.log'));
    }

    public function testFindActualLogFileFallsBackToRotatedFile(): void
    {
        $rotatedPath = $this->tmpDir . '/app-2026-04-01.log';

        file_put_contents($rotatedPath, 'rotated log data');

        $this->assertSame($rotatedPath, $this->logger->exposeFindActualLogFile($this->tmpDir . '/app.log'));
    }

    public function testFindAllLogFilesReturnsRotatedFiles(): void
    {
        $basePath = $this->tmpDir . '/app.log';
        $rotated1 = $this->tmpDir . '/app-2026-04-01.log';
        $rotated2 = $this->tmpDir . '/app-2026-03-15.log';

        file_put_contents($rotated1, 'data1');
        file_put_contents($rotated2, 'data2');

        $result = $this->logger->exposeFindAllLogFiles($basePath);

        $this->assertCount(2, $result);
        $this->assertContains($rotated1, $result);
        $this->assertContains($rotated2, $result);
    }

    public function testFindAllLogFilesReturnsEmptyWhenNoneExist(): void
    {
        $this->assertSame([], $this->logger->exposeFindAllLogFiles($this->tmpDir . '/nothing.log'));
    }

    public function testClearLogFilesDeletesMainFile(): void
    {
        $path = $this->tmpDir . '/app.log';

        file_put_contents($path, 'log');

        $this->assertSame(1, $this->logger->exposeClearLogFiles($path)); // Number of deleted files.
        $this->assertFileDoesNotExist($path);
    }

    public function testClearLogFilesDeletesRotatedFilesAlso(): void
    {
        $basePath = $this->tmpDir . '/app.log';
        $rotated = $this->tmpDir . '/app-2026-04-01.log';

        file_put_contents($basePath, 'main');
        file_put_contents($rotated, 'rotated');

        $this->assertSame(2, $this->logger->exposeClearLogFiles($basePath)); // Number of deleted files.
        $this->assertFileDoesNotExist($basePath);
        $this->assertFileDoesNotExist($rotated);
    }

    public function testClearLogFilesReturnsZeroWhenNoFilesExist(): void
    {
        // Number of deleted files.
        $this->assertSame(0, $this->logger->exposeClearLogFiles($this->tmpDir . '/ghost.log'));
    }
}
