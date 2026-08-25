<?php

/**
 * Comfino PHP SDK
 *
 * Backend routines for e-commerce platforms integration with the Comfino payment gateway API.
 *
 * @package Comfino\Tests\Unit\Support
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
 * @copyright Copyright (c) 2026 Comfino by Comperia.pl S.A.
 * @license https://opensource.org/licenses/BSD-3-Clause BSD 3-Clause License
 * @link https://github.com/comfino/php-sdk
 */

declare(strict_types=1);

namespace Comfino\Tests\Unit\Support;

use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;

/**
 * PSR-18 client that fails every request and counts the attempts it received. Used by tests that assert on how many
 * times a failing Comfino API endpoint is actually contacted, which is what negative caching is there to bound.
 */
final class FailingHttpClient implements ClientInterface
{
    public int $requestCount = 0;

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->requestCount++;

        throw new class ('Comfino API is down.') extends RuntimeException implements ClientExceptionInterface {
        };
    }
}
