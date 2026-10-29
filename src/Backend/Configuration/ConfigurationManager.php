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

use Comfino\Api\SerializerInterface;
use Comfino\Backend\Configuration\Constraint\ConfigValueConstraintInterface;
use Comfino\Backend\Configuration\Constraint\InvalidConfigValue;
use LogicException;
use Throwable;

/**
 * Configuration manager for handling configuration options.
 */
final class ConfigurationManager
{
    // Data type bit masks
    public const OPT_VALUE_TYPE_STRING = (1 << 0);
    public const OPT_VALUE_TYPE_INT = (1 << 1);
    public const OPT_VALUE_TYPE_FLOAT = (1 << 2);
    public const OPT_VALUE_TYPE_BOOL = (1 << 3);
    public const OPT_VALUE_TYPE_ARRAY = (1 << 4);
    public const OPT_VALUE_TYPE_JSON = (1 << 5);
    public const OPT_VALUE_TYPE_STRING_ARRAY = self::OPT_VALUE_TYPE_STRING | self::OPT_VALUE_TYPE_ARRAY;
    public const OPT_VALUE_TYPE_INT_ARRAY = self::OPT_VALUE_TYPE_INT | self::OPT_VALUE_TYPE_ARRAY;
    public const OPT_VALUE_TYPE_FLOAT_ARRAY = self::OPT_VALUE_TYPE_FLOAT | self::OPT_VALUE_TYPE_ARRAY;
    public const OPT_VALUE_TYPE_BOOL_ARRAY = self::OPT_VALUE_TYPE_BOOL | self::OPT_VALUE_TYPE_ARRAY;

    public const OPT_SERIALIZE_ARRAYS = 1 << 0;

    /** Marker returned by {@see constrain()} for a rejected value (a NUL-prefixed string no stored value contains). */
    private const REJECTED = "\0__comfino_rejected__";

    /**
     * Options that should be writable from the ComfinoPay admin form only, not through the remote configuration
     * webhook: they change who is paid, how orders are marked, or how much the shop logs and waits. Subtract them from
     * the accessible list passed to {@see getInstance()}. A recommendation, not enforced: the list stays plugin-owned.
     */
    public const RECOMMENDED_ADMIN_ONLY_OPTIONS = [
        'COMFINO_DEBUG',
        'COMFINO_SERVICE_MODE',
        'COMFINO_IS_SANDBOX',
        'COMFINO_STATUS_MAP',
        'COMFINO_INITIAL_ORDER_STATUS',
        'COMFINO_IGNORED_STATUSES',
        'COMFINO_FORBIDDEN_STATUSES',
        'COMFINO_API_CONNECT_TIMEOUT',
        'COMFINO_API_TIMEOUT',
        'COMFINO_API_CONNECT_NUM_ATTEMPTS',
        'COMFINO_API_QUEUE_CONNECT_TIMEOUT',
        'COMFINO_API_QUEUE_TIMEOUT',
        'COMFINO_RETRY_QUEUE_ENABLED',
        'COMFINO_RETRY_QUEUE_MAX_ATTEMPTS',
    ];

    /** @var array<string, self> One instance per tenant scope, keyed by the scope string. */
    private static array $instances = [];
    /** @var array<string, mixed>|null */
    private ?array $configuration = null;
    /** @var array<string, bool> */
    private array $modified;
    private bool $loaded = false;
    /** @var array<string, mixed> */
    private array $defaultValues = [];
    /** Opt-in restoration of the pre-3.0 destructor save; see {@see setPersistOnDestruct()}. */
    private bool $persistOnDestruct = false;
    /** @var array<string, ConfigValueConstraintInterface> */
    private array $valueConstraints = [];
    private ?ConfigurationChangeObserverInterface $changeObserver = null;
    private ?string $changeObserverTenantKey = null;
    /** @var array<string, mixed> Value of each option before its first unpersisted modification */
    private array $changeBaseline = [];
    /** @var array<string, ConfigurationChangeSource> Source of the latest unpersisted modification per option */
    private array $changeSources = [];
    /** @var string[] Options rejected by a constraint in the last write */
    private array $lastRejectedOptions = [];

    /**
     * Private constructor for ConfigurationManager.
     *
     * @param array<string, int> $availConfigOptions Available configuration options
     * @param string[] $accessibleConfigOptions Accessible configuration options
     * @param int $options Configuration options
     * @param StorageAdapterInterface $storageAdapter Storage adapter for configuration
     * @param SerializerInterface $serializer Serializer for configuration
     */
    private function __construct(
        private readonly array $availConfigOptions,
        private readonly array $accessibleConfigOptions,
        private readonly int $options,
        private readonly StorageAdapterInterface $storageAdapter,
        private readonly SerializerInterface $serializer
    ) {
        $this->modified = array_combine(array_keys($availConfigOptions), array_fill(0, count($availConfigOptions), false));
    }

