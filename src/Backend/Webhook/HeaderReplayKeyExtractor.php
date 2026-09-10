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

use Psr\Http\Message\ServerRequestInterface;

/**
 * Deduplicates on a per-delivery identifier carried in a request header.
 *
 * This is what replay protection should key on: an identifier the sender stamps on each delivery, so a redelivery of
 * the same event is recognizable while two distinct events with identical bodies are not confused for one. See
 * {@see SignatureReplayKeyExtractor} for why the signature cannot do that job.
 *
 * **The ComfinoPay API does not send such a header today.** The default names are the ones a future one would plausibly
 * use, and until it exists this extractor resolves to null for every request — which means "do not deduplicate", so
 * wiring it early is harmless rather than silently disabling replay protection in a way that looks enabled. Point it
 * at whatever header the sender actually uses when there is one.
 *
 * The tenant partition is applied by {@see WebhookManager} on top of whatever this returns, so a header value that is
 * only unique per merchant is still safe.
 */
final class HeaderReplayKeyExtractor implements ReplayKeyExtractorInterface
{
    /** Header names checked, in order, for a per-delivery identifier. */
    public const DEFAULT_HEADERS = ['CR-Delivery-Id', 'X-CR-Delivery-Id'];

    /** @var string[] */
    private readonly array $headers;

    /**
     * @param string[] $headers Header names to check, in priority order; defaults to {@see DEFAULT_HEADERS}
     * @param bool $fallBackToSignature Whether a request carrying none of the headers is deduplicated on its
     *                                  `CR-Signature` instead. Off by default, and worth leaving off: the fallback
     *                                  reintroduces exactly the content-collision this class exists to avoid, and it
     *                                  does so only for the requests that lack an identifier — the hardest case in
     *                                  which to notice it.
     */
    public function __construct(array $headers = self::DEFAULT_HEADERS, private readonly bool $fallBackToSignature = false)
    {
        $this->headers = array_values(
            /** @phpstan-ignore-next-line The is_string() guard is deliberate: the declared string[] is not enforced */
            array_filter($headers, static fn ($header): bool => is_string($header) && $header !== '')
        );
    }

    /** @inheritDoc */
    public function extract(ServerRequestInterface $request, string $signature): ?string
    {
        foreach ($this->headers as $header) {
            if (!$request->hasHeader($header)) {
                continue;
            }

            $value = trim($request->getHeader($header)[0] ?? '');

            if ($value !== '') {
                return $value;
            }
        }

        return $this->fallBackToSignature ? $signature : null;
    }
}
