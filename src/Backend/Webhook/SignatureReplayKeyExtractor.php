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
 * Deduplicates on the `CR-Signature` — the 3.0 behavior, kept as the default so nothing changes by upgrading.
 *
 * **Read this before enabling replay protection with it.** The signature is `sha3-256(apiKey . rawBody)`, so it
 * identifies the *content* of a delivery and not the delivery itself. Two genuine notifications with identical bodies
 * produce identical keys, and the second one is then rejected as a replay. Whether that can happen is a question about
 * the sender, not about this class:
 *
 *  - Safe where every payload carries something unique — a timestamp, a nonce, a monotonic id.
 *  - **Unsafe for order-status notifications**, whose payload is `{externalId, status}` and which a sender may
 *    legitimately repeat (a re-confirmation, a manual re-send, the same status reached twice).
 *
 * For the unsafe case use {@see HeaderReplayKeyExtractor} once the sender carries an identifier, and until then leave
 * replay protection off — an ordering or idempotency guard in the shop's own status handling is the correct place for
 * that problem, and it cannot mistake two events for one.
 */
final class SignatureReplayKeyExtractor implements ReplayKeyExtractorInterface
{
    /**
     * {@inheritDoc}
     *
     * The return type is narrowed to `string`: the signature is always present by the time an extractor is called, so
     * this extractor deduplicates every request. That is the 3.0 behavior, with the caveat in the class docblock.
     */
    public function extract(ServerRequestInterface $request, string $signature): string
    {
        return $signature;
    }
}
