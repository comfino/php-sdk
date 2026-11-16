<?php

/**
 * ComfinoPay PHP SDK
 *
 * Backend routines for e-commerce platforms integration with the ComfinoPay payment gateway API.
 *
 * @package Comfino\Tests\Unit\Support
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
 * @copyright Copyright (c) 2026 by ComfinoPay sp. z o.o.
 * @license https://opensource.org/licenses/BSD-3-Clause BSD 3-Clause License
 * @link https://github.com/comfino/php-sdk
 */

declare(strict_types=1);

namespace Comfino\Tests\Unit\Support;

use Nyholm\Psr7\Response;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/** PSR-18 client that answers with the queued responses in order, then with a 500. */
final class ScriptedFlagsClient implements ClientInterface
{
    public int $requestCount = 0;

    /** @param ResponseInterface[] $responses */
    public function __construct(private array $responses)
    {
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->requestCount++;

        return array_shift($this->responses) ?? new Response(500);
    }
}
