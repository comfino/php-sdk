# Configuration management

`ConfigurationManager` is a singleton that persists plugin settings via a platform-provided storage adapter. It supports typed options, array serialization, and lazy loading.

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

Then initialize the singleton:

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
    serializer: new Json()
);
```

## Reading and writing values

```php
// Read a single value (lazy-loaded on first access).
$apiKey = $configManager->getValue('COMFINO_API_KEY');
$enabled = $configManager->getValue('COMFINO_ENABLED'); // Returns bool.
$types = $configManager->getValue('COMFINO_PRODUCT_TYPES'); // Returns array.

// Write values (queued until save() is called).
$configManager->setValue('COMFINO_ENABLED', true);
$configManager->setValue('COMFINO_MIN_AMOUNT', 10000);

// Persist all queued changes.
$configManager->save();
```

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

- Call `ConfigurationManager::reset()` in tests to clear the singleton between test cases.
- `OPT_SERIALIZE_ARRAYS` causes array values to be JSON-serialized on save and deserialized on load automatically.
- Only options listed in `$accessibleConfigOptions` are exposed to external consumers (e.g., returned via the Configuration webhook endpoint).
