<?php

/**
 * ComfinoPay PHP SDK
 *
 * Backend routines for e-commerce platforms integration with the ComfinoPay payment gateway API.
 *
 * @package Comfino\Platform
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
 * @copyright Copyright (c) 2026 by ComfinoPay sp. z o.o.
 * @license https://opensource.org/licenses/BSD-3-Clause BSD 3-Clause License
 * @link https://github.com/comfino/php-sdk
 */

declare(strict_types=1);

namespace Comfino\Platform;

/**
 * The metadata every integration can supply about itself, whatever its deployment shape.
 *
 * {@see PlatformInfoInterface} assumes the integration runs *inside* the shop: it asks for the platform's own version,
 * the database server version, the shop domain and the PHP version of the process serving the storefront. A plugin
 * installed in Magento or PrestaShop knows all of that. An **out-of-process connector** — a multi-tenant SaaS service
 * that serves many merchants and reaches their e-commerce platforms over HTTP instead of running inside them — knows
 * almost none of it, and filling those members with plausible strings would put fiction into the environment reports.
 * Before this interface existed, such connectors therefore refused to implement `PlatformInfoInterface` at all and
 * hand-assembled their User-Agent string, duplicating library logic once per integration.
 *
 * This interface is the honest common denominator: the identifying half is required, and everything a connector
 * legitimately cannot know is nullable. {@see UserAgentBuilder} consumes exactly this interface, so the in-shop
 * variant and the out-of-process variant produce their User-Agent through the same code path. Implement
 * {@see PlatformInfoInterface} when the integration lives in the shop; use {@see HostedConnectorInfo} when it does
 * not.
 */
interface ConnectorInfoInterface
{
    /**
     * Returns the integration code (e.g. "MG", "PS", "WC", "IDO"). Always known.
     */
    public function getCode(): string;

    /**
     * Returns the human-readable integration name (e.g. "Magento", "PrestaShop", "connector"). Always known.
     */
    public function getName(): string;

    /**
     * Returns the installed ComfinoPay plugin/connector version string (e.g. "4.0.0"). Always known — it is this code's
     * own version.
     */
    public function getPluginVersion(): string;

    /**
     * Returns the host platform version string (e.g. "2.4.7"), or null when the integration cannot determine it.
     *
     * An out-of-process connector talking to a merchant's platform over HTTP is the null case: the platform version
     * is not exposed to it, and guessing would corrupt the environment report.
     */
    public function getVersion(): ?string;

    /**
     * Returns the PHP runtime version string (e.g. "8.2.10"), or null when it is not meaningful.
     *
     * An out-of-process connector knows its *own* PHP version, which is worth reporting, but it is not the shop's.
     */
    public function getPhpVersion(): ?string;

    /**
     * Returns the shop domain name (e.g. "myshop.pl"), or null when unknown.
     */
    public function getDomain(): ?string;

    /**
     * Returns the API language as an ISO 639-1 code (e.g. "pl", "en"). Always known — the caller chooses it.
     */
    public function getLanguage(): string;
}
