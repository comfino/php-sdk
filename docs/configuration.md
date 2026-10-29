# Configuration Management

`ConfigurationManager` persists plugin settings via a platform-provided storage adapter. It supports typed options,
array serialization, and lazy loading, and keeps **one instance per tenant scope** so a process serving several shops
never answers one shop's lookup from another shop's cached values.

## Setup

Implement `StorageAdapterInterface` for your platform:

```php
use Comfino\Backend\Configuration\StorageAdapterInterface;

class MyStorageAdapter implements StorageAdapterInterface
{
    public function load(array $configKeys): array
    {
        // Load values from the shop database / options table.
        return MyShop::getOptions($configKeys);
    }

    public function save(array $config): void
    {
        // Persist values to the shop database / options table.
        MyShop::updateOptions($config);
    }
}
```

Then obtain the manager:

```php
use Comfino\Backend\Configuration\ConfigurationManager;
use Comfino\Api\Serializer\Json;

$configManager = ConfigurationManager::getInstance(
    availConfigOptions: [
        'COMFINO_API_KEY' => ConfigurationManager::OPT_VALUE_TYPE_STRING,
        'COMFINO_ENABLED' => ConfigurationManager::OPT_VALUE_TYPE_BOOL,
        'COMFINO_PRODUCT_TYPES' => ConfigurationManager::OPT_VALUE_TYPE_STRING_ARRAY,
        'COMFINO_MIN_AMOUNT' => ConfigurationManager::OPT_VALUE_TYPE_INT,
    ],
    accessibleConfigOptions: ['COMFINO_API_KEY', 'COMFINO_ENABLED', 'COMFINO_MIN_AMOUNT'],
    options: ConfigurationManager::OPT_SERIALIZE_ARRAYS,
    storageAdapter: new MyStorageAdapter(),
    serializer: new Json(),
    /* Omit for a single-shop plugin. In a host serving several merchants, pass the merchant's id: instances are keyed
       by scope, so values loaded for one shop can never be served to another. */
    scope: $installationId
);
```

## Reading and writing values

```php
// Read a single value (lazy-loaded on first access).
$apiKey = $configManager->getConfigurationValue('COMFINO_API_KEY');
$enabled = $configManager->getConfigurationValue('COMFINO_ENABLED');        // Returns bool.
$types = $configManager->getConfigurationValue('COMFINO_PRODUCT_TYPES');    // Returns array.

// Read several at once, or everything the integration is allowed to expose.
$some = $configManager->getConfigurationValues(['COMFINO_API_KEY', 'COMFINO_ENABLED']);
$all = $configManager->returnConfigurationOptions();

// Write values (held in memory until flushed).
$configManager->setConfigurationValue('COMFINO_ENABLED', true);
$configManager->setConfigurationValue('COMFINO_MIN_AMOUNT', 10000);

// Write pending changes to storage. Required - see below.
$configManager->flush();
```

## Flushing is explicit

`__destruct()` does **not** persist. Call `flush()` where the unit of work that made the changes ends; unflushed
changes raise an `E_USER_WARNING` naming the option keys that were dropped, and `isDirty()` answers the same question
in code.

The destructor used to save, and in a plugin that was indistinguishable from an explicit call — destruction happened at
the end of the request that owned the values. In a long-lived process it is a different thing: destruction time is
unrelated to request time, so a merchant's configuration could be written back long after the request that changed it
had ended, and a process holding several scoped instances deferred every write to shutdown, in whatever order PHP
happened to release them.

`setPersistOnDestruct(true)` restores the old behavior for a host that cannot yet move the call — explicitly, so the
reliance is visible.

## Value type constants

| Constant                      | PHP type        |
|-------------------------------|-----------------|
| `OPT_VALUE_TYPE_STRING`       | `string`        |
| `OPT_VALUE_TYPE_INT`          | `int`           |
| `OPT_VALUE_TYPE_FLOAT`        | `float`         |
| `OPT_VALUE_TYPE_BOOL`         | `bool`          |
| `OPT_VALUE_TYPE_STRING_ARRAY` | `string[]`      |
| `OPT_VALUE_TYPE_INT_ARRAY`    | `int[]`         |
| `OPT_VALUE_TYPE_JSON`         | raw JSON string |

## Notes

- Call `ConfigurationManager::reset()` in tests to clear cached instances between test cases; pass a scope to drop just
  that one.
- `OPT_SERIALIZE_ARRAYS` causes array values to be JSON-serialized on save and deserialized on load automatically.
- Only options listed in `$accessibleConfigOptions` are exposed to external consumers (e.g., returned via the Configuration webhook endpoint).
