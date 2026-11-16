<?php

/**
 * ComfinoPay PHP SDK
 *
 * Backend routines for e-commerce platforms integration with the ComfinoPay payment gateway API.
 *
 * @package Comfino\Tests\Unit\Backend\Api
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
 * @copyright Copyright (c) 2026 by ComfinoPay sp. z o.o.
 * @license https://opensource.org/licenses/BSD-3-Clause BSD 3-Clause License
 * @link https://github.com/comfino/php-sdk
 */

declare(strict_types=1);

namespace Comfino\Tests\Unit\Backend\Api;

use Psr\Log\AbstractLogger;
use Stringable;

/**
 * A PSR-3 logger that keeps every message with its context.
 */
final class RecordingLogger extends AbstractLogger
{
    /** @var array<int, array{message: string, context: array<string, mixed>}> */
    public array $records = [];

    /**
     * @param mixed $level
     * @param array<string, mixed> $context
     *
     * @psalm-suppress MoreSpecificImplementedParamType PSR-3 declares a plain array; tests only pass string keys
     */
    public function log($level, string|Stringable $message, array $context = []): void
    {
        $this->records[] = ['message' => (string) $message, 'context' => $context];
    }
}