    /**
     * Retrieves a per-scope singleton instance of ConfigurationManager.
     *
     * A distinct instance is kept for each $scope so that a single process touching more than one tenant (e.g., an
     * admin bulk action spanning several shops/stores) never serves one tenant's in-memory-cached values to another.
     * The default empty scope preserves the original single-instance behavior for callers that do not need tenant
     * isolation.
     *
     * @param array<string, int> $availConfigOptions Available configuration options
     * @param string[] $accessibleConfigOptions Accessible configuration options
     * @param int $options Configuration options
     * @param StorageAdapterInterface $storageAdapter Storage adapter for configuration
     * @param SerializerInterface $serializer Serializer for configuration
     * @param string $scope Tenant scope discriminator (empty string = global/default scope)
     *
     * @return self The instance bound to $scope
     */
    public static function getInstance(
        array $availConfigOptions,
        array $accessibleConfigOptions,
        int $options,
        StorageAdapterInterface $storageAdapter,
        SerializerInterface $serializer,
        string $scope = ''
    ): self {
        if (!isset(self::$instances[$scope])) {
            self::$instances[$scope] = new self(
                $availConfigOptions,
                $accessibleConfigOptions,
                $options,
                $storageAdapter,
                $serializer
            );
        }

        return self::$instances[$scope];
    }

    /**
     * Returns the scopes that currently hold a cached instance.
     *
     * Diagnostics for the retention of every scope-addressed class in the SDK shares: the cache keeps the first
     * instance built for a scope and never evicts it, so a host that builds per request must release per request.
     * Asserting this is empty at the end of a unit of work turns a forgotten `reset()` into a failing test rather
     * than a slow leak.
     *
     * @return string[] Scope discriminators, in insertion order
     */
    public static function scopes(): array
    {
        return array_keys(self::$instances);
    }

    /**
     * Returns how many scopes hold a cached instance.
     */
    public static function scopeCount(): int
    {
        return count(self::$instances);
    }

    /**
     * Resets cached instances.
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
     * Deliberately does **not** persist.
     *
     * Persisting on destruct is safe only when destruction happens at the end of the request that owned the values -
     * true in a plugin, where an implicit save at shutdown is indistinguishable from an explicit one. In a long-lived
     * process it is a different thing entirely — destruction time is unrelated to request time, so a tenant's
     * configuration could be written back long after the request that changed it had ended, and a process holding
     * several scoped instances would defer every write to shutdown, in whatever order PHP happened to release them. A
     * write to a merchant's configuration is not something that should happen at a moment nobody chose.
     *
     * Call {@see flush()} at the end of the unit of work instead. A host that genuinely wants destruct-time persistence
     * can restore it explicitly with {@see setPersistOnDestruct()}, which at least makes the reliance visible.
     *
     * When unflushed changes are dropped, this raises an `E_USER_WARNING` rather than staying silent: losing a
     * configuration write is worth a line in the error log, and the warning names the option keys that were lost.
     */
    public function __destruct()
    {
        if ($this->persistOnDestruct) {
            $this->persist();

            return;
        }

        if (($pendingOptions = $this->pendingOptionNames()) !== []) {
            trigger_error(
                sprintf(
                    'ConfigurationManager destroyed with unflushed changes to: %s. Call flush() before the end of ' .
                    'the unit of work that made them - __destruct() no longer persists.',
                    implode(', ', $pendingOptions)
                ),
                E_USER_WARNING
            );
        }
    }

    /**
     * Writes pending changes to storage. The explicit counterpart to the removed destructor save.
     *
     * Identical to {@see persist()}; it exists because "flush" is what a caller means at the end of a unit of work, and
     * naming the operation is the point of taking it away from the destructor.
     */
    public function flush(): void
    {
        $this->persist();
    }

    /**
     * Returns true when there are configuration changes that have not been written to storage yet.
     */
    public function isDirty(): bool
    {
        return $this->pendingOptionNames() !== [];
    }

