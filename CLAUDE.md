# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

BEAR.QueryRepository is a distributed caching framework for BEAR.Sunday applications using CQRS pattern.

For conceptual documentation, see:
- BEAR.Sunday: https://bearsunday.github.io/llms-full.txt
- This package: https://bearsunday.github.io/BEAR.QueryRepository/llms-full.txt

**Key Features:**
- Event-driven cache invalidation
- Automatic dependency resolution between cached resources
- Donut caching (DO-NOT-CACHE pattern)
- CDN integration (Fastly, Akamai)
- ETag support for conditional requests
- Redis and Memcached support

## Development Commands

### Testing
```bash
# Run all tests
composer test

# Run a single test file
./vendor/bin/phpunit tests/QueryRepositoryTest.php

# Run a single test method
./vendor/bin/phpunit --filter testPut tests/QueryRepositoryTest.php

# Run tests with coverage (using pcov)
composer pcov

# Run tests with coverage (using xdebug)
composer coverage
```

### Code Quality
```bash
# Run all quality checks (coding standards + static analysis + tests)
composer tests

# Coding standards check
composer cs

# Fix coding standards
composer cs-fix

# Static analysis (PHPStan + Psalm)
composer sa

# Clean caches
composer clean
```

### Full Build
```bash
# Run complete build (cs + sa + coverage + metrics)
composer build
```

## Architecture

### Core Components

**QueryRepository** (`src/QueryRepository.php`)
- Main entry point for cache operations
- Handles `put()`, `get()`, and `purge()` operations
- Manages resource state persistence and retrieval
- Processes `#[Cacheable]` and `#[HttpCache]` annotations

**ResourceStorage** (`src/ResourceStorage.php`)
- Low-level storage abstraction using Symfony Cache (TagAwareAdapter)
- Manages two separate cache pools: Resource Object Pool and ETag Pool
- Handles cache keys with prefixes: `ro-` for resources, `donut-` for donut cache
- Supports tag-based invalidation via surrogate keys

**CacheInterceptor** (`src/CacheInterceptor.php`)
- AOP interceptor for `#[Cacheable]` annotation
- Attempts cache retrieval before method execution
- Stores result in cache on successful (200) responses
- Gracefully degrades when cache is unavailable (triggers warning instead of exception)

**CommandInterceptor** (`src/CommandInterceptor.php`)
- Handles `#[Refresh]` and `#[Purge]` annotations
- Executes cache invalidation commands after resource updates
- Only processes successful responses (code < 400)

**DonutRepository** (`src/DonutRepository.php`)
- Implements donut caching pattern (static outer shell + dynamic inner content)
- Separates content cache (frequently changing) from donut structure (stable)
- Supports partial page caching with embedded dynamic resources

**CacheDependency** (`src/CacheDependency.php`)
- Manages cache dependencies via Surrogate-Key headers
- When resource A depends on resource B, B's URI tag is added to A's surrogate keys
- Invalidating B automatically invalidates A

### Module System (Ray.Di)

**QueryRepositoryModule** (`src/QueryRepositoryModule.php`)
- Base module providing core bindings
- Installs CacheableModule and DonutCacheModule
- Binds cache storage adapters (defaults to NullAdapter)

**StorageRedisDsnModule** (`src/StorageRedisDsnModule.php`)
- Configures Redis-backed storage using DSN
- Supports marshaller configuration (default or deflate compression)
- Example usage:
```php
new StorageRedisDsnModule(
    dsn: 'redis://localhost:6379',
    marshallingOptions: [
        'enabled' => true,
        'type' => 'deflate',
        'use_igbinary' => true
    ]
);
```

**StorageMemcachedModule** (`src/StorageMemcachedModule.php`)
- Configures Memcached-backed storage

**CDN Modules:**
- `FastlyModule` - Fastly CDN integration with purging support
- `AkamaiModule` - Akamai CDN integration

### Annotations/Attributes

Located in `src-annotation/`:

**#[Cacheable]** - Marks a resource as cacheable
```php
#[Cacheable(expiry: 'short', type: 'value')]
```
- `expiry`: 'short' | 'medium' | 'long' | 'never'
- `expirySecond`: Custom TTL in seconds
- `expiryAt`: Body field containing expiry timestamp
- `type`: 'value' (body only) | 'view' (body + rendered view)
- `update`: Whether to update cache on write operations

**#[HttpCache]** - Controls HTTP cache headers
```php
#[HttpCache(maxAge: 60, sMaxAge: 600)]
```
- `isPrivate`: Private vs public cache
- `noCache`: Force revalidation
- `noStore`: Disable storage
- `mustRevalidate`: Require revalidation when stale
- `maxAge`: Client cache lifetime
- `sMaxAge`: Shared cache (CDN) lifetime

**#[Refresh]** - Invalidates dependent cache after command
```php
#[Refresh(uri: 'app://self/user')]
```

**#[Purge]** - Purges specific cache after command
```php
#[Purge(uri: 'app://self/user/{id}')]
```

### Cache Invalidation Flow

1. Resource with `#[Refresh]` or `#[Purge]` is invoked
2. CommandInterceptor executes the command
3. After successful response, annotations are processed
4. RefreshAnnotatedCommand resolves URIs and invalidates tags
5. ResourceStorage.invalidateTags() clears both RO and ETag pools
6. CDN purger (if configured) purges surrogate keys

### Surrogate Keys (Tags)

Each cached resource has multiple tags for invalidation:
- ETag value (unique identifier)
- URI tag (from UriTag class)
- Custom surrogate keys from `Header::SURROGATE_KEY`

Dependencies are tracked by copying dependent resource's tags to the parent's surrogate keys.

## Testing

Tests are organized in `tests/`:
- Unit tests for individual components
- Integration tests with fake applications in `tests/Fake/fake-app/`
- PECL extension tests in `tests-pecl-ext/` (Redis, Memcached)
- PHP 8-specific tests in `tests-php8/`

Test configuration: `phpunit.xml.dist`

## Static Analysis

- **PHPStan**: Level max, configured in `phpstan.neon`
- **Psalm**: Error level 1, configured in `psalm.xml`
- Both tools scan `src/` and `tests/`
- Fake test files are excluded from analysis

## Code Style

Follows PSR-12 coding standards with PHP_CodeSniffer.

## Documentation

- BEAR.Sunday LLM docs: https://bearsunday.github.io/llms-full.txt
- This package LLM docs: https://bearsunday.github.io/BEAR.QueryRepository/llms-full.txt
- Cache manual: https://bearsunday.github.io/manuals/1.0/en/cache.html
- Log schemas: https://bearsunday.github.io/BEAR.QueryRepository/schemas/context/ (one JSON Schema per log context)