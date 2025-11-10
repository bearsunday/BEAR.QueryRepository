# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added
- **Migration Tools**: Added `rector-migrate.php` for automated annotation-to-attribute migration
- **Migration Guide**: Added `ANNOTATION_TO_ATTRIBUTE.md` with comprehensive migration instructions
- Add CLAUDE.md with comprehensive codebase architecture and development guide
- Add marshaller configuration support for Redis with compression options (deflate)
- Add `MarshallerType` enum for type-safe marshaller selection
- Add support for `RelayCluster` in `RedisDsnProvider`
- Add Japanese README (README.ja.md)
- Add `#[Override]` PHP attribute across all applicable methods and classes
- Add Memcached EtagPool module with TagAwareAdapter support
- Add Redis DSN module (`StorageRedisDsnModule`) with provider implementation
- Add TagsPool annotation and binding to QueryRepositoryModule
- Add validation to ensure FastlyPurgeModule is installed when used
- Add Dependabot configuration
- Add PHP 8.4 support to CI workflow
- Add PHP 8.5 support to CI workflow

### Changed
- **PHP 8 Attributes Migration**: Removed `doctrine/annotations` and `doctrine/cache` dependencies, migrated to native PHP 8 attributes
- **Minimum PHP Version**: Updated requirement from PHP 8.1 to PHP 8.2
- Update development tools: PHP_CodeSniffer to 4.0, Doctrine Coding Standard to 14.0, Slevomat Coding Standard to 8.24, PHPUnit to 11.5
- Optimize readonly class declarations for PHP 8.2 (class-level modifier)
- Improve cache attribute and interceptor documentation with usage examples
- Improve `rector-migrate.php` to support vendor installation by removing hardcoded paths
- Update `ANNOTATION_TO_ATTRIBUTE.md` migration guide following Ray.AuraSqlModule pattern
- Improve marshaller provider error handling with better exception messages
- Enhance Memcached module with TagAwareAdapter support
- Update Symfony Cache to support version ^7.3
- Update `symfony/polyfill-php83` dependency to `^v1.32.0`
- Update `mobiledetect/mobiledetectlib` to support version ^4.8
- Update composer dependencies (`madapaja/twig-module`, `phpunit/phpunit`, `predis/predis`, `twig/twig`, `symfony/process`)
- Update `vimeo/psalm` to version 6.12
- Update `ray/aop` dependency to ^2.16
- Update `ray/di` dependency to ^2.17.2
- Set `ResourceStorage` to singleton scope for better performance
- Refactor `ResourceStorage` with `ProviderInterface` for serialization support
- Improve UriTag test coverage for consistent key generation across parameter order
- Normalize URI separators in generated cache keys for cross-platform compatibility
- Simplify ETag and surrogate key generation logic
- Handle both forward slashes and backslashes in surrogate key generation
- Sanitize ETags to replace reserved characters
- Replace symlink with actual file for cross-platform compatibility
- Enable package sorting in composer.json
- Refactor CI workflow for expanded PHP version and OS coverage
- Update copyright year to 2025

### Removed
- Remove Sodium marshaller related code
- Remove `bear/fastly-module` from production dependencies (moved to dev dependencies)
- Remove unnecessary singleton scopes from bindings
- Remove unused PSR cache annotations and RedisAdapter binding
- Remove unused MemcachedAdapter bindings
- Remove redundant `assert` statements from codebase
- Remove unused dependencies from composer.json

### Deprecated
- Deprecate `StorageApcModuleTest`
- Deprecate `StorageRedisModuleTest` (use `StorageRedisDsnModule` instead)
- Deprecate `StorageRedisMemcachedModule`
- Deprecate `BcModule`
- Deprecate `NamespacedCacheProvider` class
- Deprecate `ResourceStorageCacheableTrait`

### Fixed
- Fix type casting in headers
- Fix path separator replacement for cross-platform compatibility (Windows/macOS/Linux)
- Fix typo in deprecated notice
- Fix README file extension issue

## [1.9.9] - 2024-XX-XX
(Previous releases not documented yet)