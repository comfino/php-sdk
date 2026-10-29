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

/**
 * Receives the outcome of every webhook signature check, e.g. to count forged requests per tenant ({@see \Comfino\Backend\Health\WebhookHealthRecorder}).
 *
 * Called synchronously inside request processing; implementations must be cheap. Exceptions are swallowed by the manager.
 */
interface WebhookVerificationObserverInterface
{
    /**
     * @param WebhookVerificationOutcome $outcome How the check ended
     * @param string|null $tenantKey The resolved tenant, or null when none was resolved (or single-tenant)
     * @param string|null $clientIp The caller's resolved address
     */
    public function onVerification(WebhookVerificationOutcome $outcome, ?string $tenantKey, ?string $clientIp): void;
}
