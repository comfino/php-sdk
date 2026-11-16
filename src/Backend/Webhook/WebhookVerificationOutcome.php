<?php

/**
 * ComfinoPay PHP SDK
 *
 * Backend routines for e-commerce platforms integration with the ComfinoPay payment gateway API.
 *
 * @package Comfino\Backend\Webhook
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
 * @copyright Copyright (c) 2026 by ComfinoPay sp. z o.o.
 * @license https://opensource.org/licenses/BSD-3-Clause BSD 3-Clause License
 * @link https://github.com/comfino/php-sdk
 */

declare(strict_types=1);

namespace Comfino\Backend\Webhook;

/**
 * How the signature check of an inbound webhook request ended.
 *
 * The diagnostic replacement for the calculated signature the manager used to expose: it tells a host why a request
 * was rejected without handing out a value derived from the merchant's API key.
 */
enum WebhookVerificationOutcome: string
{
    /** The signature matched the resolved tenant's key. */
    case Verified = 'verified';

    /** No CR-Signature header, or a GET without the signed `vkey` parameter. */
    case MissingSignature = 'missing_signature';

    /** The request names no tenant this integration serves, or the tenant has no usable API key. */
    case UnknownTenant = 'unknown_tenant';

    /** A signature was present but did not match any of the tenant's keys. */
    case SignatureMismatch = 'signature_mismatch';

    /** The signature matched, but the delivery was already processed (replay protection). */
    case Duplicate = 'duplicate';

    /** The request was rejected before verification (body size, IP allow-list) or none has been processed yet. */
    case NotAttempted = 'not_attempted';
}
