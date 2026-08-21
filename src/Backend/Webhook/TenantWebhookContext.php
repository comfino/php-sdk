<?php

/**
 * Comfino PHP SDK
 *
 * Backend routines for e-commerce platforms integration with the Comfino payment gateway API.
 *
 * @package Comfino\Backend\Webhook
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
 * @copyright Copyright (c) 2026 Comfino by Comperia.pl S.A.
 * @license https://opensource.org/licenses/BSD-3-Clause BSD 3-Clause License
 * @link https://github.com/comfino/php-sdk
 */

declare(strict_types=1);

namespace Comfino\Backend\Webhook;

use InvalidArgumentException;

/**
 * The merchant an inbound webhook belongs to, and the only keys its signature may be verified against.
 *
 * This object is what makes cross-tenant signature confusion impossible rather than merely unlikely. Verifying against
 * a flat list of every merchant's key means any valid Comfino signature is valid for every merchant — the request is
 * authentic, so it passes, and it is then handled as whichever tenant the URL happened to name. Resolving the tenant
 * first and verifying against that tenant's key only removes the entire class of bug.
 *
 * More than one key is allowed because key rotation is real: during a rotation a merchant has both an outgoing and an
 * incoming key, and a webhook signed with either is legitimately theirs. What is *not* allowed is keys belonging to
 * different merchants, which is why this is a per-tenant object rather than a list the manager scans.
 */
final class TenantWebhookContext
{
    /** @var string[] */
    public readonly array $apiKeys;

    /**
     * @param string|null $tenantKey Stable merchant identifier — the installation id, store id, or website code the
     *                               host partitions by. Null only in a genuinely single-tenant integration
     * @param string[] $apiKeys This merchant's API keys, most-current first; more than one only for key rotation
     * @param array<string, mixed> $metadata Host-defined extras carried alongside (sandbox flag, shop id, locale) —
     *                                       the SDK never reads it, so a host can thread its own context through the
     *                                       resolver to its endpoints without a side channel
     *
     * @throws InvalidArgumentException If no usable API key is supplied
     */
    public function __construct(
        public readonly ?string $tenantKey,
        array $apiKeys,
        public readonly array $metadata = []
    ) {
        /* Empty and non-string keys are rejected here rather than filtered downstream: an empty key reduces the
           expected signature to a hash of the payload alone, which any caller can compute without knowing a secret,
           so a context carrying one would authorize forged requests. Failing at construction means a misconfigured
           tenant cannot become an authorization decision. */
        $usableKeys = array_values(
            /** @phpstan-ignore-next-line The is_string() guard is deliberate: the declared string[] is not enforced */
            array_filter($apiKeys, static fn ($apiKey): bool => is_string($apiKey) && $apiKey !== '')
        );

        if ($usableKeys === []) {
            throw new InvalidArgumentException(
                sprintf(
                    'Webhook context for tenant "%s" has no usable API key. A resolver must return null for a tenant ' .
                    'it cannot supply a key for, so the request is rejected rather than verified against an empty key.',
                    $tenantKey ?? '(unscoped)'
                )
            );
        }

        $this->apiKeys = $usableKeys;
    }

    /**
     * Builds a context for a tenant with a single API key — the shape outside of a rotation.
     *
     * @param string|null $tenantKey Stable merchant identifier
     * @param string $apiKey The merchant's API key
     * @param array<string, mixed> $metadata Host-defined extras
     */
    public static function forKey(?string $tenantKey, string $apiKey, array $metadata = []): self
    {
        return new self($tenantKey, [$apiKey], $metadata);
    }

    /**
     * Returns the key to sign outbound values with: the most current one.
     */
    public function primaryApiKey(): string
    {
        return $this->apiKeys[0];
    }

    /**
     * Returns a host-defined metadata value.
     *
     * @param string $name Metadata key
     * @param mixed $default Returned when the key is absent
     *
     * @return mixed The stored value, or $default
     */
    public function getMetadata(string $name, mixed $default = null): mixed
    {
        return $this->metadata[$name] ?? $default;
    }
}
