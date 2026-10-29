<?php

/**
 * ComfinoPay PHP SDK
 *
 * Backend routines for e-commerce platforms integration with the ComfinoPay payment gateway API.
 *
 * @package Comfino\Backend\Configuration
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
 * @copyright Copyright (c) 2026 by ComfinoPay sp. z o.o.
 * @license https://opensource.org/licenses/BSD-3-Clause BSD 3-Clause License
 * @link https://github.com/comfino/php-sdk
 */

declare(strict_types=1);

namespace Comfino\Backend\Configuration;

/**
 * Receives the configuration options that actually changed when they are persisted, e.g. to keep an append-only audit
 * trail of remote configuration changes. The host decides where the record lives.
 *
 * Called from {@see ConfigurationManager::persist()} after the storage write; exceptions are swallowed.
 */
interface ConfigurationChangeObserverInterface
{
    /**
     * @param array<string, array{before: mixed, after: mixed}> $changes Changed options only; no-op writes are omitted
     * @param ConfigurationChangeSource $source Where the changes came from
     * @param string|null $tenantKey Tenant the configuration belongs to, as given to {@see ConfigurationManager::setChangeObserver()}
     */
    public function onConfigurationChanged(array $changes, ConfigurationChangeSource $source, ?string $tenantKey): void;
}
