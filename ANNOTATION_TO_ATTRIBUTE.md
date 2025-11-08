# Migrating from Doctrine Annotations to PHP 8 Attributes

## Overview

BEAR.QueryRepository 1.13.0 has removed the dependency on `doctrine/annotations` and now exclusively uses native PHP 8 attributes. This guide will help you migrate your application code from Doctrine annotations to PHP 8 attributes.

## Why Migrate?

- **doctrine/annotations is Abandoned**: The `doctrine/annotations` package has been officially abandoned by its maintainers. Continuing to use it poses security and compatibility risks as it will no longer receive updates or security patches.
- **Native PHP Support**: PHP 8 attributes are a built-in language feature, providing first-class support without external dependencies
- **Better Performance**: No runtime annotation parsing overhead - attributes are compiled and cached by PHP itself
- **Modern Syntax**: Cleaner, more readable code that follows PHP 8+ best practices
- **No Extra Dependencies**: Eliminates the need for the abandoned doctrine/annotations package
- **Future-Proof**: Attributes are the official PHP standard for metadata, ensuring long-term compatibility

## Migration Steps

### Step 1: Update BEAR.QueryRepository

First, ensure you're using BEAR.QueryRepository v1.13.0 or later:

```bash
composer require bear/query-repository:^1.13
```

### Step 2: Install Rector (if not already installed)

Rector is an automated refactoring tool that can convert annotations to attributes:

```bash
composer require --dev rector/rector
```

### Step 3: Run Automated Migration

BEAR.QueryRepository provides a Rector configuration file for automated migration:

```bash
# Dry-run to preview changes
vendor/bin/rector process src --config=vendor/bear/query-repository/rector-migrate.php --dry-run

# Apply the changes
vendor/bin/rector process src --config=vendor/bear/query-repository/rector-migrate.php
```

If you have tests that use annotations:

```bash
vendor/bin/rector process tests --config=vendor/bear/query-repository/rector-migrate.php
```

### Step 4: Manual Review

Review the changes made by Rector and adjust if necessary. Pay special attention to:

- Multi-line annotations with complex values
- Annotations with custom parameters
- Import statements (Rector should handle these automatically)

### Step 5: Remove doctrine/annotations

After migration, you can safely remove the doctrine/annotations dependency:

```bash
composer remove doctrine/annotations
```

## Before and After Examples

### Cacheable Resource

**Before (Doctrine Annotation):**
```php
use BEAR\RepositoryModule\Annotation\Cacheable;

/**
 * @Cacheable(expiry="short", type="value")
 */
class User extends ResourceObject
{
    public function onGet($id)
    {
        // ...
    }
}
```

**After (PHP 8 Attribute):**
```php
use BEAR\RepositoryModule\Annotation\Cacheable;

#[Cacheable(expiry: "short", type: "value")]
class User extends ResourceObject
{
    public function onGet($id)
    {
        // ...
    }
}
```

### HTTP Cache Headers

**Before (Doctrine Annotation):**
```php
use BEAR\RepositoryModule\Annotation\HttpCache;

/**
 * @HttpCache(maxAge=60, sMaxAge=600)
 */
class News extends ResourceObject
{
    public function onGet()
    {
        // ...
    }
}
```

**After (PHP 8 Attribute):**
```php
use BEAR\RepositoryModule\Annotation\HttpCache;

#[HttpCache(maxAge: 60, sMaxAge: 600)]
class News extends ResourceObject
{
    public function onGet()
    {
        // ...
    }
}
```

### Cache Invalidation

**Before (Doctrine Annotation):**
```php
use BEAR\RepositoryModule\Annotation\Refresh;
use BEAR\RepositoryModule\Annotation\Purge;

class User extends ResourceObject
{
    /**
     * @Refresh(uri="app://self/users")
     * @Purge(uri="app://self/user/{id}")
     */
    public function onPut($id, $name)
    {
        // ...
    }
}
```

**After (PHP 8 Attribute):**
```php
use BEAR\RepositoryModule\Annotation\Refresh;
use BEAR\RepositoryModule\Annotation\Purge;

class User extends ResourceObject
{
    #[Refresh(uri: "app://self/users")]
    #[Purge(uri: "app://self/user/{id}")]
    public function onPut($id, $name)
    {
        // ...
    }
}
```

### Complex Cache Configuration

