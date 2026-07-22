# Upgrading to v2.0.0

## Breaking Changes

### Cache system: `cache/tag-interop` → `symfony/cache-contracts`

The `cache/tag-interop` package (abandoned) has been replaced with the actively maintained `symfony/cache-contracts`.

**What changed:**
- `CacheInvalidate` constructor now accepts any `Psr\Cache\CacheItemPoolInterface` instead of requiring `Cache\TagInterop\TaggableCacheItemPoolInterface`.

**What to do:**
Inject any `Psr\Cache\CacheItemPoolInterface`. For tag-based cache invalidation to work, the implementation must also implement `Symfony\Contracts\Cache\TagAwareCacheInterface` (e.g., Symfony's `TagAwareAdapter`).

If the cache pool does not support tagging, `CacheInvalidate` silently skips the invalidation step.

**Example:**
```php
// Before
use Cache\TagInterop\TaggableCacheItemPoolInterface;

$cache = new TaggableCacheItemPoolInterface(); // Required interface
$endpoint = new CacheInvalidate('name', '/url', $cache);

// After
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Contracts\Cache\TagAwareCacheInterface;

$cache = new SomePoolImplementation(); // Just needs CacheItemPoolInterface
// If it also implements TagAwareCacheInterface, tagging will work automatically.
$endpoint = new CacheInvalidate('name', '/url', $cache);
```

For tag invalidation to function, use an implementation that implements both interfaces, such as Symfony's `TagAwareAdapter`:
```php
use Symfony\Component\Cache\Adapter\TagAwareAdapter;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;

$cache = new TagAwareAdapter(new FilesystemAdapter());
$endpoint = new CacheInvalidate('name', '/url', $cache);
```