    /**
     * Restores the pre-3.0 behavior of persisting from the destructor.
     *
     * Provided as an escape hatch for hosts whose bootstrap has no obvious place to call {@see flush()} from. Prefer
     * fixing the call site: in any process that outlives a single request, the destructor fires at a moment unrelated
     * to the one that made the change.
     *
     * @param bool $persistOnDestruct Whether the destructor should write pending changes
     */
    public function setPersistOnDestruct(bool $persistOnDestruct): void
    {
        $this->persistOnDestruct = $persistOnDestruct;
    }

    /**
     * Returns the accessible configuration options.
     *
     * @return array<string, mixed> The accessible configuration options
     */
    public function returnConfigurationOptions(): array
    {
        return $this->getConfigurationValues($this->accessibleConfigOptions);
    }

    /**
     * Updates the configuration options with the provided values, as a remote write: only the accessible options are
     * written (none when the accessible list is empty), constraints apply, and the change observer sees the changes as
     * {@see ConfigurationChangeSource::RemoteEndpoint}.
     *
     * @param array<string, mixed> $configurationOptions Configuration options to update
     *
     * @return string[] Names of options rejected by a constraint (they keep their current value)
     */
    public function updateConfigurationOptions(array $configurationOptions): array
    {
        return $this->writeValues($configurationOptions, $this->accessibleConfigOptions, ConfigurationChangeSource::RemoteEndpoint);
    }

    /**
     * Registers per-option value constraints, applied on every write.
     *
     * @param array<string, ConfigValueConstraintInterface> $constraints Keyed by option name
     */
    public function setValueConstraints(array $constraints): void
    {
        $this->valueConstraints = $constraints;
    }

    /**
     * Registers the observer notified, on {@see persist()}, of the options that actually changed.
     *
     * @param ConfigurationChangeObserverInterface|null $observer Observer, or null to remove it
     * @param string|null $tenantKey Tenant passed to the observer
     */
    public function setChangeObserver(?ConfigurationChangeObserverInterface $observer, ?string $tenantKey = null): void
    {
        $this->changeObserver = $observer;
        $this->changeObserverTenantKey = $tenantKey;
    }

    /**
     * Returns the options a constraint rejected in the most recent write.
     *
     * @return string[]
     */
    public function getLastRejectedOptions(): array
    {
        return $this->lastRejectedOptions;
    }

    /**
     * Retrieves the value of a specific configuration option.
     *
     * @param string $optionName Name of the configuration option
     *
     * @return mixed The value of the configuration option, or null if not found
     */
    public function getConfigurationValue(string $optionName): mixed
    {
        return $this->getConfiguration()[$optionName] ?? null;
    }

    /**
     * Registers default values for configuration keys. Must be called before any getOption call.
     *
     * @param array<string, mixed> $defaults
     *
     * @throws LogicException If called after configuration is already loaded
     */
    public function setDefaults(array $defaults): void
    {
        if ($this->loaded) {
            throw new LogicException('Cannot set defaults after configuration has been loaded.');
        }

        $this->defaultValues = $defaults;
    }

    /**
     * Returns the stored value or the registered default when the stored value is null.
     */
    public function getOptionWithDefault(string $key): mixed
    {
        $value = $this->getConfigurationValue($key);

        if ($value !== null && $value !== '') {
            return $value;
        }

        return $this->defaultValues[$key] ?? null;
    }

    /**
     * Returns true if a non-null, non-empty value is stored for $key.
     */
    public function hasValue(string $key): bool
    {
        return ($value = $this->getConfigurationValue($key)) !== null && $value !== '';
    }

    /**
     * Retrieves multiple configuration values for the specified option names.
     *
     * @param string[] $optionNames Names of the configuration options to retrieve
     *
     * @return array<string, mixed> The configuration values for the specified option names
     */
    public function getConfigurationValues(array $optionNames): array
    {
        return array_intersect_key($this->getConfiguration(), array_flip($optionNames));
    }

    /**
     * Sets the value of a specific configuration option.
     *
     * @param string $optionName Name of the configuration option
     * @param mixed $optionValue Value to set for the configuration option
     */
    public function setConfigurationValue(string $optionName, mixed $optionValue): void
    {
        $this->lastRejectedOptions = [];

        if (isset($this->availConfigOptions[$optionName]) && ($optionValue = $this->constrain($optionName, $optionValue)) !== self::REJECTED) {
            $this->recordChange($optionName, ConfigurationChangeSource::Programmatic);
            $this->getConfiguration()[$optionName] = $optionValue;
            $this->modified[$optionName] = true;
        }
    }

