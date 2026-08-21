<?php

/**
 * Comfino PHP SDK
 *
 * Backend routines for e-commerce platforms integration with the Comfino payment gateway API.
 *
 * @package Comfino\Shop\Order
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
 * @copyright Copyright (c) 2026 Comfino by Comperia.pl S.A.
 * @license https://opensource.org/licenses/BSD-3-Clause BSD 3-Clause License
 * @link https://github.com/comfino/php-sdk
 */

declare(strict_types=1);

namespace Comfino\Shop\Order;

/**
 * Base order status adapter that routes Comfino status strings to platform-specific order states.
 *
 * The concrete {@see applyStatus()} method is responsible for actually updating the order
 * status in the host platform. Subclasses only need to implement that single method.
 */
abstract class AbstractStatusAdapter implements StatusAdapterInterface
{
    /**
     * @param string[] $ignoredStatuses Comfino statuses to silently skip without any action
     * @param string[] $forbiddenStatuses Comfino statuses that are blocked and must not be applied
     * @param array<string, string> $statusMap Map of Comfino status strings to platform status codes
     * @param string $scope Tenant scope this adapter serves. It selects the {@see StatusApplicationContext} entered
     *                      around the apply step, so that suppressing reflexive outbound calls for this merchant does
     *                      not also suppress a genuine event for a different merchant being served by the same process.
     *                      Leave empty in a single-shop plugin.
     */
    public function __construct(
        protected readonly array $ignoredStatuses,
        protected readonly array $forbiddenStatuses,
        protected readonly array $statusMap,
        protected readonly string $scope = ''
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
     * Normalizes the incoming status to uppercase, then applies the ignore/forbidden/unknown guard pipeline before
     * delegating to {@see applyStatus()}.
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

        /* Mark this tenant's context active so platform event hooks can suppress reflexive outbound calls to the
           Comfino API - without suppressing another tenant's legitimate event in the same process. */
        StatusApplicationContext::forScope($this->scope)->apply(
            fn () => $this->applyStatus($orderId, $platformStatusCode, $inputStatus)
        );
    }

    /**
     * Applies the resolved platform status code to the order in the host platform.
     *
     * Called only after the status has passed all guard checks (not ignored, not forbidden, and successfully resolved
     * to a platform status code via the status map).
     *
     * @param string $orderId Shop internal order identifier
     * @param string $platformStatusCode Resolved platform-specific status code
     * @param string $comfinoStatus Original Comfino status string (normalized to uppercase)
     */
    abstract protected function applyStatus(string $orderId, string $platformStatusCode, string $comfinoStatus): void;
}
