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

use Comfino\Api\ClientInterface;
use Comfino\Backend\Log\DebugLogger;
use Comfino\Backend\Log\ErrorLogger;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tenant isolation for the two loggers, plus the opt-in shape of the process-global error handlers.
 */
final class ScopedLoggerTest extends TestCase
{
    private ClientInterface&MockObject $apiClient;
    private string $logFile;

    protected function setUp(): void
    {
        parent::setUp();

        $this->apiClient = $this->createMock(ClientInterface::class);
        $this->logFile = sys_get_temp_dir() . '/comfino_scope_test_' . uniqid('', true) . '.log';

        ErrorLogger::reset();
        DebugLogger::reset();

        putenv('COMFINO_DEV_ENV');
        putenv('COMFINO_FORCE_ERRORS_HANDLING');
    }

    protected function tearDown(): void
    {
        ErrorLogger::reset();
        DebugLogger::reset();

        foreach (glob(sys_get_temp_dir() . '/comfino_scope_test_*') ?: [] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }

        putenv('COMFINO_DEV_ENV');
        putenv('COMFINO_FORCE_ERRORS_HANDLING');

        parent::tearDown();
    }

    // --- ErrorLogger ---

    /**
     * The defect this fixes: the first tenant to ask for a logger owned the reporting identity for every tenant after
     * it, so their errors were reported under the first tenant's host and sent with the first tenant's key.
     */
    public function testEachScopeGetsItsOwnErrorLogger(): void
    {
        $shopA = $this->errorLogger('shop-a.test', 'shop_a');
        $shopB = $this->errorLogger('shop-b.test', 'shop_b');

        self::assertNotSame($shopA, $shopB);
        self::assertSame('shop_a', $shopA->getScope());
        self::assertSame('shop_b', $shopB->getScope());
        self::assertSame($shopA, $this->errorLogger('ignored-because-cached', 'shop_a'));
    }

    /**
     * A shared PSR-3 destination means one tenant's errors land in another tenant's log file.
     */
    public function testEachScopeLogsToItsOwnPsrDestination(): void
    {
        $shopALogger = $this->createMock(LoggerInterface::class);
        $shopBLogger = $this->createMock(LoggerInterface::class);

        ErrorLogger::init($shopALogger, 'shop_a');
        ErrorLogger::init($shopBLogger, 'shop_b');

        $shopALogger->expects($this->once())->method('error')->with('[A]: only shop A');
        $shopBLogger->expects($this->never())->method('error');

        $this->errorLogger('shop-a.test', 'shop_a')->logError('[A]', 'only shop A');
    }

    public function testResetDropsOnlyTheNamedScope(): void
    {
        $shopA = $this->errorLogger('shop-a.test', 'shop_a');
        $shopB = $this->errorLogger('shop-b.test', 'shop_b');

        ErrorLogger::reset('shop_a');

        self::assertNotSame($shopA, $this->errorLogger('shop-a.test', 'shop_a'));
        self::assertSame($shopB, $this->errorLogger('shop-b.test', 'shop_b'));
    }

    // --- global handlers ---

    /**
     * Installing PHP's error handlers is a process-global side effect, so it never happens as a side effect of asking
     * for a logger.
     */
    public function testGettingALoggerDoesNotInstallGlobalHandlers(): void
    {
        self::assertFalse($this->errorLogger('shop-a.test', 'shop_a')->hasGlobalHandlers());
    }

    public function testGlobalHandlersCanBeInstalledAndRemoved(): void
    {
        $logger = $this->errorLogger('shop-a.test', 'shop_a');

        $logger->registerGlobalHandlers();

        self::assertTrue($logger->hasGlobalHandlers());

        $logger->unregisterGlobalHandlers();

        self::assertFalse($logger->hasGlobalHandlers());
    }

    /**
     * The shape a multi-tenant worker wants: the handlers are live for exactly the span in which this tenant is being
     * served, so a fatal can only be reported under the identity that caused it.
     */
    public function testWithGlobalHandlersRestoresThePreviousHandlingEvenOnFailure(): void
    {
        $logger = $this->errorLogger('shop-a.test', 'shop_a');

        try {
            $logger->withGlobalHandlers(static function (): void {
                throw new \RuntimeException('job failed');
            });
        } catch (\RuntimeException) {
            // Expected.
        }

        self::assertFalse($logger->hasGlobalHandlers());
    }

    /**
     * A second registration on the same instance is a no-op, so a bootstrap that runs twice does not stack handlers.
     */
    public function testRegistrationIsIdempotentPerInstance(): void
    {
        $logger = $this->errorLogger('shop-a.test', 'shop_a');

        $logger->registerGlobalHandlers();
        $logger->registerGlobalHandlers();
        $logger->unregisterGlobalHandlers();

        self::assertFalse($logger->hasGlobalHandlers());
    }

    /**
     * The deprecated name keeps working because plugin bootstraps call it.
     */
    public function testTheDeprecatedInitHandlersStillRegisters(): void
    {
        $logger = $this->errorLogger('shop-a.test', 'shop_a');

        $logger->initHandlers();

        self::assertTrue($logger->hasGlobalHandlers());

        $logger->unregisterGlobalHandlers();
    }

    /**
     * Resetting must not leave a dropped instance's handlers installed - it would keep reporting under an identity the
     * process no longer serves.
     */
    public function testResetUnregistersHandlers(): void
    {
        $logger = $this->errorLogger('shop-a.test', 'shop_a');

        $logger->registerGlobalHandlers();

        ErrorLogger::reset();

        self::assertFalse($logger->hasGlobalHandlers());
    }

    // --- DebugLogger ---

    public function testEachScopeGetsItsOwnDebugLogger(): void
    {
        $shopA = DebugLogger::getInstance($this->logFile, 'shop_a');
        $shopB = DebugLogger::getInstance($this->logFile, 'shop_b');

        self::assertNotSame($shopA, $shopB);
        self::assertSame('shop_a', $shopA->getScope());
    }

    public function testDebugEventsGoToTheScopesOwnDestination(): void
    {
        $shopALogger = $this->createMock(LoggerInterface::class);
        $shopBLogger = $this->createMock(LoggerInterface::class);

        DebugLogger::init($shopALogger, 'shop_a');
        DebugLogger::init($shopBLogger, 'shop_b');

        $shopALogger->expects($this->once())->method('debug');
        $shopBLogger->expects($this->never())->method('debug');

        DebugLogger::getInstance($this->logFile, 'shop_a')->logEvent('shop A only');
    }

    /**
     * A scope with no logger registered stays silent rather than falling back to another scope's destination.
     */
    public function testAScopeWithoutALoggerDoesNotBorrowAnothers(): void
    {
        $shopALogger = $this->createMock(LoggerInterface::class);

        DebugLogger::init($shopALogger, 'shop_a');

        $shopALogger->expects($this->never())->method('debug');

        DebugLogger::getInstance($this->logFile, 'shop_b')->logEvent('shop B only');

        self::assertNull(DebugLogger::getInstance($this->logFile, 'shop_b')->getLogger());
    }

    private function errorLogger(string $host, string $scope): ErrorLogger
    {
        return ErrorLogger::getInstance(
            $this->apiClient,
            $this->logFile,
            $host,
            'TestPlatform',
            '/var/www/html/modules/comfino',
            ['plugin_version' => '1.0.0'],
            null,
            null,
            null,
            $scope
        );
    }
}