    /**
     * Sets multiple configuration values for the specified option names.
     *
     * @param array<string, mixed> $configurationOptions Configuration options to set
     * @param string[]|null $accessibleOptions Options that may be written: null allows all, [] allows none
     *
     * @return string[] Names of options rejected by a constraint (they keep their current value)
     */
    public function setConfigurationValues(array $configurationOptions, ?array $accessibleOptions = null): array
    {
        return $this->writeValues($configurationOptions, $accessibleOptions, ConfigurationChangeSource::Programmatic);
    }

    /**
     * Persists the modified configuration options to storage.
     */
    public function persist(): void
    {
        if ($this->configuration !== null && count($optionsToSave = array_intersect_key($this->configuration, array_filter($this->modified)))) {
            foreach ($optionsToSave as $optionName => &$optionValue) {
                if (($this->availConfigOptions[$optionName] & self::OPT_VALUE_TYPE_STRING) && is_string($optionValue)) {
                    $optionValue = trim($optionValue);
                }

                if (($this->availConfigOptions[$optionName] & self::OPT_VALUE_TYPE_ARRAY) && is_array($optionValue)) {
                    if ($this->options & self::OPT_SERIALIZE_ARRAYS) {
                        $optionValue = implode(',', $optionValue);
                    }
                } elseif ($this->availConfigOptions[$optionName] & self::OPT_VALUE_TYPE_JSON) {
                    $optionValue = $this->serializer->serialize($optionValue);
                }
            }

            unset($optionValue);

            $this->storageAdapter->save($optionsToSave);

            $this->modified = array_merge($this->modified, array_combine(array_keys($optionsToSave), array_fill(0, count($optionsToSave), false)));

            $this->notifyChanges(array_keys($optionsToSave));
        }
    }

    /**
     * Writes a batch of values: options outside $accessibleOptions are ignored, constrained options are normalized or
     * rejected, and the rest of the batch still applies.
     *
     * @param array<string, mixed> $configurationOptions
     * @param string[]|null $accessibleOptions Null allows all, [] allows none
     *
     * @return string[] Rejected option names
     */
    private function writeValues(array $configurationOptions, ?array $accessibleOptions, ConfigurationChangeSource $source): array
    {
        $this->lastRejectedOptions = [];

        if ($this->configuration === null) {
            $this->configuration = [];
        }

        foreach ($configurationOptions as $optionName => $optionValue) {
            if ($accessibleOptions !== null && !in_array($optionName, $accessibleOptions, true)) {
                continue;
            }

            if (($optionValue = $this->constrain($optionName, $optionValue)) === self::REJECTED) {
                continue;
            }

            $this->recordChange($optionName, $source);

            $this->configuration[$optionName] = $optionValue;
            $this->modified[$optionName] = true;
        }

        return $this->lastRejectedOptions;
    }

    /**
     * Applies the option's constraint, if any. Returns {@see self::REJECTED} (and records the option) on rejection.
     */
    private function constrain(string $optionName, mixed $value): mixed
    {
        if (!isset($this->valueConstraints[$optionName])) {
            return $value;
        }

        try {
            return $this->valueConstraints[$optionName]->apply($optionName, $value);
        } catch (InvalidConfigValue) {
            $this->lastRejectedOptions[] = $optionName;

            return self::REJECTED;
        }
    }

    /**
     * Remembers the value an option had before its first unpersisted change, and who changed it last.
     */
    private function recordChange(string $optionName, ConfigurationChangeSource $source): void
    {
        if ($this->changeObserver === null) {
            return;
        }

        if (!array_key_exists($optionName, $this->changeBaseline)) {
            $this->changeBaseline[$optionName] = $this->getConfiguration()[$optionName] ?? null;
        }

        $this->changeSources[$optionName] = $source;
    }

    /**
     * Notifies the observer of the persisted options whose value differs from the baseline, grouped by source.
     *
     * @param string[] $persistedOptions
     */
    private function notifyChanges(array $persistedOptions): void
    {
        if ($this->changeObserver === null) {
            return;
        }

        $changesBySource = [];

        foreach ($persistedOptions as $optionName) {
            if (!array_key_exists($optionName, $this->changeBaseline)) {
                continue;
            }

            $before = $this->changeBaseline[$optionName];
            $after = $this->configuration[$optionName] ?? null;
            $source = $this->changeSources[$optionName] ?? ConfigurationChangeSource::Programmatic;

            unset($this->changeBaseline[$optionName], $this->changeSources[$optionName]);

            if ($before !== $after) {
                $changesBySource[$source->value][$optionName] = ['before' => $before, 'after' => $after];
            }
        }

        foreach ($changesBySource as $sourceValue => $changes) {
            try {
                $this->changeObserver->onConfigurationChanged(
                    $changes,
                    ConfigurationChangeSource::from($sourceValue),
                    $this->changeObserverTenantKey
                );
            } catch (Throwable) {
                // The audit record is best-effort; the configuration is already saved.
            }
        }
    }