**Before (Doctrine Annotation):**
```php
use BEAR\RepositoryModule\Annotation\Cacheable;
use BEAR\RepositoryModule\Annotation\HttpCache;

/**
 * @Cacheable(expiry="medium", expirySecond=3600, type="view", update=true)
 * @HttpCache(maxAge=60, sMaxAge=600, isPrivate=false, mustRevalidate=true)
 */
class Article extends ResourceObject
{
    // ...
}
```

**After (PHP 8 Attribute):**
```php
use BEAR\RepositoryModule\Annotation\Cacheable;
use BEAR\RepositoryModule\Annotation\HttpCache;

#[Cacheable(expiry: "medium", expirySecond: 3600, type: "view", update: true)]
#[HttpCache(maxAge: 60, sMaxAge: 600, isPrivate: false, mustRevalidate: true)]
class Article extends ResourceObject
{
    // ...
}
```

### Donut Caching

**Before (Doctrine Annotation):**
```php
use BEAR\RepositoryModule\Annotation\DonutCache;

class Page extends ResourceObject
{
    /**
     * @DonutCache
     */
    public function onGet()
    {
        // ...
    }
}
```

**After (PHP 8 Attribute):**
```php
use BEAR\RepositoryModule\Annotation\DonutCache;

class Page extends ResourceObject
{
    #[DonutCache]
    public function onGet()
    {
        // ...
    }
}
```

## Supported Annotations

The following BEAR.QueryRepository annotations are automatically converted:

- `@Cacheable` → `#[Cacheable]` - Resource caching configuration
- `@HttpCache` → `#[HttpCache]` - HTTP cache control headers
- `@NoHttpCache` → `#[NoHttpCache]` - Disable HTTP caching
- `@Refresh` → `#[Refresh]` - Invalidate dependent caches
- `@Purge` → `#[Purge]` - Purge specific cache entries
- `@DonutCache` → `#[DonutCache]` - Donut caching pattern

## Key Differences

1. **Syntax Change**: Use `#[AttributeName]` instead of `@AttributeName` in docblocks
2. **Named Parameters**: Use colons for parameters (e.g., `expiry: "short"` instead of `expiry="short"`)
3. **Placement**: Attributes go before the class/method declaration, not in docblocks
4. **Import Statements**: Add proper `use` statements for all attributes
5. **Multiple Attributes**: Stack multiple attributes on separate lines or combine with commas

## Troubleshooting

### Rector doesn't find annotations

Make sure your code is using fully qualified class names in `use` statements:

```php
// Correct
use BEAR\RepositoryModule\Annotation\Cacheable;

// Incorrect - Rector won't recognize this
use BEAR\RepositoryModule\Annotation as Cache;
```

### Import statements not updated

Rector should automatically update import statements, but if it doesn't:

1. Manually verify `use` statements are present
2. Run your IDE's "Optimize Imports" feature
3. Use tools like PHP-CS-Fixer to clean up unused imports

### Complex annotation values

For annotations with complex array values, you may need to manually adjust the syntax:

**Before:**
```php
/**
 * @HttpCache(etag={"id", "updated_at"})
 */
```

**After:**
```php
#[HttpCache(etag: ["id", "updated_at"])]
```

### Testing the migration

After migration, ensure all tests pass:

```bash
composer test
```

## Manual Migration

If you prefer not to use Rector, you can manually convert annotations:

1. Replace `/** @AnnotationName */` with `#[AnnotationName]`
2. Move attributes from docblocks to the line before the method/property/class
3. Convert parameter syntax from `key="value"` to `key: "value"`
4. Ensure all necessary `use` statements are present
5. For multiple attributes, place each on a new line or combine: `#[Attr1, Attr2]`

## Need Help?

If you encounter issues during migration:

1. Check the [BEAR.QueryRepository documentation](https://github.com/bearsunday/BEAR.QueryRepository)
2. Review the [PHP 8 Attributes documentation](https://www.php.net/manual/en/language.attributes.php)
3. [Open an issue](https://github.com/bearsunday/BEAR.QueryRepository/issues) on GitHub

## References

- [PHP 8 Attributes RFC](https://wiki.php.net/rfc/attributes_v2)
- [Rector Documentation](https://github.com/rectorphp/rector)
- [BEAR.Sunday Documentation](https://bearsunday.github.io/)