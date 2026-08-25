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
use Comfino\Backend\Webhook\StatusAdapterResolverInterface;
use Comfino\Backend\Webhook\TenantAwareWebhookEndpointInterface;
use Comfino\Backend\Webhook\VerifiedWebhookRequest;
use Comfino\Backend\Webhook\WebhookEndpoint;
use Comfino\Enum\OrderStatus;
use Comfino\Shop\Order\StatusAdapterInterface;
use Comfino\Shop\Order\StatusManager;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;

/**
 * The `status` webhook: Comfino tells the shop that a loan changed state.
 *
 * **Two shapes, and the second one is why this class implements
 * {@see TenantAwareWebhookEndpointInterface}.** Constructed with a {@see StatusManager}, the endpoint is bound to one
 * merchant's adapter — correct for a plugin installed in a shop, and the only shape that existed before 3.1.
 * Constructed with a {@see StatusAdapterResolverInterface}, it asks for the adapter of whichever merchant the request
 * was *verified for*, so one endpoint (and one {@see \Comfino\Backend\Webhook\WebhookManager} around it) can serve
 * every merchant a host has. The bound shape keeps working untouched.
 *
 * The tenant used is always the verified one. Nothing about the merchant is read out of the payload, which is
 * attacker-controlled until the signature has been checked and is not re-checked afterwards.
 */
class StatusNotification extends WebhookEndpoint implements TenantAwareWebhookEndpointInterface
{
    private readonly StatusManager|StatusAdapterResolverInterface $statusSource;

    /**
     * @param string $name Unique endpoint identifier
     * @param string $endpointUrl URL pattern for request matching
     * @param StatusManager|StatusAdapterResolverInterface $statusManager The one merchant's status manager, or a
     *                                                                    resolver that supplies the adapter for the
     *                                                                    merchant each request was verified for
     * @param string[] $forbiddenStatuses Statuses that must be refused with a 400 rather than applied
     * @param string[] $ignoredStatuses Statuses to acknowledge without applying
     */
    public function __construct(
        string $name,
        string $endpointUrl,
        StatusManager|StatusAdapterResolverInterface $statusManager,
        private readonly array $forbiddenStatuses,
        private readonly array $ignoredStatuses
    ) {
        parent::__construct($name, $endpointUrl);

        $this->statusSource = $statusManager;
        $this->methods = ['POST', 'PUT', 'PATCH'];
    }

    /**
     * {@inheritDoc}
     *
     * The tenant-blind entry point. It works for a bound endpoint and refuses for a resolving one: without the tenant
     * there is no way to know whose adapter to use, and guessing is the cross-tenant bug this whole seam removes. A
     * host reaching this path with a resolver has bypassed {@see \Comfino\Backend\Webhook\WebhookManager}.
     *
     * @return array<string, mixed>|null
     */
    public function processRequest(ServerRequestInterface $serverRequest, ?string $endpointName = null): ?array
    {
        if ($this->statusSource instanceof StatusAdapterResolverInterface) {
            throw new InvalidRequest(
                (string) $serverRequest->getUri(),
                $serverRequest->getBody()->getContents(),
                'This endpoint resolves its status adapter per tenant and must be called with the verified request.'
            );
        }

        $this->apply($serverRequest, $endpointName, $this->statusSource);

        return null;
    }

    /**
     * {@inheritDoc}
     *
     * @return array<string, mixed>|null
     */
    public function processTenantRequest(
        ServerRequestInterface $serverRequest,
        ?string $endpointName,
        VerifiedWebhookRequest $verified
    ): ?array {
        if (!$this->statusSource instanceof StatusAdapterResolverInterface) {
            // Bound to one merchant at construction time; the tenant adds nothing this endpoint may act on.
            $this->apply($serverRequest, $endpointName, $this->statusSource);

            return null;
        }

        $adapter = $this->statusSource->resolve($verified->tenant);

        if ($adapter === null) {
            /* Nothing to apply the status to. Acknowledged rather than refused: the sender retries every non-2xx, and
               a merchant that has been deprovisioned since the request was signed will not come back. The payload is
               still validated first, so a malformed notification is still a 400. */
            $this->validatedPayload($serverRequest, $endpointName);

            return null;
        }

        $this->apply($serverRequest, $endpointName, $adapter);

        return null;
    }

    /**
     * Validates the notification and hands it to the given adapter or manager.
     *
     * @param ServerRequestInterface $serverRequest PSR-7 server request
     * @param string|null $endpointName Optional explicit endpoint name for direct routing
     * @param StatusManager|StatusAdapterInterface $target Where the status change goes
     */
    private function apply(
        ServerRequestInterface $serverRequest,
        ?string $endpointName,
        StatusManager|StatusAdapterInterface $target
    ): void {
        $payload = $this->validatedPayload($serverRequest, $endpointName);

        if ($payload === null) {
            return;
        }

        [$externalId, $status] = $payload;

        try {
            if ($target instanceof StatusManager) {
                $target->setOrderStatus($externalId, $status);
            } else {
                $target->setStatus($externalId, $status);
            }
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
    }

    /**
     * Parses and validates the notification.
     *
     * @param ServerRequestInterface $serverRequest PSR-7 server request
     * @param string|null $endpointName Optional explicit endpoint name for direct routing
     *
     * @return array{string, string}|null The external order id and the status, or null when the status is one to ignore
     *
     * @throws InvalidRequest When a field is missing or the status is forbidden
     */
    private function validatedPayload(ServerRequestInterface $serverRequest, ?string $endpointName): ?array
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

        return [(string) $requestPayload['externalId'], (string) $requestPayload['status']];
    }
}
