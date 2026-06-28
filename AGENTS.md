# AGENTS.md

This file provides guidance to Codex (Codex.ai/code) when working with code in this repository.

## Overview

**BEAR.QueryRepository** is a distributed caching framework for BEAR.Resource applications implementing Resource Query Responsibility Segregation (RQRS), inspired by CQRS. It separates reads and writes into distinct repositories to optimize performance and resource utilization.

Key capabilities:
- Event-driven cache invalidation with dependency resolution
- Donut caching (combining static and dynamic content)
- CDN integration (Fastly, Akamai) with surrogate key management
- ETag support for conditional requests and 304 responses
- Multi-layer cache support (Redis, Memcached, APC, CDN, client-side)

## Development Commands

### Testing
```bash
# Run all tests
composer test
# or
./vendor/bin/phpunit

# Run tests with coverage (using PCOV)
composer pcov

# Run tests with coverage (using Xdebug)
composer coverage

# Run single test
./vendor/bin/phpunit tests/QueryRepositoryTest.php

# Run specific test method
./vendor/bin/phpunit --filter testMethodName tests/QueryRepositoryTest.php
```

### Code Quality
```bash
# Run all quality checks (coding standards + static analysis + tests)
composer tests

# Coding standards check (PHPCS)
composer cs

# Fix coding standards automatically (PHPCBF)
composer cs-fix

# Static analysis (PHPStan + Psalm)
composer sa

# Clean caches
composer clean

# Full build (cs + sa + coverage + metrics)
composer build
```

### Static Analysis
```bash
# Run PHPStan
./vendor/bin/phpstan analyse -c phpstan.neon

# Run Psalm
./vendor/bin/psalm --show-info=true
```

## Architecture

### Core Concepts

**Query Repository Pattern (RQRS)**
- **Query (Read)**: Reads are served from cache via `QueryRepository` and `CacheInterceptor`
- **Command (Write)**: Writes invalidate cache via `CommandInterceptor` and refresh dependencies
- Resources with `#[Cacheable]` attribute are automatically cached on first access

**Interceptor Architecture**
- `CacheInterceptor`: Intercepts resource GET requests, checks cache, serves from cache or generates and stores
- `CommandInterceptor`: Intercepts resource POST/PUT/PATCH/DELETE, invalidates related caches after successful operations
- `RefreshInterceptor`: Supports `#[RefreshCache]` to invalidate dependencies

### Core Components

**QueryRepository** (`src/QueryRepository.php`)
- Main entry point for cache operations: `put()`, `get()`, `purge()`
- Coordinates between `ResourceStorage`, `HeaderSetter`, `EtagSetter`
- Handles cache TTL calculation from `#[Cacheable]` annotation parameters

**ResourceStorage** (`src/ResourceStorage.php`)
- Low-level cache storage implementation using Symfony Cache components
- Manages two cache pools: Resource Object pool and ETag pool
- Uses TagAwareAdapter for tag-based invalidation
- Supports both "value" caching (body only) and "view" caching (rendered output)
- Key prefixes: `ro-` for resource objects, `donut-` for donut cache

**DonutRepository** (`src/DonutRepository.php`)
- Implements donut caching: static outer shell with dynamic holes
- Two modes:
  - Static content with donut holes: uses `putStatic()` with s-maxage for CDN
  - Pure donut: uses `putDonut()` for edge-cached templates
- Refreshes dynamic content while serving static parts from cache

**Interceptors**
- `CacheInterceptor`: Read-through cache for queries
- `CommandInterceptor`: Write-through cache invalidation for commands
- `RefreshInterceptor`: Explicit cache refresh for dependencies

### Annotations & Attributes

**#[Cacheable]** (`src-annotation/Cacheable.php`)
```php
#[Cacheable(
    expiry: 'short'|'medium'|'long'|'never',  // Predefined TTL
    expirySecond: 3600,                        // Explicit TTL in seconds
    expiryAt: 'fieldName',                     // Field containing expiry timestamp
    update: false,                             // Force cache update
    type: 'value'|'view'                       // Cache body or rendered view
)]
```

**#[HttpCache]** (`src-annotation/HttpCache.php`)
- Sets HTTP cache headers (Cache-Control, max-age, s-maxage)

