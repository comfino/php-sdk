<?php

/**
 * ComfinoPay PHP SDK
 *
 * Backend routines for e-commerce platforms integration with the ComfinoPay payment gateway API.
 *
 * @package Comfino\Backend\Log
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
 * @copyright Copyright (c) 2026 by ComfinoPay sp. z o.o.
 * @license https://opensource.org/licenses/BSD-3-Clause BSD 3-Clause License
 * @link https://github.com/comfino/php-sdk
 */

declare(strict_types=1);

namespace Comfino\Backend\Log;

use Psr\Http\Message\ServerRequestInterface;

/**
 * Service mode bound to a per-install secret: the `COMFINO_SERVICE_SESSION` cookie must carry the expected token,
 * compared in constant time.
 *
 * Use this wherever service mode changes what a shopper sees (e.g. hiding the payment method outside service sessions).
 * {@see CookieServiceModeChecker} accepts a constant value any visitor can set, which is acceptable only for gating
 * extra debug logging.
 */
final class TokenServiceModeChecker implements ServiceModeCheckerInterface
{
    public const COOKIE_NAME = 'COMFINO_SERVICE_SESSION';
    /** Shortest accepted token; anything shorter is guessable and disables service mode. */
    public const MIN_TOKEN_LENGTH = 16;

    /**
     * @param string $expectedToken Per-install random token (e.g. {@see self::generateToken()}, stored in the config)
     * @param ServerRequestInterface|null $request Request to read the cookie from; null reads $_COOKIE
     */
    public function __construct(private readonly string $expectedToken, private readonly ?ServerRequestInterface $request = null)
    {
    }

    /**
     * Returns a new random token for an installation (64 hex characters).
     */
    public static function generateToken(): string
    {
        return bin2hex(random_bytes(32));
    }

    public function isServiceMode(): bool
    {
        if (strlen($this->expectedToken) < self::MIN_TOKEN_LENGTH) {
            return false;
        }

        $cookie = $this->request !== null ? ($this->request->getCookieParams()[self::COOKIE_NAME] ?? null) : ($_COOKIE[self::COOKIE_NAME] ?? null);

        return is_string($cookie) && hash_equals($this->expectedToken, $cookie);
    }
}
