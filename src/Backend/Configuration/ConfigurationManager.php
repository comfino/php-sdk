<?php

/**
 * Comfino PHP SDK
 *
 * Backend routines for e-commerce platforms integration with the Comfino payment gateway API.
 *
 * @package Comfino\Backend\Configuration
 * @author Artur Kozubski <akozubski@comperia.pl>
 * @copyright Copyright (c) 2026 Comfino by Comperia.pl S.A.
 * @license https://opensource.org/licenses/BSD-3-Clause BSD 3-Clause License
 * @link https://github.com/comfino/php-sdk
 */

declare(strict_types=1);

namespace Comfino\Backend\Configuration;

use Comfino\Api\SerializerInterface;

/**
 * Configuration manager for handling configuration options.
 */
final class ConfigurationManager
{
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

    private static ?self $instance = null;
    /** @var array<string, mixed>|null */
    private ?array $configuration = null;
    /** @var array<string, bool> */
    private array $modified;
    private bool $loaded = false;

    /**
     * Retrieves the singleton instance of ConfigurationManager.
     *
     * @param array<string, int> $availConfigOptions Available configuration options
     * @param string[] $accessibleConfigOptions Accessible configuration options
     * @param int $options Configuration options
     * @param StorageAdapterInterface $storageAdapter Storage adapter for configuration
     * @param SerializerInterface $serializer Serializer for configuration
     *
     * @return self The singleton instance of ConfigurationManager
     */
    public static function getInstance(
        array $availConfigOptions,
        array $accessibleConfigOptions,
        int $options,
        StorageAdapterInterface $storageAdapter,
        SerializerInterface $serializer
    ): self {
        if (self::$instance === null) {
            self::$instance = new self(
                $availConfigOptions,
                $accessibleConfigOptions,
                $options,
                $storageAdapter,
                $serializer
            );
        }

        return self::$instance;
    }

    public static function reset(): void
    {
        self::$instance = null;
    }

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
        $this->modified = array_combine(
            array_keys($availConfigOptions),
            array_fill(0, count($availConfigOptions), false)
        );
    }

    public function __destruct()
    {
        $this->persist();
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
     * Updates the configuration options with the provided values.
     *
     * @param array<string, mixed> $configurationOptions Configuration options to update
     */
    public function updateConfigurationOptions(array $configurationOptions): void
    {
        $this->setConfigurationValues($configurationOptions, $this->accessibleConfigOptions);
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
        if (isset($this->availConfigOptions[$optionName])) {
            $this->getConfiguration()[$optionName] = $optionValue;
            $this->modified[$optionName] = true;
        }
    }

    /**
     * Sets multiple configuration values for the specified option names.
     *
     * @param array<string, mixed> $configurationOptions Configuration options to set
     * @param string[]|null $accessibleOptions Optional list of accessible configuration options
     */
    public function setConfigurationValues(array $configurationOptions, ?array $accessibleOptions = null): void
    {
        if ($this->configuration === null) {
            $this->configuration = [];
        }

        foreach ($configurationOptions as $optionName => $optionValue) {
            if (empty($accessibleOptions) || in_array($optionName, $accessibleOptions, true)) {
                $this->configuration[$optionName] = $optionValue;
                $this->modified[$optionName] = true;
            }
        }
    }

    /**
     * Persists the modified configuration options to storage.
     */
    public function persist(): void
    {
        if (
            $this->configuration !== null &&
            count($optionsToSave = array_intersect_key($this->configuration, array_filter($this->modified)))
        ) {
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

            $this->modified = array_merge(
                $this->modified,
                array_combine(array_keys($optionsToSave), array_fill(0, count($optionsToSave), false))
            );
        }
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
                                $this->configuration[$optionName] = array_map(
                                    static fn ($value): string => (string) $value,
                                    $optionValue
                                );
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
                                $this->configuration[$optionName] = array_map(
                                    static fn ($value): int => (int) $value,
                                    $optionValue
                                );
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
                                $this->configuration[$optionName] = array_map(
                                    static fn ($value): float => (float) $value,
                                    $optionValue
                                );
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
                                $this->configuration[$optionName] = array_map(
                                    static fn ($value): bool => (bool) $value,
                                    $optionValue
                                );
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
