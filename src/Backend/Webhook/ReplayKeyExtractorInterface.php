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
 * Decides what makes one webhook delivery *the same delivery* as another, for replay protection.
 *
 * Until 3.1 there was no choice: {@see WebhookManager} deduplicated on the `CR-Signature`, which is
 * `sha3-256(apiKey . rawBody)` — a function of the **content**, not of the delivery. For a payload that is unique per
 * delivery that works. For a status notification, whose body is essentially `{externalId, status}`, it does not: two
 * genuine notifications carrying the same body hash identically, so enabling replay protection converts "the merchant
 * was told twice" into "the merchant was told once, and the second real event was dropped". That is a silent data
 * loss, and it is the reason a host without a per-delivery identifier should run with replay protection off.
 *
 * Making the key an extraction step separates the two questions. The default,
 * {@see SignatureReplayKeyExtractor}, preserves 3.0 behavior exactly. A host whose deliveries carry an identifier —
 * a header, a field the API adds later — points {@see HeaderReplayKeyExtractor} at it and gets replay protection that
 * means what it says.
 *
 * Returning null means **"this delivery cannot be identified, so do not deduplicate it"**: the request proceeds and is
 * not recorded. Failing open is deliberate. A caller that cannot tell two deliveries apart should process both — the
 * downstream is expected to be idempotent about a repeated status anyway — rather than drop one on a guess.
 */
interface ReplayKeyExtractorInterface
{
    /**
     * Returns the identity of this delivery or null when it has none.
     *
     * Called after the signature has been verified, so the request may be trusted to the extent its sender is. The
     * value is used as a store key and is compared for equality only, so any stable string will do.
     *
     * @param ServerRequestInterface $request The verified inbound request
     * @param string $signature The `CR-Signature` the request arrived with, for implementations that want to combine
     *                          it with something else
     *
     * @return string|null The delivery identity, or null to skip deduplication for this request
     */
    public function extract(ServerRequestInterface $request, string $signature): ?string;
}
