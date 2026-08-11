# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added
- Cache observability is now built on [Koriym.SemanticLogger](https://github.com/koriym/Koriym.SemanticLogger): an open/event/close tree whose nesting **is** the embed/dependency structure (a parent's embedded children nest under it). Typed `AbstractContext` subclasses live in `src/Log/Context/` with per-context JSON Schemas in `docs/schemas/context/`.
- `SafeSemanticLogger` (best-effort decorator) guarantees logging never breaks cache reads/writes. The DI default bound via `SafeSemanticLoggerProvider` in `DonutCacheModule` is `SafeSemanticLogger(new SemanticLogger())`; `NullSemanticLogger` is the constructor-parameter fallback when no logger is injected.
- `invalidate` context records per-target outcomes as self-describing status words: `roPool`/`etagPool` (`invalidated`|`failed`), `cdn` (`purged`|`failed`), plus `durationMs`. A CDN purge failure is fail-closed: the local pools are invalidated first and the outcome is logged as `cdn: failed`, then the purge exception propagates so a write does not silently leave stale CDN content.
- Logs validate against their schemas in tests via `SemanticLogValidator` (`SemanticLogTreeTrait`), and `vendor/bin/stree` renders the cache log as a readable tree (`demo/run-dependency.php`, `demo/run-donut.php`).
- Direct (non-AOP) top-level `put()`, `purge()` and `invalidateTags()` calls are rooted in `manual_store` / `manual_purge` / `manual_invalidate` scopes so their save/purge/invalidate events stay visible; an event with no enclosing scope would otherwise be dropped at flush.
- `TopLevelAwareInterface` (`BEAR\QueryRepository\Log`): the "would the next open be top-level" capability that grants manual-scope rooting. Call sites check this interface instead of the concrete `SafeSemanticLogger`, so any custom `SemanticLoggerInterface` decorator can opt in to `manual_store` / `manual_purge` / `manual_invalidate` rooting.
- `pre_write_cleanup` context (`docs/schemas/context/pre_write_cleanup.json`): writer-side marker emitted right before a writer clears the entry it is about to rewrite, so cleanup-vs-invalidation is a recorded fact, not a reader inference.
- `cache_error` context: emitted when the cache layer itself throws (e.g. cache server down) in the read/write interceptors, so a cache outage is distinguishable from a genuine cold-cache miss in the log.
- `saved` outcome field on the save contexts (`save_value` / `save_view` / `save_donut` / `save_donut_view` / `save_etag`): the cache pool's accept/reject result, so a silently failed store no longer looks like a successful save.
- `tags` (invalidation tags) on all five save contexts, so a save can be correlated with the `invalidate` events that later bust it.
- `put_skipped` context: emitted when a miss is not followed by a put, so a miss without save events reads as a recorded skip, not a lost write. `reason` is `etag-present`, `error-code` (with the actual response `code`, also emitted by `CacheInterceptor` on a non-200 GET), or `not-cacheable` (a donut page re-rendered from its template is never stored as a rendered page).
- `source` field on the `command` context naming the producing interceptor (`CommandInterceptor` / `DonutCommandInterceptor` / `RefreshInterceptor`).
- `operation` field on `cache_error` (`read` / `write`), so the failing side of a degraded cache layer is recorded.
- `cdn` on `invalidate` is now tri-state: `purged` (a configured purger ran), `failed` (it threw), `skipped` (the bound purger is `NullPurger`, i.e. no CDN configured) — previously a no-op NullPurger was indistinguishable from a real purge (`purged`).
- `ttl` field on `save_etag`, completing the save contexts; all `ttl` descriptions state the convention that 31536000 is the `never` expiry placeholder and event-driven invalidation is the intended eviction path.
- `log_session_broken` sentinel: when `SafeSemanticLogger` must discard a broken logging session (e.g. a LIFO violation), the recovery flush returns this scope carrying the cause instead of a silent empty log — "no records" is never misread as "no cache activity".
- Negative TTL clamping: a past `expiryAt` or a negative `expirySecond`/ttl argument is clamped to 0 at the `QueryRepository`/`ResourceStorage` boundary, matching the `"minimum": 0` the schemas declare.
- The demos verify themselves: all three scripts print the semantic log (tree + pretty JSON) and validate the flushed session offline against `docs/schemas/context`, printing a one-line verdict and exiting non-zero on any violation. `demo/run-dependency.php` scenario 3 is now command-driven — a PUT on `LevelThree` (whose new `onPut` carries `#[Purge]`) opens a `command` scope whose purge cascades to level-two/level-one — alongside the manual `manual_purge` entry kind in scenario 6. `demo/run.php` binds real in-memory pools so its log shows genuine cache hits (the `QueryRepositoryModule` default `NullAdapter` made every GET miss).

### Deprecated
- `RepositoryLogger`, `RepositoryLoggerInterface`, `StructuredRepositoryLoggerInterface` and `NullRepositoryLogger`. Internal cache code now logs through `Koriym\SemanticLogger\SemanticLoggerInterface`; the legacy flat interface remains bound for BC but receives no internal events.

### Removed
- `docs/schemas/repository-log.json` (the flat op-string log format it described is gone; per-context schemas in `docs/schemas/context/` replace it).

### Changed
- Cache logging call sites (`QueryRepository`, `ResourceStorage`, `DonutRepository`, `CacheInterceptor`, `AbstractDonutCacheInterceptor`, `CommandInterceptor`, `RefreshInterceptor`) now emit typed contexts through `SemanticLoggerInterface` instead of `RepositoryLoggerInterface::log()`.
- `SaveDonutContext`/`SaveDonutViewContext`: the misleading `sMaxAge` field is renamed to `ttl` — the value is the cache entry TTL, never a CDN s-maxage.
- `SaveEtagContext`/`SaveDonutViewContext`: `surrogateKeys` renamed to `tags`; all save contexts now consistently report invalidation tags under `tags`.
- Command scopes are opened even for failed writes: a 4xx response closes with `command_result` (code 4xx) and no invalidation events, recording that the purge/refresh was correctly skipped instead of vanishing from the log.
- Removed the post-save `assert()` in `ResourceStorage::saveDonut()`: with assertions enabled it threw AFTER the `saved: false` event was logged, contradicting quiet-failure recording.
- Pre-write cleanup is recorded at the source instead of inferred: `QueryRepository::doPut()` and `DonutRepository::putStatic()`/`putDonut()` emit a `pre_write_cleanup` marker right before clearing the entry they are about to rewrite, and an `invalidate` is cleanup iff the event immediately preceding it in the same scope's event stream is that marker. This supersedes the earlier reader-side tag-correlation rule — and removes its undecidable donut case — in the schemas and guides.
- Added runtime dependency `koriym/semantic-logger`.

## [1.16.2] - 2026-06-29

### Fixed
- Remove unused ETag from invalidation tags: ETag was unnecessarily registered as a cache invalidation tag. No code path invalidates by ETag (only by URI tag and surrogate keys), so each content version produced a new, non-volatile tag Set that was never read or reclaimed. Under `volatile-*` eviction policies these Sets leaked memory indefinitely. (#180)

## [1.16.1] - 2026-06-01

### Fixed
- Fix multi-embed cache dependency: a `#[Cacheable]` resource embedding more than one child kept only the last child's dependency, so purging an earlier child failed to invalidate the parent (stale cache). `CacheDependency::depends()` now accumulates child tags instead of overwriting, and the erroneous assertion that a parent had no prior tags has been removed.

## [1.16.0] - 2026-05-16

### Fixed
- Resolve embed cache dependencies before HAL rendering: child resources' ETag headers are now collected before `HalRenderer` strips `Request` instances from the body, so the parent's `Surrogate-Key` reliably includes all embedded children (#174)
- Include async embed children in dependency resolution: walk by `AbstractRequest` instead of the concrete `Request`, so `AsyncRequest` (and other `AbstractRequest` subclasses) are no longer silently skipped

### Changed
- Cache dependency resolution moved from `EtagSetter` / `DevEtagSetter` into `QueryRepository::put()` (runs on HTTP 200 only, before persistence)
- `QueryRepository::__construct()` now requires `CacheDependencyInterface`
- `EtagSetter::__construct()` and `DevEtagSetter::__construct()` no longer accept `CacheDependencyInterface`
- DI users via `QueryRepositoryModule` are unaffected; only callers that directly `new` these classes need to update their constructor calls

### Added
- HAL embed cache dependency test (`tests/CacheDependencyTest.php`)
- Non-Cacheable embed child test fixtures and `continue` path coverage

## [1.15.0] - 2026-02-03

### Added
- Add `ServerContextInterface` for coroutine-safe request handling in Swoole/RoadRunner environments
- Add `GlobalServerContext` as default implementation using `$_SERVER` superglobal
- Add `RepositoryLoggerInterface` with `reset()` method for long-running process support

### Changed
- Bind `ServerContextInterface` to `GlobalServerContext` in `QueryRepositoryModule`
- Use `ServerContextInterface` in `ResourceStorage` instead of direct `$_SERVER` access

## [1.14.0] - 2025-01-24

### Added
- Add LLM documentation (`docs/llms.txt`, `docs/llms-full.txt`) for AI-assisted development
- Add JSON schema for RepositoryLogger output (`docs/schemas/repository-log.json`)
- Add cache dependency demos for AI log analysis (`demo/run-dependency.php`, `demo/run-donut.php`)
- Add cache dependency test coverage documentation (`tests/CACHE_DEPENDENCY_TESTS.md`)
- Add test resources for cache dependency patterns (ParentA, ParentB, ChildC)

### Changed
- Change RepositoryLogger output to JSON format for structured logging
- Update `.gitattributes` to exclude development files from release
- Require `ray/aop` ^2.19.1 and `ray/di` ^2.20 for PHP 8.5 compatibility
- Update copyright year to 2026

### Fixed
- Fix UriTagTest typo in documentation

## [1.13.0] - 2024-11-11

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