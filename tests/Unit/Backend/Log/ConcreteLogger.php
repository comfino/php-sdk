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

use Comfino\Backend\Log\Logger;

/**
 * Concrete subclass that exposes Logger's protected methods for testing.
 */
final class ConcreteLogger extends Logger
{
    public function exposeFindActualLogFile(string $logFilePath): ?string
    {
        return $this->findActualLogFile($logFilePath);
    }

    /**
     * @return string[]
     */
    public function exposeFindAllLogFiles(string $logFilePath): array
    {
        return $this->findAllLogFiles($logFilePath);
    }

    public function exposeClearLogFiles(string $logFilePath): int
    {
        return $this->clearLogFiles($logFilePath);
    }
}
