<?php

/**
 * ComfinoPay PHP SDK
 *
 * Backend routines for e-commerce platforms integration with the ComfinoPay payment gateway API.
 *
 * @package Comfino\Tests\Unit\Backend\Queue
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
 * @copyright Copyright (c) 2026 by ComfinoPay sp. z o.o.
 * @license https://opensource.org/licenses/BSD-3-Clause BSD 3-Clause License
 * @link https://github.com/comfino/php-sdk
 */

declare(strict_types=1);

namespace Comfino\Tests\Unit\Backend\Queue;

use Comfino\Backend\Queue\RetryableOperationHandlerInterface;
use Throwable;

/**
 * Handler whose behavior is scripted per call: a list of outcomes (a Throwable to throw, or null to succeed) is
 * consumed in order; once exhausted, every further call succeeds. Records the payloads it was called with.
 */
final class ScriptedHandler implements RetryableOperationHandlerInterface
{
    /** @var array<int, Throwable|null> */
    private array $outcomes;

    /** @var array<int, array<string, scalar>> */
    public array $calls = [];

    /**
     * @param array<int, Throwable|null> $outcomes
     */
    public function __construct(array $outcomes = [])
    {
        $this->outcomes = $outcomes;
    }

    /**
     * Convenience: a handler that always throws the given error.
     */
    public static function alwaysThrows(Throwable $error): self
    {
        $handler = new self();
        $handler->outcomes = [];
        $handler->always = $error;

        return $handler;
    }

    private ?Throwable $always = null;

    /**
     * @throws Throwable
     */
    public function execute(array $payload): void
    {
        $this->calls[] = $payload;

        if ($this->always !== null) {
            throw $this->always;
        }

        $outcome = array_shift($this->outcomes);

        if ($outcome instanceof Throwable) {
            throw $outcome;
        }
    }

    public function callCount(): int
    {
        return count($this->calls);
    }
}
