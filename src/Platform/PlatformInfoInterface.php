<?php

/**
 * Comfino PHP SDK
 *
 * Backend routines for e-commerce platforms integration with the Comfino payment gateway API.
 *
 * @package Comfino\Platform
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
 * @copyright Copyright (c) 2026 Comfino by Comperia.pl S.A.
 * @license https://opensource.org/licenses/BSD-3-Clause BSD 3-Clause License
 * @link https://github.com/comfino/php-sdk
 */

declare(strict_types=1);

namespace Comfino\Platform;

/**
 * Provides platform-specific metadata about the shop environment.
 *
 * Implementations are responsible for reading version, locale, currency, and infrastructure details from the host
 * platform (Magento, PrestaShop, WooCommerce, etc.).
 */
interface PlatformInfoInterface
{
    /**
     * Returns shop platform code (e.g. "MG", "PS", "WC").
     */
    public function getCode(): string;

    /**
     * Returns the human-readable shop platform name (e.g. "Magento", "PrestaShop", "WooCommerce").
     */
    public function getName(): string;

    /**
     * Returns the shop platform version string (e.g. "2.4.7").
     */
    public function getVersion(): string;

    /**
     * Returns the shop language as an ISO 639-1 code (e.g. "pl", "en").
     */
    public function getLanguage(): string;

    /**
     * Returns the shop currency as an ISO 4217 code (e.g. "PLN", "EUR").
     */
    public function getCurrency(): string;

    /**
     * Returns the shop domain name (e.g. "myshop.pl").
     */
    public function getDomain(): string;

    /**
     * Returns the database server version string (e.g. "8.0.32", "10.6.14-MariaDB").
     */
    public function getDatabaseVersion(): string;

    /**
     * Returns the PHP runtime version string (e.g. "8.2.10").
     */
    public function getPhpVersion(): string;

    /**
     * Returns the installed plugin/module version string (e.g. "4.0.0").
     */
    public function getPluginVersion(): string;

    /**
     * Returns all platform info fields as an associative array.
     *
     * Keys: shopName, shopVersion, shopLanguage, shopCurrency, shopDomain, databaseVersion, phpVersion, pluginVersion.
     *
     * @return array<string, string>
     */
    public function toArray(): array;
}
