<?php

/**
 * Comfino PHP SDK
 *
 * Backend routines for e-commerce platforms integration with the Comfino payment gateway API.
 *
 * @package Comfino\Backend\Log
 * @author Artur Kozubski <akozubski@comperia.pl>
 * @copyright Copyright (c) 2026 Comfino by Comperia.pl S.A.
 * @license https://opensource.org/licenses/BSD-3-Clause BSD 3-Clause License
 * @link https://github.com/comfino/php-sdk
 */

declare(strict_types=1);

namespace Comfino\Backend\Log;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * PSR-3 logger factory for the Comfino SDK.
 *
 * Returns NullLogger by default. Integrations should inject their own PSR-3 logger
 * implementations via DebugLogger::init() and ErrorLogger::init() during bootstrap.
 */
final class LoggerFactory
{
    /**
     * Creates a null logger that discards all messages.
     *
     * Use this as a placeholder until a real logger is configured.
     */
    public static function createNullLogger(): LoggerInterface
    {
        return new NullLogger();
    }

    /**
     * Creates a debug logger stub (NullLogger).
     *
     * Provide a real implementation by calling DebugLogger::init($psr3Logger) during bootstrap.
     */
    public static function createDebugLogger(): LoggerInterface
    {
        return new NullLogger();
    }

    /**
     * Creates an error logger stub (NullLogger).
     *
     * Provide a real implementation by calling ErrorLogger::init($psr3Logger) during bootstrap.
     */
    public static function createErrorLogger(): LoggerInterface
    {
        return new NullLogger();
    }
}
