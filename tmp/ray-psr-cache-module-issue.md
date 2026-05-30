# Remove legacy doctrine annotation comments from provider classes

## Problem
After migrating to PHP 8 attributes in Ray.PsrCacheModule 1.5.0, legacy doctrine annotation comments remain in provider classes. These cause `AnnotationException` when other packages using `koriym/attributes`'s `DualReader` attempt to parse them.

## Error Message
```
Doctrine\Common\Annotations\AnnotationException: [Semantical Error] The class "Ray\PsrCacheModule\Annotation\RedisConfig" is not annotated with @Annotation.
Are you sure this class can be used as annotation?
If so, then you need to add @Annotation to the _class_ doc comment of "Ray\PsrCacheModule\Annotation\RedisConfig".
If it is indeed no annotation, then you need to add @IgnoreAnnotation("RedisConfig") to the _class_ doc comment of method Ray\PsrCacheModule\RedisProvider::__construct().
```

## Root Cause
**File:** `src/RedisProvider.php` (lines 20-23)

```php
/**
 * @param list<string> $server
 *
 * @RedisConfig("server")  // <- Legacy annotation must be removed
 */
#[RedisConfig('server')]
public function __construct(private array $server)
```

The docblock contains both:
1. **Legacy doctrine annotation:** `@RedisConfig("server")` (line 20)
2. **New PHP 8 attribute:** `#[RedisConfig('server')]` (line 22)

When `DualReader` from `koriym/attributes` scans the docblock, it finds `@RedisConfig` and attempts to process it as a doctrine annotation. Since the `RedisConfig` class no longer has the `@Annotation` docblock annotation (correctly removed during the PHP 8 attribute migration), doctrine's `AnnotationReader` throws an exception.

## Files to Fix

### 1. `src/RedisProvider.php`

**Current (line 17-23):**
```php
/**
 * @param list<string> $server
 *
 * @RedisConfig("server")
 */
#[RedisConfig('server')]
public function __construct(private array $server)
```

**Should be:**
```php
/**
 * @param list<string> $server
 */
#[RedisConfig('server')]
public function __construct(private array $server)
```

### 2. `src/MemcachedProvider.php`

Please check if similar legacy `@MemcacheConfig` annotations exist and remove them.

### 3. Other Provider Classes

Check all provider classes for legacy annotation comments that correspond to classes in `src/Annotation/`.

## Solution
Remove all legacy `@AnnotationName` comments from docblocks in provider classes while preserving:
- ✅ PHP 8 attributes (`#[AnnotationName]`)
- ✅ Type hints (`@param`, `@return`, `@var`, etc.)
- ✅ Other documentation comments (`@see`, `@throws`, etc.)

## Steps to Reproduce
1. Install `ray/psr-cache-module:^1.5.0` in a project that uses `koriym/attributes` with `DualReader`
2. Use `Psr6RedisModule` in dependency injection configuration:
```php
$module = new Psr6RedisModule('127.0.0.1:6379');
$injector = new Injector($module);
```
3. Attempt to instantiate a class that depends on Redis
4. Observe `AnnotationException` from doctrine annotation parser

## Expected Behavior
Provider classes should only use PHP 8 attributes without any legacy doctrine annotation comments in docblocks.

## Environment
- **ray/psr-cache-module:** 1.5.0
- **koriym/attributes:** 1.0.7
- **doctrine/annotations:** 1.14.4 (indirect dependency via other packages)
- **PHP:** 8.2+

## Additional Context
This issue was discovered in `BEAR.QueryRepository` tests after updating to `ray/psr-cache-module 1.5.0`. The migration to PHP 8 attributes was successful for annotation classes, but the provider classes still contain legacy annotation comments in docblocks that should have been removed during the migration.

The issue affects any downstream package that:
- Uses `ray/psr-cache-module 1.5.0`
- Has `doctrine/annotations` as an indirect dependency (via `ray/aop`, `koriym/attributes`, etc.)
- Uses `DualReader` to parse both annotations and attributes

## Related Stack Trace
```
/vendor/doctrine/annotations/lib/Doctrine/Common/Annotations/AnnotationException.php:40
/vendor/doctrine/annotations/lib/Doctrine/Common/Annotations/DocParser.php:840
/vendor/koriym/attributes/src/DualReader.php:42
/vendor/ray/aop/sl-src/CacheReader.php:146
/vendor/ray/di/src/di/AnnotatedClassMethods.php:41
/vendor/ray/psr-cache-module/src/Psr6RedisModule.php:47
```