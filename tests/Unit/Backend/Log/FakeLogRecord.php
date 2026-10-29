<?php

/**
 * ComfinoPay PHP SDK
 *
 * Backend routines for e-commerce platforms integration with the ComfinoPay payment gateway API.
 *
 * @package Comfino\Tests\Unit\Backend\Log
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
 * @copyright Copyright (c) 2026 by ComfinoPay sp. z o.o.
 * @license https://opensource.org/licenses/BSD-3-Clause BSD 3-Clause License
 * @link https://github.com/comfino/php-sdk
 */

declare(strict_types=1);

namespace Comfino\Tests\Unit\Backend\Log;

/**
 * Stand-in for Monolog 3's immutable `LogRecord` (Monolog is not a dependency): readonly context/extra and a `with()`
 * that returns a modified copy.
 */
final class FakeLogRecord
{
    /**
     * @param array<string, mixed> $context
     * @param array<string, mixed> $extra
     */
    public function __construct(public readonly array $context = [], public readonly array $extra = [], public readonly string $message = '')
    {
    }

    /**
     * @param array<string, mixed>|null $context
     * @param array<string, mixed>|null $extra
     */
    public function with(?array $context = null, ?array $extra = null, ?string $message = null): self
    {
        return new self($context ?? $this->context, $extra ?? $this->extra, $message ?? $this->message);
    }
}
