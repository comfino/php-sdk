<?php

/**
 * Comfino PHP SDK
 *
 * Backend routines for e-commerce platforms integration with the Comfino payment gateway API.
 *
 * @package Comfino\Tests\Unit\Backend
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
 * @copyright Copyright (c) 2026 Comfino by Comperia.pl S.A.
 * @license https://opensource.org/licenses/BSD-3-Clause BSD 3-Clause License
 * @link https://github.com/comfino/php-sdk
 */

declare(strict_types=1);

namespace Comfino\Tests\Unit\Backend;

use Comfino\Backend\FileUtils;
use PHPUnit\Framework\TestCase;

final class FileUtilsTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmpDir = sys_get_temp_dir() . '/comfino_test_' . uniqid('', true);

        mkdir($this->tmpDir, 0755, true);
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        array_map('unlink', glob($this->tmpDir . '/*') ?: []);
        rmdir($this->tmpDir);
    }

    public function testBuildPathFromComponents(): void
    {
        $this->assertSame(
            'foo' . DIRECTORY_SEPARATOR . 'bar' . DIRECTORY_SEPARATOR . 'baz.txt',
            FileUtils::buildPathFromComponents(['foo', 'bar', 'baz.txt'])
        );
    }

    public function testBuildPathFromSingleComponent(): void
    {
        $this->assertSame('file.txt', FileUtils::buildPathFromComponents(['file.txt']));
    }

    public function testBuildPathFromEmptyComponents(): void
    {
        $this->assertSame('', FileUtils::buildPathFromComponents([]));
    }

    public function testReadReturnsFileContents(): void
    {
        $path = $this->tmpDir . '/read_test.txt';

        file_put_contents($path, 'hello world');

        $this->assertSame('hello world', FileUtils::read($path));
    }

    public function testReadReturnsEmptyStringForNonExistentFile(): void
    {
        $this->assertSame('', FileUtils::read($this->tmpDir . '/does_not_exist.txt'));
    }

    public function testReadLastLinesReturnsLastNLines(): void
    {
        $path = $this->tmpDir . '/lines_test.txt';

        file_put_contents($path, "line1\nline2\nline3\nline4\nline5\n");

        $lines = FileUtils::readLastLines($path, 3);

        $this->assertNotEmpty($lines);

        $joined = implode('', $lines);

        $this->assertStringContainsString('line3', $joined);
        $this->assertStringContainsString('line4', $joined);
        $this->assertStringContainsString('line5', $joined);
    }

    public function testReadLastLinesReturnsAllLinesWhenFewerThanRequested(): void
    {
        $path = $this->tmpDir . '/short_test.txt';

        file_put_contents($path, "a\nb\n");

        $this->assertNotEmpty(FileUtils::readLastLines($path, 10));
    }

    public function testReadLastLinesReturnsEmptyArrayForNonExistentFile(): void
    {
        $this->assertSame([], FileUtils::readLastLines($this->tmpDir . '/missing.txt', 5));
    }

    public function testWriteCreatesFileWithContent(): void
    {
        $path = $this->tmpDir . '/write_test.txt';

        FileUtils::write($path, 'test content');

        $this->assertFileExists($path);
        $this->assertSame('test content', file_get_contents($path));
    }

    public function testWriteOverwritesExistingFile(): void
    {
        $path = $this->tmpDir . '/overwrite_test.txt';

        file_put_contents($path, 'old content');

        FileUtils::write($path, 'new content');

        $this->assertSame('new content', file_get_contents($path));
    }

    public function testAppendAddsContentToFile(): void
    {
        $path = $this->tmpDir . '/append_test.txt';

        file_put_contents($path, 'first');

        FileUtils::append($path, ' second');

        $this->assertSame('first second', file_get_contents($path));
    }

    public function testExistsReturnsTrueForExistingFile(): void
    {
        $path = $this->tmpDir . '/exists_test.txt';

        file_put_contents($path, '');

        $this->assertTrue(FileUtils::exists($path));
    }

    public function testExistsReturnsFalseForNonExistentFile(): void
    {
        $this->assertFalse(FileUtils::exists($this->tmpDir . '/no_such_file.txt'));
    }

    public function testExistsReturnsFalseForDirectory(): void
    {
        $this->assertFalse(FileUtils::exists($this->tmpDir));
    }

    public function testIsWritableReturnsTrueForWritableFile(): void
    {
        $path = $this->tmpDir . '/writable_test.txt';

        file_put_contents($path, '');

        $this->assertTrue(FileUtils::isWritable($path));
    }

    public function testIsWritableReturnsTrueForWritableDirectory(): void
    {
        // File does not exist - should check directory writability.
        $this->assertTrue(FileUtils::isWritable($this->tmpDir . '/new_file.txt'));
    }

    public function testIsReadableReturnsTrueForReadableFile(): void
    {
        $path = $this->tmpDir . '/readable_test.txt';

        file_put_contents($path, 'data');

        $this->assertTrue(FileUtils::isReadable($path));
    }

    public function testIsReadableReturnsFalseForNonExistentFile(): void
    {
        $this->assertFalse(FileUtils::isReadable($this->tmpDir . '/phantom.txt'));
    }
}