    /**
     * Returns the names of the options that are modified but not yet written to storage.
     *
     * @return string[] Pending option names, in the order they appear in the configuration
     */
    private function pendingOptionNames(): array
    {
        if ($this->configuration === null) {
            return [];
        }

        return array_keys(array_intersect_key($this->configuration, array_filter($this->modified)));
    }

    /**
     * Retrieves the configuration options.
     *
     * @return array<string, mixed> The configuration options
     */
    private function &getConfiguration(): array
    {
        if ($this->configuration === null) {
            $this->configuration = [];

            $this->load();

            $this->loaded = true;
        } elseif (!$this->loaded) {
            $modifiedOptions = $this->configuration;

            $this->load();

            $this->configuration = array_merge($this->configuration, $modifiedOptions);
            $this->loaded = true;
        }

        return $this->configuration;
    }

    /**
     * Loads configuration options from storage.
     */
    private function load(): void
    {
        foreach ($this->storageAdapter->load() as $optionName => $optionValue) {
            if (isset($this->availConfigOptions[$optionName])) {
                switch ($this->availConfigOptions[$optionName] & (~self::OPT_VALUE_TYPE_ARRAY)) {
                    case self::OPT_VALUE_TYPE_STRING:
                        if ($this->availConfigOptions[$optionName] & self::OPT_VALUE_TYPE_ARRAY) {
                            if (is_array($optionValue)) {
                                $this->configuration[$optionName] = array_map(static fn ($value): string => (string) $value, $optionValue);
                            } else {
                                $this->configuration[$optionName] = (!empty($optionValue) ? array_map(
                                    static fn ($value): string => (string) $value,
                                    explode(',', $optionValue)
                                ) : ($optionValue !== null ? [] : null));
                            }
                        } else {
                            $this->configuration[$optionName] = ($optionValue !== null ? (string) $optionValue : null);
                        }

                        break;

                    case self::OPT_VALUE_TYPE_INT:
                        if ($this->availConfigOptions[$optionName] & self::OPT_VALUE_TYPE_ARRAY) {
                            if (is_array($optionValue)) {
                                $this->configuration[$optionName] = array_map(static fn ($value): int => (int) $value, $optionValue);
                            } else {
                                $this->configuration[$optionName] = (!empty($optionValue) ? array_map(
                                    static fn ($value): int => (int) $value,
                                    explode(',', $optionValue)
                                ) : ($optionValue !== null ? [] : null));
                            }
                        } else {
                            $this->configuration[$optionName] = ($optionValue !== null ? (int) $optionValue : null);
                        }

                        break;

                    case self::OPT_VALUE_TYPE_FLOAT:
                        if ($this->availConfigOptions[$optionName] & self::OPT_VALUE_TYPE_ARRAY) {
                            if (is_array($optionValue)) {
                                $this->configuration[$optionName] = array_map(static fn ($value): float => (float) $value, $optionValue);
                            } else {
                                $this->configuration[$optionName] = (!empty($optionValue) ? array_map(
                                    static fn ($value): float => (float) $value,
                                    explode(',', $optionValue)
                                ) : ($optionValue !== null ? [] : null));
                            }
                        } else {
                            $this->configuration[$optionName] = ($optionValue !== null ? (float) $optionValue : null);
                        }

                        break;

                    case self::OPT_VALUE_TYPE_BOOL:
                        if ($this->availConfigOptions[$optionName] & self::OPT_VALUE_TYPE_ARRAY) {
                            if (is_array($optionValue)) {
                                $this->configuration[$optionName] = array_map(static fn ($value): bool => (bool) $value, $optionValue);
                            } else {
                                $this->configuration[$optionName] = (!empty($optionValue) ? array_map(
                                    static fn ($value): bool => (bool) $value,
                                    explode(',', $optionValue)
                                ) : ($optionValue !== null ? [] : null));
                            }
                        } else {
                            $this->configuration[$optionName] = (bool) $optionValue;
                        }

                        break;

                    case self::OPT_VALUE_TYPE_JSON:
                        if (is_array($optionValue)) {
                            $this->configuration[$optionName] = $optionValue;
                        } else {
                            $this->configuration[$optionName] = !empty($optionValue)
                                ? $this->serializer->unserialize($optionValue)
                                : null;
                        }

                        break;
                }
            }
        }
    }
}
