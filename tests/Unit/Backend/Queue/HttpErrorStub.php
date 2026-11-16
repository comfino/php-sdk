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

use Comfino\Api\HttpErrorExceptionInterface;
use Exception;

/**
 * Minimal HTTP error double with a configurable status code, exercising the classifier's status-based branches
 * without depending on the concrete api-client exception constructors.
 */
final class HttpErrorStub extends Exception implements HttpErrorExceptionInterface
{
    private string $requestBody = '';
    private string $responseBody = '';

    public function __construct(private readonly int $statusCode, string $message = 'http error')
    {
        parent::__construct($message, $statusCode);
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    public function getUrl(): string
    {
        return 'https://api-ecommerce.comfino.pl/v1/orders/1/cancel';
    }

    public function getRequestBody(): string
    {
        return $this->requestBody;
    }

    public function setRequestBody(string $requestBody): void
    {
        $this->requestBody = $requestBody;
    }

    public function getResponseBody(): string
    {
        return $this->responseBody;
    }

    public function setResponseBody(string $responseBody): void
    {
        $this->responseBody = $responseBody;
    }
}
