<?php

/**
 * ComfinoPay PHP SDK
 *
 * Backend routines for e-commerce platforms integration with the ComfinoPay payment gateway API.
 *
 * @package Comfino\Tests\Unit
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
 * @copyright Copyright (c) 2026 by ComfinoPay sp. z o.o.
 * @license https://opensource.org/licenses/BSD-3-Clause BSD 3-Clause License
 * @link https://github.com/comfino/php-sdk
 */

declare(strict_types=1);

namespace Comfino\Tests\Unit\Backend\Webhook;

use Comfino\Backend\Webhook\StaticApiKeyResolver;
use Comfino\Backend\Webhook\TenantWebhookContext;
use InvalidArgumentException;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;

final class ApiKeyFloorTest extends TestCase
{
    public function testAFifteenCharacterKeyIsRejectedAndASixteenCharacterOneAccepted(): void
    {
        $this->assertSame(['123456789012345a'], TenantWebhookContext::forKey('shop', '123456789012345a')->apiKeys);

        $this->expectException(InvalidArgumentException::class);

        TenantWebhookContext::forKey('shop', '123456789012345');
    }

    public function testShortKeysAreDroppedFromAMixedList(): void
    {
        $context = new TenantWebhookContext('shop', ['', 'short', 'a-real-api-key-0123']);

        $this->assertSame(['a-real-api-key-0123'], $context->apiKeys);
    }

    public function testAResolverWithOnlyShortKeysIsAnUnknownTenant(): void
    {
        $resolver = new StaticApiKeyResolver(['', 'short', '123456789012345']);

        $this->assertFalse($resolver->hasUsableApiKey());
        $this->assertNull($resolver->resolve(new ServerRequest('POST', 'https://shop.test/hook')));
        $this->assertNull($resolver->resolveByTenantKey());
    }

    public function testAResolverKeepsTheValidKeyBesideAShortOne(): void
    {
        $resolver = new StaticApiKeyResolver(['short', 'a-real-api-key-0123'], 'shop');

        $this->assertSame(['a-real-api-key-0123'], $resolver->resolveByTenantKey('shop')?->apiKeys);
    }
}
