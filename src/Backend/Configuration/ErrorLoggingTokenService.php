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

use Closure;
use Comfino\Api\ClientInterface;
use Comfino\Backend\Cache\ScopedCache;
use Comfino\Backend\Clock\ClockInterface;
use Comfino\Backend\Clock\SystemClock;
use Throwable;
use UnexpectedValueException;

/**
 * Keeps the error-logging (CETS) access token in the shop configuration fresh.
 *
 * The token signs the frontend logging token, so the API key never reaches the browser. It is claimed with
 * {@see ClientInterface::claimErrorLoggingToken()} when it is missing or expires within the refresh window, and stored
 * under {@see self::TOKEN_KEY} / {@see self::EXPIRES_AT_KEY} (Unix timestamp). Both keys must be registered options of
 * the {@see ConfigurationManager}.
 *
 * The claim is best-effort. A failed claim starts a cooldown in the cache: the plugin calls this on every request, and
 * without the cooldown an unreachable API would add the client's full retry escalation to each of them.
 */
final class ErrorLoggingTokenService
{
    public const TOKEN_KEY = 'COMFINO_ERROR_LOGGING_ACCESS_TOKEN';
    public const EXPIRES_AT_KEY = 'COMFINO_ERROR_LOGGING_ACCESS_TOKEN_EXPIRES_AT';

    private const COOLDOWN_CACHE_KEY = 'error_logging_token_claim_cooldown';

    /**
     * @param ConfigurationManager $configurationManager Stores the token and its expiry
     * @param ClientInterface|Closure(): ClientInterface $apiClient API client, or a factory called only when a claim is due (keeps
     *                                                              the common, token-valid path free of client construction)
     * @param ScopedCache|null $cache Holds the failure cooldown, or null to retry on every call
     * @param ClockInterface $clock Time source for the expiry checks
     * @param int $refreshWindowSeconds A token expiring within this window is refreshed
     * @param int $failureCooldownSeconds How long a failed claim is not retried
     */
    public function __construct(
        private readonly ConfigurationManager $configurationManager,
        private readonly ClientInterface|Closure $apiClient,
        private readonly ?ScopedCache $cache = null,
        private readonly ClockInterface $clock = new SystemClock(),
        private readonly int $refreshWindowSeconds = 3600,
        private readonly int $failureCooldownSeconds = 900
    ) {
    }

    /**
     * Claims and stores a new token if the stored one is missing or about to expire.
     *
     * @param string $apiKey The shop's API key; nothing is claimed while it is empty
     *
     * @return bool True when a new token was stored
     */
    public function refreshIfNeeded(string $apiKey): bool
    {
        if ($apiKey === '' || !$this->needsRefresh() || $this->onCooldown()) {
            return false;
        }

        try {
            $client = $this->apiClient instanceof Closure ? ($this->apiClient)() : $this->apiClient;
            $response = $client->claimErrorLoggingToken();

            if (($expiresAt = strtotime($response->expiresAt)) === false) {
                throw new UnexpectedValueException("Unparsable token expiry: \"$response->expiresAt\".");
            }

            $this->configurationManager->setConfigurationValue(self::TOKEN_KEY, $response->accessToken);
            $this->configurationManager->setConfigurationValue(self::EXPIRES_AT_KEY, $expiresAt);
            $this->configurationManager->persist();
        } catch (Throwable) {
            $this->cache?->set(self::COOLDOWN_CACHE_KEY, true, $this->failureCooldownSeconds);

            return false;
        }

        return true;
    }

    /**
     * Tells whether the stored token is missing or expires within the refresh window.
     */
    public function needsRefresh(): bool
    {
        return $this->getAccessToken() === '' || $this->getExpiresAt() <= $this->clock->now() + $this->refreshWindowSeconds;
    }

    /**
     * Returns the stored token, or an empty string when none is stored.
     */
    public function getAccessToken(): string
    {
        return (string) ($this->configurationManager->getConfigurationValue(self::TOKEN_KEY) ?? '');
    }

    /**
     * Returns the stored token expiry as a Unix timestamp, or 0 when none is stored.
     */
    public function getExpiresAt(): int
    {
        return (int) ($this->configurationManager->getConfigurationValue(self::EXPIRES_AT_KEY) ?? 0);
    }

    private function onCooldown(): bool
    {
        return $this->cache?->get(self::COOLDOWN_CACHE_KEY) !== null;
    }
}