**#[RefreshCache]** (`src-annotation/RefreshCache.php`)
- Declares cache dependencies; invalidates related resources on command

### Module System

**QueryRepositoryModule** (`src/QueryRepositoryModule.php`)
- Base module providing core bindings
- Installs `CacheableModule` and `DonutCacheModule`

**Storage Modules**
- `StorageMemcachedModule`: Memcached adapter configuration
- `StorageRedisDsnModule`: Redis adapter with DSN configuration
- `StorageExpiryModule`: Custom TTL configuration

#### Redis Marshaller Configuration

`StorageRedisDsnModule` supports optional marshaller configuration for data compression and serialization optimization:

**Supported Marshaller Types:**
- `default`: Standard PHP serialization (optionally with igbinary for better performance)
- `deflate`: Compression using zlib extension (reduces memory usage and network transfer)

**Usage Example:**
```php
use BEAR\QueryRepository\StorageRedisDsnModule;

$module = new StorageRedisDsnModule(
    dsn: 'redis://localhost:6379',
    options: [],
    defaultLifetime: 3600,
    marshallingOptions: [
        'enabled' => true,
        'type' => 'deflate',        // Use compression
        'use_igbinary' => true      // Use igbinary for serialization (requires ext-igbinary)
    ]
);
```

**When to use deflate marshaller:**
- Caching large objects (HTML, JSON responses, large arrays)
- Reducing Redis memory footprint and costs
- Network bandwidth is a concern (remote Redis instances)
- Trading CPU (compression overhead) for memory/bandwidth savings

**Required PHP extensions:**
- `deflate` type: requires `zlib` extension
- `use_igbinary`: requires `igbinary` extension (optional but recommended)

**Feature Modules**
- `CacheableModule`: Enables `#[Cacheable]` caching
- `DonutCacheModule`: Enables donut caching with `#[DonutCache]`
- `MobileEtagModule`: Varies ETag by mobile/desktop detection
- `DevEtagModule`: Development mode with cache disabled

### Cache Invalidation Strategy

**Tag-based Invalidation**
- Each resource gets a URI tag: `UriTag` generates stable keys from URI + query params
- ETags are stored with tags for surrogate key invalidation
- `ResourceStorage::invalidateTags()` purges by tag across pools

**Dependency Resolution**
- `#[RefreshCache]` annotation declares resource dependencies
- `CommandInterceptor` triggers `RefreshAnnotatedCommand` or `RefreshSameCommand`
- Cascading invalidation through dependency graph

### Key Interfaces

- `QueryRepositoryInterface`: Main cache repository contract
- `ResourceStorageInterface`: Low-level storage operations
- `DonutRepositoryInterface`: Donut caching operations
- `PurgerInterface`: CDN purge integration point
- `RepositoryLoggerInterface`: Cache operation logging
- `EtagSetterInterface`: ETag generation strategy
- `UriTagInterface`: URI to cache key conversion

## Testing Structure

- `tests/`: Main test suite (PHPUnit)
- `tests-pecl-ext/`: Tests requiring PECL extensions (Redis, Memcached)
- `tests-php8/`: PHP 8.0+ specific tests
- `tests-deprecated/`: Tests for deprecated features
- `tests/Fake/fake-app/`: Mock BEAR.Sunday application for integration tests

## Key Design Patterns

1. **Interceptor Pattern**: AOP-based caching via Ray.Aop interceptors
2. **Repository Pattern**: Separates cache storage from business logic
3. **Strategy Pattern**: Pluggable storage backends (Redis, Memcached, APCu)
4. **Decorator Pattern**: Cache layers wrap resource objects
5. **Tag-based Cache**: Symfony Cache TagAwareAdapter for multi-key invalidation

## Important Notes

- Cache keys are generated by `UriTag` which creates consistent keys from URI + sorted query parameters
- `ResourceState` is the serializable cache value containing code, headers, body, view
- Donut caching requires a `DonutRendererInterface` implementation (typically via template engine)
- CDN integration via `PurgerInterface` for surrogate key purging (see `bear/fastly-module`)
- ETag generation supports mobile variance via `MobileEtagSetter`
