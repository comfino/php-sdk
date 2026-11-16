<?php

/**
 * ComfinoPay PHP SDK
 *
 * Backend routines for e-commerce platforms integration with the ComfinoPay payment gateway API.
 *
 * @package Comfino\Tests\Unit\Backend\Log
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
 * @copyright Copyright (c) 2026 by ComfinoPay sp. z o.o.
 * @license https://opensource.org/licenses/BSD-3-Clause BSD 3-Clause License
 * @link https://github.com/comfino/php-sdk
 */

declare(strict_types=1);

namespace Comfino\Tests\Unit\Backend\Log;

use Comfino\Backend\Log\TokenServiceModeChecker;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;

final class TokenServiceModeCheckerTest extends TestCase
{
    protected function tearDown(): void
    {
        parent::tearDown();

        unset($_COOKIE[TokenServiceModeChecker::COOKIE_NAME]);
    }

    public function testMatchingTokenEnablesServiceMode(): void
    {
        $token = TokenServiceModeChecker::generateToken();

        $this->assertSame(64, strlen($token));
        $this->assertTrue((new TokenServiceModeChecker($token, $this->request($token)))->isServiceMode());
    }

    public function testTheOldConstantValueDoesNotEnableServiceMode(): void
    {
        $this->assertFalse((new TokenServiceModeChecker(TokenServiceModeChecker::generateToken(), $this->request('ACTIVE')))->isServiceMode());
    }

    public function testMissingCookieOrShortTokenDisablesServiceMode(): void
    {
        $this->assertFalse((new TokenServiceModeChecker(TokenServiceModeChecker::generateToken(), new ServerRequest('GET', '/')))->isServiceMode());
        $this->assertFalse((new TokenServiceModeChecker('short', $this->request('short')))->isServiceMode());
        $this->assertFalse((new TokenServiceModeChecker('', $this->request('')))->isServiceMode());
    }

    public function testFallsBackToTheCookieSuperglobal(): void
    {
        $token = TokenServiceModeChecker::generateToken();
        $_COOKIE[TokenServiceModeChecker::COOKIE_NAME] = $token;

        $this->assertTrue((new TokenServiceModeChecker($token))->isServiceMode());
    }

    private function request(string $cookie): ServerRequest
    {
        return (new ServerRequest('GET', '/'))->withCookieParams([TokenServiceModeChecker::COOKIE_NAME => $cookie]);
    }
}
