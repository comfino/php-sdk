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

use Psr\Http\Message\ServerRequestInterface;

/**
 * IP whitelist interface for restricting webhook access to known sender addresses.
 *
 * Implementations receive the full PSR-7 server request so they can inspect proxy-forwarding headers (CF-Connecting-IP,
 * X-Forwarded-For, X-Real-IP) in addition to REMOTE_ADDR.
 */
interface IpWhitelistInterface
{
    /**
     * Determines whether the request originates from an allowed IP address.
     *
     * @param ServerRequestInterface $request Incoming PSR-7 server request
     *
     * @return bool True if the request should be accepted, false to reject with 403
     */
    public function isAllowed(ServerRequestInterface $request): bool;
}
