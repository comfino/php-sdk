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
 * Ready-made {@see ConnectorInfoInterface} for a connector that runs outside the shop.
 *
 * The shape this covers is a long-lived multi-tenant SaaS service that serves many merchants and talks to their
 * e-commerce platforms over HTTP rather than running inside them. Such a service knows its own code, its own version,
 * its own PHP runtime and its own public host name — and does not know a merchant's platform version, database
 * version, or the PHP version serving the storefront. It reports what it knows and nothing else: the database version
 * is simply absent from {@see ConnectorInfoInterface}, an unset domain stays null, and {@see UserAgentBuilder} renders
 * a null as "unknown" rather than inventing a value.
 *
 * The defaults follow the shape such connectors already hand-assembled before this class existed: the name segment
 * reads "connector" and the version segment repeats the connector's own version, because for an out-of-process
 * deployment "what is talking to us, and which build of it" is the whole question, and there is no separate platform
 * build to report.
 *
 *     $info = new HostedConnectorInfo('IDO', '1.4.2', 'connector.example.com', 'pl');
 *     // IDO Comfino [1.4.2], connector [1.4.2], PHP [8.3.1], connector.example.com
 *
 * Pass $platformVersion explicitly when the connector *does* learn the merchant's platform version from an API call —
 * that is a better string than the connector's own version repeated, and the only reason the parameter exists.
 */
final class HostedConnectorInfo implements ConnectorInfoInterface
{
    private readonly string $phpVersion;

    /**
     * @param string $code Integration code carried into every API call and error report (e.g. "IDO")
     * @param string $connectorVersion This connector's own release version (e.g. "1.4.2")
     * @param string|null $domain Public host name this connector answers on, or null when it has none worth reporting
     * @param string $language API language code (ISO 639-1)
     * @param string|null $platformVersion Merchant platform version when the connector actually knows it; null (the
     *                                     default) reports the connector version in the platform slot, matching what
     *                                     an out-of-process deployment can honestly say
     * @param string $name Name segment of the User-Agent; "connector" unless the deployment has a better public name
     * @param string|null $phpVersion PHP version of *this* process; null (the default) reads the running interpreter's
     *                                version, which is the correct answer in every normal deployment
     */
    public function __construct(
        private readonly string $code,
        private readonly string $connectorVersion,
        private readonly ?string $domain = null,
        private readonly string $language = 'pl',
        private readonly ?string $platformVersion = null,
        private readonly string $name = 'connector',
        ?string $phpVersion = null
    ) {
        $this->phpVersion = $phpVersion ?? PHP_VERSION;
    }

    /** @inheritDoc */
    public function getCode(): string
    {
        return $this->code;
    }

    /** @inheritDoc */
    public function getName(): string
    {
        return $this->name;
    }

    /** @inheritDoc */
    public function getPluginVersion(): string
    {
        return $this->connectorVersion;
    }

    /**
     * {@inheritDoc}
     *
     * Falls back to the connector's own version: a connector running outside the shop has no separate platform build
     * to report, and its own version is the string a support conversation actually needs.
     */
    public function getVersion(): string
    {
        return $this->platformVersion ?? $this->connectorVersion;
    }

    /** @inheritDoc */
    public function getPhpVersion(): string
    {
        return $this->phpVersion;
    }

    /** @inheritDoc */
    public function getDomain(): ?string
    {
        return $this->domain;
    }

    /** @inheritDoc */
    public function getLanguage(): string
    {
        return $this->language;
    }

    /**
     * Returns the User-Agent string for this connector.
     *
     * Convenience for the common call site; identical to `UserAgentBuilder::build($info)`.
     */
    public function getUserAgent(): string
    {
        return UserAgentBuilder::build($this);
    }

    /**
     * Returns the metadata as an associative array, for logging and environment reports.
     *
     * Unknown members are reported as null rather than as the string "unknown" — the substitution is a rendering
     * decision that belongs to {@see UserAgentBuilder}, not to the data.
     *
     * @return array<string, string|null>
     */
    public function toArray(): array
    {
        return [
            'code' => $this->code,
            'name' => $this->name,
            'connectorVersion' => $this->connectorVersion,
            'platformVersion' => $this->platformVersion,
            'phpVersion' => $this->phpVersion,
            'domain' => $this->domain,
            'language' => $this->language,
        ];
    }
}
