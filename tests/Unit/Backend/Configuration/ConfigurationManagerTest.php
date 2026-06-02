<?php

/**
 * Comfino PHP SDK
 *
 * Backend routines for e-commerce platforms integration with the Comfino payment gateway API.
 *
 * @package Comfino\Tests\Unit\Backend\Configuration
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
 * @copyright Copyright (c) 2026 Comfino by Comperia.pl S.A.
 * @license https://opensource.org/licenses/BSD-3-Clause BSD 3-Clause License
 * @link https://github.com/comfino/php-sdk
 */

declare(strict_types=1);

namespace Comfino\Tests\Unit\Backend\Configuration;

use Comfino\Api\SerializerInterface;
use Comfino\Backend\Configuration\ConfigurationManager;
use Comfino\Backend\Configuration\StorageAdapterInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class ConfigurationManagerTest extends TestCase
{
    private StorageAdapterInterface&MockObject $storageAdapter;
    private SerializerInterface&MockObject $serializer;
    /** @var array<string, int> */
    private array $availConfigOptions;
    /** @var string[] */
    private array $accessibleConfigOptions;

    protected function setUp(): void
    {
        parent::setUp();

        $this->storageAdapter = $this->createMock(StorageAdapterInterface::class);
        $this->serializer = $this->createMock(SerializerInterface::class);

        $this->availConfigOptions = [
            'api_key' => ConfigurationManager::OPT_VALUE_TYPE_STRING,
            'timeout' => ConfigurationManager::OPT_VALUE_TYPE_INT,
            'price' => ConfigurationManager::OPT_VALUE_TYPE_FLOAT,
            'enabled' => ConfigurationManager::OPT_VALUE_TYPE_BOOL,
            'categories' => ConfigurationManager::OPT_VALUE_TYPE_STRING_ARRAY,
            'limits' => ConfigurationManager::OPT_VALUE_TYPE_INT_ARRAY,
            'prices' => ConfigurationManager::OPT_VALUE_TYPE_FLOAT_ARRAY,
            'flags' => ConfigurationManager::OPT_VALUE_TYPE_BOOL_ARRAY,
            'metadata' => ConfigurationManager::OPT_VALUE_TYPE_JSON,
        ];

        $this->accessibleConfigOptions = ['api_key', 'timeout', 'enabled'];

        $this->resetSingleton();
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        $this->resetSingleton();
    }

    private function resetSingleton(): void
    {
        ConfigurationManager::reset();
    }

    private function createInstance(int $options = 0): ConfigurationManager
    {
        return ConfigurationManager::getInstance(
            $this->availConfigOptions,
            $this->accessibleConfigOptions,
            $options,
            $this->storageAdapter,
            $this->serializer
        );
    }

    public function testGetInstanceReturnsSameInstance(): void
    {
        $this->storageAdapter->method('load')->willReturn([]);

        $instance1 = $this->createInstance();
        $instance2 = $this->createInstance();

        $this->assertSame($instance1, $instance2, 'getInstance should return the same instance (singleton pattern).');
    }

    public function testGetConfigurationValueReturnsNullForNonexistentOption(): void
    {
        $this->storageAdapter->method('load')->willReturn([]);

        $manager = $this->createInstance();
        $value = $manager->getConfigurationValue('nonexistent_option');

        $this->assertNull($value);
    }

    public function testSetConfigurationValueMarksOptionAsModified(): void
    {
        $this->storageAdapter->method('load')->willReturn([]);
        $this->storageAdapter->expects($this->once())->method('save')->with(['api_key' => 'test_key']);

        $manager = $this->createInstance();
        $manager->setConfigurationValue('api_key', 'test_key');
        $manager->persist();
    }

    public function testSetConfigurationValueIgnoresUnavailableOptions(): void
    {
        $this->storageAdapter->method('load')->willReturn([]);
        $this->storageAdapter->expects($this->never())->method('save');

        $manager = $this->createInstance();
        $manager->setConfigurationValue('unknown_option', 'value');

        unset($manager);
    }

    public function testGetConfigurationValueReturnsSetValue(): void
    {
        $this->storageAdapter->method('load')->willReturn([]);

        $manager = $this->createInstance();
        $manager->setConfigurationValue('api_key', 'sk_live_12345');

        $this->assertEquals('sk_live_12345', $manager->getConfigurationValue('api_key'));
    }

    public function testSetConfigurationValuesUpdatesMultipleOptions(): void
    {
        $this->storageAdapter->method('load')->willReturn([]);
        $this->storageAdapter->expects($this->once())->method('save')->with(['api_key' => 'key123', 'timeout' => 30]);

        $manager = $this->createInstance();
        $manager->setConfigurationValues(['api_key' => 'key123', 'timeout' => 30]);
        $manager->persist();
    }

    public function testSetConfigurationValuesRespectsAccessibleOptions(): void
    {
        $this->storageAdapter->method('load')->willReturn([]);
        $this->storageAdapter->expects($this->once())->method('save')->with(['api_key' => 'key123']);

        $manager = $this->createInstance();
        $manager->setConfigurationValues(
            ['api_key' => 'key123', 'metadata' => ['foo' => 'bar']],
            ['api_key']
        );
        $manager->persist();
    }

    public function testGetConfigurationValuesReturnsMultipleValues(): void
    {
        $this->storageAdapter->method('load')->willReturn(['api_key' => 'key123', 'timeout' => '30']);

        $manager = $this->createInstance();
        $values = $manager->getConfigurationValues(['api_key', 'timeout']);

        $this->assertEquals(['api_key' => 'key123', 'timeout' => 30], $values);
    }

    public function testReturnConfigurationOptionsReturnsOnlyAccessibleOptions(): void
    {
        $this->storageAdapter->method('load')->willReturn([
            'api_key' => 'key123',
            'timeout' => '30',
            'metadata' => '{"foo":"bar"}',
        ]);

        $manager = $this->createInstance();
        $values = $manager->returnConfigurationOptions();

        $this->assertArrayHasKey('api_key', $values);
        $this->assertArrayHasKey('timeout', $values);
        $this->assertArrayNotHasKey('metadata', $values);
        $this->assertCount(2, $values);
    }

    public function testUpdateConfigurationOptionsUpdatesOnlyAccessibleOptions(): void
    {
        $this->storageAdapter->method('load')->willReturn([]);
        $this->storageAdapter->expects($this->once())->method('save')->with(['api_key' => 'new_key']);

        $manager = $this->createInstance();
        $manager->updateConfigurationOptions(['api_key' => 'new_key', 'metadata' => ['foo' => 'bar']]);
        $manager->persist();
    }

    public function testPersistTrimsStringValues(): void
    {
        $this->storageAdapter->method('load')->willReturn([]);
        $this->storageAdapter->expects($this->once())->method('save')->with(['api_key' => 'trimmed_key']);

        $manager = $this->createInstance();
        $manager->setConfigurationValue('api_key', '  trimmed_key  ');
        $manager->persist();
    }

    public function testPersistSerializesArraysToCommaSeparatedString(): void
    {
        $this->storageAdapter->method('load')->willReturn([]);
        $this->storageAdapter->expects($this->once())->method('save')->with(['categories' => 'cat1,cat2,cat3']);

        $manager = $this->createInstance(ConfigurationManager::OPT_SERIALIZE_ARRAYS);
        $manager->setConfigurationValue('categories', ['cat1', 'cat2', 'cat3']);
        $manager->persist();
    }

    public function testPersistKeepsArraysAsArraysWhenSerializationDisabled(): void
    {
        $this->storageAdapter->method('load')->willReturn([]);
        $this->storageAdapter->expects($this->once())->method('save')->with(['categories' => ['cat1', 'cat2', 'cat3']]);

        $manager = $this->createInstance(0);
        $manager->setConfigurationValue('categories', ['cat1', 'cat2', 'cat3']);
        $manager->persist();
    }

    public function testPersistSerializesJsonValues(): void
    {
        $metadata = ['key1' => 'value1', 'key2' => 'value2'];
        $serializedMetadata = '{"key1":"value1","key2":"value2"}';

        $this->storageAdapter->method('load')->willReturn([]);
        $this->serializer->method('serialize')->with($metadata)->willReturn($serializedMetadata);
        $this->storageAdapter->expects($this->once())->method('save')->with(['metadata' => $serializedMetadata]);

        $manager = $this->createInstance();
        $manager->setConfigurationValue('metadata', $metadata);
        $manager->persist();
    }

    public function testLoadDeserializesStringValues(): void
    {
        $this->storageAdapter->method('load')->willReturn(['api_key' => 'test_key']);

        $manager = $this->createInstance();
        $value = $manager->getConfigurationValue('api_key');

        $this->assertIsString($value);
        $this->assertEquals('test_key', $value);
    }

    public function testLoadDeserializesIntValues(): void
    {
        $this->storageAdapter->method('load')->willReturn(['timeout' => '30']);

        $manager = $this->createInstance();
        $value = $manager->getConfigurationValue('timeout');

        $this->assertIsInt($value);
        $this->assertEquals(30, $value);
    }

    public function testLoadDeserializesFloatValues(): void
    {
        $this->storageAdapter->method('load')->willReturn(['price' => '19.99']);

        $manager = $this->createInstance();
        $value = $manager->getConfigurationValue('price');

        $this->assertIsFloat($value);
        $this->assertEquals(19.99, $value);
    }

    public function testLoadDeserializesBoolValues(): void
    {
        $this->storageAdapter->method('load')->willReturn(['enabled' => '1']);

        $manager = $this->createInstance();
        $value = $manager->getConfigurationValue('enabled');

        $this->assertIsBool($value);
        $this->assertTrue($value);
    }

    public function testLoadDeserializesStringArrayFromCommaSeparated(): void
    {
        $this->storageAdapter->method('load')->willReturn(['categories' => 'cat1,cat2,cat3']);

        $manager = $this->createInstance();
        $value = $manager->getConfigurationValue('categories');

        $this->assertIsArray($value);
        $this->assertEquals(['cat1', 'cat2', 'cat3'], $value);
    }

    public function testLoadDeserializesStringArrayFromNativeArray(): void
    {
        $this->storageAdapter->method('load')->willReturn(['categories' => ['cat1', 'cat2', 'cat3']]);

        $manager = $this->createInstance();
        $value = $manager->getConfigurationValue('categories');

        $this->assertIsArray($value);
        $this->assertEquals(['cat1', 'cat2', 'cat3'], $value);
    }

    public function testLoadDeserializesIntArrayFromCommaSeparated(): void
    {
        $this->storageAdapter->method('load')->willReturn(['limits' => '10,20,30']);

        $manager = $this->createInstance();
        $value = $manager->getConfigurationValue('limits');

        $this->assertIsArray($value);
        $this->assertEquals([10, 20, 30], $value);
    }

    public function testLoadDeserializesFloatArrayFromCommaSeparated(): void
    {
        $this->storageAdapter->method('load')->willReturn(['prices' => '9.99,19.99,29.99']);

        $manager = $this->createInstance();
        $value = $manager->getConfigurationValue('prices');

        $this->assertIsArray($value);
        $this->assertEquals([9.99, 19.99, 29.99], $value);
    }

    public function testLoadDeserializesBoolArrayFromCommaSeparated(): void
    {
        $this->storageAdapter->method('load')->willReturn(['flags' => '1,0,1']);

        $manager = $this->createInstance();
        $value = $manager->getConfigurationValue('flags');

        $this->assertIsArray($value);
        $this->assertEquals([true, false, true], $value);
    }

    public function testLoadDeserializesJsonValues(): void
    {
        $serializedData = '{"key":"value"}';
        $deserializedData = ['key' => 'value'];

        $this->storageAdapter->method('load')->willReturn(['metadata' => $serializedData]);
        $this->serializer->method('unserialize')->with($serializedData)->willReturn($deserializedData);

        $manager = $this->createInstance();
        $value = $manager->getConfigurationValue('metadata');

        $this->assertIsArray($value);
        $this->assertEquals($deserializedData, $value);
    }

    public function testLoadDeserializesJsonArrayValues(): void
    {
        $arrayData = ['key' => 'value'];

        $this->storageAdapter->method('load')->willReturn(['metadata' => $arrayData]);

        $manager = $this->createInstance();
        $value = $manager->getConfigurationValue('metadata');

        $this->assertIsArray($value);
        $this->assertEquals($arrayData, $value);
    }

    public function testLoadHandlesNullValues(): void
    {
        $this->storageAdapter->method('load')->willReturn(['api_key' => null]);

        $manager = $this->createInstance();
        $value = $manager->getConfigurationValue('api_key');

        $this->assertNull($value);
    }

    public function testLoadHandlesEmptyStringArrays(): void
    {
        $this->storageAdapter->method('load')->willReturn(['categories' => '']);

        $manager = $this->createInstance();
        $value = $manager->getConfigurationValue('categories');

        $this->assertIsArray($value);
        $this->assertEmpty($value);
    }

    public function testLoadConvertsNullArrayToNull(): void
    {
        $this->storageAdapter->method('load')->willReturn(['categories' => null]);

        $manager = $this->createInstance();
        $value = $manager->getConfigurationValue('categories');

        $this->assertNull($value);
    }

    public function testLoadIgnoresOptionsNotInAvailableList(): void
    {
        $this->storageAdapter->method('load')->willReturn([
            'api_key' => 'key123',
            'unknown_option' => 'value',
        ]);

        $manager = $this->createInstance();
        $values = $manager->getConfigurationValues(['api_key', 'unknown_option']);

        $this->assertArrayHasKey('api_key', $values);
        $this->assertArrayNotHasKey('unknown_option', $values);
    }

    public function testPersistOnlyModifiedOptions(): void
    {
        $this->storageAdapter->method('load')->willReturn([
            'api_key' => 'old_key',
            'timeout' => '30',
        ]);
        $this->storageAdapter->expects($this->once())->method('save')->with(['api_key' => 'new_key']);

        $manager = $this->createInstance();

        $manager->getConfigurationValue('timeout');
        $manager->setConfigurationValue('api_key', 'new_key');
        $manager->persist();
    }

    public function testPersistIsCalledOnDestruct(): void
    {
        $this->storageAdapter->method('load')->willReturn([]);
        $this->storageAdapter->expects($this->once())->method('save')->with(['api_key' => 'test_key']);

        $manager = $this->createInstance();
        $manager->setConfigurationValue('api_key', 'test_key');
        $manager->persist();
    }

    public function testLazyLoadingOnFirstAccess(): void
    {
        $this->storageAdapter->expects($this->once())->method('load')->willReturn(['api_key' => 'lazy_key']);

        $manager = $this->createInstance();

        $value1 = $manager->getConfigurationValue('api_key');
        $value2 = $manager->getConfigurationValue('api_key');

        $this->assertEquals('lazy_key', $value1);
        $this->assertEquals('lazy_key', $value2);
    }

    public function testSetBeforeLoadPreservesModifiedValues(): void
    {
        $this->storageAdapter->method('load')->willReturn(['api_key' => 'stored_key', 'timeout' => '30']);

        $manager = $this->createInstance();
        $manager->setConfigurationValue('api_key', 'new_key');

        $apiKey = $manager->getConfigurationValue('api_key');
        $timeout = $manager->getConfigurationValue('timeout');

        $this->assertEquals('new_key', $apiKey);
        $this->assertEquals(30, $timeout);
    }

    public function testMultiplePersistCallsOnlyPersistModifiedOptions(): void
    {
        $this->storageAdapter->method('load')->willReturn([]);
        $this->storageAdapter->expects($this->once())->method('save')->with(['api_key' => 'key1']);

        $manager = $this->createInstance();
        $manager->setConfigurationValue('api_key', 'key1');
        $manager->persist();
        $manager->persist();

        unset($manager);
    }

    public function testPersistResetsModifiedFlags(): void
    {
        $this->storageAdapter->method('load')->willReturn([]);
        $this->storageAdapter->expects($this->once())->method('save')->with(['api_key' => 'key1']);

        $manager = $this->createInstance();
        $manager->setConfigurationValue('api_key', 'key1');
        $manager->persist();

        unset($manager);
    }

    public function testPersistWithNoModifications(): void
    {
        $this->storageAdapter->method('load')->willReturn(['api_key' => 'key123']);
        $this->storageAdapter->expects($this->never())->method('save');

        $manager = $this->createInstance();
        $manager->getConfigurationValue('api_key');

        unset($manager);
    }

    public function testComplexWorkflow(): void
    {
        $this->storageAdapter->method('load')->willReturn([
            'api_key' => 'initial_key',
            'timeout' => '60',
            'enabled' => '0',
        ]);

        $this->storageAdapter->expects($this->once())->method('save')
            ->with(['api_key' => 'updated_key', 'enabled' => true]);

        $manager = $this->createInstance();

        $this->assertEquals('initial_key', $manager->getConfigurationValue('api_key'));
        $this->assertEquals(60, $manager->getConfigurationValue('timeout'));
        $this->assertFalse($manager->getConfigurationValue('enabled'));

        $manager->setConfigurationValue('api_key', 'updated_key');
        $manager->setConfigurationValue('enabled', true);

        $this->assertEquals('updated_key', $manager->getConfigurationValue('api_key'));
        $this->assertEquals(60, $manager->getConfigurationValue('timeout'));
        $this->assertTrue($manager->getConfigurationValue('enabled'));

        $manager->persist();
    }

    public function testEmptyArrayDeserialization(): void
    {
        $this->storageAdapter->method('load')->willReturn(['categories' => []]);

        $manager = $this->createInstance();
        $value = $manager->getConfigurationValue('categories');

        $this->assertIsArray($value);
        $this->assertEmpty($value);
    }

    public function testNullIntValueDeserialization(): void
    {
        $this->storageAdapter->method('load')->willReturn(['timeout' => null]);

        $manager = $this->createInstance();
        $value = $manager->getConfigurationValue('timeout');

        $this->assertNull($value);
    }

    public function testNullFloatValueDeserialization(): void
    {
        $this->storageAdapter->method('load')->willReturn(['price' => null]);

        $manager = $this->createInstance();
        $value = $manager->getConfigurationValue('price');

        $this->assertNull($value);
    }

    public function testEmptyJsonDeserialization(): void
    {
        $this->storageAdapter->method('load')->willReturn(['metadata' => '']);

        $manager = $this->createInstance();
        $value = $manager->getConfigurationValue('metadata');

        $this->assertNull($value);
    }

    public function testMultipleModificationsBeforePersist(): void
    {
        $this->storageAdapter->method('load')->willReturn([]);
        $this->storageAdapter->expects($this->once())->method('save')->with(['api_key' => 'final_key']);

        $manager = $this->createInstance();
        $manager->setConfigurationValue('api_key', 'key1');
        $manager->setConfigurationValue('api_key', 'key2');
        $manager->setConfigurationValue('api_key', 'final_key');
        $manager->persist();
    }

    public function testTypeCoercionFromStringToInt(): void
    {
        $this->storageAdapter->method('load')->willReturn(['timeout' => 'not_a_number']);

        $manager = $this->createInstance();
        $value = $manager->getConfigurationValue('timeout');

        $this->assertIsInt($value);
        $this->assertEquals(0, $value);
    }

    public function testTypeCoercionFromStringToFloat(): void
    {
        $this->storageAdapter->method('load')->willReturn(['price' => '19.99']);

        $manager = $this->createInstance();
        $value = $manager->getConfigurationValue('price');

        $this->assertIsFloat($value);
        $this->assertEquals(19.99, $value);
    }

    public function testTypeCoercionFromStringToBool(): void
    {
        $this->storageAdapter->method('load')->willReturn(['enabled' => 'true']);

        $manager = $this->createInstance();
        $value = $manager->getConfigurationValue('enabled');

        $this->assertIsBool($value);
        $this->assertTrue($value);
    }

    public function testIntArrayWithNativeArray(): void
    {
        $this->storageAdapter->method('load')->willReturn(['limits' => [10, 20, 30]]);

        $manager = $this->createInstance();
        $value = $manager->getConfigurationValue('limits');

        $this->assertIsArray($value);
        $this->assertContainsOnly('integer', $value);
        $this->assertEquals([10, 20, 30], $value);
    }

    public function testFloatArrayWithNativeArray(): void
    {
        $this->storageAdapter->method('load')->willReturn(['prices' => [9.99, 19.99, 29.99]]);

        $manager = $this->createInstance();
        $value = $manager->getConfigurationValue('prices');

        $this->assertIsArray($value);
        $this->assertContainsOnly('float', $value);
        $this->assertEquals([9.99, 19.99, 29.99], $value);
    }

    public function testBoolArrayWithNativeArray(): void
    {
        $this->storageAdapter->method('load')->willReturn(['flags' => [true, false, true]]);

        $manager = $this->createInstance();
        $value = $manager->getConfigurationValue('flags');

        $this->assertIsArray($value);
        $this->assertContainsOnly('boolean', $value);
        $this->assertEquals([true, false, true], $value);
    }
}
