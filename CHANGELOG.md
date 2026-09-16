# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.17.0] - 2026-09-17

### Added
- **Cache observability rebuilt on [Koriym.SemanticLogger](https://github.com/koriym/Koriym.SemanticLogger)** (#178): typed, schema-validated open/event/close log tree replaces free-text `RepositoryLoggerInterface` messages. Off by default - install `DevQueryRepositoryLogModule` (dev) or `ProdQueryRepositoryLogModule` (prod) to record. See `docs/reading-the-log.md`, `docs/why-the-log-records-everything.md`, `docs/what-the-log-proves.md` (Japanese translations included).
- New log contexts: `cache_policy` (#186), `cache_error`, `pool_error`, `put_skipped`, `pre_write_cleanup`, `cdn_headers`, `conditional_request`, `invalidate` (tri-state `cdn`, plus `durationMs`), `command` (`source` field), `semantic_logger_error` (core). Schemas in `docs/schemas/context/`.
- New fields on existing save contexts: `saved`, `tags`, `requestedTtl` (`save_etag`), `durationMs` on the `cache_hit`/`cache_miss` close.
- `#[CacheLog]` qualifier resolves the cache logger. `SafeSemanticLogger`/`SessionStoreInterface`/`ProcessSession` keep it safe across a serialized injector (#179). `TopLevelAwareInterface` lets a custom logger opt into manual-call scope rooting.
- Direct (non-AOP) `put()`/`putStatic()`/`putDonut()`/`purge()`/`invalidateTags()` calls now open `manual_store`/`manual_purge`/`manual_invalidate` scopes.
- `LogSinkInterface`/`ShutdownFlush`, `LogWriterInterface` (`LogFileWriter`/`LogStreamWriter`/`PsrLogWriter`), `ConcurrentRuntimeInterface`/`HostRuntime` (refuses to arm under RoadRunner/a Swoole coroutine).
- `ProdQueryRepositoryLogModule`: buffers a session and applies `RetentionPolicyInterface`/`KeepMutationsAndFailures` at flush.
- `DevQueryRepositoryLogModule`: writes one file per request plus `latest.json` for `vendor/bin/stree`.
- `demo/run-degraded.php` and `DemoLogCoverageTest`: the demos now cover every context, schema enum value and command source, and self-validate against `docs/schemas/context`.
- `UriScopedHttpCacheInterface::isNotModifiedFor()` / `ScopedValidatorInterface::hasEtagFor()`: opt-in ETag scoped to the requested URI (#197, #201). Entries written before this version cannot be scoped: each client pays one full (non-304) response after the upgrade, once.
- Negative TTL is clamped to 0 at the `QueryRepository`/`ResourceStorage` boundary.
- Dependency evidence in `docs/reading-the-log.md` is now stated per parent declaration (#188).

### Deprecated
- `RepositoryLogger`, `RepositoryLoggerInterface`, `StructuredRepositoryLoggerInterface`, `NullRepositoryLogger`: bound for BC but receive no internal events.
- The `update` parameter of `#[Cacheable]`: it has no effect.

### Removed
- `docs/schemas/repository-log.json` (superseded by per-context schemas in `docs/schemas/context/`).
- `BEAR\QueryRepository\Log\NullSemanticLogger` (koriym/semantic-logger 0.9 ships its own).
- `skills/bear-cache-log/`: folded into [bear-observe](https://github.com/bearsunday/BEAR.EventSourcing/blob/1.x/skills/bear-observe/SKILL.md).

### Changed
- Cache logging call sites now emit typed contexts through `SemanticLoggerInterface`/`#[CacheLog]` instead of `RepositoryLoggerInterface::log()`.
- `SaveDonutContext`/`SaveDonutViewContext`: `sMaxAge` field renamed to `requestedTtl`.
- `SaveEtagContext`/`SaveDonutViewContext`: `surrogateKeys` field renamed to `tags`.
- A failed command write (4xx) now closes `command_result` instead of vanishing from the log.
- Removed the post-save `assert()` in `ResourceStorage::saveDonut()` (it threw after `saved: false` was already logged).
- Pre-write cleanup is recorded at the source (`pre_write_cleanup` marker) instead of inferred from tag correlation.
- A donut refresh no longer appends an `r` marker to the ETag, so an unchanged recomposition still revalidates via `If-None-Match`.
- New runtime dependency: `koriym/semantic-logger`.
- **Breaking**: recording is off by default - `#[CacheLog]` binds to `NullSemanticLogger` unless a log module is installed.
- **Breaking** (#190): `Exception\CacheStoreFailure` now marks the cache-failure boundary. An unreadable ETag pool answers the conditional request in full instead of failing it, and a donut write the store refuses now serves the rendered page instead of a 500.
- `#[Refresh]` no longer double-writes its own `#[Cacheable]` destination after a regenerating GET.
- **Breaking**: renamed `ResourceDonut::FOMRAT` → `FORMAT`, `EtagSetter::getEtagByEitireView()` → `getEtagByEntireView()`, and the misspelled parameters `$httpCacche` → `$httpCache` (`EtagSetter::getEtagByPartialBody()`), `$concheControlMaxAge` → `$cacheControlMaxAge` (`HeaderSetter::__invoke()`) - a named-argument caller has to follow.

### Fixed
- Donut caching could not handle a non-200 response: `saveView()` required a 200-only validator, and `DonutRepository`/`ResourceDonut` did not restore the stored status code (#206, #207). Behaviour change: a `#[CacheableResponse]`/`#[DonutCache]` page answering 2xx or 3xx is now served with that status instead of crashing or degrading to 200 - a redirect that was reachable only once now persists until its cache entry is invalidated, so such a page needs the surrogate keys that invalidate it.
- `onPost` on a `#[Cacheable]` class ran with no interceptor at all, silently dropping `#[Refresh]`/`#[Purge]` (#212).
- `onPost` on a `#[Cacheable]`/donut class missing an `onGet`-required parameter threw `UnmatchedQuery` uncaught - a regression from #214, the fix above (#219, #220).
- A write could be answered from cache without running, when a command method carried `#[RefreshCache]` or a method-level `#[CacheableResponse]`; both now bind `DonutCommandInterceptor`. `DonutCacheModule` also missed `onPost` in its write matcher.
- `DevEtagSetter`/`MobileEtagSetter` set a validator on a non-200 response, where `EtagSetter` always skipped it; `CdnCacheControlHeaderSetterInterface` likewise now applies to 200 only.
- A client `If-None-Match` token containing a PSR-6 reserved character reached the ETag pool as a cache key and threw a 500; such tokens (and `*`) are now dropped and the request answered in full.
- An embedded child was rendered twice per parent store instead of reusing the execution the renderer already paid for.
- A donut refresh advanced `Last-Modified` even for byte-identical content; `Age` is now derived from `storedAt` instead of `Last-Modified`.
- `putStatic()`/`putDonut()` logged a negative lifetime verbatim while storage clamped what it stored.
- A donut write failure on the AOP path (pool outage, renderer error) is now recorded as `cache_error{operation: write}`.
- **Behaviour change for 1.16.x installations**: fixed a 1.16.0 regression where a resource declaring its own `Surrogate-Key` lost embed dependency tracking and kept serving purged children.
- Deduplicated the `Surrogate-Key` header when a resource is written twice in one request.
- `ResourceStorage::saveEtag()` used a hard-coded `new UriTag()` instead of the injected `UriTagInterface`.
- **Behaviour change for existing installations** (#185): a donut template with no `Surrogate-Key` was untagged and unreachable by `purge()`; it now rebuilds on its first purge. Entries written before this version need a rewrite or a cache clear to become reachable.
- A `#[Cacheable]` value entry with no renderer previously degraded every store to a warning and left the cache empty; it is now stored without rendering. The ETag validator falls back from the view to the body, a stored value entry no longer carries the renderer's `Content-Type`, and a custom `EtagSetterInterface` now receives `$ro->view === null` on the value path and must read the body instead.
- A value-entry body PHP cannot `serialize()` (a `Closure`, for instance) is a separate, known limitation: the write's `serialize()` `Exception` propagates uncaught rather than degrading to `CacheStoreFailure` (#208's rule: only the store's own failure is swallowed). Keep such values out of a value entry's body, or catch `Exception` at the call site.
- `CliHttpCache::isNotModifiedFor()` ignored the CLI request form's `If-None-Match` argument.
- `MatchQuery` threw for an `onGet` parameter with a default value that a write's query omitted.
- Two donut placeholders on one template line were captured as a single URI by a greedy pattern.
- `CDN-Cache-Control` directives are now comma-separated per RFC 9213 (were space-separated).
- Under `AkamaiModule`, a `#[CacheableResponse]` page lost its embedded children's invalidation tags (`ResourceStorageInterface::saveDonutView()` gained an optional `$tags` parameter).
- `MobileEtagSetter` serialized the raw body and threw on any `#[Embed]`; it now hashes the materialized copy the way `EtagSetter` does.

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