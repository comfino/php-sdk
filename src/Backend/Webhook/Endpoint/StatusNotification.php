<?php

/**
 * Comfino PHP SDK
 *
 * Backend routines for e-commerce platforms integration with the Comfino payment gateway API.
 *
 * @package Comfino\Backend\Webhook\Endpoint
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
 * @copyright Copyright (c) 2026 Comfino by Comperia.pl S.A.
 * @license https://opensource.org/licenses/BSD-3-Clause BSD 3-Clause License
 * @link https://github.com/comfino/php-sdk
 */

declare(strict_types=1);

namespace Comfino\Backend\Webhook\Endpoint;

use Comfino\Api\HttpErrorExceptionInterface;
use Comfino\Api\Exception\InvalidRequest;
use Comfino\Backend\Webhook\WebhookEndpoint;
use Comfino\Enum\OrderStatus;
use Comfino\Shop\Order\StatusManager;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;

class StatusNotification extends WebhookEndpoint
{
    public function __construct(
        string $name,
        string $endpointUrl,
        private readonly StatusManager $statusManager,
        /** @var string[] */
        private readonly array $forbiddenStatuses,
        /** @var string[] */
        private readonly array $ignoredStatuses
    ) {
        parent::__construct($name, $endpointUrl);

        $this->methods = ['POST', 'PUT', 'PATCH'];
    }

    /** @return array<string, mixed>|null */
    public function processRequest(ServerRequestInterface $serverRequest, ?string $endpointName = null): ?array
    {
        $requestPayload = parent::processRequest($serverRequest, $endpointName);

        if (!isset($requestPayload['status'])) {
            throw new InvalidRequest(
                (string) $serverRequest->getUri(),
                $serverRequest->getBody()->getContents(),
                'Status must be set.'
            );
        }

        $status = OrderStatus::fromApiValue($requestPayload['status']);

        if (in_array($status->getValue(), $this->ignoredStatuses, true)) {
            return null;
        }

        if (!isset($requestPayload['externalId'])) {
            throw new InvalidRequest(
                (string) $serverRequest->getUri(),
                $serverRequest->getBody()->getContents(),
                'External ID must be set.'
            );
        }

        if (in_array($status->getValue(), $this->forbiddenStatuses, true)) {
            throw new InvalidRequest(
                (string) $serverRequest->getUri(),
                $serverRequest->getBody()->getContents(),
                'Invalid status "' . $requestPayload['status'] . '".'
            );
        }

        try {
            $this->statusManager->setOrderStatus($requestPayload['externalId'], $requestPayload['status']);
        } catch (Throwable $e) {
            if ($e instanceof HttpErrorExceptionInterface) {
                throw $e;
            }

            throw new InvalidRequest(
                (string) $serverRequest->getUri(),
                $serverRequest->getBody()->getContents(),
                $e->getMessage(),
                $e->getCode(),
                $e
            );
        }

        return null;
    }
}
