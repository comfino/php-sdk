<?php

/**
 * ComfinoPay PHP SDK
 *
 * Backend routines for e-commerce platforms integration with the ComfinoPay payment gateway API.
 *
 * @package Comfino\Tests\Unit\Backend\Configuration
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
 * @copyright Copyright (c) 2026 by ComfinoPay sp. z o.o.
 * @license https://opensource.org/licenses/BSD-3-Clause BSD 3-Clause License
 * @link https://github.com/comfino/php-sdk
 */

declare(strict_types=1);

namespace Comfino\Tests\Unit\Backend\Configuration;

use Comfino\Api\Serializer\Json;
use Comfino\Backend\Configuration\ConfigurationChangeObserverInterface;
use Comfino\Backend\Configuration\ConfigurationChangeSource;
use Comfino\Backend\Configuration\ConfigurationManager;
use Comfino\Backend\Configuration\Constraint\AllowedValuesConstraint;
use Comfino\Backend\Configuration\Constraint\HttpsUrlConstraint;
use Comfino\Backend\Configuration\Constraint\IntRangeConstraint;
use Comfino\Backend\Configuration\Constraint\InvalidConfigValue;
use Comfino\Backend\Configuration\StorageAdapterInterface;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ConfigurationWriteHardeningTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $saved = [];
    /** @var array<int, array{array<string, array{before: mixed, after: mixed}>, ConfigurationChangeSource, ?string}> */
    private array $notifications = [];

    protected function setUp(): void
    {
        parent::setUp();

        ConfigurationManager::reset();
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        ConfigurationManager::reset();
    }

    public function testRejectedOptionIsSkippedAndTheRestOfTheBatchPersists(): void
    {
        $manager = $this->createManager();

        $rejected = $manager->updateConfigurationOptions([
            'COMFINO_PAYWALL_CUSTOM_CSS_URL' => 'http://attacker.example/x.css',
            'COMFINO_API_TIMEOUT' => 600,
            'COMFINO_PAYMENT_TEXT' => 'Pay later',
        ]);
        $manager->persist();

        $this->assertSame(['COMFINO_PAYWALL_CUSTOM_CSS_URL'], $rejected);
        $this->assertSame(['COMFINO_PAYWALL_CUSTOM_CSS_URL'], $manager->getLastRejectedOptions());
        $this->assertSame('https://cdn.example/old.css', $manager->getConfigurationValue('COMFINO_PAYWALL_CUSTOM_CSS_URL'));
        $this->assertSame(30, $manager->getConfigurationValue('COMFINO_API_TIMEOUT'), 'Clamped to the maximum.');
        $this->assertSame(['COMFINO_API_TIMEOUT' => 30, 'COMFINO_PAYMENT_TEXT' => 'Pay later'], $this->saved);
    }

    public function testConstraintsAlsoApplyToProgrammaticWrites(): void
    {
        $manager = $this->createManager();

        $manager->setConfigurationValue('COMFINO_API_TIMEOUT', 0);
        $manager->setConfigurationValue('COMFINO_PAYWALL_CUSTOM_CSS_URL', 'javascript:alert(1)');

        $this->assertSame(1, $manager->getConfigurationValue('COMFINO_API_TIMEOUT'));
        $this->assertSame('https://cdn.example/old.css', $manager->getConfigurationValue('COMFINO_PAYWALL_CUSTOM_CSS_URL'));

        $manager->persist();
    }

    public function testObserverReceivesOnlyRealChangesWithTheirSource(): void
    {
        $manager = $this->createManager();

        $manager->updateConfigurationOptions([
            'COMFINO_PAYMENT_TEXT' => 'ComfinoPay', // Unchanged value
            'COMFINO_API_TIMEOUT' => 10,
        ]);
        $manager->persist();

        $manager->setConfigurationValue('COMFINO_PAYMENT_TEXT', 'Raty');
        $manager->persist();

        $this->assertSame(
            [
                [['COMFINO_API_TIMEOUT' => ['before' => 5, 'after' => 10]], ConfigurationChangeSource::RemoteEndpoint, 'store_1'],
                [['COMFINO_PAYMENT_TEXT' => ['before' => 'ComfinoPay', 'after' => 'Raty']], ConfigurationChangeSource::Programmatic, 'store_1'],
            ],
            $this->notifications
        );
    }

    public function testNoOpWriteNotifiesNothing(): void
    {
        $manager = $this->createManager();

        $manager->updateConfigurationOptions(['COMFINO_PAYMENT_TEXT' => 'ComfinoPay']);
        $manager->persist();

        $this->assertSame([], $this->notifications);
    }

    public function testAThrowingObserverDoesNotUndoThePersist(): void
    {
        $manager = $this->createManager();
        $observer = $this->createMock(ConfigurationChangeObserverInterface::class);
        $observer->method('onConfigurationChanged')->willThrowException(new RuntimeException('Audit table missing.'));
        $manager->setChangeObserver($observer);

        $manager->setConfigurationValue('COMFINO_PAYMENT_TEXT', 'Raty');
        $manager->persist();

        $this->assertSame(['COMFINO_PAYMENT_TEXT' => 'Raty'], $this->saved);
    }

    public function testEmptyAccessibleListAllowsNoRemoteWrite(): void
    {
        $manager = $this->createManager([]);

        $manager->updateConfigurationOptions(['COMFINO_PAYMENT_TEXT' => 'Hacked']);
        $manager->persist();

        $this->assertSame('ComfinoPay', $manager->getConfigurationValue('COMFINO_PAYMENT_TEXT'));
        $this->assertSame([], $this->saved);
    }

    public function testNullAccessibleListStillAllowsAllProgrammaticWrites(): void
    {
        $manager = $this->createManager([]);

        $manager->setConfigurationValues(['COMFINO_PAYMENT_TEXT' => 'Raty']);

        $this->assertSame('Raty', $manager->getConfigurationValue('COMFINO_PAYMENT_TEXT'));

        $manager->persist();
    }

    public function testHttpsUrlConstraint(): void
    {
        $constraint = new HttpsUrlConstraint(['comfino.pl']);

        $this->assertSame('https://cdn.comfino.pl/a.css', $constraint->apply('X', ' https://cdn.comfino.pl/a.css '));
        $this->assertSame('', $constraint->apply('X', ''));

        $invalidValues = ['http://cdn.comfino.pl/a.css', 'https://comfino.pl.evil.example/a.css', 'https://user:pw@comfino.pl/', '//comfino.pl/a.css', 42];

        foreach ($invalidValues as $invalid) {
            try {
                $constraint->apply('X', $invalid);
                $this->fail('Accepted ' . var_export($invalid, true));
            } catch (InvalidConfigValue) {
                $this->addToAssertionCount(1);
            }
        }

        $this->expectException(InvalidConfigValue::class);

        (new HttpsUrlConstraint([], false))->apply('X', '');
    }

    public function testIntRangeConstraint(): void
    {
        $constraint = new IntRangeConstraint(1, 30);

        $this->assertSame(5, $constraint->apply('X', '5'));
        $this->assertSame(30, $constraint->apply('X', 600));
        $this->assertSame(1, $constraint->apply('X', -4));

        $this->expectException(InvalidConfigValue::class);

        (new IntRangeConstraint(1, 30, false))->apply('X', 600);
    }

    public function testIntRangeConstraintRejectsNonIntegers(): void
    {
        $this->expectException(InvalidConfigValue::class);

        (new IntRangeConstraint(1, 30))->apply('X', '5s');
    }

    public function testAllowedValuesConstraint(): void
    {
        $constraint = new AllowedValuesConstraint(['pending', 'processing']);

        $this->assertSame('pending', $constraint->apply('X', 'pending'));
        $this->assertSame(['pending'], $constraint->apply('X', ['pending']));
        $this->assertSame(
            ['ACCEPTED' => 'processing'],
            (new AllowedValuesConstraint(['processing']))->apply('X', ['ACCEPTED' => 'processing'])
        );
        $this->assertSame(
            ['ACCEPTED' => 'x'],
            (new AllowedValuesConstraint(['ACCEPTED'], checkKeys: true))->apply('X', ['ACCEPTED' => 'x'])
        );

        $this->expectException(InvalidConfigValue::class);

        $constraint->apply('X', ['pending', 'complete']);
    }

    public function testRecommendedAdminOnlyOptionsCoverTheSensitiveSet(): void
    {
        foreach (['COMFINO_DEBUG', 'COMFINO_IS_SANDBOX', 'COMFINO_STATUS_MAP', 'COMFINO_INITIAL_ORDER_STATUS', 'COMFINO_API_TIMEOUT'] as $option) {
            $this->assertContains($option, ConfigurationManager::RECOMMENDED_ADMIN_ONLY_OPTIONS);
        }
    }

    /**
     * @param string[] $accessible
     */
    private function createManager(?array $accessible = null): ConfigurationManager
    {
        $options = [
            'COMFINO_PAYWALL_CUSTOM_CSS_URL' => ConfigurationManager::OPT_VALUE_TYPE_STRING,
            'COMFINO_API_TIMEOUT' => ConfigurationManager::OPT_VALUE_TYPE_INT,
            'COMFINO_PAYMENT_TEXT' => ConfigurationManager::OPT_VALUE_TYPE_STRING,
        ];

        $storage = $this->createMock(StorageAdapterInterface::class);
        $storage->method('load')->willReturn([
            'COMFINO_PAYWALL_CUSTOM_CSS_URL' => 'https://cdn.example/old.css',
            'COMFINO_API_TIMEOUT' => 5,
            'COMFINO_PAYMENT_TEXT' => 'ComfinoPay',
        ]);
        $storage->method('save')->willReturnCallback(function (array $saved): void {
            $this->saved = array_merge($this->saved, $saved);
        });

        $manager = ConfigurationManager::getInstance($options, $accessible ?? array_keys($options), 0, $storage, new Json());
        $manager->setValueConstraints([
            'COMFINO_PAYWALL_CUSTOM_CSS_URL' => new HttpsUrlConstraint(),
            'COMFINO_API_TIMEOUT' => new IntRangeConstraint(1, 30),
        ]);
        $manager->setChangeObserver(
            new class (function (array $changes, ConfigurationChangeSource $source, ?string $tenantKey): void {
                $this->notifications[] = [$changes, $source, $tenantKey];
            }) implements ConfigurationChangeObserverInterface {
                public function __construct(private readonly \Closure $record)
                {
                }

                public function onConfigurationChanged(array $changes, ConfigurationChangeSource $source, ?string $tenantKey): void
                {
                    ($this->record)($changes, $source, $tenantKey);
                }
            },
            'store_1'
        );

        return $manager;
    }
}
