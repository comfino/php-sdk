<?php

/**
 * ComfinoPay PHP SDK
 *
 * Backend routines for e-commerce platforms integration with the ComfinoPay payment gateway API.
 *
 * @package Comfino\Shop\Order
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
 * @copyright Copyright (c) 2026 by ComfinoPay sp. z o.o.
 * @license https://opensource.org/licenses/BSD-3-Clause BSD 3-Clause License
 * @link https://github.com/comfino/php-sdk
 */

declare(strict_types=1);

namespace Comfino\Shop\Order;

use Psr\Log\LoggerInterface;

/**
 * Base order status adapter that routes ComfinoPay status strings to platform-specific order states.
 *
 * The concrete {@see applyStatus()} method is responsible for actually updating the order
 * status in the host platform. Subclasses only need to implement that single method.
 */
abstract class AbstractStatusAdapter implements StatusAdapterInterface
{
    /**
     * @param string[] $ignoredStatuses ComfinoPay statuses to silently skip without any action
     * @param string[] $forbiddenStatuses ComfinoPay statuses that are blocked and must not be applied
     * @param array<string, string> $statusMap Map of ComfinoPay status strings to platform status codes
     * @param string $scope Tenant scope this adapter serves. It selects the {@see StatusApplicationContext} entered
     *                      around the apply step, so that suppressing reflexive outbound calls for this merchant does
     *                      not also suppress a genuine event for a different merchant being served by the same process.
     *                      Leave empty in a single-shop plugin.
     * @param LoggerInterface|null $logger Receives a debug line for every notification skipped by
     *                                     {@see acceptsOrder()} or {@see isStatusAlreadyApplied()}
     */
    public function __construct(
        protected readonly array $ignoredStatuses,
        protected readonly array $forbiddenStatuses,
        protected readonly array $statusMap,
        protected readonly string $scope = '',
        protected readonly ?LoggerInterface $logger = null
    ) {
    }

    /**
     * Returns the tenant scope this adapter serves.
     */
    public function getScope(): string
    {
        return $this->scope;
    }

    /**
     * {@inheritDoc}
     *
     * Normalizes the incoming status to uppercase, then applies the ignore/forbidden/unknown guard pipeline, the
     * order-ownership check ({@see acceptsOrder()}) and the idempotency check ({@see isStatusAlreadyApplied()}) before
     * delegating to {@see applyStatus()}. A skipped notification is acknowledged, not rejected: the sender retries every
     * non-2xx response.
     */
    public function setStatus(string $orderId, string $status): void
    {
        $inputStatus = strtoupper($status);

        if (in_array($inputStatus, $this->ignoredStatuses, true)) {
            // Silently skip ignored statuses.
            return;
        }

        if (in_array($inputStatus, $this->forbiddenStatuses, true)) {
            // Block forbidden statuses.
            return;
        }

        // Skip unknown/unmapped statuses.
        $platformStatusCode = $this->statusMap[$inputStatus] ?? null;

        if ($platformStatusCode === null) {
            return;
        }

        if (!$this->acceptsOrder($orderId)) {
            $this->logger?->debug('Status notification skipped: order not owned by this integration.', ['orderId' => $orderId, 'status' => $inputStatus]);

            return;
        }

        if ($this->isStatusAlreadyApplied($orderId, $platformStatusCode)) {
            $this->logger?->debug('Status notification skipped: status already applied.', ['orderId' => $orderId, 'status' => $inputStatus]);

            return;
        }

        /* Mark this tenant's context active so platform event hooks can suppress reflexive outbound calls to the
           ComfinoPay API - without suppressing another tenant's legitimate event in the same process. */
        StatusApplicationContext::forScope($this->scope)->apply(fn () => $this->applyStatus($orderId, $platformStatusCode, $inputStatus));
    }

    /**
     * Tells whether the order belongs to this integration (was placed with the ComfinoPay payment method). A notification
     * for any other order is dropped. Override it: the default accepts every order, which is what lets a holder of the
     * API key change the status of orders paid by other means (audit M-10).
     *
     * @param string $orderId Shop internal order identifier
     */
    protected function acceptsOrder(string $orderId): bool
    {
        return true;
    }

    /**
     * Tells whether the order already carries the target status, so a redelivered notification is a no-op rather than
     * a repeated write and history entry. The default reports false (always apply).
     *
     * @param string $orderId Shop internal order identifier
     * @param string $platformStatusCode Resolved platform-specific status code
     */
    protected function isStatusAlreadyApplied(string $orderId, string $platformStatusCode): bool
    {
        return false;
    }

    /**
     * Applies the resolved platform status code to the order in the host platform.
     *
     * Called only after the status has passed all guard checks (not ignored, not forbidden, and successfully resolved
     * to a platform status code via the status map).
     *
     * @param string $orderId Shop internal order identifier
     * @param string $platformStatusCode Resolved platform-specific status code
     * @param string $comfinoStatus Original ComfinoPay status string (normalized to uppercase)
     */
    abstract protected function applyStatus(string $orderId, string $platformStatusCode, string $comfinoStatus): void;
}
