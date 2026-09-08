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

/**
 * Tracks whether an API-initiated order status change is currently being applied.
 *
 * Platforms that react to order persistence through event hooks (e.g., Magento observers, PrestaShop hooks) can use
 * this context to suppress reflexive outbound calls back to the ComfinoPay API - for instance, to avoid sending a
 * cancellation request for an order that the ComfinoPay API itself just reported as canceled.
 *
 * The context is entered automatically by {@see AbstractStatusAdapter::setStatus()} for the duration of the
 * platform-specific apply step, so integrations only need to query {@see isActiveInScope()} (or the static
 * {@see isActive()}) from their event hook.
 *
 * A depth counter (rather than a plain flag) keeps the context correct if status application ever nests.
 *
 * **The counter is per scope, not per process.** It used to be a single static integer, which in a long-lived process
 * handling several merchants meant the suppression was shared: while tenant A's status change was being applied, a
 * genuine, unrelated status change for tenant B - one the platform should have reacted to - saw an active context and
 * was suppressed. Tenant A's write silently swallowed tenant B's event. Instances are keyed by scope for the same
 * reason {@see ConfigurationManager} is, and the default empty scope preserves the original behavior for single-shop
 * plugins.
 */
final class StatusApplicationContext
{
    /** @var array<string, self> One instance per tenant scope, keyed by the scope string. */
    private static array $instances = [];

    private int $depth = 0;

    /**
     * Retrieves the context instance for a tenant scope.
     *
     * @param string $scope Tenant scope discriminator (empty string = global/default scope)
     *
     * @return self The instance bound to $scope
     */
    public static function forScope(string $scope = ''): self
    {
        return self::$instances[$scope] ??= new self($scope);
    }

    /**
     * Drops cached instances, discarding any depth they hold.
     *
     * @param string|null $scope When given, only the instance bound to this scope is dropped; when null (default), all
     *                           cached instances (every scope) are dropped.
     */
    public static function reset(?string $scope = null): void
    {
        if ($scope === null) {
            self::$instances = [];
        } else {
            unset(self::$instances[$scope]);
        }
    }

    /**
     * Runs the given callback with the default scope's context marked active.
     *
     * @template T
     * @param callable(): T $callback
     *
     * @return T
     *
     * @deprecated Use `StatusApplicationContext::forScope($scope)->apply($callback)` so the suppression belongs to the
     *             tenant whose order is being written. This static form is the default scope, which in a multi-tenant
     *             process is shared by every tenant that does not pass one.
     */
    public static function run(callable $callback)
    {
        return self::forScope()->apply($callback);
    }

    /**
     * Returns true while an API-initiated status change is being applied.
     *
     * @param string|null $scope The tenant scope to ask about. Passing the scope gives the precise answer: is *this*
     *                           tenant's status change being applied right now. Passing null (the default) answers the
     *                           tenant-blind question - is *any* tenant's status change being applied - which is what
     *                           this class did before it had scopes, and is therefore what an event hook that does not
     *                           know its tenant still gets.
     */
    public static function isActive(?string $scope = null): bool
    {
        if ($scope !== null) {
            return isset(self::$instances[$scope]) && self::$instances[$scope]->isActiveInScope();
        }

        foreach (self::$instances as $instance) {
            if ($instance->isActiveInScope()) {
                return true;
            }
        }

        return false;
    }

    /**
     * Private constructor to enforce the per-scope instance pattern.
     *
     * @param string $scope Tenant scope this instance belongs to
     */
    private function __construct(private readonly string $scope = '')
    {
    }

    /**
     * Returns the tenant scope this instance is bound to.
     */
    public function getScope(): string
    {
        return $this->scope;
    }

    /**
     * Runs the given callback with this scope's context marked active, guaranteeing the context is exited even on
     * exception.
     *
     * @template T
     * @param callable(): T $callback
     *
     * @return T
     */
    public function apply(callable $callback)
    {
        $this->depth++;

        try {
            return $callback();
        } finally {
            $this->depth--;
        }
    }

    /**
     * Returns true while an API-initiated status change is being applied in this scope.
     */
    public function isActiveInScope(): bool
    {
        return $this->depth > 0;
    }

    /**
     * Returns the current nesting depth in this scope. Exposed for diagnostics and tests.
     */
    public function getDepth(): int
    {
        return $this->depth;
    }
}
