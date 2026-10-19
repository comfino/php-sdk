<?php

/**
 * ComfinoPay PHP SDK
 *
 * Backend routines for e-commerce platforms integration with the ComfinoPay payment gateway API.
 *
 * @package Comfino\Backend\Webhook
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
 * @copyright Copyright (c) 2026 by ComfinoPay sp. z o.o.
 * @license https://opensource.org/licenses/BSD-3-Clause BSD 3-Clause License
 * @link https://github.com/comfino/php-sdk
 */

declare(strict_types=1);

namespace Comfino\Backend\Webhook;

use Comfino\Api\Exception\InvalidEndpoint;
use Comfino\Api\Exception\InvalidRequest;
use Psr\Http\Message\ServerRequestInterface;

/**
 * An endpoint that wants to know **which merchant** the request it is handling was verified for.
 *
 * This is the webhook side of what {@see \Comfino\Api\ApiContext} did for the client in 3.0.0, and it closes the last
 * place where a multi-tenant host still had to build the whole stack per request.
 *
 * The problem it solves: {@see WebhookManager} resolves the tenant, verifies the signature against that tenant's key
 * and then calls `WebhookEndpointInterface::processRequest()` — which is not told any of it. The
 * {@see VerifiedWebhookRequest} stays inside the manager. An endpoint that needs to act on behalf of a merchant
 * therefore has to be *constructed* for that merchant, which forces the manager, the resolver and the endpoint to be
 * per-request objects in a host serving many merchants. Worse, it makes the safe wiring counter-intuitive: the tenant
 * resolver has to be bound to one merchant and re-check the request against it, because a resolver that honestly
 * answered "whichever merchant the URL names" would let a notification verified for merchant B be applied by merchant
 * A's endpoint.
 *
 * With this interface an endpoint is tenant-agnostic, so one manager can be a container service and the resolver can
 * be what its name says — request-driven, with nothing to bind to.
 *
 * **Why a second interface instead of a third parameter on `processRequest()`.** PHP does not allow an implementation
 * to declare fewer parameters than its interface, so adding even an optional parameter to
 * {@see WebhookEndpointInterface} would break every endpoint anyone has written. The manager checks for this interface
 * and calls {@see processTenantRequest()} when it is present, {@see WebhookEndpointInterface::processRequest()}
 * otherwise. In the next major the two collapse into one method.
 */
interface TenantAwareWebhookEndpointInterface extends WebhookEndpointInterface
{
    /**
     * Processes a request whose tenant has already been resolved and verified.
     *
     * The tenant is trustworthy in a way nothing read out of the request is: it was resolved before verification and
     * the signature was then checked against that tenant's key alone. Acting on `$verified->tenant` is therefore safe;
     * re-reading the merchant out of the request body is not.
     *
     * @param ServerRequestInterface $serverRequest PSR-7 server request
     * @param string|null $endpointName Optional explicit endpoint name for direct routing
     * @param VerifiedWebhookRequest $verified The tenant this request was verified against, plus the request's
     *                                         signature and resolved caller address
     *
     * @return array<string, mixed>|null Response data as associative array, or null for an empty body
     *
     * @throws InvalidEndpoint When the request does not match this endpoint
     * @throws InvalidRequest When the request data is malformed or fails validation
     */
    public function processTenantRequest(ServerRequestInterface $serverRequest, ?string $endpointName, VerifiedWebhookRequest $verified): ?array;
}
