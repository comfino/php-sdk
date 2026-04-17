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

use Comfino\Backend\Log\LoggerFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class LoggerFactoryTest extends TestCase
{
    public function testCreateNullLoggerReturnsNullLogger(): void
    {
        $this->assertInstanceOf(NullLogger::class, LoggerFactory::createNullLogger());
    }

    public function testCreateDebugLoggerReturnsNullLoggerByDefault(): void
    {
        $this->assertInstanceOf(NullLogger::class, LoggerFactory::createDebugLogger());
    }

    public function testCreateErrorLoggerReturnsNullLoggerByDefault(): void
    {
        $this->assertInstanceOf(NullLogger::class, LoggerFactory::createErrorLogger());
    }

    public function testNullLoggerDiscardsMessages(): void
    {
        $logger = LoggerFactory::createNullLogger();

        // NullLogger should not throw exceptions.
        $logger->debug('Debug message', ['key' => 'value']);
        $logger->info('Info message');
        $logger->warning('Warning message');
        $logger->error('Error message');
        $logger->critical('Critical message');

        // @phpstan-ignore method.alreadyNarrowedType
        $this->assertTrue(true, 'NullLogger discards messages without throwing exceptions.');
    }

    public function testDebugLoggerDiscardsMessages(): void
    {
        $logger = LoggerFactory::createDebugLogger();

        $logger->debug('Debug message');
        $logger->error('Error message');

        // @phpstan-ignore method.alreadyNarrowedType
        $this->assertTrue(true, 'Default debug logger discards messages without throwing exceptions.');
    }

    public function testErrorLoggerDiscardsMessages(): void
    {
        $logger = LoggerFactory::createErrorLogger();

        $logger->error('Error message');
        $logger->critical('Critical message');

        // @phpstan-ignore method.alreadyNarrowedType
        $this->assertTrue(true, 'Default error logger discards messages without throwing exceptions.');
    }
}
