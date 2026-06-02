<?php

/**
 * Comfino PHP SDK
 *
 * Backend routines for e-commerce platforms integration with the Comfino payment gateway API.
 *
 * @package Comfino\Tests\Unit\Backend\Log
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
 * @copyright Copyright (c) 2026 Comfino by Comperia.pl S.A.
 * @license https://opensource.org/licenses/BSD-3-Clause BSD 3-Clause License
 * @link https://github.com/comfino/php-sdk
 */

declare(strict_types=1);

namespace Comfino\Tests\Unit\Backend\Log;

use Comfino\Backend\Log\CookieServiceModeChecker;
use PHPUnit\Framework\TestCase;

final class CookieServiceModeCheckerTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $originalCookies;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalCookies = $_COOKIE;
    }

    protected function tearDown(): void
    {
        $_COOKIE = $this->originalCookies;

        parent::tearDown();
    }

    public function testReturnsFalseWhenCookieAbsent(): void
    {
        unset($_COOKIE['COMFINO_SERVICE_SESSION']);

        $this->assertFalse((new CookieServiceModeChecker())->isServiceMode());
    }

    public function testReturnsTrueWhenCookieIsActive(): void
    {
        $_COOKIE['COMFINO_SERVICE_SESSION'] = 'ACTIVE';

        $this->assertTrue((new CookieServiceModeChecker())->isServiceMode());
    }

    public function testReturnsFalseWhenCookieHasWrongValue(): void
    {
        $_COOKIE['COMFINO_SERVICE_SESSION'] = 'INACTIVE';

        $this->assertFalse((new CookieServiceModeChecker())->isServiceMode());
    }

    public function testReturnsFalseWhenCookieIsEmpty(): void
    {
        $_COOKIE['COMFINO_SERVICE_SESSION'] = '';

        $this->assertFalse((new CookieServiceModeChecker())->isServiceMode());
    }

    public function testIsCaseSensitive(): void
    {
        $_COOKIE['COMFINO_SERVICE_SESSION'] = 'active';

        $this->assertFalse((new CookieServiceModeChecker())->isServiceMode());
    }
}
