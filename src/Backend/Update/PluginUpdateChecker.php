<?php

/**
 * ComfinoPay PHP SDK
 *
 * Backend routines for e-commerce platforms integration with the ComfinoPay payment gateway API.
 *
 * @package Comfino\Backend\Update
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
 * @copyright Copyright (c) 2026 by ComfinoPay sp. z o.o.
 * @license https://opensource.org/licenses/BSD-3-Clause BSD 3-Clause License
 * @link https://github.com/comfino/php-sdk
 */

declare(strict_types=1);

namespace Comfino\Backend\Update;

use Closure;
use Comfino\Api\ClientInterface;
use Comfino\Backend\Cache\ScopedCache;
use Comfino\Backend\Clock\ClockInterface;
use Comfino\Backend\Clock\SystemClock;
use Comfino\Backend\Queue\JitterInterface;
use Comfino\Backend\Queue\SystemJitter;
use Throwable;

/**
 * Checks the ComfinoPay release API for a newer version of the plugin, at most about once a day per installation.
 *
 * - The result is cached for a jittered 20-28 h. Shops tend to install and upgrade around the same calendar moments,
 *   so a fixed TTL would make every installation re-check the shared release API at the same clustered hour.
 * - A short lock stops concurrent admin requests that all see a cache miss from each firing a release check.
 * - The update flag is recomputed against the installed version on every cached read, so a result cached before an
 *   upgrade does not keep announcing the version that is now installed.
 *
 * The result array keeps the key names the plugins already render (`github_version` is the latest released version;
 * the name predates the move from GitHub to the release API).
 *
 * @phpstan-type UpdateInfo array{
 *     update_available: bool,
 *     current_version: string,
 *     github_version?: string,
 *     download_url?: string|null,
 *     release_notes_url?: string,
 *     description_html?: string|null,
 *     checked_at?: int,
 *     error?: string
 * }
 */
final class PluginUpdateChecker
{
    private const CACHE_KEY = 'plugin_update_check';
    private const LOCK_KEY = 'plugin_update_check_lock';

    /**
     * @param ClientInterface|Closure(): ClientInterface $apiClient API client, or a factory called only on a cache miss
     * @param string $platform Canonical platform slug polled on the release API (e.g. "magento", "prestashop")
     * @param string $currentVersion Installed plugin version
     * @param ScopedCache|null $cache Result and lock store, or null to query the API on every call
     * @param ClockInterface $clock Time source for `checked_at`
     * @param JitterInterface $jitter Random source for the cache TTL spread
     * @param int $minCacheTtl Shortest result lifetime in seconds
     * @param int $maxCacheTtl Longest result lifetime in seconds
     * @param int $lockTtl Lifetime of the concurrency lock in seconds
     */
    public function __construct(
        private readonly ClientInterface|Closure $apiClient,
        private readonly string $platform,
        private readonly string $currentVersion,
        private readonly ?ScopedCache $cache = null,
        private readonly ClockInterface $clock = new SystemClock(),
        private readonly JitterInterface $jitter = new SystemJitter(),
        private readonly int $minCacheTtl = 72000,
        private readonly int $maxCacheTtl = 100800,
        private readonly int $lockTtl = 300
    ) {
    }

    /**
     * Returns the update information, from the cache when available.
     *
     * While another request holds the lock, returns "no update" without an API call.
     *
     * @return UpdateInfo
     */
    public function check(): array
    {
        $cached = $this->cache?->get(self::CACHE_KEY);

        if (is_array($cached) && isset($cached['update_available'])) {
            return $this->refreshUpdateFlag($cached);
        }

        if ($this->cache !== null) {
            if ($this->cache->get(self::LOCK_KEY) !== null) {
                return ['update_available' => false, 'current_version' => $this->currentVersion];
            }

            $this->cache->set(self::LOCK_KEY, true, $this->lockTtl);
        }

        $updateInfo = $this->fetchLatestRelease();

        $this->cache?->set(
            self::CACHE_KEY,
            $updateInfo,
            $this->minCacheTtl + $this->jitter->random(max(0, $this->maxCacheTtl - $this->minCacheTtl))
        );

        return $updateInfo;
    }

    /**
     * Drops the cached result and the lock, then checks again.
     *
     * @return UpdateInfo
     */
    public function forceCheck(): array
    {
        $this->cache?->delete(self::CACHE_KEY);
        $this->cache?->delete(self::LOCK_KEY);

        return $this->check();
    }

    /**
     * Tells whether $availableVersion is newer than $currentVersion. A leading "v"/"V" on either is ignored.
     */
    public static function isNewerVersion(string $availableVersion, string $currentVersion): bool
    {
        return version_compare(ltrim(trim($availableVersion), 'vV'), ltrim(trim($currentVersion), 'vV'), '>');
    }

    /**
     * @return UpdateInfo
     */
    private function fetchLatestRelease(): array
    {
        try {
            $client = $this->apiClient instanceof Closure ? ($this->apiClient)() : $this->apiClient;
            $release = $client->getLatestPluginRelease($this->platform);
        } catch (Throwable $e) {
            return [
                'update_available' => false,
                'current_version' => $this->currentVersion,
                'error' => 'Failed to fetch release information from ComfinoPay API: ' . $e->getMessage(),
                'checked_at' => $this->clock->now(),
            ];
        }

        return [
            'update_available' => self::isNewerVersion($release->version, $this->currentVersion),
            'current_version' => $this->currentVersion,
            'github_version' => $release->version,
            'download_url' => $release->downloadUrl,
            'release_notes_url' => $release->releaseUrl,
            'description_html' => $release->descriptionHtml,
            'checked_at' => $this->clock->now(),
        ];
    }

    /**
     * @param array<string, mixed> $updateInfo
     *
     * @return UpdateInfo
     */
    private function refreshUpdateFlag(array $updateInfo): array
    {
        $updateInfo['current_version'] = $this->currentVersion;

        if (isset($updateInfo['github_version']) && is_string($updateInfo['github_version'])) {
            $updateInfo['update_available'] = self::isNewerVersion($updateInfo['github_version'], $this->currentVersion);
        }

        /** @var UpdateInfo $updateInfo */
        return $updateInfo;
    }
}
