<?php

/**
 * Comfino PHP SDK
 *
 * Backend routines for e-commerce platforms integration with the Comfino payment gateway API.
 *
 * @package Comfino\Backend\Configuration
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
 * @copyright Copyright (c) 2026 Comfino by Comperia.pl S.A.
 * @license https://opensource.org/licenses/BSD-3-Clause BSD 3-Clause License
 * @link https://github.com/comfino/php-sdk
 */

declare(strict_types=1);

namespace Comfino\Backend\Configuration;

/**
 * Interface for configuration storage adapters.
 */
interface StorageAdapterInterface
{
    /**
     * Loads configuration options from storage.
     *
     * @return array<string, mixed> The loaded configuration options
     */
    public function load(): array;

    /**
     * Saves configuration options to storage.
     *
     * @param array<string, mixed> $configurationOptions The configuration options to save
     */
    public function save(array $configurationOptions): void;
}
